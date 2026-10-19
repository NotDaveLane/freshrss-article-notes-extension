<?php

declare(strict_types=1);

/**
 * Adds the URL index (normalized URL hash → entry ids; FreshRSS does not index `entry.link`) and the normalized URL of
 * each note, so notes stay findable by URL after FreshRSS purges their article. Idempotent.
 *
 * The normalization used to backfill notes is a frozen copy of the 1.1.0 rules (it must not follow later changes to
 * `UrlNormalizer`, which would change what this shipped migration does).
 */
return new class implements ArticleNotes\Migration {
	/** @var list<string> */
	private array $trackingParameters = ['fbclid', 'gclid', 'mc_cid', 'mc_eid', 'ref_src'];
	/** @var array<string,int> */
	private array $defaultPorts = ['http' => 80, 'https' => 443];

	#[\Override]
	public function up(PDO $pdo, string $prefix, string $driver): void {
		$this->createUrlTable($pdo, $prefix, $driver);
		$notes = $prefix . 'articlenotes_note';
		if (!$this->columnExists($pdo, $notes, 'normalized_url')) {
			$pdo->exec("ALTER TABLE {$notes} ADD COLUMN normalized_url TEXT NULL");
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
		$update = $pdo->prepare("UPDATE {$notes} SET normalized_url = :normalized, url_hash = :hash WHERE id = :id");
		$afterId = 0;
		do {
			$select = $pdo->prepare("SELECT id, entry_link, entry_guid FROM {$notes} WHERE url_hash = '' AND id > :after ORDER BY id LIMIT 500");
			$select->execute([':after' => $afterId]);
			/** @var list<array<string,string|int>> $rows */
			$rows = $select->fetchAll(PDO::FETCH_ASSOC);
			foreach ($rows as $row) {
				$afterId = (int)$row['id'];
				$normalized = $this->normalizeUrl((string)$row['entry_link']) ?? $this->normalizeUrl((string)$row['entry_guid']);
				if ($normalized !== null) {
					$update->execute([':normalized' => $normalized, ':hash' => sha1($normalized), ':id' => $afterId]);
				}
			}
		} while (count($rows) === 500);
	}

	/**
	 * The normalized form of an http(s) URL, or null for anything else (relative, other schemes, malformed). The
	 * scheme is always written `https` and a leading `www.` is dropped: this form is for matching, not for display.
	 */
	private function normalizeUrl(string $url): ?string {
		$url = trim($url);
		if ($url === '' || strlen($url) > 16383 || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
			return null;
		}
		if (preg_match('~^(https?)://([^/?#]*)([^?#]*)(?:\?([^#]*))?(?:#.*)?$~i', $url, $matches) !== 1) {
			return null;
		}
		$scheme = strtolower($matches[1]);
		$host = $this->host($matches[2], $scheme);
		if ($host === null) {
			return null;
		}
		$path = $this->path($matches[3]);
		$query = $this->query($matches[4] ?? '');
		return 'https://' . $host . $path . ($query === '' ? '' : '?' . $query);
	}

	/** @return string|null host with its non-default port, lowercased, without `www.` and credentials */
	private function host(string $authority, string $scheme): ?string {
		$at = strrpos($authority, '@');
		$hostPort = $at === false ? $authority : substr($authority, $at + 1);
		if (preg_match('/^(?:(\[[0-9a-f:.]+\])|([^:\[\]]+))(?::(\d*))?$/i', $hostPort, $matches) !== 1) {
			return null;
		}
		$ipv6 = $matches[1] ?? '';
		$host = strtolower($ipv6 !== '' ? $ipv6 : $matches[2] ?? '');
		if ($ipv6 === '') {
			$host = rtrim($host, '.');
			if (preg_match('/[^\x00-\x7f]/', $host) === 1) {
				$ascii = function_exists('idn_to_ascii') ? idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46) : false;
				if ($ascii === false) {
					return null;
				}
				$host = $ascii;
			}
			if (str_starts_with($host, 'www.') && strlen($host) > 4) {
				$host = substr($host, 4);
			}
			if ($host === '' || preg_match('/^[a-z0-9._~%-]+$/', $host) !== 1) {
				return null;
			}
		}
		$port = $matches[3] ?? '';
		if ($port !== '') {
			if (strlen($port) > 5 || (int)$port < 1 || (int)$port > 65535) {
				return null;
			}
			if ((int)$port !== $this->defaultPorts[$scheme]) {
				$host .= ':' . (int)$port;
			}
		}
		return $host;
	}

	private function path(string $path): string {
		$path = $this->normalizeEncoding($path);
		if ($path !== '/') {
			$path = rtrim($path, '/');
		}
		return $path === '' ? '/' : $path;
	}

	private function query(string $query): string {
		$pairs = [];
		foreach (explode('&', $query) as $pair) {
			if ($pair === '') {
				continue;
			}
			$pair = $this->normalizeEncoding($pair);
			$name = strtolower(explode('=', $pair, 2)[0]);
			if (str_starts_with($name, 'utm_') || in_array($name, $this->trackingParameters, true)) {
				continue;
			}
			$pairs[] = $pair;
		}
		sort($pairs, SORT_STRING);
		return implode('&', $pairs);
	}

	/** Decodes the percent-encoded unreserved characters, upper-cases the other escapes and encodes non-ASCII bytes. */
	private function normalizeEncoding(string $value): string {
		$value = preg_replace_callback('/[^\x00-\x7f]/', static fn (array $m): string => rawurlencode($m[0]), $value) ?? $value;
		return preg_replace_callback('/%([0-9a-fA-F]{2})/', static function (array $m): string {
			$character = chr((int)hexdec($m[1]));
			return preg_match('/^[A-Za-z0-9\-._~]$/', $character) === 1 ? $character : '%' . strtoupper($m[1]);
		}, $value) ?? $value;
	}
};
