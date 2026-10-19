<?php

declare(strict_types=1);

return new class implements ArticleNotes\Migration {
	#[\Override]
	public function up(PDO $pdo, string $prefix, string $driver): void {
		$pdo->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'anmigtest2 (id INTEGER NOT NULL PRIMARY KEY)');
	}
};
