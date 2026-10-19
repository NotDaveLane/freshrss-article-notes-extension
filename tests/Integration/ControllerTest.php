<?php

declare(strict_types=1);

use ArticleNotes\NoteService;
use ArticleNotesTests\TestEnvironment;
use PHPUnit\Framework\TestCase;

final class ControllerTest extends TestCase {
	private const TOKEN = 'test-csrf-token';

	private static int $feedId = 0;
	private static int $sequence = 0;

	#[\Override]
	public static function setUpBeforeClass(): void {
		require_once dirname(__DIR__, 2) . '/xExtension-ArticleNotes/Controllers/ArticleNotesController.php';
		Minz_User::change(TestEnvironment::USER);
		cliInitUser(TestEnvironment::USER);
		$feed = new FreshRSS_Feed('http://fixtures/controller-feed.xml', false);
		$feed->_name('Controller Feed');
		$feed->_categoryId(FreshRSS_CategoryDAO::DEFAULTCATEGORYID);
		$id = FreshRSS_Factory::createFeedDao()->addFeedObject($feed);
		self::assertIsInt($id);
		self::$feedId = $id;
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
		$id = (string)(1710000000000000 + (++self::$sequence) * 1000 + random_int(0, 999));
		self::assertTrue(FreshRSS_Factory::createEntryDao()->addEntry([
			'id' => $id, 'guid' => 'guid-' . $id, 'title' => 'Title ' . $id, 'author' => 'A',
			'content' => 'Content', 'link' => 'http://fixtures/articles/' . $id, 'date' => 1756717200, 'lastSeen' => time(),
			'hash' => md5($id), 'is_read' => false, 'is_favorite' => false, 'id_feed' => self::$feedId, 'tags' => '',
		], false));
		return $id;
	}

	private function noteService(): NoteService {
		$extension = Minz_ExtensionManager::findExtension('ArticleNotes');
		self::assertInstanceOf(ArticleNotesExtension::class, $extension);
		return $extension->noteService();
	}

	/** @return list<array{status:int,body:array<string,mixed>}> */
	private function bothActions(): array {
		$controller = new FreshExtension_ArticleNotes_Controller();
		return [$controller->save(), $controller->delete()];
	}

	/** @param array{status:int,body:array<string,mixed>} $response */
	private static function errorOf(array $response): string {
		self::assertIsString($response['body']['error']);
		return $response['body']['error'];
	}

	/** @param array<string,string> $params */
	private function request(array $params): void {
		Minz_Request::_params($params);
	}

	public function testSaveStoresTheNoteAndReturnsTheRenderedBlock(): void {
		$entryId = $this->addEntry();
		$this->request(['id' => $entryId, 'note' => "<script>alert(1)</script>\nsecond line"]);
		$response = (new FreshExtension_ArticleNotes_Controller())->save();
		self::assertSame(200, $response['status']);
		self::assertTrue($response['body']['ok']);
		$html = $response['body']['html'];
		self::assertIsString($html);
		self::assertStringContainsString('Your note', $html);
		self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;<br>', $html);
		self::assertStringNotContainsString('<script>', $html);
		self::assertSame("<script>alert(1)</script>\nsecond line", $this->noteService()->get($entryId)?->note);
	}

	public function testSavingEmptyTextDeletesTheNote(): void {
		$entryId = $this->addEntry();
		$this->noteService()->save($entryId, 'something');
		$this->request(['id' => $entryId, 'note' => "  \n"]);
		$response = (new FreshExtension_ArticleNotes_Controller())->save();
		self::assertTrue($response['body']['ok']);
		self::assertSame('', $response['body']['html']);
		self::assertNull($this->noteService()->get($entryId));
	}

	public function testDeleteTombstonesTheNote(): void {
		$entryId = $this->addEntry();
		$this->noteService()->save($entryId, 'something');
		$this->request(['id' => $entryId]);
		$response = (new FreshExtension_ArticleNotes_Controller())->delete();
		self::assertSame(200, $response['status']);
		self::assertSame('', $response['body']['html']);
		self::assertNull($this->noteService()->get($entryId));
	}

	public function testCsrfTokenIsRequired(): void {
		$entryId = $this->addEntry();
		$this->request(['id' => $entryId, 'note' => 'nope']);
		foreach ([[], ['_csrf' => 'wrong']] as $post) {
			$_POST = $post;
			foreach ($this->bothActions() as $response) {
				self::assertSame(403, $response['status']);
				self::assertFalse($response['body']['ok']);
				self::assertStringContainsString('security token', self::errorOf($response));
			}
		}
		self::assertNull($this->noteService()->get($entryId));
	}

	public function testSignedOutRequestsAreRefused(): void {
		$entryId = $this->addEntry();
		FreshRSS_Auth::removeAccess();
		$this->request(['id' => $entryId, 'note' => 'nope']);
		foreach ($this->bothActions() as $response) {
			self::assertSame(403, $response['status']);
			self::assertStringContainsString('signed in', self::errorOf($response));
		}
		self::assertNull($this->noteService()->get($entryId));
	}

	public function testOnlyPostIsAccepted(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$this->request(['id' => '1', 'note' => 'x']);
		self::assertSame(405, (new FreshExtension_ArticleNotes_Controller())->save()['status']);
	}

	public function testTooLongNoteIsMappedToAMessage(): void {
		$entryId = $this->addEntry();
		$this->request(['id' => $entryId, 'note' => str_repeat('a', NoteService::MAX_LENGTH + 1)]);
		$response = (new FreshExtension_ArticleNotes_Controller())->save();
		self::assertSame(422, $response['status']);
		self::assertSame('The note is too long (20,000 characters at most).', $response['body']['error']);
		self::assertNull($this->noteService()->get($entryId));
	}

	public function testUnknownArticleIsMappedToAMessage(): void {
		foreach (['999999999999999', 'abc', ''] as $id) {
			$this->request(['id' => $id, 'note' => 'x']);
			$response = (new FreshExtension_ArticleNotes_Controller())->save();
			self::assertSame(404, $response['status']);
			self::assertSame('This article no longer exists.', $response['body']['error']);
		}
	}
}
