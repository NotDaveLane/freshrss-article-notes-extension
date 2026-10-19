<?php

declare(strict_types=1);

use ArticleNotes\Storage;
use ArticleNotesTests\TestEnvironment;
use PHPUnit\Framework\TestCase;

/** Migration 0006 (highlights table) on a database at schema 0005 with notes and page notes. */
final class HighlightsMigrationTest extends TestCase {
	private const SCRATCH = 'mig6_';

	private PDO $pdo;
	private string $prefix;
	private string $driver;

	#[\Override]
	protected function setUp(): void {
		$this->pdo = (new Storage(TestEnvironment::USER))->pdo();
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
		foreach (['articlenotes_highlight', 'articlenotes_url', 'articlenotes_note', 'articlenotes_note_new'] as $table) {
			$this->pdo->exec('DROP TABLE IF EXISTS ' . $this->prefix . $table);
		}
	}

	private function migrate(string $file): void {
		$migration = require dirname(__DIR__, 2) . '/xExtension-ArticleNotes/Migrations/' . $file;
		self::assertInstanceOf(ArticleNotes\Migration::class, $migration);
		$migration->up($this->pdo, $this->prefix, $this->driver);
	}

	private function previousSchema(): void {
		foreach (['0002_create_note_table.php', '0003_add_label_id.php', '0004_add_url_index.php', '0005_allow_page_notes.php'] as $file) {
			$this->migrate($file);
		}
		$notes = $this->prefix . 'articlenotes_note';
		$this->pdo->exec("INSERT INTO {$notes} (entry_id, note, created_at, updated_at, entry_guid, entry_link, entry_title, entry_author, "
			. "entry_date, feed_id, feed_name, feed_url, label_applied, normalized_url, url_hash) VALUES (11, 'article note', 1, 2, 'g', "
			. "'https://example.org/a', 't', 'a', 3, 4, 'f', 'http://f/', 1, 'https://example.org/a', 'h1')");
		$this->pdo->exec("INSERT INTO {$notes} (entry_id, note, created_at, updated_at, entry_guid, entry_link, entry_title, entry_author, "
			. "entry_date, feed_id, feed_name, feed_url, label_applied, normalized_url, url_hash, page_url_hash) VALUES (NULL, 'page note', 1, 2, '', "
			. "'https://example.org/p', 'T', '', 0, 0, '', '', 0, 'https://example.org/p', 'h2', 'h2')");
	}

	/** @return list<array<string,mixed>> */
	private function notes(): array {
		$statement = $this->pdo->query('SELECT * FROM ' . $this->prefix . 'articlenotes_note ORDER BY id');
		self::assertNotFalse($statement);
		/** @var list<array<string,mixed>> $rows */
		$rows = $statement->fetchAll(PDO::FETCH_ASSOC);
		return $rows;
	}

	private function insertHighlight(string $uuid, ?int $entryId = 11, string $note = 'n'): void {
		$statement = $this->pdo->prepare('INSERT INTO ' . $this->prefix . 'articlenotes_highlight (uuid, entry_id, url, normalized_url, '
			. 'url_hash, title, entry_guid, entry_link, entry_title, entry_author, entry_date, feed_id, feed_name, feed_url, source, selector, '
			. 'color, note, created_at, updated_at) VALUES (:uuid, :entry_id, :url, :normalized, :hash, :title, :guid, :link, :entry_title, :author, 1, 2, '
			. ":feed, :feed_url, 'page', :selector, 'yellow', :note, 5, 6)");
		self::assertNotFalse($statement);
		$statement->execute([':uuid' => $uuid, ':entry_id' => $entryId, ':url' => 'https://example.org/a', ':normalized' => 'https://example.org/a', ':entry_title' => 't', ':hash' => 'h1',
			':title' => 'é title', ':guid' => 'g', ':link' => 'https://example.org/a', ':author' => 'a', ':feed' => 'f',
			':feed_url' => 'http://f/', ':selector' => '{"quote":{"exact":"x"}}', ':note' => $note]);
	}

	public function testCreatesTheTableAndKeepsNotesUntouched(): void {
		$this->previousSchema();
		$before = $this->notes();
		self::assertCount(2, $before);

		$this->migrate('0006_create_highlight_table.php');

		self::assertSame($before, $this->notes());
		$this->insertHighlight('a0000000-0000-4000-8000-000000000001');
		$this->insertHighlight('a0000000-0000-4000-8000-000000000002');
		$this->insertHighlight('a0000000-0000-4000-8000-000000000003', null, str_repeat('é', 20000));
		$statement = $this->pdo->query('SELECT note, deleted_at, label_applied, label_id FROM ' . $this->prefix
			. "articlenotes_highlight WHERE uuid='a0000000-0000-4000-8000-000000000003'");
		self::assertNotFalse($statement);
		$row = $statement->fetch(PDO::FETCH_ASSOC);
		self::assertIsArray($row);
		self::assertSame(str_repeat('é', 20000), $row['note']);
		self::assertNull($row['deleted_at']);
		self::assertEquals(0, $row['label_applied']);
		self::assertNull($row['label_id']);
	}

	public function testUuidIsUnique(): void {
		$this->previousSchema();
		$this->migrate('0006_create_highlight_table.php');
		$this->insertHighlight('b0000000-0000-4000-8000-000000000001');
		$this->expectException(PDOException::class);
		$this->insertHighlight('b0000000-0000-4000-8000-000000000001');
	}

	public function testIsIdempotentAndKeepsRows(): void {
		$this->previousSchema();
		$this->migrate('0006_create_highlight_table.php');
		$this->insertHighlight('c0000000-0000-4000-8000-000000000001');
		$this->migrate('0006_create_highlight_table.php');
		$statement = $this->pdo->query('SELECT COUNT(*) FROM ' . $this->prefix . 'articlenotes_highlight');
		self::assertNotFalse($statement);
		self::assertSame(1, (int)$statement->fetchColumn());
	}

	public function testIdsAreAssignedAscending(): void {
		$this->previousSchema();
		$this->migrate('0006_create_highlight_table.php');
		$this->insertHighlight('d0000000-0000-4000-8000-000000000001');
		$this->insertHighlight('d0000000-0000-4000-8000-000000000002');
		$statement = $this->pdo->query('SELECT id FROM ' . $this->prefix . 'articlenotes_highlight ORDER BY id');
		self::assertNotFalse($statement);
		$ids = array_map(static fn (mixed $id): int => is_numeric($id) ? (int)$id : 0, $statement->fetchAll(PDO::FETCH_COLUMN));
		self::assertCount(2, $ids);
		self::assertGreaterThan($ids[0], $ids[1]);
	}
}
