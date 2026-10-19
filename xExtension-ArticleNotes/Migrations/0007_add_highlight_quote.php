<?php

declare(strict_types=1);

/**
 * Adds `quote`, the selected text of a highlight kept in a column of its own so the Notes page can search it (the
 * selector is a JSON document whose keys and context would match too), and fills it for the existing highlights.
 */
return new class implements ArticleNotes\Migration {
	#[\Override]
	public function up(PDO $pdo, string $prefix, string $driver): void {
		$table = $prefix . 'articlenotes_highlight';
		try {
			// query() and closeCursor(), not exec(): on MySQL, exec('SELECT …') leaves the connection unusable.
			$probe = $pdo->query("SELECT quote FROM {$table} WHERE 1 = 0");
			$exists = $probe !== false;
			if ($probe !== false) {
				$probe->closeCursor();
			}
		} catch (\PDOException) {
			$exists = false;
		}
		if (!$exists) {
			$pdo->exec("ALTER TABLE {$table} ADD COLUMN quote TEXT NULL");
		}

		$select = $pdo->query("SELECT id, selector FROM {$table} WHERE quote IS NULL");
		if ($select === false) {
			throw new \RuntimeException('Cannot read the highlights to backfill their quote');
		}
		/** @var list<array<string,mixed>> $rows */
		$rows = $select->fetchAll(PDO::FETCH_ASSOC);
		$update = $pdo->prepare("UPDATE {$table} SET quote=:quote WHERE id=:id");
		if ($update === false) {
			throw new \RuntimeException('Cannot prepare the quote backfill');
		}
		foreach ($rows as $row) {
			$selector = json_decode(is_string($row['selector'] ?? null) ? $row['selector'] : '', true);
			$exact = is_array($selector) && is_array($selector['quote'] ?? null) ? ($selector['quote']['exact'] ?? '') : '';
			$update->bindValue(':quote', is_string($exact) ? $exact : '');
			$update->bindValue(':id', is_numeric($row['id'] ?? null) ? (int)$row['id'] : 0, PDO::PARAM_INT);
			$update->execute();
		}
	}
};
