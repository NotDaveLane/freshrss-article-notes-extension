<?php

declare(strict_types=1);

use ArticleNotes\Migrator;
use ArticleNotes\Storage;
use ArticleNotesTests\TestEnvironment;
use PHPUnit\Framework\TestCase;

final class MigratorTest extends TestCase {
	private Storage $storage;
	private int $version = 0;

	#[\Override]
	protected function setUp(): void {
		$this->storage = new Storage(TestEnvironment::USER);
		$this->dropTables();
		$this->version = 0;
	}

	#[\Override]
	protected function tearDown(): void {
		$this->dropTables();
	}

	private function dropTables(): void {
		foreach (['anmigtest', 'anmigtest2'] as $table) {
			$this->storage->pdo()->exec('DROP TABLE IF EXISTS ' . $this->storage->pdo()->prefix() . $table);
		}
	}

	private function migrator(string $set): Migrator {
		return new Migrator(
			dirname(__DIR__) . '/fixtures/migrations/' . $set,
			$this->storage->pdo(),
			fn (): int => $this->version,
			function (int $version): void {
				$this->version = $version;
			}
		);
	}

	private function tableExists(string $table): bool {
		$pdo = $this->storage->pdo();
		$statement = $pdo->query('SELECT COUNT(*) FROM ' . $pdo->prefix() . $table);
		return $statement !== false;
	}

	public function testRealMigrationsAreNumberedFromOne(): void {
		$migrator = new Migrator(
			dirname(__DIR__, 2) . '/xExtension-ArticleNotes/Migrations',
			$this->storage->pdo(),
			static fn (): int => 0,
			static function (int $version): void {
			}
		);
		self::assertGreaterThanOrEqual(1, $migrator->latestVersion());
	}

	public function testFreshUserMigratesToLatest(): void {
		$migrator = $this->migrator('ok');
		self::assertSame(2, $migrator->latestVersion());
		self::assertSame(2, $migrator->migrate());
		self::assertSame(2, $this->version);
		self::assertTrue($this->tableExists('anmigtest'));
		self::assertTrue($this->tableExists('anmigtest2'));
	}

	public function testRerunningIsANoOp(): void {
		$migrator = $this->migrator('ok');
		$migrator->migrate();
		self::assertSame(0, $migrator->migrate());
		self::assertSame(2, $this->version);
	}

	public function testOlderUserIsUpgradedInOrder(): void {
		$this->migrator('ok')->migrate();
		$this->storage->pdo()->exec('DROP TABLE ' . $this->storage->pdo()->prefix() . 'anmigtest2');
		$this->version = 1;
		self::assertSame(1, $this->migrator('ok')->migrate());
		self::assertSame(2, $this->version);
		self::assertTrue($this->tableExists('anmigtest2'));
	}

	public function testReplayingAMigrationIsHarmless(): void {
		$this->migrator('ok')->migrate();
		$this->version = 0;
		self::assertSame(2, $this->migrator('ok')->migrate());
		self::assertSame(2, $this->version);
	}

	public function testFailingMigrationKeepsLastSuccessfulVersion(): void {
		$migrator = $this->migrator('failing');
		try {
			$migrator->migrate();
			self::fail('Expected the migration to fail');
		} catch (RuntimeException $exception) {
			self::assertSame('boom', $exception->getMessage());
		}
		self::assertSame(1, $this->version);
		self::assertSame(1, $migrator->currentVersion());
	}
}
