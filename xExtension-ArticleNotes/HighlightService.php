<?php

declare(strict_types=1);

namespace ArticleNotes;

/**
 * The single entry point for writing one user's highlights. It validates, resolves the target (an article, or a web
 * page that is no article) and stores; it never anchors a selector in a document (clients do).
 *
 * @phpstan-type Selector array{quote:array{exact:string,prefix?:string,suffix?:string},position?:array{start:int,end:int}}
 */
final class HighlightService {
	public const MAX_PER_TARGET = 1000;
	public const MAX_EXACT_LENGTH = 2000;
	public const MAX_CONTEXT_LENGTH = 64;
	public const DEFAULT_COLOR = 'yellow';

	private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

	/** @var list<\Closure(Highlight,bool):void> */
	private array $savedListeners = [];
	/** @var list<\Closure(Highlight):void> */
	private array $deletedListeners = [];

	/** @param (\Closure():int)|null $clock current Unix time (default: time()) */
	public function __construct(
		private readonly string $user,
		private readonly NoteService $notes,
		private readonly ?\Closure $clock = null,
	) {
	}

	/** @param \Closure(Highlight,bool):void $listener called after a highlight is created (true) or updated (false) */
	public function onSaved(\Closure $listener): void {
		$this->savedListeners[] = $listener;
	}

	/** @param \Closure(Highlight):void $listener called after a highlight is tombstoned */
	public function onDeleted(\Closure $listener): void {
		$this->deletedListeners[] = $listener;
	}

	/** The live highlight with this UUID, or null. */
	public function get(string $uuid): ?Highlight {
		return $this->dao()->find(strtolower($uuid));
	}

	/**
	 * Creates a highlight, idempotently by UUID: the same UUID on the same target returns the existing highlight.
	 *
	 * @param array<string,mixed> $input `uuid`, `entry_id`, `url`, `title`, `source`, `selector`, `color`, `note`
	 * @return array{highlight:Highlight,created:bool}
	 * @throws HighlightException
	 */
	public function create(array $input): array {
		$dao = $this->dao();
		$uuid = self::uuidOf($input['uuid'] ?? null);
		$entryId = self::entryIdOf($input['entry_id'] ?? null);
		$url = self::stringOf($input, 'url');
		$title = self::titleOf($input);
		$source = self::sourceOf($input['source'] ?? null);
		$selector = self::selectorOf($input['selector'] ?? null);
		$color = self::colorOf($input['color'] ?? self::DEFAULT_COLOR);
		$note = self::noteOf($input['note'] ?? '');
		if ($entryId === null && trim($url) === '') {
			throw new HighlightException(HighlightException::INVALID_TARGET, 'Give the "entry_id" of an article or the "url" of a page.');
		}

		$existing = $dao->find($uuid, true);
		if ($existing !== null) {
			if ($existing->isDeleted() || !$this->sameTarget($existing, $entryId, $url)) {
				throw new HighlightException(HighlightException::UUID_CONFLICT,
					'This uuid is already used by a ' . ($existing->isDeleted() ? 'deleted highlight' : 'highlight on another target') . '.');
			}
			return ['highlight' => $existing, 'created' => false];
		}

		[$article, $pageUrl, $normalized] = $this->resolveTarget($entryId, $url);
		$live = $article === null ? $dao->countLiveForPage($normalized) : $dao->countLiveForEntry($article->entryId);
		if ($live >= self::MAX_PER_TARGET) {
			throw new HighlightException(HighlightException::TOO_MANY, 'A page or article is limited to ' . self::MAX_PER_TARGET . ' highlights.');
		}
		if ($title === '' && $article !== null) {
			$title = mb_substr($article->title, 0, NoteService::MAX_TITLE_LENGTH, 'UTF-8');
		}

		$now = $this->now();
		try {
			$dao->insert(self::newHighlight($uuid, $article, $pageUrl, $normalized, $title, $source, $selector, $color, $note, $now));
		} catch (\RuntimeException $exception) {
			$raced = $dao->find($uuid, true);	// a concurrent retry of the same request won
			if ($raced === null || $raced->isDeleted() || !$this->sameTarget($raced, $entryId, $url)) {
				throw $exception;
			}
			return ['highlight' => $raced, 'created' => false];
		}
		$highlight = $dao->find($uuid);
		if ($highlight === null) {
			throw new \RuntimeException('Highlight vanished after saving');
		}
		foreach ($this->savedListeners as $listener) {
			$listener($highlight, true);
		}
		return ['highlight' => $dao->find($uuid) ?? $highlight, 'created' => true];	// listeners may have updated it (label bookkeeping)
	}

	/**
	 * Changes the note and/or the color of a highlight (the selector is immutable: delete and recreate to move it).
	 *
	 * @param string|null $note the new note ('' clears it), null to keep it
	 * @param string|null $color the new color, null to keep it
	 * @throws HighlightException
	 */
	public function update(string $uuid, ?string $note, ?string $color): Highlight {
		$dao = $this->dao();
		$uuid = strtolower($uuid);
		$note = $note === null ? null : self::noteOf($note);
		$color = $color === null ? null : self::colorOf($color);
		$current = $dao->find($uuid);
		if ($current === null) {
			throw new HighlightException(HighlightException::NOT_FOUND, 'There is no such highlight.');
		}
		$dao->update($uuid, $note ?? $current->note, $color ?? $current->color, max($this->now(), $current->updatedAt + 1));
		$highlight = $dao->find($uuid);
		if ($highlight === null) {
			throw new \RuntimeException('Highlight vanished after saving');
		}
		foreach ($this->savedListeners as $listener) {
			$listener($highlight, false);
		}
		return $dao->find($uuid) ?? $highlight;
	}

	/** Tombstones a highlight. @return bool whether a live highlight was deleted */
	public function delete(string $uuid): bool {
		$dao = $this->dao();
		$uuid = strtolower($uuid);
		$current = $dao->find($uuid);
		if ($current === null || !$dao->softDelete($uuid, max($this->now(), $current->updatedAt + 1))) {
			return false;
		}
		$highlight = $dao->find($uuid, true);
		if ($highlight !== null) {
			foreach ($this->deletedListeners as $listener) {
				$listener($highlight);
			}
		}
		return true;
	}

	/**
	 * The live highlights of an article in document order.
	 *
	 * @return list<Highlight>
	 */
	public function forEntry(string $entryId): array {
		return self::inDocumentOrder($this->dao()->listForEntry($entryId));
	}

	/**
	 * The live highlights of the page (no article) at a normalized URL in document order.
	 *
	 * @return list<Highlight>
	 */
	public function forPage(string $normalizedUrl): array {
		return self::inDocumentOrder($this->dao()->listForPage($normalizedUrl));
	}

	/**
	 * The live highlights of what a URL stands for: the articles at that URL, then the page itself.
	 *
	 * @return list<Highlight>
	 * @throws HighlightException not an http or https URL
	 */
	public function forUrl(string $url): array {
		$normalized = UrlNormalizer::normalize($url)
			?? throw new HighlightException(HighlightException::INVALID_URL, '"url" must be an http or https URL.');
		$highlights = [];
		foreach ((new UrlLookup($this->notes))->find($normalized)['matches'] as $match) {
			$highlights = array_merge($highlights, $this->forEntry($match->entryId));
		}
		return array_merge($highlights, $this->forPage($normalized));
	}

	/**
	 * Highlights (tombstones included unless `$includeDeleted` is false) after `($since, $afterId)` in `(updated_at, id)` order.
	 *
	 * @return list<Highlight>
	 */
	public function changedSince(int $since, int $limit, int $afterId = 0, bool $includeDeleted = true): array {
		return $this->dao()->listChangedSince($since, $limit, $afterId, $includeDeleted);
	}

	/**
	 * The given article ids that FreshRSS still has.
	 *
	 * @param list<string> $entryIds
	 * @return list<string>
	 */
	public function existingEntryIds(array $entryIds): array {
		return $this->notes->existingEntryIds($entryIds);
	}

	public function count(): int {
		return $this->dao()->count();
	}

	/**
	 * By `position.start` when present (then by creation), else by creation.
	 *
	 * @param list<Highlight> $highlights
	 * @return list<Highlight>
	 */
	public static function inDocumentOrder(array $highlights): array {
		usort($highlights, static fn (Highlight $a, Highlight $b): int => [$a->positionStart() === null ? 1 : 0, $a->positionStart() ?? 0, $a->createdAt, $a->id]
			<=> [$b->positionStart() === null ? 1 : 0, $b->positionStart() ?? 0, $b->createdAt, $b->id]);
		return $highlights;
	}

	/**
	 * @return array{ArticleSnapshot|null,string,string} the article (when the target is one), the URL to store, its normalized form
	 * @throws HighlightException
	 */
	private function resolveTarget(?string $entryId, string $url): array {
		if ($entryId !== null) {
			$article = ArticleSnapshot::load($this->user, $entryId)
				?? throw new HighlightException(HighlightException::UNKNOWN_ENTRY, 'There is no such article.');
			return [$article, $article->url(), $article->normalizedUrl];
		}
		$normalized = UrlNormalizer::normalize($url)
			?? throw new HighlightException(HighlightException::INVALID_URL, '"url" must be an http or https URL.');
		$match = (new UrlLookup($this->notes))->find($normalized)['matches'][0] ?? null;
		if ($match === null) {
			return [null, trim($url), $normalized];
		}
		// A purged article that still has annotations is known by its snapshot only.
		$article = ArticleSnapshot::load($this->user, $match->entryId) ?? new ArticleSnapshot(
			$match->entryId, '', $match->url, $match->title, '', $match->publishedAt, $match->feedId, $match->feedName, $match->feedUrl,
			UrlNormalizer::normalize($match->url) ?? $normalized,
		);
		return [$article, $article->url(), $article->normalizedUrl];
	}

	/**
	 * @param Selector $selector
	 */
	private static function newHighlight(
		string $uuid, ?ArticleSnapshot $article, string $url, string $normalized, string $title, string $source,
		array $selector, string $color, string $note, int $now,
	): Highlight {
		return new Highlight(
			0, $uuid, $article?->entryId, $url, $normalized, $title,
			$article === null ? '' : $article->guid, $article === null ? '' : $article->link, $article === null ? '' : $article->title,
			$article === null ? '' : $article->author, $article === null ? 0 : $article->date,
			$article === null ? 0 : $article->feedId, $article === null ? '' : $article->feedName, $article === null ? '' : $article->feedUrl,
			$source, $selector, $color, $note, $now, $now, null,
		);
	}

	/** Whether a retried create names the target of an existing highlight. */
	private function sameTarget(Highlight $existing, ?string $entryId, string $url): bool {
		if ($entryId !== null) {
			return $existing->entryId === $entryId;
		}
		$normalized = UrlNormalizer::normalize($url);
		if ($normalized === null) {
			return false;
		}
		if ($existing->entryId === null) {
			return $existing->normalizedUrl === $normalized;
		}
		$match = (new UrlLookup($this->notes))->find($normalized)['matches'][0] ?? null;
		return $existing->normalizedUrl === $normalized || ($match !== null && $match->entryId === $existing->entryId);
	}

	/** @throws HighlightException */
	private static function uuidOf(mixed $value): string {
		if ($value === null) {
			$bytes = random_bytes(16);
			$bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
			$bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
			return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
		}
		if (!is_string($value) || preg_match(self::UUID_PATTERN, strtolower($value)) !== 1) {
			throw new HighlightException(HighlightException::INVALID_UUID, '"uuid" must be a UUID version 4.');
		}
		return strtolower($value);
	}

	/** @throws HighlightException */
	private static function entryIdOf(mixed $value): ?string {
		if ($value === null) {
			return null;
		}
		if (is_int($value) && $value > 0) {
			return (string)$value;
		}
		if (is_string($value) && preg_match('/^[1-9]\d{0,19}$/', $value) === 1) {
			return $value;
		}
		throw new HighlightException(HighlightException::INVALID_FIELD, '"entry_id" must be an article id.');
	}

	/**
	 * @param array<string,mixed> $input
	 * @throws HighlightException
	 */
	private static function stringOf(array $input, string $name): string {
		$value = $input[$name] ?? '';
		if (!is_string($value)) {
			throw new HighlightException(HighlightException::INVALID_FIELD, '"' . $name . '" must be a string.');
		}
		return $value;
	}

	/**
	 * @param array<string,mixed> $input
	 * @throws HighlightException
	 */
	private static function titleOf(array $input): string {
		$title = trim(self::stringOf($input, 'title'));
		if (mb_strlen($title, 'UTF-8') > NoteService::MAX_TITLE_LENGTH) {
			throw new HighlightException(HighlightException::TITLE_TOO_LONG, 'A title is limited to ' . NoteService::MAX_TITLE_LENGTH . ' characters.');
		}
		return $title;
	}

	/** @throws HighlightException */
	private static function sourceOf(mixed $value): string {
		if (!is_string($value) || !in_array($value, Highlight::SOURCES, true)) {
			throw new HighlightException(HighlightException::INVALID_SOURCE, '"source" must be one of: ' . implode(', ', Highlight::SOURCES) . '.');
		}
		return $value;
	}

	/** @throws HighlightException */
	private static function colorOf(mixed $value): string {
		if (!is_string($value) || !in_array($value, Highlight::COLORS, true)) {
			throw new HighlightException(HighlightException::INVALID_COLOR, '"color" must be one of: ' . implode(', ', Highlight::COLORS) . '.');
		}
		return $value;
	}

	/** @throws HighlightException */
	private static function noteOf(mixed $value): string {
		if (!is_string($value)) {
			throw new HighlightException(HighlightException::INVALID_FIELD, '"note" must be a string.');
		}
		if (mb_strlen($value, 'UTF-8') > NoteService::MAX_LENGTH) {
			throw new HighlightException(HighlightException::NOTE_TOO_LONG, 'A note is limited to ' . NoteService::MAX_LENGTH . ' characters.');
		}
		return trim($value) === '' ? '' : $value;
	}

	/**
	 * Validates a selector and returns it reduced to the known keys.
	 *
	 * @return Selector
	 * @throws HighlightException
	 */
	public static function selectorOf(mixed $value): array {
		$invalid = static fn (string $message): HighlightException =>
			new HighlightException(HighlightException::INVALID_SELECTOR, 'Invalid "selector": ' . $message);
		if (!is_array($value) || !is_array($value['quote'] ?? null)) {
			throw $invalid('expected {"quote": {"exact": "…"}, "position": {"start": n, "end": n}}.');
		}
		$quote = $value['quote'];
		$exact = $quote['exact'] ?? null;
		if (!is_string($exact) || trim($exact) === '' || mb_strlen($exact, 'UTF-8') > self::MAX_EXACT_LENGTH) {
			throw $invalid('"quote.exact" must be a text of 1 to ' . self::MAX_EXACT_LENGTH . ' characters.');
		}
		$selector = ['quote' => ['exact' => $exact]];
		foreach (['prefix', 'suffix'] as $context) {
			if (!isset($quote[$context])) {
				continue;
			}
			if (!is_string($quote[$context]) || mb_strlen($quote[$context], 'UTF-8') > self::MAX_CONTEXT_LENGTH) {
				throw $invalid('"quote.' . $context . '" must be a text of at most ' . self::MAX_CONTEXT_LENGTH . ' characters.');
			}
			$selector['quote'][$context] = $quote[$context];
		}
		if (isset($value['position'])) {
			$position = $value['position'];
			if (!is_array($position) || !is_int($position['start'] ?? null) || !is_int($position['end'] ?? null)
				|| $position['start'] < 0 || $position['start'] >= $position['end']) {
				throw $invalid('"position" must be {"start": n, "end": m} with 0 <= n < m.');
			}
			$selector['position'] = ['start' => $position['start'], 'end' => $position['end']];
		}
		return $selector;
	}

	private function dao(): HighlightDAO {
		return new HighlightDAO($this->notes->pdo());
	}

	private function now(): int {
		return $this->clock === null ? time() : ($this->clock)();
	}
}
