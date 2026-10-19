<?php

declare(strict_types=1);

use ArticleNotes\Settings;
use ArticleNotesTests\TestEnvironment;
use PHPUnit\Framework\TestCase;

final class SettingsAndLabelTest extends TestCase {
	private const OTHER_USER = 'settingsother';
	private const TOKEN = 'settings-csrf-token';

	private static int $feedId = 0;
	private static int $sequence = 0;

	#[\Override]
	public static function setUpBeforeClass(): void {
		require_once dirname(__DIR__, 2) . '/xExtension-ArticleNotes/Controllers/ArticleNotesController.php';
		self::useUser(TestEnvironment::USER);
		$feed = new FreshRSS_Feed('http://fixtures/settings-feed.xml', false);
		$feed->_name('Settings Feed');
		$feed->_categoryId(FreshRSS_CategoryDAO::DEFAULTCATEGORYID);
		$id = FreshRSS_Factory::createFeedDao()->addFeedObject($feed);
		self::assertIsInt($id);
		self::$feedId = $id;
	}

	#[\Override]
	public static function tearDownAfterClass(): void {
		self::useUser(TestEnvironment::USER);
		FreshRSS_Factory::createFeedDao()->deleteFeed(self::$feedId);
		if (FreshRSS_user_Controller::userExists(self::OTHER_USER)) {
			FreshRSS_user_Controller::deleteUser(self::OTHER_USER);
		}
	}

	#[\Override]
	protected function setUp(): void {
		self::useUser(TestEnvironment::USER);
		Minz_Translate::registerPath(dirname(__DIR__, 2) . '/xExtension-ArticleNotes/i18n');
		Minz_Translate::reset('en');
		$this->settings()->save(true, Settings::DEFAULT_LABEL_NAME);
	}

	#[\Override]
	protected function tearDown(): void {
		unset($_SERVER['REQUEST_METHOD']);
		$_POST = [];
		Minz_Request::_params([]);
	}

	private static function useUser(string $user): void {
		Minz_User::change($user);
		cliInitUser($user);
	}

	/** A fresh extension instance: extensions cache the user's configuration, and tests switch users in-process. */
	private function extension(): ArticleNotesExtension {
		$loaded = Minz_ExtensionManager::findExtension('ArticleNotes');
		self::assertInstanceOf(ArticleNotesExtension::class, $loaded);
		return new ArticleNotesExtension([
			'name' => 'ArticleNotes', 'entrypoint' => 'ArticleNotes', 'path' => $loaded->getPath(), 'type' => 'system',
		]);
	}

	private function settings(): Settings {
		return $this->extension()->settings();
	}

	private function addEntry(): string {
		$id = (string)(1720000000000000 + (++self::$sequence) * 1000 + random_int(0, 999));
		self::assertTrue(FreshRSS_Factory::createEntryDao()->addEntry([
			'id' => $id, 'guid' => 'guid-' . $id, 'title' => 'Title ' . $id, 'author' => 'A',
			'content' => 'Content', 'link' => 'http://fixtures/articles/' . $id, 'date' => 1756717200, 'lastSeen' => time(),
			'hash' => md5($id), 'is_read' => false, 'is_favorite' => false, 'id_feed' => self::$feedId, 'tags' => '',
		], false));
		return $id;
	}

	/** @return list<string> names of the labels on the article */
	private function labelsOf(string $entryId): array {
		$names = [];
		foreach (FreshRSS_Factory::createTagDao()->getTagsForEntry($entryId) as $tag) {
			if ($tag['checked']) {
				$names[] = $tag['name'];
			}
		}
		return $names;
	}

	private function labelId(string $name): int {
		$tag = FreshRSS_Factory::createTagDao()->searchByName($name);
		self::assertNotNull($tag);
		return $tag->id();
	}

	public function testLabelIsOnByDefaultAndNamedAnnotated(): void {
		$settings = new Settings(static fn (): mixed => null, static function (): void {
		});
		self::assertTrue($settings->labelEnabled());
		self::assertSame('Annotated', $settings->labelName());
	}

	public function testFirstNoteCreatesTheLabelAndAppliesIt(): void {
		$name = 'Annotated first ' . random_int(1000, 9999);
		$this->settings()->save(true, $name);
		self::assertNull(FreshRSS_Factory::createTagDao()->searchByName($name));
		$entryId = $this->addEntry();
		$note = $this->extension()->noteService()->save($entryId, 'hello');
		self::assertSame([$name], $this->labelsOf($entryId));
		self::assertTrue($note?->labelApplied);
		self::assertSame($this->labelId($name), $this->extension()->noteService()->get($entryId)?->labelId);
	}

	public function testExistingLabelIsReused(): void {
		$name = 'Reused ' . random_int(1000, 9999);
		$this->settings()->save(true, $name);
		$service = $this->extension()->noteService();
		$service->save($this->addEntry(), 'one');
		$id = $this->labelId($name);
		$service->save($this->addEntry(), 'two');
		self::assertSame($id, $this->labelId($name));
		self::assertSame(2, FreshRSS_Factory::createTagDao()->countEntries($id));
	}

	public function testPreExistingUserLabelIsNotRemovedOnDelete(): void {
		$name = 'Mine ' . random_int(1000, 9999);
		$this->settings()->save(true, $name);
		$tags = FreshRSS_Factory::createTagDao();
		$tagId = $tags->addTag(['name' => $name]);
		self::assertIsInt($tagId);
		$entryId = $this->addEntry();
		self::assertTrue($tags->tagEntry($tagId, $entryId));
		$service = $this->extension()->noteService();
		$note = $service->save($entryId, 'note');
		self::assertFalse($note?->labelApplied);
		$service->delete($entryId);
		self::assertSame([$name], $this->labelsOf($entryId));
	}

	public function testLabelAppliedByTheExtensionIsRemovedOnDelete(): void {
		$name = 'Removable ' . random_int(1000, 9999);
		$this->settings()->save(true, $name);
		$service = $this->extension()->noteService();
		$entryId = $this->addEntry();
		$service->save($entryId, 'note');
		self::assertSame([$name], $this->labelsOf($entryId));
		$service->delete($entryId);
		self::assertSame([], $this->labelsOf($entryId));
		// A revived note is labelled again, and the label can be taken back again.
		self::assertTrue($service->save($entryId, 'again')?->labelApplied);
		self::assertSame([$name], $this->labelsOf($entryId));
		$service->save($entryId, '');
		self::assertSame([], $this->labelsOf($entryId));
	}

	public function testRenamingAffectsOnlyFutureNotes(): void {
		$old = 'Old name ' . random_int(1000, 9999);
		$new = 'New name ' . random_int(1000, 9999);
		$this->settings()->save(true, $old);
		$first = $this->addEntry();
		$this->extension()->noteService()->save($first, 'a');
		$this->settings()->save(true, $new);
		$second = $this->addEntry();
		$this->extension()->noteService()->save($second, 'b');
		self::assertSame([$old], $this->labelsOf($first));
		self::assertSame([$new], $this->labelsOf($second));
		$this->extension()->noteService()->delete($first);
		self::assertSame([], $this->labelsOf($first), 'the label applied back then is the one removed');
	}

	public function testSettingOffMeansNoLabel(): void {
		$this->settings()->save(false, 'Never applied ' . random_int(1000, 9999));
		$entryId = $this->addEntry();
		$note = $this->extension()->noteService()->save($entryId, 'note');
		self::assertSame([], $this->labelsOf($entryId));
		self::assertFalse($note?->labelApplied);
		self::assertFalse($this->settings()->labelEnabled());
	}

	public function testTurningTheSettingOffKeepsExistingLabels(): void {
		$name = 'Kept ' . random_int(1000, 9999);
		$this->settings()->save(true, $name);
		$entryId = $this->addEntry();
		$this->extension()->noteService()->save($entryId, 'note');
		$this->settings()->save(false, $name);
		$this->extension()->noteService()->save($entryId, 'edited');
		self::assertSame([$name], $this->labelsOf($entryId));
		$this->extension()->noteService()->delete($entryId);
		self::assertSame([], $this->labelsOf($entryId));
	}

	public function testEditingAnExistingNoteDoesNotRelabel(): void {
		$name = 'Edit ' . random_int(1000, 9999);
		$this->settings()->save(true, $name);
		$entryId = $this->addEntry();
		$service = $this->extension()->noteService();
		$service->save($entryId, 'a');
		FreshRSS_Factory::createTagDao()->tagEntry($this->labelId($name), $entryId, false);
		$service->save($entryId, 'b');
		self::assertSame([], $this->labelsOf($entryId), 'the user removed the label; editing does not bring it back');
	}

	/** @return array<string,array{string}> */
	public static function invalidNames(): array {
		return [
			'empty' => [''],
			'blank' => ["  \t "],
			'too long' => [str_repeat('é', 64)],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('invalidNames')]
	public function testInvalidLabelNamesAreRejectedAndNothingIsStored(string $name): void {
		$settings = $this->settings();
		$settings->save(true, 'Valid name');
		$this->expectException(InvalidArgumentException::class);
		try {
			$settings->save(false, $name);
		} finally {
			self::assertSame('Valid name', $settings->labelName());
			self::assertTrue($settings->labelEnabled());
		}
	}

	public function testNameIsTrimmedAndBoundariesAccepted(): void {
		$settings = $this->settings();
		$settings->save(true, "  Padded  ");
		self::assertSame('Padded', $settings->labelName());
		$settings->save(true, str_repeat('é', 63));
		self::assertSame(str_repeat('é', 63), $settings->labelName());
		$settings->save(true, 'x');
		self::assertSame('x', $settings->labelName());
	}

	public function testSettingsArePerUser(): void {
		$this->settings()->save(false, 'Mine alone');
		if (!FreshRSS_user_Controller::userExists(self::OTHER_USER)) {
			self::assertTrue(FreshRSS_user_Controller::createUser(self::OTHER_USER, null, 'settings-other-password', ['language' => 'en']));
		}
		self::useUser(self::OTHER_USER);
		self::assertTrue($this->settings()->labelEnabled());
		self::assertSame('Annotated', $this->settings()->labelName());
		$this->settings()->save(true, 'Theirs');
		self::useUser(TestEnvironment::USER);
		self::assertFalse($this->settings()->labelEnabled());
		self::assertSame('Mine alone', $this->settings()->labelName());
	}

	private function post(bool $withToken, string $name, bool $enabled): ?string {
		Minz_Session::_param('passwordHash', FreshRSS_Context::userConf()->passwordHash);
		Minz_Session::_param('csrf', self::TOKEN);
		self::assertTrue(FreshRSS_Auth::giveAccess());
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = $withToken ? ['_csrf' => self::TOKEN] : [];
		Minz_Request::_params(['label_name' => $name] + ($enabled ? ['label_enabled' => '1'] : []));
		return (new FreshExtension_ArticleNotes_Controller())->saveSettings();
	}

	public function testSavingRequiresTheCsrfToken(): void {
		self::assertStringContainsString('security token', (string)$this->post(false, 'Nope', false));
		self::assertSame('Annotated', $this->settings()->labelName());
		self::assertTrue($this->settings()->labelEnabled());
	}

	public function testSavingStoresValidSettingsAndRejectsInvalidOnes(): void {
		self::assertNull($this->post(true, '  Reading list ', false));
		self::assertSame('Reading list', $this->settings()->labelName());
		self::assertFalse($this->settings()->labelEnabled());
		self::assertStringContainsString('1 to 63', (string)$this->post(true, '', true));
		self::assertSame('Reading list', $this->settings()->labelName());
	}

	public function testMenuEntryLinksToTheSettingsPage(): void {
		$html = $this->extension()->menuEntry();
		self::assertStringContainsString('c=ArticleNotes', $html);
		self::assertStringContainsString('c=ArticleNotes&amp;a=settings"', $html);
		self::assertStringContainsString('Article notes', $html);
	}

	public function testApiUrlEndsWithTheExtensionEndpoint(): void {
		self::assertStringEndsWith('/api/misc.php/ArticleNotes/', ArticleNotesExtension::apiUrl());
	}

	public function testLabelIdMigrationIsIdempotent(): void {
		self::assertTrue($this->extension()->ensureSchema());
		$pdo = (new ArticleNotes\Storage(TestEnvironment::USER))->pdo();
		// Re-apply 0003 (the column exists already) twice, the way the Migrator runs migrations.
		$migrator = new ArticleNotes\Migrator(dirname(__DIR__, 2) . '/xExtension-ArticleNotes/Migrations', $pdo,
			static fn (): int => 2, static function (int $version): void {
			});
		self::assertGreaterThanOrEqual(1, $migrator->migrate());
		self::assertGreaterThanOrEqual(1, $migrator->migrate());
		self::assertGreaterThanOrEqual(0, (new ArticleNotes\NoteDAO($pdo))->count(true));
	}
}
