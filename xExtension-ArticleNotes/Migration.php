<?php

declare(strict_types=1);

namespace ArticleNotes;

/**
 * A numbered, per-user schema migration. Files in Migrations/ are named `NNNN_description.php` and return an instance.
 *
 * `up()` MUST be idempotent (use `CREATE TABLE IF NOT EXISTS`-style guards) because a crash between `up()` and the
 * recorded version, or two concurrent requests, can run it twice. It MUST work on SQLite, MySQL/MariaDB and
 * PostgreSQL; branch on `$driver` (`sqlite`, `mysql`, `pgsql`) where SQL differs. Never edit a shipped migration.
 */
interface Migration {
	/** @param string $prefix FreshRSS's per-user table prefix, to prepend to every table name */
	public function up(\PDO $pdo, string $prefix, string $driver): void;
}
