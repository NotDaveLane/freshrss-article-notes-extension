<?php

declare(strict_types=1);

use ArticleNotesTests\TestEnvironment;
use PHPUnit\Framework\TestCase;

/** The Notes page actions: list, edit in place and export. */
final class NotesPageControllerTest extends TestCase {
	private const TOKEN = 'test-csrf-token';

	private static int $feedId = 0;
	private static int $sequence = 0;

	#[\Override]
	public static function setUpBeforeClass(): void {
		require_once dirname(__DIR__, 2) . '/xExtension-ArticleNotes/Controllers/ArticleNotesController.php';
		Minz_User::change(TestEnvironment::USER);
		cliInitUser(TestEnvironment::USER);
		$feed = new FreshRSS_Feed('http://fixtures/notes-page-feed.xml', false);
		$feed->_name('Notes Page Feed');
		$feed->_categoryId(FreshRSS_CategoryDAO::DEFAULTCATEGORYID);
		$id = FreshRSS_Factory::createFeedDao()->addFeedObject($feed);
		self::assertIsInt($id);
		self::$feedId = $id;
	}

	#[\Override]
	public static function tearDownAfterClass(): void {
		FreshRSS_Factory::createFeedDao()->deleteFeed(self::$feedId);
	}

	#[\Override]
	protected function setUp(): void {
		Minz_User::change(TestEnvironment::USER);
		cliInitUser(TestEnvironment::USER);
		Minz_Translate::registerPath(dirname(__DIR__, 2) . '/xExtension-ArticleNotes/i18n');
		Minz_Translate::reset('en');
		Minz_Session::_param('passwordHash', FreshRSS_Context::userConf()->passwordHash);
		Minz_Session::_param('csrf', self::TOKEN);
		self::assertTrue(FreshRSS_Auth::giveAccess());
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = ['_csrf' => self::TOKEN];
		Minz_Request::_params([]);
	}

	#[\Override]
	protected function tearDown(): void {
		unset($_SERVER['REQUEST_METHOD']);
		$_POST = [];
		Minz_Request::_params([]);
	}

	private function addEntry(): string {
		$id = (string)(1733000000000000 + (++self::$sequence) * 1000 + random_int(0, 999));
		self::assertTrue(FreshRSS_Factory::createEntryDao()->addEntry([
			'id' => $id, 'guid' => 'guid-' . $id, 'title' => 'Title ' . $id, 'author' => 'A',
			'content' => 'Content', 'link' => 'http://fixtures/np-articles/' . $id, 'date' => 1756717200, 'lastSeen' => time(),
			'hash' => md5($id), 'is_read' => false, 'is_favorite' => false, 'id_feed' => self::$feedId, 'tags' => '',
		], false));
		return $id;
	}

	/** @param array<string,string> $params */
	private function request(array $params): void {
		Minz_Request::_params($params);
	}

	private static function extension(): ArticleNotesExtension {
		$extension = Minz_ExtensionManager::findExtension('ArticleNotes');
		self::assertInstanceOf(ArticleNotesExtension::class, $extension);
		return $extension;
	}

	private function noteOf(string $entryId): ?ArticleNotes\Note {
		return self::extension()->noteService()->get($entryId);
	}

	public function testEditsAnArticleNote(): void {
		$entryId = $this->addEntry();
		self::extension()->noteService()->save($entryId, 'first');
		$controller = new FreshExtension_ArticleNotes_Controller();

		$this->request(['type' => 'article', 'id' => $entryId, 'op' => 'save', 'note' => '  <i>second</i> ']);
		self::assertNull($controller->notesEdit());
		self::assertSame('<i>second</i>', $this->noteOf($entryId)?->note);

		$this->request(['type' => 'article', 'id' => $entryId, 'op' => 'delete']);
		self::assertNull($controller->notesEdit());
		self::assertNull($this->noteOf($entryId));
	}

	public function testRejectsTooLongNotes(): void {
		$entryId = $this->addEntry();
		self::extension()->noteService()->save($entryId, 'first');
		$controller = new FreshExtension_ArticleNotes_Controller();
		$this->request(['type' => 'article', 'id' => $entryId, 'op' => 'save', 'note' => str_repeat('x', 20001)]);
		self::assertStringContainsString('too long', (string)$controller->notesEdit());
		self::assertSame('first', $this->noteOf($entryId)?->note);
	}

	public function testEditsAPageNoteAndKeepsItsTitle(): void {
		$service = self::extension()->noteService();
		$service->savePageNote('https://example.org/np-page', 'page note', 'Kept title');
		$controller = new FreshExtension_ArticleNotes_Controller();

		$this->request(['type' => 'page', 'url' => 'https://example.org/np-page', 'op' => 'save', 'note' => 'changed']);
		self::assertNull($controller->notesEdit());
		$note = $service->getPageNote('https://example.org/np-page');
		self::assertNotNull($note);
		self::assertSame('changed', $note->note);
		self::assertSame('Kept title', $note->entryTitle);

		$this->request(['type' => 'page', 'url' => 'https://example.org/np-page', 'op' => 'delete']);
		self::assertNull($controller->notesEdit());
		self::assertNull($service->getPageNote('https://example.org/np-page'));
		self::assertNotNull($controller->notesEdit(), 'a note that is gone is reported');
	}

	public function testEditsAndDeletesAHighlight(): void {
		$entryId = $this->addEntry();
		$service = self::extension()->highlightService();
		$highlight = $service->create(['entry_id' => $entryId, 'source' => 'freshrss', 'selector' => ['quote' => ['exact' => 'a passage']]])['highlight'];
		$controller = new FreshExtension_ArticleNotes_Controller();

		$this->request(['type' => 'highlight', 'uuid' => $highlight->uuid, 'op' => 'save', 'note' => '<b>n</b>', 'color' => 'purple']);
		self::assertNull($controller->notesEdit());
		$changed = $service->get($highlight->uuid);
		self::assertNotNull($changed);
		self::assertSame('<b>n</b>', $changed->note);
		self::assertSame('purple', $changed->color);

		$this->request(['type' => 'highlight', 'uuid' => $highlight->uuid, 'op' => 'save', 'note' => '', 'color' => 'chartreuse']);
		self::assertStringContainsString('color', (string)$controller->notesEdit());
		self::assertSame('purple', $service->get($highlight->uuid)?->color);

		$this->request(['type' => 'highlight', 'uuid' => $highlight->uuid, 'op' => 'delete']);
		self::assertNull($controller->notesEdit());
		self::assertNull($service->get($highlight->uuid));
		self::assertNotNull($controller->notesEdit());
	}

	public function testEditsNeedTheCsrfTokenAndPost(): void {
		$entryId = $this->addEntry();
		self::extension()->noteService()->save($entryId, 'first');
		$controller = new FreshExtension_ArticleNotes_Controller();
		$this->request(['type' => 'article', 'id' => $entryId, 'op' => 'delete']);

		$_POST = [];
		self::assertStringContainsString('security token', (string)$controller->notesEdit());
		$_POST = ['_csrf' => self::TOKEN];
		$_SERVER['REQUEST_METHOD'] = 'GET';
		self::assertStringContainsString('security token', (string)$controller->notesEdit());
		self::assertSame('first', $this->noteOf($entryId)?->note);
	}

	public function testRejectsUnknownTypes(): void {
		$controller = new FreshExtension_ArticleNotes_Controller();
		$this->request(['type' => 'bogus', 'op' => 'delete']);
		self::assertSame('Invalid request.', $controller->notesEdit());
		$this->request(['type' => 'article', 'id' => 'abc', 'op' => 'delete']);
		self::assertNotNull($controller->notesEdit());
	}

	public function testListsTheFilteredPage(): void {
		$entryId = $this->addEntry();
		self::extension()->noteService()->save($entryId, 'findable-needle-text');
		$controller = new FreshExtension_ArticleNotes_Controller();
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$this->request(['q' => 'findable-needle', 'kind' => 'article', 'page' => '9']);
		$page = $controller->notesPage();
		self::assertSame(1, $page->page, 'a page past the end shows the last one');
		self::assertSame(1, $page->total);
		self::assertSame($entryId, $page->groups[0]->entryId);
		self::assertSame(['q' => 'findable-needle', 'kind' => 'article'], $page->filter->toParams());
	}

	public function testExports(): void {
		$entryId = $this->addEntry();
		self::extension()->noteService()->save($entryId, 'exportable-needle');
		$controller = new FreshExtension_ArticleNotes_Controller();
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$this->request(['q' => 'exportable-needle']);
		$markdown = $controller->export();
		self::assertStringStartsWith('text/markdown', $markdown['type']);
		self::assertMatchesRegularExpression('/^articlenotes-\d{4}-\d\d-\d\d\.md$/', $markdown['filename']);
		self::assertStringContainsString("# ArticleNotes\n", $markdown['body']);
		self::assertStringContainsString('exportable-needle', $markdown['body']);
		self::assertStringContainsString('Title ' . $entryId, $markdown['body']);

		$this->request(['q' => 'exportable-needle', 'format' => 'json']);
		$json = $controller->export();
		self::assertStringContainsString('application/ld+json', $json['type']);
		self::assertStringEndsWith('.jsonld', $json['filename']);
		$decoded = json_decode($json['body'], true);
		self::assertIsArray($decoded);
		self::assertSame(1, $decoded['total']);

		$this->request(['q' => 'no-such-text-anywhere', 'format' => 'json']);
		$empty = json_decode($controller->export()['body'], true);
		self::assertIsArray($empty);
		self::assertSame(0, $empty['total']);
	}
}
