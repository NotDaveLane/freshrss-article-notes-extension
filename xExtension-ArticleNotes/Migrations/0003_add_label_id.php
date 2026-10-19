<?php

declare(strict_types=1);

/** Remembers which label the extension applied to an article, so deleting the note removes that very label. */
return new class implements ArticleNotes\Migration {
	#[\Override]
	public function up(PDO $pdo, string $prefix, string $driver): void {
		$table = $prefix . 'articlenotes_note';
		try {
			// query() and closeCursor(), not exec(): on MySQL, exec('SELECT …') leaves the connection unusable.
			$probe = $pdo->query("SELECT label_id FROM {$table} WHERE 1 = 0");
			$exists = $probe !== false;
			if ($probe !== false) {
				$probe->closeCursor();
			}
		} catch (\PDOException) {
			$exists = false;
		}
		if ($exists) {
			return;
		}
		$pdo->exec("ALTER TABLE {$table} ADD COLUMN label_id BIGINT NULL");
	}
};
