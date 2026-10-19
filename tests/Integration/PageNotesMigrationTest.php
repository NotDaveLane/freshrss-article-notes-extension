<?php

declare(strict_types=1);

use ArticleNotes\Storage;
use ArticleNotesTests\TestEnvironment;
use PHPUnit\Framework\TestCase;

/** Migration 0005 (page notes) on a database created at schema 0004, with notes and tombstones. */
final class PageNotesMigrationTest extends TestCase {
	private const SCRATCH = 'mig5_';

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
		foreach (['articlenotes_url', 'articlenotes_note', 'articlenotes_note_new'] as $table) {
			$this->pdo->exec('DROP TABLE IF EXISTS ' . $this->prefix . $table);
		}
	}

	private function migrate(string $file): void {
		$migration = require dirname(__DIR__, 2) . '/xExtension-ArticleNotes/Migrations/' . $file;
		self::assertInstanceOf(ArticleNotes\Migration::class, $migration);
		$migration->up($this->pdo, $this->prefix, $this->driver);
	}

	private function insertArticleNote(int $entryId, ?int $deletedAt, string $url): void {
		$statement = $this->pdo->prepare('INSERT INTO ' . $this->prefix . 'articlenotes_note (entry_id, note, created_at, updated_at, '
			. 'deleted_at, entry_guid, entry_link, entry_title, entry_author, entry_date, feed_id, feed_name, feed_url, label_applied, '
			. 'label_id, normalized_url, url_hash) VALUES (:entry_id, :note, 5, 6, :deleted_at, :guid, :link, :title, :author, 7, 8, '
			. ':feed_name, :feed_url, 1, 9, :normalized, :hash)');
		self::assertNotFalse($statement);
		$statement->execute([':entry_id' => $entryId, ':note' => 'note ' . $entryId, ':deleted_at' => $deletedAt,
			':guid' => 'g' . $entryId, ':link' => $url, ':title' => 't', ':author' => 'a', ':feed_name' => 'f',
			':feed_url' => 'http://f/', ':normalized' => $url, ':hash' => sha1($url)]);
	}

	/** @return list<array<string,mixed>> */
	private function rows(): array {
		$statement = $this->pdo->query('SELECT * FROM ' . $this->prefix . 'articlenotes_note WHERE entry_id IS NOT NULL ORDER BY id');
		self::assertNotFalse($statement);
		/** @var list<array<string,mixed>> $rows */
		$rows = $statement->fetchAll(PDO::FETCH_ASSOC);
		foreach ($rows as &$row) {
			unset($row['page_url_hash']);	// the only new column
		}
		return $rows;
	}

	private function insertPage(string $hash, string $url): void {
		$statement = $this->pdo->prepare('INSERT INTO ' . $this->prefix . 'articlenotes_note (entry_id, note, created_at, updated_at, '
			. 'entry_guid, entry_link, entry_title, entry_author, entry_date, feed_id, feed_name, feed_url, label_applied, normalized_url, '
			. "url_hash, page_url_hash) VALUES (NULL, 'p', 1, 1, '', :link, '', '', 0, 0, '', '', 0, :link2, :hash, :hash2)");
		self::assertNotFalse($statement);
		$statement->execute([':link' => $url, ':link2' => $url, ':hash' => $hash, ':hash2' => $hash]);
	}

	private function upgradedDatabase(): void {
		foreach (['0002_create_note_table.php', '0003_add_label_id.php', '0004_add_url_index.php'] as $file) {
			$this->migrate($file);
		}
		$this->insertArticleNote(11, null, 'https://example.org/one');
		$this->insertArticleNote(12, 100, 'https://example.org/two');
		$this->insertArticleNote(13, null, 'https://example.org/three');
	}

	public function testKeepsEveryRowTombstoneAndIdAndAllowsPageNotes(): void {
		$this->upgradedDatabase();
		$before = $this->rows();
		self::assertCount(3, $before);

		$this->migrate('0005_allow_page_notes.php');

		self::assertSame($before, $this->rows());
		$this->insertPage(sha1('https://example.org/page'), 'https://example.org/page');
		$this->insertPage(sha1('https://example.org/page2'), 'https://example.org/page2');
		$this->insertArticleNote(14, null, 'https://example.org/four');
		// Notes without a page hash (not possible through the service) do not collide either.
		$this->pdo->exec('INSERT INTO ' . $this->prefix . 'articlenotes_note (entry_id, note, created_at, updated_at, entry_guid, entry_link, '
			. "entry_title, entry_author, entry_date, feed_id, feed_name, feed_url, label_applied) VALUES (NULL, 'q', 1, 1, '', '', '', '', 0, 0, '', '', 0)");
		$count = $this->pdo->query('SELECT COUNT(*) FROM ' . $this->prefix . 'articlenotes_note');
		self::assertNotFalse($count);
		self::assertSame(7, (int)$count->fetchColumn());
	}

	public function testStillUniquePerArticleAndPerPage(): void {
		$this->upgradedDatabase();
		$this->migrate('0005_allow_page_notes.php');
		$this->insertPage(sha1('https://example.org/page'), 'https://example.org/page');
		$violations = 0;
		$inserts = [
			fn () => $this->insertPage(sha1('https://example.org/page'), 'https://example.org/page'),
			fn () => $this->insertArticleNote(11, null, 'https://example.org/dup'),
		];
		foreach ($inserts as $insert) {
			try {
				$insert();
			} catch (PDOException) {
				$violations++;
			}
		}
		self::assertSame(2, $violations, 'one page note per URL, one note per article');
	}

	public function testIsIdempotent(): void {
		$this->upgradedDatabase();
		$this->migrate('0005_allow_page_notes.php');
		$after = $this->rows();
		$this->migrate('0005_allow_page_notes.php');
		self::assertSame($after, $this->rows());
		$this->insertPage(sha1('https://example.org/p'), 'https://example.org/p');
		$this->migrate('0005_allow_page_notes.php');
		$count = $this->pdo->query('SELECT COUNT(*) FROM ' . $this->prefix . 'articlenotes_note');
		self::assertNotFalse($count);
		self::assertSame(4, (int)$count->fetchColumn());
	}

	public function testNewIdsContinueAfterTheExistingOnes(): void {
		$this->upgradedDatabase();
		$before = $this->rows();
		$this->migrate('0005_allow_page_notes.php');
		$this->insertPage(sha1('x'), 'https://example.org/x');
		$statement = $this->pdo->query('SELECT MAX(id) FROM ' . $this->prefix . 'articlenotes_note');
		self::assertNotFalse($statement);
		self::assertGreaterThan((int)(is_numeric($before[2]['id'] ?? null) ? $before[2]['id'] : 0), (int)$statement->fetchColumn());
	}
}
