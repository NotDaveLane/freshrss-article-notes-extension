<?php

declare(strict_types=1);

namespace ArticleNotes;

/** Applies the missing numbered migrations of one user, recording progress after each one. */
final class Migrator {
	/** @var array<int,string>|null version => file path */
	private ?array $files = null;

	/**
	 * @param \Closure():int $readVersion returns the applied schema version (0 when none)
	 * @param \Closure(int):void $writeVersion persists the applied schema version
	 */
	public function __construct(
		private readonly string $directory,
		private readonly \PDO $pdo,
		private readonly \Closure $readVersion,
		private readonly \Closure $writeVersion,
		private readonly ?string $lockFile = null,
	) {
	}

	public function currentVersion(): int {
		return ($this->readVersion)();
	}

	public function latestVersion(): int {
		$files = $this->files();
		return $files === [] ? 0 : max(array_keys($files));
	}

	/**
	 * @return int number of migrations applied
	 * @throws \Throwable the failure of a migration; the recorded version stays at the last successful one
	 */
	public function migrate(): int {
		if ($this->currentVersion() >= $this->latestVersion()) {
			return 0;
		}
		$lock = $this->lockFile === null ? false : fopen($this->lockFile, 'c');
		if ($lock !== false) {
			flock($lock, LOCK_EX);
		}
		try {
			$driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
			$prefix = $this->pdo instanceof \Minz_Pdo ? $this->pdo->prefix() : '';
			$previousMode = $this->pdo->getAttribute(\PDO::ATTR_ERRMODE);
			$this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
			$applied = 0;
			try {
				foreach ($this->files() as $version => $file) {
					if ($version <= $this->currentVersion()) {
						continue;
					}
					$migration = require $file;
					if (!$migration instanceof Migration) {
						throw new \RuntimeException('Migration ' . basename($file) . ' must return an ' . Migration::class);
					}
					$migration->up($this->pdo, $prefix, is_string($driver) ? $driver : '');
					($this->writeVersion)($version);
					$applied++;
				}
			} finally {
				$this->pdo->setAttribute(\PDO::ATTR_ERRMODE, is_int($previousMode) ? $previousMode : \PDO::ERRMODE_SILENT);
			}
			return $applied;
		} finally {
			if ($lock !== false) {
				flock($lock, LOCK_UN);
				fclose($lock);
			}
		}
	}

	/** @return array<int,string> */
	private function files(): array {
		if ($this->files === null) {
			$files = [];
			$found = glob($this->directory . '/[0-9][0-9][0-9][0-9]_*.php');
			foreach ($found === false ? [] : $found as $file) {
				$files[(int)basename($file)] = $file;
			}
			ksort($files);
			$this->files = $files;
		}
		return $this->files;
	}
}
