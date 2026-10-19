<?php

declare(strict_types=1);

/**
 * Lets a note exist without a FreshRSS article (a "page note" on any web page): `entry_id` becomes nullable (still
 * unique when present) and `page_url_hash` (the hash of the normalized URL, NULL on article notes) is unique, so a page
 * has at most one note. SQLite cannot change a column's nullability, so the table is rebuilt there, in a transaction.
 * Idempotent; keeps every row, tombstone and id.
 */
return new class implements ArticleNotes\Migration {
	#[\Override]
	public function up(PDO $pdo, string $prefix, string $driver): void {
		$notes = $prefix . 'articlenotes_note';
		if ($driver === 'mysql') {
			$pdo->exec("ALTER TABLE {$notes} MODIFY entry_id BIGINT NULL");
			if (!$this->columnExists($pdo, $notes, 'page_url_hash')) {
				$pdo->exec("ALTER TABLE {$notes} ADD COLUMN page_url_hash VARCHAR(40) NULL");
			}
			$this->createUniqueIndexMysql($pdo, $notes);
			return;
		}
		if ($driver === 'pgsql') {
			$pdo->exec("ALTER TABLE {$notes} ALTER COLUMN entry_id DROP NOT NULL");
			$pdo->exec("ALTER TABLE {$notes} ADD COLUMN IF NOT EXISTS page_url_hash VARCHAR(40) NULL");
			$pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS {$notes}_page_url_hash_idx ON {$notes} (page_url_hash)");
			return;
		}
		$this->rebuildSqlite($pdo, $notes);
	}

	private function createUniqueIndexMysql(PDO $pdo, string $notes): void {
		try {
			$pdo->exec("CREATE UNIQUE INDEX {$notes}_page_url_hash_idx ON {$notes} (page_url_hash)");
		} catch (\PDOException $exception) {
			if (($exception->errorInfo[1] ?? 0) !== 1061) {	// ER_DUP_KEYNAME: already there
				throw $exception;
			}
		}
	}

	private function columnExists(PDO $pdo, string $table, string $column): bool {
		try {
			// query() and closeCursor(), not exec(): on MySQL, exec('SELECT …') leaves the connection unusable.
			$probe = $pdo->query("SELECT {$column} FROM {$table} WHERE 1 = 0");
			if ($probe === false) {
				return false;
			}
			$probe->closeCursor();
			return true;
		} catch (\PDOException) {
			return false;
		}
	}

	private function rebuildSqlite(PDO $pdo, string $notes): void {
		$info = $pdo->query("PRAGMA table_info({$notes})");
		if ($info === false) {
			throw new \RuntimeException('Cannot inspect ' . $notes);
		}
		$entryIdRequired = false;
		$hasPageHash = false;
		/** @var list<array{name:string,notnull:int|string}> $columns */
		$columns = $info->fetchAll(PDO::FETCH_ASSOC);
		foreach ($columns as $column) {
			$entryIdRequired = $entryIdRequired || ($column['name'] === 'entry_id' && (int)$column['notnull'] === 1);
			$hasPageHash = $hasPageHash || $column['name'] === 'page_url_hash';
		}
		if (!$entryIdRequired && $hasPageHash) {
			$pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS {$notes}_page_url_hash_idx ON {$notes} (page_url_hash)");
			return;
		}

		$copy = 'id, entry_id, note, created_at, updated_at, deleted_at, entry_guid, entry_link, entry_title, entry_author, '
			. 'entry_date, feed_id, feed_name, feed_url, label_applied, label_id, normalized_url, url_hash';
		$pdo->beginTransaction();
		try {
			$pdo->exec("DROP TABLE IF EXISTS {$notes}_new");
			$pdo->exec("CREATE TABLE {$notes}_new (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				entry_id BIGINT NULL,
				note TEXT NOT NULL,
				created_at BIGINT NOT NULL,
				updated_at BIGINT NOT NULL,
				deleted_at BIGINT NULL,
				entry_guid TEXT NOT NULL,
				entry_link TEXT NOT NULL,
				entry_title TEXT NOT NULL,
				entry_author TEXT NOT NULL,
				entry_date BIGINT NOT NULL,
				feed_id INT NOT NULL,
				feed_name TEXT NOT NULL,
				feed_url TEXT NOT NULL,
				label_applied SMALLINT NOT NULL DEFAULT 0,
				label_id BIGINT NULL,
				normalized_url TEXT NULL,
				url_hash VARCHAR(40) NOT NULL DEFAULT '',
				page_url_hash VARCHAR(40) NULL
			)");
			$pdo->exec("INSERT INTO {$notes}_new ({$copy}) SELECT {$copy} FROM {$notes}");
			$pdo->exec("DROP TABLE {$notes}");
			$pdo->exec("ALTER TABLE {$notes}_new RENAME TO {$notes}");
			$pdo->exec("CREATE UNIQUE INDEX {$notes}_entry_id_idx ON {$notes} (entry_id)");
			$pdo->exec("CREATE INDEX {$notes}_updated_at_idx ON {$notes} (updated_at)");
			$pdo->exec("CREATE INDEX {$notes}_url_hash_idx ON {$notes} (url_hash)");
			$pdo->exec("CREATE UNIQUE INDEX {$notes}_page_url_hash_idx ON {$notes} (page_url_hash)");
			$pdo->commit();
		} catch (\Throwable $exception) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}
			throw $exception;
		}
	}
};
