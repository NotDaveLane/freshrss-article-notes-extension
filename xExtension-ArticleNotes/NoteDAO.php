<?php

declare(strict_types=1);

namespace ArticleNotes;

/** Reads and writes one user's notes table. Timestamps are Unix seconds. */
final class NoteDAO {
	private const COLUMNS = 'id, entry_id, note, created_at, updated_at, deleted_at, entry_guid, entry_link, entry_title, '
		. 'entry_author, entry_date, feed_id, feed_name, feed_url, label_applied, label_id, normalized_url';

	private readonly string $table;

	public function __construct(private readonly \Minz_Pdo $pdo) {
		$this->table = $pdo->prefix() . 'articlenotes_note';
	}

	public function pdo(): \Minz_Pdo {
		return $this->pdo;
	}

	public function find(string $entryId, bool $includeDeleted = false): ?Note {
		$rows = $this->fetch(
			'SELECT ' . self::COLUMNS . ' FROM ' . $this->table . ' WHERE entry_id=:entry_id'
				. ($includeDeleted ? '' : ' AND deleted_at IS NULL'),
			[':entry_id' => $entryId],
		);
		return $rows[0] ?? null;
	}

	/** Creates the note of `$note->entryId`, or updates it (reviving a tombstone). `id` and `createdAt` of an existing row are kept. */
	public function upsert(Note $note): void {
		$values = [
			':note' => $note->note,
			':updated_at' => $note->updatedAt,
			':entry_guid' => $note->entryGuid,
			':entry_link' => $note->entryLink,
			':entry_title' => $note->entryTitle,
			':entry_author' => $note->entryAuthor,
			':entry_date' => $note->entryDate,
			':feed_id' => $note->feedId,
			':feed_name' => $note->feedName,
			':feed_url' => $note->feedUrl,
			':normalized_url' => $note->normalizedUrl,
			':url_hash' => $note->normalizedUrl === '' ? '' : UrlNormalizer::hash($note->normalizedUrl),
			':entry_id' => $note->entryId,
		];
		$insertValues = $values + [':created_at' => $note->updatedAt];
		if ($this->find($note->entryId, true) !== null) {
			$this->execute('UPDATE ' . $this->table . ' SET note=:note, updated_at=:updated_at, deleted_at=NULL, '
				. 'entry_guid=:entry_guid, entry_link=:entry_link, entry_title=:entry_title, entry_author=:entry_author, '
				. 'entry_date=:entry_date, feed_id=:feed_id, feed_name=:feed_name, feed_url=:feed_url, '
				. 'normalized_url=:normalized_url, url_hash=:url_hash WHERE entry_id=:entry_id', $values);
			return;
		}
		$this->execute('INSERT INTO ' . $this->table . ' (entry_id, note, created_at, updated_at, deleted_at, entry_guid, '
			. 'entry_link, entry_title, entry_author, entry_date, feed_id, feed_name, feed_url, label_applied, normalized_url, url_hash) '
			. 'VALUES (:entry_id, :note, :created_at, :updated_at, NULL, :entry_guid, :entry_link, :entry_title, '
			. ':entry_author, :entry_date, :feed_id, :feed_name, :feed_url, 0, :normalized_url, :url_hash)', $insertValues);
	}

	/** @return bool whether a live note was tombstoned */
	public function softDelete(string $entryId, int $now): bool {
		return $this->execute(
			'UPDATE ' . $this->table . ' SET deleted_at=:now, updated_at=:now_updated WHERE entry_id=:entry_id AND deleted_at IS NULL',
			[':now' => $now, ':now_updated' => $now, ':entry_id' => $entryId],
		) > 0;
	}

	/** Records the label the extension applied to the article, or null when it applied none (anymore). */
	public function setAppliedLabel(string $entryId, ?int $labelId): void {
		if ($labelId === null) {
			$this->execute('UPDATE ' . $this->table . ' SET label_applied=0, label_id=NULL WHERE entry_id=:entry_id',
				[':entry_id' => $entryId]);
			return;
		}
		$this->execute('UPDATE ' . $this->table . ' SET label_applied=1, label_id=:label_id WHERE entry_id=:entry_id',
			[':label_id' => $labelId, ':entry_id' => $entryId]);
	}

	/**
	 * The live notes of articles whose normalized URL has the given hash, most recently published first.
	 *
	 * @return list<Note>
	 */
	public function findByUrlHash(string $urlHash, int $limit): array {
		return $this->fetch(
			'SELECT ' . self::COLUMNS . ' FROM ' . $this->table . ' WHERE url_hash=:url_hash AND deleted_at IS NULL'
				. ' ORDER BY entry_date DESC, id DESC LIMIT ' . max(0, $limit),
			[':url_hash' => $urlHash],
		);
	}

	/**
	 * Notes (tombstones included) positioned after `($since, $afterId)` in `(updated_at, id)` order, so
	 * `$afterId = 0` returns everything updated at or after `$since`. Page by passing the last note's values.
	 *
	 * @return list<Note>
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

	/**
	 * The given article ids that still exist in the user's FreshRSS `entry` table.
	 *
	 * @param list<string> $entryIds
	 * @return list<string>
	 */
	public function existingEntryIds(array $entryIds): array {
		$entryIds = array_values(array_unique(array_filter($entryIds, static fn (string $id): bool => ctype_digit($id))));
		if ($entryIds === []) {
			return [];
		}
		$values = [];
		foreach ($entryIds as $i => $entryId) {
			$values[':id' . $i] = (int)$entryId;
		}
		$statement = $this->run('SELECT id FROM ' . $this->pdo->prefix() . 'entry WHERE id IN (' . implode(', ', array_keys($values)) . ')',
			$values);
		$existing = [];
		while (($id = $statement->fetchColumn()) !== false) {
			$existing[] = is_scalar($id) ? (string)$id : '';
		}
		return $existing;
	}

	public function count(bool $includeDeleted = false): int {
		$statement = $this->pdo->prepare('SELECT COUNT(*) FROM ' . $this->table . ($includeDeleted ? '' : ' WHERE deleted_at IS NULL'));
		if ($statement === false || !$statement->execute()) {
			throw new \RuntimeException('Cannot count notes');
		}
		return (int)$statement->fetchColumn();
	}

	/**
	 * @param array<string,int|string> $values
	 * @return list<Note>
	 */
	private function fetch(string $sql, array $values): array {
		$statement = $this->run($sql, $values);
		$notes = [];
		while (is_array($row = $statement->fetch(\PDO::FETCH_ASSOC))) {
			/** @var non-empty-array<string,string|int|null> $row */
			$notes[] = new Note(
				(int)$row['id'], (string)$row['entry_id'], (string)$row['note'], (int)$row['created_at'], (int)$row['updated_at'],
				$row['deleted_at'] === null ? null : (int)$row['deleted_at'],
				(string)$row['entry_guid'], (string)$row['entry_link'], (string)$row['entry_title'], (string)$row['entry_author'],
				(int)$row['entry_date'], (int)$row['feed_id'], (string)$row['feed_name'], (string)$row['feed_url'],
				(int)$row['label_applied'] === 1,
				$row['label_id'] === null ? null : (int)$row['label_id'],
				(string)($row['normalized_url'] ?? ''),
			);
		}
		return $notes;
	}

	/** @param array<string,int|string> $values */
	private function execute(string $sql, array $values): int {
		return $this->run($sql, $values)->rowCount();
	}

	/** @param array<string,int|string> $values */
	private function run(string $sql, array $values): \PDOStatement {
		$statement = $this->pdo->prepare($sql);
		if ($statement === false) {
			throw new \RuntimeException('Cannot prepare notes query: ' . json_encode($this->pdo->errorInfo()));
		}
		foreach ($values as $name => $value) {
			$statement->bindValue($name, $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
		}
		if (!$statement->execute()) {
			throw new \RuntimeException('Notes query failed: ' . json_encode($statement->errorInfo()));
		}
		return $statement;
	}
}
