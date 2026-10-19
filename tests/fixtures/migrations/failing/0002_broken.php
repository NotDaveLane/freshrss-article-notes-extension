<?php

declare(strict_types=1);

return new class implements ArticleNotes\Migration {
	#[\Override]
	public function up(PDO $pdo, string $prefix, string $driver): void {
		throw new RuntimeException('boom');
	}
};
