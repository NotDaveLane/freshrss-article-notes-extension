<?php

declare(strict_types=1);

namespace ArticleNotes;

/** Reads one user's annotations (article notes, page notes and highlights) grouped by article or page, for the Notes page. */
final class NotesBrowser {
	public const PER_PAGE = 25;
	/** Escape character of the LIKE patterns (a backslash would be an escape character of MySQL string literals too). */
	private const ESCAPE = '!';

	private readonly string $notes;
	private readonly string $highlights;
	private readonly NoteDAO $noteDao;
	private readonly HighlightDAO $highlightDao;

	public function __construct(private readonly \Minz_Pdo $pdo) {
		$this->notes = $pdo->prefix() . 'articlenotes_note';
		$this->highlights = $pdo->prefix() . 'articlenotes_highlight';
		$this->noteDao = new NoteDAO($pdo);
		$this->highlightDao = new HighlightDAO($pdo);
	}

	/**
	 * One page of groups, the most recently annotated first.
	 *
	 * @param int $page 1-based
	 * @return array{groups:list<NotesGroup>,total:int} `total` counts the groups of all pages
	 */
	public function groups(NotesFilter $filter, int $page = 1, int $perPage = self::PER_PAGE): array {
		$perPage = max(1, $perPage);
		[$noteWhere, $noteValues, $highlightWhere, $highlightValues] = $this->conditions($filter);
		$values = $noteValues + $highlightValues;
		$source = $this->source($noteWhere, $highlightWhere);
		$total = $this->run('SELECT COUNT(*) FROM (SELECT g_entry FROM (' . $source . ') AS annotations GROUP BY g_entry, g_page) AS grouped', $values)
			->fetchColumn();
		$keys = $this->keys($source, $values, ' LIMIT ' . $perPage . ' OFFSET ' . (max(1, $page) - 1) * $perPage);
		return ['groups' => $this->load($keys, $noteWhere, $noteValues, $highlightWhere, $highlightValues), 'total' => is_scalar($total) ? (int)$total : 0];
	}

	/**
	 * Every group (for exports).
	 *
	 * @return list<NotesGroup>
	 */
	public function all(NotesFilter $filter): array {
		[$noteWhere, $noteValues, $highlightWhere, $highlightValues] = $this->conditions($filter);
		$values = $noteValues + $highlightValues;
		$keys = $this->keys($this->source($noteWhere, $highlightWhere), $values, '');
		return $this->load($keys, $noteWhere, $noteValues, $highlightWhere, $highlightValues);
	}

	/**
	 * The feeds of the annotated articles (from the snapshots, so also feeds FreshRSS no longer has), by name.
	 *
	 * @return array<int,string> feed id => name
	 */
	public function feeds(): array {
		$feeds = [];
		foreach ([$this->notes, $this->highlights] as $table) {
			$statement = $this->run('SELECT feed_id, MAX(feed_name) AS feed_name FROM ' . $table
				. ' WHERE entry_id IS NOT NULL AND deleted_at IS NULL GROUP BY feed_id', []);
			while (is_array($row = $statement->fetch(\PDO::FETCH_ASSOC))) {
				$id = is_scalar($row['feed_id'] ?? null) ? (int)$row['feed_id'] : 0;
				$name = is_scalar($row['feed_name'] ?? null) ? (string)$row['feed_name'] : '';
				$feeds[$id] ??= $name;
			}
		}
		asort($feeds, SORT_NATURAL | SORT_FLAG_CASE);
		return $feeds;
	}

	/** The group-forming columns of every matching annotation. */
	private function source(string $noteWhere, string $highlightWhere): string {
		$columns = 'entry_id AS g_entry, CASE WHEN entry_id IS NULL THEN url_hash ELSE \'\' END AS g_page, updated_at';
		return 'SELECT ' . $columns . ' FROM ' . $this->notes . ' WHERE ' . $noteWhere
			. ' UNION ALL SELECT ' . $columns . ' FROM ' . $this->highlights . ' WHERE ' . $highlightWhere;
	}

	/**
	 * @param array<string,int|string> $values
	 * @return list<array{string|null,string}> (article id or null, page hash) of the groups, most recent first
	 */
	private function keys(string $source, array $values, string $limit): array {
		$statement = $this->run('SELECT g_entry, g_page, MAX(updated_at) AS latest FROM (' . $source . ') AS annotations '
			. 'GROUP BY g_entry, g_page ORDER BY latest DESC, g_entry DESC, g_page DESC' . $limit, $values);
		$keys = [];
		while (is_array($row = $statement->fetch(\PDO::FETCH_ASSOC))) {
			$entry = $row['g_entry'] ?? null;
			$page = $row['g_page'] ?? '';
			$keys[] = [is_scalar($entry) ? (string)$entry : null, is_scalar($page) ? (string)$page : ''];
		}
		return $keys;
	}

	/**
	 * @param list<array{string|null,string}> $keys
	 * @param array<string,int|string> $noteValues
	 * @param array<string,int|string> $highlightValues
	 * @return list<NotesGroup>
	 */
	private function load(array $keys, string $noteWhere, array $noteValues, string $highlightWhere, array $highlightValues): array {
		if ($keys === []) {
			return [];
		}
		$targets = [];
		$targetValues = [];
		foreach ($keys as $i => [$entryId, $pageHash]) {
			if ($entryId === null) {
				$targets[] = '(entry_id IS NULL AND url_hash=:g' . $i . ')';
				$targetValues[':g' . $i] = $pageHash;
			} else {
				$targets[] = 'entry_id=:g' . $i;
				$targetValues[':g' . $i] = $entryId;
			}
		}
		$target = ' AND (' . implode(' OR ', $targets) . ')';
		$notes = $this->noteDao->search($noteWhere . $target, $noteValues + $targetValues);
		$highlights = $this->highlightDao->search($highlightWhere . $target, $highlightValues + $targetValues);
		$entryIds = array_values(array_filter(array_map(static fn (array $key): ?string => $key[0], $keys), static fn (?string $id): bool => $id !== null));
		$existing = $this->noteDao->existingEntryIds($entryIds);

		$contents = $this->contents(array_values($existing));
		$groups = [];
		foreach ($keys as [$entryId, $pageHash]) {
			$groupNote = null;
			foreach ($notes as $note) {
				if ($entryId === null ? ($note->entryId === null && UrlNormalizer::hash($note->normalizedUrl) === $pageHash) : $note->entryId === $entryId) {
					$groupNote = $note;
					break;
				}
			}
			$groupHighlights = array_values(array_filter($highlights, static fn (Highlight $highlight): bool => $entryId === null
				? $highlight->entryId === null && UrlNormalizer::hash($highlight->normalizedUrl) === $pageHash
				: $highlight->entryId === $entryId));
			$groups[] = self::group($entryId, $groupNote, HighlightService::inDocumentOrder($groupHighlights),
				$entryId === null || in_array($entryId, $existing, true), $entryId === null ? null : ($contents[$entryId] ?? null));
		}
		return $groups;
	}

	/**
	 * The text of articles, whitespace removed (for {@see orphans()}).
	 *
	 * @param list<string> $entryIds
	 * @return array<string,string> article id => text; articles without stored content are absent
	 */
	private function contents(array $entryIds): array {
		$entryIds = array_values(array_filter($entryIds, static fn (string $id): bool => ctype_digit($id)));
		if ($entryIds === []) {
			return [];
		}
		$values = [];
		foreach ($entryIds as $i => $entryId) {
			$values[':id' . $i] = (int)$entryId;
		}
		$statement = $this->run('SELECT id, content FROM ' . $this->pdo->prefix() . 'entry WHERE id IN (' . implode(', ', array_keys($values)) . ')', $values);
		$texts = [];
		while (is_array($row = $statement->fetch(\PDO::FETCH_ASSOC))) {
			$content = $row['content'] ?? null;
			if (is_string($content) && $content !== '' && is_scalar($row['id'] ?? null)) {
				$texts[(string)$row['id']] = self::squash(html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
			}
		}
		return $texts;
	}

	/** Text without whitespace, to compare a quote with an article whatever its line breaks and markup. */
	private static function squash(string $text): string {
		return preg_replace('/[\s\x{00A0}]+/u', '', $text) ?? $text;
	}

	/** @param list<Highlight> $highlights */
	private static function group(?string $entryId, ?Note $note, array $highlights, bool $exists, ?string $content): NotesGroup {
		$first = $highlights[0] ?? null;
		$orphaned = [];
		foreach ($highlights as $highlight) {	// only passages selected in FreshRSS can be looked up here
			if ($content !== null && $highlight->source === 'freshrss' && !str_contains($content, self::squash($highlight->selector['quote']['exact']))) {
				$orphaned[] = $highlight->uuid;
			}
		}
		$latest = max([$note->updatedAt ?? 0, ...array_map(static fn (Highlight $highlight): int => $highlight->updatedAt, $highlights)]);
		if ($entryId === null) {
			return new NotesGroup(null, $note !== null ? $note->entryTitle : ($first !== null ? $first->title : ''),
				$note !== null ? $note->entryLink : ($first !== null ? $first->url : ''), '', 0, true, $note, $highlights, $latest);
		}
		$snapshot = $note ?? $first;	// both carry the snapshot of the article
		return new NotesGroup($entryId, $snapshot !== null ? $snapshot->entryTitle : '',
			$note !== null ? $note->url() : ($first !== null ? $first->entryLink : ''),
			$snapshot !== null ? $snapshot->feedName : '', $snapshot !== null ? $snapshot->entryDate : 0, $exists, $note, $highlights, $latest, $orphaned);
	}

	/**
	 * The WHERE clauses (notes, highlights) of a filter, and their values. Both always exclude tombstones.
	 *
	 * @return array{string,array<string,int|string>,string,array<string,int|string>} notes WHERE and values, highlights WHERE and values
	 */
	private function conditions(NotesFilter $filter): array {
		$notes = ['deleted_at IS NULL'];
		$highlights = ['deleted_at IS NULL'];
		$noteValues = [];
		$highlightValues = [];
		if ($filter->kind !== '') {	// highlights are neither article notes nor page notes
			$highlights[] = $filter->kind === 'highlight' ? '1=1' : '1=0';
			$notes[] = match ($filter->kind) {
				'article' => 'entry_id IS NOT NULL',
				'page' => 'entry_id IS NULL',
				default => '1=0',
			};
		}
		if ($filter->color !== '') {
			$notes[] = '1=0';
			$highlights[] = 'color=:h_color';
			$highlightValues[':h_color'] = $filter->color;
		}
		if ($filter->feedId !== 0) {
			$notes[] = 'feed_id=:n_feed';
			$highlights[] = 'feed_id=:h_feed';
			$noteValues[':n_feed'] = $filter->feedId;
			$highlightValues[':h_feed'] = $filter->feedId;
		}
		if ($filter->hasNote) {
			$highlights[] = "note <> ''";
		}
		if ($filter->query !== '') {
			$pattern = '%' . self::escapeLike(mb_strtolower($filter->query, 'UTF-8')) . '%';
			$like = static fn (string $column, string $name): string => 'LOWER(' . $column . ') LIKE :' . $name . " ESCAPE '" . self::ESCAPE . "'";
			$notes[] = '(' . implode(' OR ', [$like('note', 'n_q1'), $like('entry_title', 'n_q2')]) . ')';
			$highlights[] = '(' . implode(' OR ', [$like('note', 'h_q1'), $like('quote', 'h_q2'), $like('title', 'h_q3'), $like('entry_title', 'h_q4')]) . ')';
			foreach (['n_q1', 'n_q2'] as $name) {
				$noteValues[':' . $name] = $pattern;
			}
			foreach (['h_q1', 'h_q2', 'h_q3', 'h_q4'] as $name) {
				$highlightValues[':' . $name] = $pattern;
			}
		}
		return [implode(' AND ', $notes), $noteValues, implode(' AND ', $highlights), $highlightValues];
	}

	/** Makes `%`, `_` and the escape character match themselves in a LIKE pattern. */
	public static function escapeLike(string $text): string {
		return strtr($text, [self::ESCAPE => self::ESCAPE . self::ESCAPE, '%' => self::ESCAPE . '%', '_' => self::ESCAPE . '_']);
	}

	/** @param array<string,int|string> $values */
	private function run(string $sql, array $values): \PDOStatement {
		$statement = $this->pdo->prepare($sql);
		if ($statement === false) {
			throw new \RuntimeException('Cannot prepare notes page query: ' . json_encode($this->pdo->errorInfo()));
		}
		foreach ($values as $name => $value) {
			$statement->bindValue($name, $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
		}
		if (!$statement->execute()) {
			throw new \RuntimeException('Notes page query failed: ' . json_encode($statement->errorInfo()));
		}
		return $statement;
	}
}
