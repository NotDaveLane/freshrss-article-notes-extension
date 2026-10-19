<?php

declare(strict_types=1);

/** Creates the per-user notes table: one note per article, with an article snapshot and a deletion tombstone. */
return new class implements ArticleNotes\Migration {
	#[\Override]
	public function up(PDO $pdo, string $prefix, string $driver): void {
		$table = $prefix . 'articlenotes_note';
		$columns = <<<'SQL'
			entry_id BIGINT NOT NULL,
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
			label_applied SMALLINT NOT NULL DEFAULT 0
			SQL;

		if ($driver === 'mysql') {
			// TEXT holds 64 KiB; 20,000 characters can need 80,000 bytes in utf8mb4.
			$mysqlColumns = str_replace('note TEXT', 'note MEDIUMTEXT', $columns);
			$pdo->exec("CREATE TABLE IF NOT EXISTS {$table} (
				id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
				{$mysqlColumns},
				UNIQUE INDEX {$table}_entry_id_idx (entry_id),
				INDEX {$table}_updated_at_idx (updated_at)
			) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=INNODB");
			return;
		}

		$idColumn = $driver === 'pgsql' ? 'id BIGSERIAL PRIMARY KEY' : 'id INTEGER PRIMARY KEY AUTOINCREMENT';
		$pdo->exec("CREATE TABLE IF NOT EXISTS {$table} ({$idColumn}, {$columns})");
		$pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS {$table}_entry_id_idx ON {$table} (entry_id)");
		$pdo->exec("CREATE INDEX IF NOT EXISTS {$table}_updated_at_idx ON {$table} (updated_at)");
	}
};
