<?php

declare(strict_types=1);

namespace ArticleNotes;

/** Database access to one user's FreshRSS database (same connection and table prefix as FreshRSS's own tables). */
final class Storage extends \Minz_ModelPdo {
	/** Tables owned by the extension (without prefix); dropped on uninstall. */
	public const TABLES = ['articlenotes_note', 'articlenotes_highlight', 'articlenotes_url'];

	public function pdo(): \Minz_Pdo {
		return $this->pdo;
	}

	/** Drops every extension table of this user. */
	public function dropTables(): void {
		$previousMode = $this->pdo->getAttribute(\PDO::ATTR_ERRMODE);
		$this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
		try {
			foreach (self::TABLES as $table) {
				$this->pdo->exec('DROP TABLE IF EXISTS ' . $this->pdo->prefix() . $table);
			}
		} finally {
			$this->pdo->setAttribute(\PDO::ATTR_ERRMODE, is_int($previousMode) ? $previousMode : \PDO::ERRMODE_SILENT);
		}
	}
}
