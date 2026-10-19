<?php

declare(strict_types=1);

namespace ArticleNotes;

/** The single entry point for writing one user's notes. */
final class NoteService {
	public const MAX_LENGTH = 20000;
	public const MAX_TITLE_LENGTH = 500;

	/** @var list<\Closure(Note,bool):void> */
	private array $savedListeners = [];
	/** @var list<\Closure(Note):void> */
	private array $deletedListeners = [];
	private bool $ready = false;

	/**
	 * @param \Closure():bool $ensureSchema migrates the user's schema; must return true when it is up to date
	 * @param (\Closure():int)|null $clock current Unix time (default: time())
	 */
	public function __construct(
		private readonly string $user,
		private readonly \Closure $ensureSchema,
		private readonly ?\Closure $clock = null,
	) {
	}

	/** @param \Closure(Note,bool):void $listener called after a note is saved; the flag tells whether it was created or revived */
	public function onSaved(\Closure $listener): void {
		$this->savedListeners[] = $listener;
	}

	/** @param \Closure(Note):void $listener called after a note is tombstoned */
	public function onDeleted(\Closure $listener): void {
		$this->deletedListeners[] = $listener;
	}

	/** The live note of an article, or null. */
	public function get(string $entryId): ?Note {
		return $this->dao()->find($entryId);
	}

	/**
	 * Creates or updates the note of an article; empty (after trimming) text deletes it.
	 *
	 * @return Note|null the saved note, null when it was deleted
	 * @throws NoteException unknown article or text over {@see MAX_LENGTH} characters
	 */
	public function save(string $entryId, string $text): ?Note {
		$text = trim($text);
		if ($text === '') {
			$this->delete($entryId);
			return null;
		}
		if (mb_strlen($text, 'UTF-8') > self::MAX_LENGTH) {
			throw new NoteException('A note is limited to ' . self::MAX_LENGTH . ' characters', NoteException::TOO_LONG);
		}
		$entry = $this->ensureReady() ? \FreshRSS_Factory::createEntryDao($this->user)->searchById($entryId) : null;
		if ($entry === null) {
			throw new NoteException('Unknown article', NoteException::UNKNOWN_ENTRY);
		}
		$feed = \FreshRSS_Factory::createFeedDao($this->user)->searchById($entry->feedId());

		$dao = $this->dao();
		$existing = $dao->find($entry->id(), true);
		$created = $dao->find($entry->id()) === null;
		// Strictly after the previous version, so a note's (id, updated_at) pair, its ETag, changes with every save.
		$updatedAt = max($this->now(), $existing === null ? 0 : $existing->updatedAt + 1);
		$dao->upsert(new Note(
			0, $entry->id(), $text, 0, $updatedAt, null,
			$entry->guid(), $entry->link(true), $entry->title(), implode(', ', $entry->authors()), (int)$entry->date(true),
			$entry->feedId(), $feed === null ? '' : $feed->name(true), $feed === null ? '' : $feed->url(false),
			false, null, UrlNormalizer::forEntry($entry->link(true), $entry->guid()) ?? '',
		));
		$note = $dao->find($entry->id());
		if ($note === null) {
			throw new \RuntimeException('Note vanished after saving');
		}
		foreach ($this->savedListeners as $listener) {
			$listener($note, $created);
		}
		return $dao->find($entry->id()) ?? $note;	// listeners may have updated it (label bookkeeping)
	}

	/** The live page note at a URL (any form of it), or null. */
	public function getPageNote(string $url): ?Note {
		$normalized = UrlNormalizer::normalize($url);
		return $normalized === null ? null : $this->dao()->findPage($normalized);
	}

	/**
	 * Creates or updates the note of a web page that is no FreshRSS article; empty (after trimming) text deletes it. Page
	 * notes never get a label (there is no article to label).
	 *
	 * @param string $title the page title as the client saw it (optional, at most {@see MAX_TITLE_LENGTH} characters)
	 * @return Note|null the saved note, null when it was deleted
	 * @throws NoteException invalid URL, text or title too long, or a URL that is a FreshRSS article ({@see NoteException::$entryId})
	 */
	public function savePageNote(string $url, string $text, string $title = ''): ?Note {
		$normalized = UrlNormalizer::normalize($url);
		if ($normalized === null) {
			throw new NoteException('The URL must be an http or https URL', NoteException::INVALID_URL);
		}
		$text = trim($text);
		if ($text === '') {
			$this->deletePageNote($url);
			return null;
		}
		$title = trim($title);
		if (mb_strlen($text, 'UTF-8') > self::MAX_LENGTH) {
			throw new NoteException('A note is limited to ' . self::MAX_LENGTH . ' characters', NoteException::TOO_LONG);
		}
		if (mb_strlen($title, 'UTF-8') > self::MAX_TITLE_LENGTH) {
			throw new NoteException('A page title is limited to ' . self::MAX_TITLE_LENGTH . ' characters', NoteException::TITLE_TOO_LONG);
		}
		$article = $this->articleAt($normalized);
		if ($article !== null) {
			throw new NoteException('This URL is the FreshRSS article ' . $article . ': use the article note', NoteException::URL_IS_ARTICLE, $article);
		}

		$dao = $this->dao();
		$existing = $dao->findPage($normalized, true);
		$created = $dao->findPage($normalized) === null;
		$updatedAt = max($this->now(), $existing === null ? 0 : $existing->updatedAt + 1);
		$dao->upsertPage(new Note(0, null, $text, 0, $updatedAt, null, '', trim($url), $title, '', 0, 0, '', '', false, null, $normalized));
		$note = $dao->findPage($normalized);
		if ($note === null) {
			throw new \RuntimeException('Note vanished after saving');
		}
		foreach ($this->savedListeners as $listener) {
			$listener($note, $created);
		}
		return $note;
	}

	/** Tombstones the page note at a URL. @return bool whether a note was deleted */
	public function deletePageNote(string $url): bool {
		$normalized = UrlNormalizer::normalize($url);
		if ($normalized === null) {
			return false;
		}
		$dao = $this->dao();
		if (!$dao->softDeletePage($normalized, $this->now())) {
			return false;
		}
		$note = $dao->findPage($normalized, true);
		if ($note !== null) {
			foreach ($this->deletedListeners as $listener) {
				$listener($note);
			}
		}
		return true;
	}

	/** The id of a FreshRSS article (or of a purged one that still has a note) at a normalized URL, or null. */
	private function articleAt(string $normalized): ?string {
		$matches = (new UrlLookup($this))->find($normalized)['matches'];
		return $matches === [] ? null : $matches[0]->entryId;
	}

	/** Tombstones the note of an article (the article need not exist anymore). @return bool whether a note was deleted */
	public function delete(string $entryId): bool {
		$dao = $this->dao();
		if (!$dao->softDelete($entryId, $this->now())) {
			return false;
		}
		$note = $dao->find($entryId, true);
		if ($note !== null) {
			foreach ($this->deletedListeners as $listener) {
				$listener($note);
			}
		}
		return true;
	}

	/**
	 * Notes (tombstones included unless `$includeDeleted` is false) after `($since, $afterId)` in `(updated_at, id)` order.
	 *
	 * @return list<Note>
	 */
	public function changedSince(int $since, int $limit, int $afterId = 0, bool $includeDeleted = true): array {
		return $this->dao()->listChangedSince($since, $limit, $afterId, $includeDeleted);
	}

	/**
	 * The given article ids that FreshRSS still has (articles can be purged after being annotated).
	 *
	 * @param list<string> $entryIds
	 * @return list<string>
	 */
	public function existingEntryIds(array $entryIds): array {
		return $this->dao()->existingEntryIds($entryIds);
	}

	/**
	 * The live notes of articles at a normalized URL, most recently published first.
	 *
	 * @return list<Note>
	 */
	public function findByUrl(string $normalizedUrl, int $limit): array {
		return $this->dao()->findByUrlHash(UrlNormalizer::hash($normalizedUrl), $limit);
	}

	/** The URL index of this user's articles. */
	public function urlIndex(): UrlIndex {
		return new UrlIndex($this->dao()->pdo());
	}

	public function count(): int {
		return $this->dao()->count();
	}

	private function dao(): NoteDAO {
		if (!$this->ensureReady()) {
			throw new \RuntimeException('ArticleNotes storage is not available for user ' . $this->user);
		}
		return new NoteDAO((new Storage($this->user))->pdo());
	}

	private function ensureReady(): bool {
		return $this->ready = $this->ready || ($this->ensureSchema)();
	}

	private function now(): int {
		return $this->clock === null ? time() : ($this->clock)();
	}
}
