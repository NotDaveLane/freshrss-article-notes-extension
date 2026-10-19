<?php

declare(strict_types=1);

namespace ArticleNotes;

/** Reads and writes one user's highlights table. Timestamps are Unix seconds. */
final class HighlightDAO {
	private const COLUMNS = 'id, uuid, entry_id, url, normalized_url, title, entry_guid, entry_link, entry_title, entry_author, '
		. 'entry_date, feed_id, feed_name, feed_url, source, selector, color, note, created_at, updated_at, deleted_at, '
		. 'label_applied, label_id';

	private readonly string $table;

	public function __construct(private readonly \Minz_Pdo $pdo) {
		$this->table = $pdo->prefix() . 'articlenotes_highlight';
	}

	public function find(string $uuid, bool $includeDeleted = false): ?Highlight {
		$rows = $this->fetch(
			'SELECT ' . self::COLUMNS . ' FROM ' . $this->table . ' WHERE uuid=:uuid' . ($includeDeleted ? '' : ' AND deleted_at IS NULL'),
			[':uuid' => $uuid],
		);
		return $rows[0] ?? null;
	}

	/** Stores a new highlight (its `id` is assigned by the database). */
	public function insert(Highlight $highlight): void {
		$this->execute('INSERT INTO ' . $this->table . ' (uuid, entry_id, url, normalized_url, url_hash, title, entry_guid, '
			. 'entry_link, entry_title, entry_author, entry_date, feed_id, feed_name, feed_url, source, selector, color, note, '
			. 'created_at, updated_at, deleted_at, label_applied, label_id) VALUES (:uuid, :entry_id, :url, :normalized_url, '
			. ':url_hash, :title, :entry_guid, :entry_link, :entry_title, :entry_author, :entry_date, :feed_id, :feed_name, '
			. ':feed_url, :source, :selector, :color, :note, :created_at, :updated_at, NULL, 0, NULL)', [
			':uuid' => $highlight->uuid,
			':entry_id' => $highlight->entryId,
			':url' => $highlight->url,
			':normalized_url' => $highlight->normalizedUrl,
			':url_hash' => UrlNormalizer::hash($highlight->normalizedUrl),
			':title' => $highlight->title,
			':entry_guid' => $highlight->entryGuid,
			':entry_link' => $highlight->entryLink,
			':entry_title' => $highlight->entryTitle,
			':entry_author' => $highlight->entryAuthor,
			':entry_date' => $highlight->entryDate,
			':feed_id' => $highlight->feedId,
			':feed_name' => $highlight->feedName,
			':feed_url' => $highlight->feedUrl,
			':source' => $highlight->source,
			':selector' => json_encode($highlight->selector, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
			':color' => $highlight->color,
			':note' => $highlight->note,
			':created_at' => $highlight->createdAt,
			':updated_at' => $highlight->updatedAt,
		]);
	}

	/** Changes the note and color of a live highlight. */
	public function update(string $uuid, string $note, string $color, int $updatedAt): void {
		$this->execute('UPDATE ' . $this->table . ' SET note=:note, color=:color, updated_at=:updated_at WHERE uuid=:uuid AND deleted_at IS NULL',
			[':note' => $note, ':color' => $color, ':updated_at' => $updatedAt, ':uuid' => $uuid]);
	}

	/** @return bool whether a live highlight was tombstoned */
	public function softDelete(string $uuid, int $now): bool {
		return $this->execute(
			'UPDATE ' . $this->table . ' SET deleted_at=:now, updated_at=:now_updated WHERE uuid=:uuid AND deleted_at IS NULL',
			[':now' => $now, ':now_updated' => $now, ':uuid' => $uuid],
		) > 0;
	}

	/** Records the label the extension applied to the highlight's article, or null when it applied none (anymore). */
	public function setAppliedLabel(string $uuid, ?int $labelId): void {
		if ($labelId === null) {
			$this->execute('UPDATE ' . $this->table . ' SET label_applied=0, label_id=NULL WHERE uuid=:uuid', [':uuid' => $uuid]);
			return;
		}
		$this->execute('UPDATE ' . $this->table . ' SET label_applied=1, label_id=:label_id WHERE uuid=:uuid',
			[':label_id' => $labelId, ':uuid' => $uuid]);
	}

	/** Forgets the label applied through any highlight (live or deleted) of an article. */
	public function clearAppliedLabels(string $entryId): void {
		$this->execute('UPDATE ' . $this->table . ' SET label_applied=0, label_id=NULL WHERE entry_id=:entry_id AND label_applied=1',
			[':entry_id' => $entryId]);
	}

	/** @return list<int> the ids of the labels applied through highlights (live or deleted) of an article */
	public function appliedLabelIds(string $entryId): array {
		$statement = $this->run('SELECT label_id FROM ' . $this->table
			. ' WHERE entry_id=:entry_id AND label_applied=1 AND label_id IS NOT NULL', [':entry_id' => $entryId]);
		$ids = [];
		while (($id = $statement->fetchColumn()) !== false) {
			$ids[] = is_scalar($id) ? (int)$id : 0;
		}
		return $ids;
	}

	/** The number of live highlights of an article. */
	public function countLiveForEntry(string $entryId): int {
		return $this->countLive('entry_id=:target', [':target' => $entryId]);
	}

	/** The number of live highlights of the page (no article) at a normalized URL. */
	public function countLiveForPage(string $normalizedUrl): int {
		return $this->countLive('entry_id IS NULL AND url_hash=:target', [':target' => UrlNormalizer::hash($normalizedUrl)]);
	}

	/**
	 * Live highlights of an article, oldest first.
	 *
	 * @return list<Highlight>
	 */
	public function listForEntry(string $entryId): array {
		return $this->fetch('SELECT ' . self::COLUMNS . ' FROM ' . $this->table
			. ' WHERE entry_id=:entry_id AND deleted_at IS NULL ORDER BY created_at ASC, id ASC', [':entry_id' => $entryId]);
	}

	/**
	 * Live highlights of the page (no article) at a normalized URL, oldest first.
	 *
	 * @return list<Highlight>
	 */
	public function listForPage(string $normalizedUrl): array {
		return $this->fetch('SELECT ' . self::COLUMNS . ' FROM ' . $this->table
			. ' WHERE entry_id IS NULL AND url_hash=:hash AND deleted_at IS NULL ORDER BY created_at ASC, id ASC',
			[':hash' => UrlNormalizer::hash($normalizedUrl)]);
	}

	/**
	 * Highlights (tombstones included unless `$includeDeleted` is false) positioned after `($since, $afterId)` in
	 * `(updated_at, id)` order, so `$afterId = 0` returns everything updated at or after `$since`.
	 *
	 * @return list<Highlight>
	 */
	public function listChangedSince(int $since, int $limit, int $afterId = 0, bool $includeDeleted = true): array {
		return $this->fetch(
			'SELECT ' . self::COLUMNS . ' FROM ' . $this->table
				. ' WHERE (updated_at > :since OR (updated_at = :since_equal AND id > :after_id))'
				. ($includeDeleted ? '' : ' AND deleted_at IS NULL')
				. ' ORDER BY updated_at ASC, id ASC LIMIT ' . max(0, $limit),
			[':since' => $since, ':since_equal' => $since, ':after_id' => $afterId],
		);
	}

	public function count(): int {
		return $this->countLive('1=1', []);
	}

	/** @param array<string,int|string> $values */
	private function countLive(string $condition, array $values): int {
		$statement = $this->run('SELECT COUNT(*) FROM ' . $this->table . ' WHERE ' . $condition . ' AND deleted_at IS NULL', $values);
		return (int)$statement->fetchColumn();
	}

	/**
	 * @param array<string,int|string|null> $values
	 * @return list<Highlight>
	 */
	private function fetch(string $sql, array $values): array {
		$statement = $this->run($sql, $values);
		$highlights = [];
		while (is_array($row = $statement->fetch(\PDO::FETCH_ASSOC))) {
			/** @var non-empty-array<string,string|int|null> $row */
			$highlights[] = new Highlight(
				(int)$row['id'], (string)$row['uuid'], $row['entry_id'] === null ? null : (string)$row['entry_id'],
				(string)$row['url'], (string)$row['normalized_url'], (string)$row['title'],
				(string)$row['entry_guid'], (string)$row['entry_link'], (string)$row['entry_title'], (string)$row['entry_author'],
				(int)$row['entry_date'], (int)$row['feed_id'], (string)$row['feed_name'], (string)$row['feed_url'],
				(string)$row['source'], self::decodeSelector((string)$row['selector']), (string)$row['color'], (string)$row['note'],
				(int)$row['created_at'], (int)$row['updated_at'], $row['deleted_at'] === null ? null : (int)$row['deleted_at'],
				(int)$row['label_applied'] === 1, $row['label_id'] === null ? null : (int)$row['label_id'],
			);
		}
		return $highlights;
	}

	/** @return array{quote:array{exact:string,prefix?:string,suffix?:string},position?:array{start:int,end:int}} */
	private static function decodeSelector(string $json): array {
		$selector = json_decode($json, true);
		if (!is_array($selector) || !is_array($selector['quote'] ?? null)) {
			throw new \RuntimeException('Corrupt highlight selector');
		}
		/** @var array{quote:array{exact:string,prefix?:string,suffix?:string},position?:array{start:int,end:int}} $selector */
		return $selector;
	}

	/** @param array<string,int|string|null> $values */
	private function execute(string $sql, array $values): int {
		return $this->run($sql, $values)->rowCount();
	}

	/** @param array<string,int|string|null> $values */
	private function run(string $sql, array $values): \PDOStatement {
		$statement = $this->pdo->prepare($sql);
		if ($statement === false) {
			throw new \RuntimeException('Cannot prepare highlights query: ' . json_encode($this->pdo->errorInfo()));
		}
		foreach ($values as $name => $value) {
			$statement->bindValue($name, $value, is_int($value) ? \PDO::PARAM_INT : ($value === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR));
		}
		if (!$statement->execute()) {
			throw new \RuntimeException('Highlights query failed: ' . json_encode($statement->errorInfo()));
		}
		return $statement;
	}
}
