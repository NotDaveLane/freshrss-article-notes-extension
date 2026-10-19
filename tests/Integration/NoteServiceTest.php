<?php

declare(strict_types=1);

use ArticleNotes\NoteException;
use ArticleNotes\NoteService;
use ArticleNotesTests\TestEnvironment;
use PHPUnit\Framework\TestCase;

final class NoteServiceTest extends TestCase {
	private const OTHER_USER = 'notesother';

	private static int $sequence = 0;
	private static int $feedId = 0;

	#[\Override]
	public static function setUpBeforeClass(): void {
		self::useUser(TestEnvironment::USER);
		self::$feedId = self::createFeed('http://fixtures/note-service-feed.xml', 'Note Service Feed');
	}

	#[\Override]
	public static function tearDownAfterClass(): void {
		self::useUser(TestEnvironment::USER);
		if (FreshRSS_user_Controller::userExists(self::OTHER_USER)) {
			FreshRSS_user_Controller::deleteUser(self::OTHER_USER);
		}
	}

	#[\Override]
	protected function setUp(): void {
		self::useUser(TestEnvironment::USER);
	}

	private static function useUser(string $user): void {
		Minz_User::change($user);
		cliInitUser($user);
	}

	private static function createFeed(string $url, string $name): int {
		$feed = new FreshRSS_Feed($url, false);
		$feed->_name($name);
		$feed->_categoryId(FreshRSS_CategoryDAO::DEFAULTCATEGORYID);
		$id = FreshRSS_Factory::createFeedDao()->addFeedObject($feed);
		self::assertIsInt($id);
		return $id;
	}

	private function addEntry(string $title = 'Article'): string {
		$n = ++self::$sequence;
		$id = (string)(1700000000000000 + $n * 1000 + random_int(0, 999));
		$added = FreshRSS_Factory::createEntryDao()->addEntry([
			'id' => $id, 'guid' => 'guid-' . $id, 'title' => $title . ' ' . $n, 'author' => 'Author ' . $n,
			'content' => 'Content', 'link' => 'http://fixtures/articles/' . $id, 'date' => 1756717200 + $n, 'lastSeen' => time(),
			'hash' => md5($id), 'is_read' => false, 'is_favorite' => false, 'id_feed' => self::$feedId, 'tags' => '',
		], false);
		self::assertTrue($added);
		return $id;
	}

	private function service(?Closure $clock = null): NoteService {
		$extension = Minz_ExtensionManager::findExtension('ArticleNotes');
		self::assertInstanceOf(ArticleNotesExtension::class, $extension);
		// A fresh instance per service: extensions cache the user's configuration, and tests switch users in-process.
		$extension = new ArticleNotesExtension([
			'name' => 'ArticleNotes', 'entrypoint' => 'ArticleNotes', 'path' => $extension->getPath(), 'type' => 'system',
		]);
		return new NoteService(Minz_User::name() ?? '', $extension->ensureSchema(...), $clock);
	}

	public function testCreateStoresNoteAndSnapshot(): void {
		$entryId = $this->addEntry('Snapshot');
		$note = $this->service(static fn (): int => 1000)->save($entryId, "  my note \n");
		self::assertNotNull($note);
		self::assertSame('my note', $note->note);
		self::assertSame($entryId, $note->entryId);
		self::assertSame(1000, $note->createdAt);
		self::assertSame(1000, $note->updatedAt);
		self::assertNull($note->deletedAt);
		self::assertSame('guid-' . $entryId, $note->entryGuid);
		self::assertSame('http://fixtures/articles/' . $entryId, $note->entryLink);
		self::assertStringStartsWith('Snapshot ', $note->entryTitle);
		self::assertStringStartsWith('Author ', $note->entryAuthor);
		self::assertStringNotContainsString(';', $note->entryAuthor);
		self::assertGreaterThan(1756717200, $note->entryDate);
		self::assertSame(self::$feedId, $note->feedId);
		self::assertSame('Note Service Feed', $note->feedName);
		self::assertSame('http://fixtures/note-service-feed.xml', $note->feedUrl);
		self::assertFalse($note->labelApplied);
	}

	public function testUpdateKeepsOneNotePerArticle(): void {
		$entryId = $this->addEntry();
		$time = 1000;
		$service = $this->service(static function () use (&$time): int {
			return $time;
		});
		$first = $service->save($entryId, 'first');
		$time = 2000;
		$second = $service->save($entryId, 'second');
		self::assertNotNull($first);
		self::assertNotNull($second);
		self::assertSame($first->id, $second->id);
		self::assertSame('second', $second->note);
		self::assertSame(1000, $second->createdAt);
		self::assertSame(2000, $second->updatedAt);
		self::assertSame('second', $service->get($entryId)?->note);
	}

	public function testSaveAfterDeleteRevivesTheTombstone(): void {
		$entryId = $this->addEntry();
		$time = 1000;
		$service = $this->service(static function () use (&$time): int {
			return $time;
		});
		$created = $service->save($entryId, 'one');
		$time = 2000;
		self::assertTrue($service->delete($entryId));
		self::assertNull($service->get($entryId));
		$time = 3000;
		$revived = $service->save($entryId, 'two');
		self::assertNotNull($created);
		self::assertNotNull($revived);
		self::assertSame($created->id, $revived->id);
		self::assertNull($revived->deletedAt);
		self::assertSame(3000, $revived->updatedAt);
		self::assertSame('two', $revived->note);
	}

	public function testDeleteSetsTombstoneTimestamps(): void {
		$entryId = $this->addEntry();
		$service = $this->service(static fn (): int => 5000);
		$service->save($entryId, 'x');
		self::assertTrue($service->delete($entryId));
		self::assertFalse($service->delete($entryId), 'already deleted');
		self::assertFalse($service->delete('999'), 'no note');
		$tombstone = null;
		foreach ($service->changedSince(0, 1000) as $note) {
			if ($note->entryId === $entryId) {
				$tombstone = $note;
			}
		}
		self::assertNotNull($tombstone);
		self::assertSame(5000, $tombstone->deletedAt);
		self::assertSame(5000, $tombstone->updatedAt);
	}

	public function testEmptyTextDeletesTheNote(): void {
		$entryId = $this->addEntry();
		$service = $this->service();
		$service->save($entryId, 'something');
		self::assertNull($service->save($entryId, " \n\t "));
		self::assertNull($service->get($entryId));
	}

	public function testLengthLimitCountsCharactersNotBytes(): void {
		$entryId = $this->addEntry();
		$service = $this->service();
		$atLimit = str_repeat('é', NoteService::MAX_LENGTH);	// 40,000 bytes
		self::assertSame($atLimit, $service->save($entryId, $atLimit)?->note);
		self::assertSame($atLimit, $service->get($entryId)?->note);

		$emoji = str_repeat('😀', NoteService::MAX_LENGTH);
		self::assertSame($emoji, $service->save($entryId, $emoji)?->note);

		try {
			$service->save($entryId, $atLimit . 'x');
			self::fail('Expected NoteException');
		} catch (NoteException $exception) {
			self::assertSame(NoteException::TOO_LONG, $exception->getCode());
		}
		$stored = $service->get($entryId);
		self::assertSame($emoji, $stored->note, 'rejected save leaves the note unchanged');
	}

	public function testUnknownEntryIsRejected(): void {
		$this->expectException(NoteException::class);
		$this->expectExceptionCode(NoteException::UNKNOWN_ENTRY);
		$this->service()->save('42', 'note');
	}

	public function testListenersAreNotified(): void {
		$entryId = $this->addEntry();
		$service = $this->service();
		$events = [];
		$service->onSaved(static function (ArticleNotes\Note $note) use (&$events): void {
			$events[] = 'saved:' . $note->note;
		});
		$service->onDeleted(static function (ArticleNotes\Note $note) use (&$events): void {
			$events[] = 'deleted:' . ($note->isDeleted() ? 'tombstone' : 'live');
		});
		$service->save($entryId, 'a');
		$service->save($entryId, 'b');
		$service->delete($entryId);
		$service->delete($entryId);
		self::assertSame(['saved:a', 'saved:b', 'deleted:tombstone'], $events);
	}

	public function testSavedListenersAreToldWhetherTheNoteWasCreated(): void {
		$entryId = $this->addEntry();
		$service = $this->service();
		$flags = [];
		$service->onSaved(static function (ArticleNotes\Note $note, bool $created) use (&$flags): void {
			$flags[] = $created;
		});
		$service->save($entryId, 'a');
		$service->save($entryId, 'b');
		$service->delete($entryId);
		$service->save($entryId, 'c');
		self::assertSame([true, false, true], $flags);
	}

	public function testChangedSinceIsOrderedAndIncludesTombstones(): void {
		$time = 1;
		$service = $this->service(static function () use (&$time): int {
			return $time;
		});
		$ids = [$this->addEntry(), $this->addEntry(), $this->addEntry()];
		$base = 9_000_000_000;
		$time = $base + 10;
		$service->save($ids[0], 'a');
		$service->save($ids[1], 'b');	// same second as a: ordered by id
		$time = $base + 20;
		$service->save($ids[2], 'c');
		$time = $base + 30;
		$service->delete($ids[0]);

		$changes = $service->changedSince($base, 100);
		self::assertSame([$ids[1], $ids[2], $ids[0]], array_map(static fn ($n) => $n->entryId, $changes));
		self::assertTrue($changes[2]->isDeleted());

		$sameSecond = $service->changedSince($base + 10, 100);
		self::assertCount(3, $sameSecond);
		self::assertCount(2, $service->changedSince($base + 11, 100));
		self::assertCount(0, $service->changedSince($base + 31, 100));

		$page = $service->changedSince($base, 1);
		self::assertSame([$ids[1]], array_map(static fn ($n) => $n->entryId, $page));
		$next = $service->changedSince($page[0]->updatedAt, 1, $page[0]->id);
		self::assertSame([$ids[2]], array_map(static fn ($n) => $n->entryId, $next));

		$live = $service->changedSince($base, 2, 0, false);
		self::assertSame([$ids[1], $ids[2]], array_map(static fn ($n) => $n->entryId, $live), 'tombstones excluded, page filled');
	}

	public function testExistingEntryIds(): void {
		$service = $this->service();
		$kept = $this->addEntry();
		self::assertSame([$kept], $service->existingEntryIds([$kept, '999999999999999', 'not-a-number']));
		self::assertSame([], $service->existingEntryIds([]));
	}

	public function testCountExcludesTombstones(): void {
		$service = $this->service();
		$before = $service->count();
		$first = $this->addEntry();
		$second = $this->addEntry();
		$service->save($first, 'a');
		$service->save($second, 'b');
		self::assertSame($before + 2, $service->count());
		$service->delete($first);
		self::assertSame($before + 1, $service->count());
	}

	public function testSnapshotSurvivesEntryDeletion(): void {
		$entryId = $this->addEntry('Purged');
		$service = $this->service();
		$service->save($entryId, 'keep me');

		$pdo = (new ArticleNotes\Storage(TestEnvironment::USER))->pdo();
		$statement = $pdo->prepare('DELETE FROM `_entry` WHERE id=:id');
		self::assertNotFalse($statement);
		self::assertTrue($statement->execute([':id' => $entryId]));
		self::assertNull(FreshRSS_Factory::createEntryDao()->searchById($entryId));

		$note = $service->get($entryId);
		self::assertNotNull($note);
		self::assertSame('keep me', $note->note);
		self::assertStringStartsWith('Purged ', $note->entryTitle);
		self::assertSame('guid-' . $entryId, $note->entryGuid);
		self::assertSame('Note Service Feed', $note->feedName);
		self::assertTrue($service->delete($entryId), 'a note can still be deleted after its article is gone');
	}

	public function testNotesAreIsolatedPerUser(): void {
		$entryId = $this->addEntry();
		$this->service()->save($entryId, 'private to A');

		self::assertTrue(FreshRSS_user_Controller::createUser(self::OTHER_USER, null, 'other-password-123', ['language' => 'en'], false));
		self::useUser(self::OTHER_USER);
		$other = $this->service();
		self::assertNull($other->get($entryId));
		self::assertSame(0, $other->count());
		self::assertSame([], $other->changedSince(0, 100));
		try {
			$other->save($entryId, 'intrusion');
			self::fail('User B must not annotate user A\'s article');
		} catch (NoteException $exception) {
			self::assertSame(NoteException::UNKNOWN_ENTRY, $exception->getCode());
		}
		self::assertFalse($other->delete($entryId));

		self::useUser(TestEnvironment::USER);
		self::assertSame('private to A', $this->service()->get($entryId)?->note);
	}

	public function testMigrationIsIdempotent(): void {
		$extension = Minz_ExtensionManager::findExtension('ArticleNotes');
		self::assertInstanceOf(ArticleNotesExtension::class, $extension);
		self::assertTrue($extension->ensureSchema());
		$pdo = (new ArticleNotes\Storage(TestEnvironment::USER))->pdo();
		$migration = require dirname(__DIR__, 2) . '/xExtension-ArticleNotes/Migrations/0002_create_note_table.php';
		self::assertInstanceOf(ArticleNotes\Migration::class, $migration);
		$previous = $pdo->getAttribute(PDO::ATTR_ERRMODE);
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
		$migration->up($pdo, $pdo->prefix(), is_string($driver) ? $driver : '');
		$migration->up($pdo, $pdo->prefix(), is_string($driver) ? $driver : '');
		$pdo->setAttribute(PDO::ATTR_ERRMODE, is_int($previous) ? $previous : PDO::ERRMODE_SILENT);
		self::assertGreaterThanOrEqual(0, (new ArticleNotes\NoteDAO($pdo))->count(true));
	}
}
