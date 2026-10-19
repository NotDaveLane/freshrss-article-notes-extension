<?php

declare(strict_types=1);

/** Baseline: creates nothing. It proves the migration mechanism end to end; the notes table arrives in 0002. */
return new class implements ArticleNotes\Migration {
	#[\Override]
	public function up(PDO $pdo, string $prefix, string $driver): void {
	}
};
