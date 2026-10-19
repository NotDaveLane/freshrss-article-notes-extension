<?php

declare(strict_types=1);

use ArticleNotesTests\TestEnvironment;
use PHPUnit\Framework\TestCase;

/** The FreshRSS-side highlight actions (used by the article UI, no API password). */
final class HighlightControllerTest extends TestCase {
	private const TOKEN = 'test-csrf-token';

	private static int $feedId = 0;
	private static int $sequence = 0;

	#[\Override]
	public static function setUpBeforeClass(): void {
		require_once dirname(__DIR__, 2) . '/xExtension-ArticleNotes/Controllers/ArticleNotesController.php';
		Minz_User::change(TestEnvironment::USER);
		cliInitUser(TestEnvironment::USER);
		$feed = new FreshRSS_Feed('http://fixtures/hl-controller-feed.xml', false);
		$feed->_name('Highlight Controller Feed');
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
			'content' => 'Content', 'link' => 'http://fixtures/hl-articles/' . $id, 'date' => 1756717200, 'lastSeen' => time(),
			'hash' => md5($id), 'is_read' => false, 'is_favorite' => false, 'id_feed' => self::$feedId, 'tags' => '',
		], false));
		return $id;
	}

	/** @param array<string,string> $params */
	private function request(array $params): void {
		Minz_Request::_params($params);
	}

	/** @return array<string,string> */
	private static function createParams(string $entryId): array {
		return ['entry_id' => $entryId, 'source' => 'freshrss', 'selector' => '{"quote":{"exact":"<b>passage</b>"},"position":{"start":1,"end":8}}',
			'color' => 'pink', 'note' => '<script>x</script>'];
	}

	/**
	 * @param array{status:int,body:array<string,mixed>} $response
	 * @return array<string,mixed>
	 */
	private static function highlightOf(array $response): array {
		self::assertTrue($response['body']['ok'], (string)json_encode($response));
		self::assertIsArray($response['body']['highlight']);
		/** @var array<string,mixed> $highlight */
		$highlight = $response['body']['highlight'];
		return $highlight;
	}

	public function testCreateReadUpdateAndDelete(): void {
		$entryId = $this->addEntry();
		$controller = new FreshExtension_ArticleNotes_Controller();
		$this->request(self::createParams($entryId));
		$created = $controller->highlightCreate();
		self::assertSame(201, $created['status']);
		$highlight = self::highlightOf($created);
		self::assertSame('pink', $highlight['color']);
		self::assertSame('<script>x</script>', $highlight['note'], 'stored as entered, escaped on rendering');
		self::assertSame(['quote' => ['exact' => '<b>passage</b>'], 'position' => ['start' => 1, 'end' => 8]], $highlight['selector']);
		$uuid = $highlight['uuid'];
		self::assertIsString($uuid);

		// Retrying with the uuid is idempotent.
		$this->request(self::createParams($entryId) + ['uuid' => $uuid]);
		$again = $controller->highlightCreate();
		self::assertSame(200, $again['status']);
		self::assertSame($highlight['id'], self::highlightOf($again)['id']);

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$this->request(['entry_id' => $entryId]);
		$read = $controller->highlights();
		self::assertSame(200, $read['status']);
		self::assertIsArray($read['body']['highlights']);
		self::assertCount(1, $read['body']['highlights']);
		$_SERVER['REQUEST_METHOD'] = 'POST';

		$this->request(['uuid' => $uuid, 'color' => 'blue', 'note' => 'changed']);
		$updated = self::highlightOf($controller->highlightUpdate());
		self::assertSame('blue', $updated['color']);
		self::assertSame('changed', $updated['note']);

		$this->request(['uuid' => $uuid]);
		self::assertSame(['status' => 200, 'body' => ['ok' => true]], $controller->highlightDelete());
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$this->request(['entry_id' => $entryId]);
		self::assertSame([], $controller->highlights()['body']['highlights']);
	}

	public function testWritesRequireTheCsrfToken(): void {
		$entryId = $this->addEntry();
		$controller = new FreshExtension_ArticleNotes_Controller();
		$_POST = [];
		$this->request(self::createParams($entryId) + ['uuid' => '00000000-0000-4000-8000-000000000000']);
		foreach ([$controller->highlightCreate(), $controller->highlightUpdate(), $controller->highlightDelete()] as $response) {
			self::assertSame(403, $response['status']);
			self::assertFalse($response['body']['ok']);
		}
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$this->request(['entry_id' => $entryId]);
		self::assertSame([], $controller->highlights()['body']['highlights'], 'nothing was stored');
	}

	public function testWritesRefuseGet(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$controller = new FreshExtension_ArticleNotes_Controller();
		$this->request(self::createParams($this->addEntry()));
		self::assertSame(405, $controller->highlightCreate()['status']);
		self::assertSame(405, $controller->highlightUpdate()['status']);
		self::assertSame(405, $controller->highlightDelete()['status']);
	}

	public function testSignedOutIsRefused(): void {
		$entryId = $this->addEntry();
		FreshRSS_Auth::removeAccess();
		self::assertFalse(FreshRSS_Auth::hasAccess());
		$controller = new FreshExtension_ArticleNotes_Controller();
		$this->request(self::createParams($entryId));
		self::assertSame(403, $controller->highlightCreate()['status']);
		self::assertSame(403, $controller->highlightUpdate()['status']);
		self::assertSame(403, $controller->highlightDelete()['status']);
		$_SERVER['REQUEST_METHOD'] = 'GET';
		self::assertSame(403, $controller->highlights()['status']);
	}

	public function testInvalidInputGetsTranslatedErrors(): void {
		$controller = new FreshExtension_ArticleNotes_Controller();
		$entryId = $this->addEntry();
		$this->request(['selector' => '{"quote":{"exact":""}}'] + self::createParams($entryId));
		$response = $controller->highlightCreate();
		self::assertSame(422, $response['status']);
		self::assertSame('invalid_selector', $response['body']['code']);
		self::assertSame('The selected passage is invalid (1 to 2,000 characters).', $response['body']['error']);

		$this->request(['selector' => 'not json'] + self::createParams($entryId));
		self::assertSame(422, $controller->highlightCreate()['status']);
		$this->request(['entry_id' => '1'] + self::createParams($entryId));
		self::assertSame(404, $controller->highlightCreate()['status']);
		$this->request(['uuid' => '10000000-0000-4000-8000-000000000000', 'selector' => '{}']);
		self::assertSame(422, $controller->highlightUpdate()['status']);
		$this->request(['uuid' => '10000000-0000-4000-8000-000000000000']);
		self::assertSame(400, $controller->highlightUpdate()['status']);
		$this->request(['uuid' => '10000000-0000-4000-8000-000000000000', 'note' => 'x']);
		self::assertSame(404, $controller->highlightUpdate()['status']);
	}
}
