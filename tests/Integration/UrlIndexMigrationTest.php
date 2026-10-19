<?php

declare(strict_types=1);

use ArticleNotes\Storage;
use ArticleNotes\UrlNormalizer;
use ArticleNotesTests\TestEnvironment;
use PHPUnit\Framework\TestCase;

/** Migration 0004 on a database created at schema 0002/0003, with existing notes. */
final class UrlIndexMigrationTest extends TestCase {
	private const SCRATCH = 'mig4_';

	private PDO $pdo;
	private string $prefix;
	private string $driver;

	#[\Override]
	protected function setUp(): void {
		$storage = new Storage(TestEnvironment::USER);
		$this->pdo = $storage->pdo();
		$this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$this->prefix = $this->pdo->prefix() . self::SCRATCH;
		$this->driver = (is_string($driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) ? $driver : '');
		$this->drop();
	}

	#[\Override]
	protected function tearDown(): void {
		$this->drop();
		$this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
	}

	private function drop(): void {
		foreach (['articlenotes_url', 'articlenotes_note'] as $table) {
			$this->pdo->exec('DROP TABLE IF EXISTS ' . $this->prefix . $table);
		}
	}

	private function migration(string $file): ArticleNotes\Migration {
		$migration = require dirname(__DIR__, 2) . '/xExtension-ArticleNotes/Migrations/' . $file;
		self::assertInstanceOf(ArticleNotes\Migration::class, $migration);
		return $migration;
	}

	private function insertNote(string $entryId, string $link, string $guid, ?int $deletedAt = null): void {
		$statement = $this->pdo->prepare('INSERT INTO ' . $this->prefix . 'articlenotes_note (entry_id, note, created_at, updated_at, deleted_at, '
			. 'entry_guid, entry_link, entry_title, entry_author, entry_date, feed_id, feed_name, feed_url, label_applied) '
			. 'VALUES (:entry_id, :note, 1, 1, :deleted_at, :guid, :link, :title, :author, 1, 1, :feed_name, :feed_url, 0)');
		self::assertNotFalse($statement);
		$statement->execute([':entry_id' => $entryId, ':note' => 'old note', ':deleted_at' => $deletedAt, ':guid' => $guid, ':link' => $link,
			':title' => 't', ':author' => 'a', ':feed_name' => 'f', ':feed_url' => 'http://f/']);
	}

	/** @return array<int,array{normalized_url:string,url_hash:string}> notes by entry id */
	private function notes(): array {
		$statement = $this->pdo->query('SELECT entry_id, normalized_url, url_hash FROM ' . $this->prefix . 'articlenotes_note');
		self::assertNotFalse($statement);
		$notes = [];
		/** @var list<array{entry_id:string,normalized_url:string,url_hash:string}> $rows */
		$rows = $statement->fetchAll(PDO::FETCH_ASSOC);
		foreach ($rows as $row) {
			$notes[(int)$row['entry_id']] = ['normalized_url' => $row['normalized_url'], 'url_hash' => $row['url_hash']];
		}
		return $notes;
	}

	public function testUpgradesASchema0003DatabaseWithNotes(): void {
		$this->migration('0002_create_note_table.php')->up($this->pdo, $this->prefix, $this->driver);
		$this->migration('0003_add_label_id.php')->up($this->pdo, $this->prefix, $this->driver);
		$this->insertNote('11', 'http://www.example.org/migrated/?utm_source=x', 'g1');
		$this->insertNote('12', '', 'https://example.org/from-guid/', 123);
		$this->insertNote('13', '', 'tag:no-url', null);

		$migration = $this->migration('0004_add_url_index.php');
		$migration->up($this->pdo, $this->prefix, $this->driver);

		$notes = $this->notes();
		self::assertSame('https://example.org/migrated', $notes[11]['normalized_url']);
		self::assertSame(UrlNormalizer::hash('https://example.org/migrated'), $notes[11]['url_hash']);
		self::assertSame('https://example.org/from-guid', $notes[12]['normalized_url'], 'GUID URL when there is no link; tombstones too');
		self::assertSame('', $notes[13]['normalized_url']);
		self::assertSame('', $notes[13]['url_hash']);

		$probe = $this->pdo->query('SELECT entry_id, source, url_hash, link, guid FROM ' . $this->prefix . 'articlenotes_url');
		self::assertNotFalse($probe);
		self::assertSame([], $probe->fetchAll());
	}

	public function testIsIdempotent(): void {
		$this->migration('0002_create_note_table.php')->up($this->pdo, $this->prefix, $this->driver);
		$this->migration('0003_add_label_id.php')->up($this->pdo, $this->prefix, $this->driver);
		$this->insertNote('21', 'https://example.org/twice', 'g');
		$migration = $this->migration('0004_add_url_index.php');
		$migration->up($this->pdo, $this->prefix, $this->driver);
		$migration->up($this->pdo, $this->prefix, $this->driver);
		self::assertSame('https://example.org/twice', $this->notes()['21']['normalized_url']);
	}

	public function testMigratorUpgradesAUserAtSchema0002(): void {
		$user = 'migupgrade';
		self::assertTrue(FreshRSS_user_Controller::createUser($user, null, 'web-password-123', ['language' => 'en'], false));
		try {
			$pdo = (new Storage($user))->pdo();
			$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
			$source = dirname(__DIR__, 2) . '/xExtension-ArticleNotes/Migrations';
			$old = sys_get_temp_dir() . '/an-mig-' . bin2hex(random_bytes(3));
			mkdir($old);
			foreach (['0001_baseline.php', '0002_create_note_table.php'] as $file) {
				copy($source . '/' . $file, $old . '/' . $file);
			}
			$version = 0;
			$write = static function (int $v) use (&$version): void {
				$version = $v;
			};
			$read = static function () use (&$version): int {
				return $version;
			};
			(new ArticleNotes\Migrator($old, $pdo, $read, $write))->migrate();
			self::assertSame(2, $version);
			foreach ((array)glob($old . '/*') as $file) {
				unlink((string)$file);
			}
			rmdir($old);

			$table = $pdo->prefix() . 'articlenotes_note';
			$pdo->exec("INSERT INTO {$table} (entry_id, note, created_at, updated_at, entry_guid, entry_link, entry_title, entry_author, "
				. "entry_date, feed_id, feed_name, feed_url, label_applied) VALUES (5, 'kept', 1, 1, 'g', "
				. "'http://www.example.org/upgrade/', 't', 'a', 1, 1, 'f', 'http://f/', 0)");

			$migrator = new ArticleNotes\Migrator($source, $pdo, $read, $write);
			self::assertSame($migrator->latestVersion() - 2, $migrator->migrate());
			self::assertSame($migrator->latestVersion(), $version);
			$row = $pdo->query("SELECT note, normalized_url, url_hash FROM {$table} WHERE entry_id=5");
			self::assertNotFalse($row);
			self::assertSame(['note' => 'kept', 'normalized_url' => 'https://example.org/upgrade',
				'url_hash' => UrlNormalizer::hash('https://example.org/upgrade')], $row->fetch(PDO::FETCH_ASSOC));
			self::assertSame(0, $migrator->migrate());
		} finally {
			Minz_User::change(TestEnvironment::USER);
			FreshRSS_user_Controller::deleteUser($user);
			cliInitUser(TestEnvironment::USER);
		}
	}
}
