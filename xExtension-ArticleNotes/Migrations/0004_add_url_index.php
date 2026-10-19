<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/UrlNormalizer.php';

/**
 * Adds the URL index (normalized URL hash → entry ids; FreshRSS does not index `entry.link`) and the normalized URL of
 * each note, so notes stay findable by URL after FreshRSS purges their article. Idempotent.
 */
return new class implements ArticleNotes\Migration {
	#[\Override]
	public function up(PDO $pdo, string $prefix, string $driver): void {
		$this->createUrlTable($pdo, $prefix, $driver);
		$notes = $prefix . 'articlenotes_note';
		if (!$this->columnExists($pdo, $notes, 'normalized_url')) {
			$pdo->exec("ALTER TABLE {$notes} ADD COLUMN normalized_url TEXT NOT NULL DEFAULT ''");
		}
		if (!$this->columnExists($pdo, $notes, 'url_hash')) {
			$pdo->exec("ALTER TABLE {$notes} ADD COLUMN url_hash VARCHAR(40) NOT NULL DEFAULT ''");
		}
		$this->createIndex($pdo, $driver, $notes . '_url_hash_idx', $notes, 'url_hash');
		$this->backfillNotes($pdo, $notes);
	}

	private function createUrlTable(PDO $pdo, string $prefix, string $driver): void {
		$table = $prefix . 'articlenotes_url';
		$columns = <<<'SQL'
			entry_id BIGINT NOT NULL,
			source SMALLINT NOT NULL,
			url_hash VARCHAR(40) NOT NULL,
			link TEXT NOT NULL,
			guid TEXT NOT NULL,
			PRIMARY KEY (entry_id, source)
			SQL;
		if ($driver === 'mysql') {
			$pdo->exec("CREATE TABLE IF NOT EXISTS {$table} (
				{$columns},
				INDEX {$table}_url_hash_idx (url_hash)
			) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=INNODB");
			return;
		}
		$pdo->exec("CREATE TABLE IF NOT EXISTS {$table} ({$columns})");
		$pdo->exec("CREATE INDEX IF NOT EXISTS {$table}_url_hash_idx ON {$table} (url_hash)");
	}

	private function createIndex(PDO $pdo, string $driver, string $name, string $table, string $column): void {
		if ($driver !== 'mysql') {
			$pdo->exec("CREATE INDEX IF NOT EXISTS {$name} ON {$table} ({$column})");
			return;
		}
		try {
			$pdo->exec("CREATE INDEX {$name} ON {$table} ({$column})");
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

	private function backfillNotes(PDO $pdo, string $notes): void {
		$select = $pdo->query("SELECT id, entry_link, entry_guid FROM {$notes} WHERE url_hash = ''");
		if ($select === false) {
			return;
		}
		/** @var list<array<string,string|int>> $rows */
		$rows = $select->fetchAll(PDO::FETCH_ASSOC);
		$update = $pdo->prepare("UPDATE {$notes} SET normalized_url = :normalized, url_hash = :hash WHERE id = :id");
		foreach ($rows as $row) {
			$normalized = ArticleNotes\UrlNormalizer::forEntry((string)$row['entry_link'], (string)$row['entry_guid']);
			if ($normalized !== null) {
				$update->execute([':normalized' => $normalized, ':hash' => ArticleNotes\UrlNormalizer::hash($normalized), ':id' => $row['id']]);
			}
		}
	}
};
