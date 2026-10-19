<?php

declare(strict_types=1);

use ArticleNotes\Storage;
use ArticleNotesTests\TestEnvironment;
use PHPUnit\Framework\TestCase;

/** Migration 0007 (searchable `quote` column of highlights, backfilled from the selector). */
final class HighlightQuoteMigrationTest extends TestCase {
	private const SCRATCH = 'mig7_';

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
		foreach (['0002_create_note_table.php', '0003_add_label_id.php', '0004_add_url_index.php', '0005_allow_page_notes.php',
			'0006_create_highlight_table.php'] as $file) {
			$this->migrate($file);
		}
		$statement = $this->pdo->prepare('INSERT INTO ' . $this->prefix . 'articlenotes_highlight (uuid, entry_id, url, normalized_url, '
			. 'url_hash, title, entry_guid, entry_link, entry_title, entry_author, entry_date, feed_id, feed_name, feed_url, source, selector, '
			. "color, note, created_at, updated_at) VALUES (:uuid, NULL, 'u', 'u', 'h', '', '', '', '', '', 0, 0, '', '', 'page', :selector, "
			. "'yellow', '', 1, 1)");
		self::assertNotFalse($statement);
		$statement->execute([':uuid' => 'e0000000-0000-4000-8000-000000000001', ':selector' => '{"quote":{"exact":"Ünïcode \"quoted\" / text","prefix":"zz"}}']);
		$statement->execute([':uuid' => 'e0000000-0000-4000-8000-000000000002', ':selector' => '{"quote":{"exact":"second"}}']);
	}

	/** @return array<string,string> uuid => quote */
	private function quotes(): array {
		$statement = $this->pdo->query('SELECT uuid, quote FROM ' . $this->prefix . 'articlenotes_highlight ORDER BY uuid');
		self::assertNotFalse($statement);
		/** @var array<string,string> $quotes */
		$quotes = $statement->fetchAll(PDO::FETCH_KEY_PAIR);
		return $quotes;
	}

	public function testAddsAndBackfillsTheQuote(): void {
		$this->previousSchema();
		$this->migrate('0007_add_highlight_quote.php');
		self::assertSame([
			'e0000000-0000-4000-8000-000000000001' => 'Ünïcode "quoted" / text',
			'e0000000-0000-4000-8000-000000000002' => 'second',
		], $this->quotes());
	}

	public function testIsIdempotent(): void {
		$this->previousSchema();
		$this->migrate('0007_add_highlight_quote.php');
		$before = $this->quotes();
		$this->migrate('0007_add_highlight_quote.php');
		self::assertSame($before, $this->quotes());
	}
}
