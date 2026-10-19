<?php

declare(strict_types=1);

namespace ArticleNotes;

/**
 * The articles and pages a user annotated, one entry per target: a handful of queries however many targets there are
 * (one grouped query over the union of notes and highlights, then one query each for the notes, the highlight colors
 * and the articles that still exist). A target is an article (`entry_id`) or a page (hash of its normalized URL).
 */
final class AnnotatedTargets {
	/** SQL aggregates over the union (`deleted_at IS NULL` = live annotation). */
	private const LIVE = 'deleted_at IS NULL';
	private const LAST_LIVE = 'MAX(CASE WHEN ' . self::LIVE . ' THEN updated_at END)';
	private const LIVE_COUNT = 'SUM(CASE WHEN ' . self::LIVE . ' THEN 1 ELSE 0 END)';

	/** The number of SQL statements run so far (tests assert it does not grow with the number of targets). */
	public int $queries = 0;

	private readonly string $notes;
	private readonly string $highlights;

	public function __construct(private readonly \Minz_Pdo $pdo) {
		$this->notes = $pdo->prefix() . 'articlenotes_note';
		$this->highlights = $pdo->prefix() . 'articlenotes_highlight';
	}

	/**
	 * Targets with a live annotation, most recently annotated first, then by article id and page hash (descending).
	 *
	 * @param int $since only targets with an annotation created, edited or deleted at or after this Unix time
	 * @param array{int,int,string}|null $after position of the last target of the previous page: last annotated time, article id (0 for a page), page hash
	 * @param 'article'|'page'|null $kind
	 * @return list<AnnotatedTarget> at most `$limit` targets
	 */
	public function page(int $since, int $limit, ?array $after = null, ?string $kind = null, ?int $feedId = null): array {
		[$where, $values] = $this->filters($kind, $feedId);
		$having = self::LIVE_COUNT . ' > 0';
		if ($since > 0) {
			$having .= ' AND MAX(updated_at) >= :since';
			$values[':since'] = $since;
		}
		if ($after !== null) {
			$having .= ' AND (' . self::LAST_LIVE . ' < :after_at OR (' . self::LAST_LIVE . ' = :after_at_equal'
				. ' AND (eid < :after_eid OR (eid = :after_eid_equal AND page_hash < :after_hash))))';
			$values += [':after_at' => $after[0], ':after_at_equal' => $after[0], ':after_eid' => $after[1],
				':after_eid_equal' => $after[1], ':after_hash' => $after[2]];
		}
		$live = static fn (string $column): string => 'MAX(CASE WHEN ' . self::LIVE . ' THEN ' . $column . ' END)';
		$rows = $this->rows(
			'SELECT eid, page_hash, ' . self::LAST_LIVE . ' AS last_at, MIN(CASE WHEN ' . self::LIVE . ' THEN created_at END) AS first_at, '
				. 'SUM(CASE WHEN ' . self::LIVE . ' THEN is_hl ELSE 0 END) AS hl_count, '
				. $live('feed_id') . ' AS feed_id, ' . $live('link') . ' AS link, ' . $live('normalized_url') . ' AS normalized_url, '
				. $live('title') . ' AS title, ' . $live('guid') . ' AS guid, ' . $live('author') . ' AS author, '
				. $live('entry_date') . ' AS entry_date, ' . $live('feed_name') . ' AS feed_name, ' . $live('feed_url') . ' AS feed_url '
				. 'FROM (' . $this->union() . ') AS t' . $where . ' GROUP BY eid, page_hash HAVING ' . $having
				. ' ORDER BY last_at DESC, eid DESC, page_hash DESC LIMIT ' . max(0, $limit),
			$values,
		);
		return $this->complete($rows);
	}

	/**
	 * Targets that changed at or after `$since` and have no live annotation any more.
	 *
	 * @param 'article'|'page'|null $kind
	 * @return list<array{entry_id:?string,url:string,normalized_url:string}>
	 */
	public function removedSince(int $since, ?string $kind = null, ?int $feedId = null): array {
		[$where, $values] = $this->filters($kind, $feedId);
		$values[':since'] = $since;
		$rows = $this->rows(
			'SELECT eid, page_hash, MAX(link) AS link, MAX(normalized_url) AS normalized_url FROM (' . $this->union() . ') AS t'
				. $where . ' GROUP BY eid, page_hash HAVING ' . self::LIVE_COUNT . ' = 0 AND MAX(updated_at) >= :since'
				. ' ORDER BY eid DESC, page_hash DESC',
			$values,
		);
		return array_map(static fn (array $row): array => [
			'entry_id' => (int)$row['eid'] > 0 ? (string)$row['eid'] : null,
			'url' => (string)$row['link'],
			'normalized_url' => (string)$row['normalized_url'],
		], $rows);
	}

	/**
	 * Notes and highlights as one set of rows, one per annotation: the target key (`eid`, `page_hash`) and the columns the
	 * aggregates need. Notes of articles and highlights of articles carry the article snapshot; page ones their URL and title.
	 */
	private function union(): string {
		return 'SELECT COALESCE(entry_id, 0) AS eid, CASE WHEN entry_id IS NULL THEN url_hash ELSE \'\' END AS page_hash, feed_id, '
			. 'entry_link AS link, normalized_url, entry_title AS title, entry_guid AS guid, entry_author AS author, entry_date, '
			. 'feed_name, feed_url, created_at, updated_at, deleted_at, 0 AS is_hl FROM ' . $this->notes
			. ' UNION ALL SELECT COALESCE(entry_id, 0), CASE WHEN entry_id IS NULL THEN url_hash ELSE \'\' END, feed_id, '
			. 'CASE WHEN entry_id IS NULL THEN url ELSE entry_link END, normalized_url, '
			. 'CASE WHEN entry_id IS NULL THEN title ELSE entry_title END, entry_guid, entry_author, entry_date, '
			. 'feed_name, feed_url, created_at, updated_at, deleted_at, 1 FROM ' . $this->highlights;
	}

	/**
	 * @param 'article'|'page'|null $kind
	 * @return array{string,array<string,int|string>} the WHERE clause (with its leading space, or empty) and its values
	 */
	private function filters(?string $kind, ?int $feedId): array {
		$conditions = [];
		$values = [];
		if ($kind === 'article') {
			$conditions[] = 'eid > 0';
		} elseif ($kind === 'page') {
			$conditions[] = 'eid = 0';
		}
		if ($feedId !== null) {
			$conditions[] = 'feed_id = :feed_id';
			$values[':feed_id'] = $feedId;
		}
		return [$conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions), $values];
	}

	/**
	 * Adds the note and the highlight colors to the targets of one page of results.
	 *
	 * @param list<array<string,string|int|null>> $rows
	 * @return list<AnnotatedTarget>
	 */
	private function complete(array $rows): array {
		if ($rows === []) {
			return [];
		}
		$entryIds = [];
		$pageHashes = [];
		foreach ($rows as $row) {
			if ((int)$row['eid'] > 0) {
				$entryIds[] = (int)$row['eid'];
			} else {
				$pageHashes[] = (string)$row['page_hash'];
			}
		}
		$notes = [];
		foreach ($this->liveNotes($entryIds, $pageHashes) as $note) {
			$notes[$note->entryId ?? UrlNormalizer::hash($note->normalizedUrl)] = $note;
		}
		$colors = $this->liveColors($entryIds, $pageHashes);

		$targets = [];
		foreach ($rows as $row) {
			$isArticle = (int)$row['eid'] > 0;
			$key = $isArticle ? (string)$row['eid'] : (string)$row['page_hash'];
			$link = (string)$row['link'];
			$guid = (string)$row['guid'];
			$normalized = (string)$row['normalized_url'];
			if ($isArticle && $link === '' && UrlNormalizer::normalize($guid) !== null) {
				$link = $guid;
			}
			if ($normalized === '') {
				$normalized = $isArticle ? (UrlNormalizer::forEntry($link, $guid) ?? '') : (UrlNormalizer::normalize($link) ?? '');
			}
			$note = $notes[$key] ?? null;
			$title = $note !== null && $note->entryTitle !== '' ? $note->entryTitle : (string)$row['title'];
			$targets[] = new AnnotatedTarget(
				$isArticle ? $key : null, $link, $normalized, $title, $guid, (string)$row['author'],
				(int)$row['entry_date'], (int)$row['feed_id'], (string)$row['feed_name'], (string)$row['feed_url'],
				(int)$row['hl_count'], $colors[($isArticle ? 'a' : 'p') . $key] ?? [], (int)$row['first_at'], (int)$row['last_at'],
				$isArticle ? '' : $key, $note,
			);
		}
		return $targets;
	}

	/**
	 * @param list<int> $entryIds
	 * @param list<string> $pageHashes
	 * @return list<Note>
	 */
	private function liveNotes(array $entryIds, array $pageHashes): array {
		[$condition, $values] = $this->targetCondition('entry_id', 'page_url_hash', $entryIds, $pageHashes);
		$dao = new NoteDAO($this->pdo);
		$this->queries++;
		return $dao->search('deleted_at IS NULL AND (' . $condition . ')', $values);
	}

	/**
	 * @param list<int> $entryIds
	 * @param list<string> $pageHashes
	 * @return array<string,list<string>> distinct highlight colors per target (`a<entry id>` or `p<page hash>`)
	 */
	private function liveColors(array $entryIds, array $pageHashes): array {
		[$condition, $values] = $this->targetCondition('entry_id', 'url_hash', $entryIds, $pageHashes, true);
		$rows = $this->rows('SELECT entry_id, url_hash, color FROM ' . $this->highlights . ' WHERE deleted_at IS NULL AND ('
			. $condition . ') GROUP BY entry_id, url_hash, color', $values);
		$found = [];
		foreach ($rows as $row) {
			$key = $row['entry_id'] === null ? 'p' . (string)$row['url_hash'] : 'a' . (string)$row['entry_id'];
			$found[$key][] = (string)$row['color'];
		}
		foreach ($found as $key => $present) {
			$found[$key] = array_values(array_intersect(Highlight::COLORS, $present));
		}
		return $found;
	}

	/**
	 * `entry_id IN (…) OR (<hash column> IN (…))` for the targets of a page of results.
	 *
	 * @param list<int> $entryIds
	 * @param list<string> $pageHashes
	 * @return array{string,array<string,int|string>}
	 */
	private function targetCondition(string $entryColumn, string $hashColumn, array $entryIds, array $pageHashes, bool $pagesNeedNoEntry = false): array {
		$parts = [];
		$values = [];
		if ($entryIds !== []) {
			foreach ($entryIds as $i => $id) {
				$values[':e' . $i] = $id;
			}
			$parts[] = $entryColumn . ' IN (' . implode(', ', array_keys($values)) . ')';
		}
		if ($pageHashes !== []) {
			$names = [];
			foreach ($pageHashes as $i => $hash) {
				$names[':h' . $i] = $hash;
			}
			$values += $names;
			$parts[] = ($pagesNeedNoEntry ? $entryColumn . ' IS NULL AND ' : '') . $hashColumn . ' IN (' . implode(', ', array_keys($names)) . ')';
		}
		return [implode(' OR ', array_map(static fn (string $part): string => '(' . $part . ')', $parts)), $values];
	}

	/**
	 * @param array<string,int|string> $values
	 * @return list<array<string,string|int|null>>
	 */
	private function rows(string $sql, array $values): array {
		$statement = $this->pdo->prepare($sql);
		if ($statement === false) {
			throw new \RuntimeException('Cannot prepare annotated targets query: ' . json_encode($this->pdo->errorInfo()));
		}
		foreach ($values as $name => $value) {
			$statement->bindValue($name, $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
		}
		$this->queries++;
		if (!$statement->execute()) {
			throw new \RuntimeException('Annotated targets query failed: ' . json_encode($statement->errorInfo()));
		}
		/** @var list<array<string,string|int|null>> $rows */
		$rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
		return $rows;
	}
}
