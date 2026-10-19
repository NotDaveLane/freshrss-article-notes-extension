<?php

declare(strict_types=1);

use ArticleNotes\ApiRequest;
use ArticleNotes\ApiResponse;
use ArticleNotes\NoteException;
use ArticleNotes\NoteService;
use ArticleNotesTests\TestEnvironment;
use PHPUnit\Framework\TestCase;

/** Page notes: notes on web pages that are not FreshRSS articles (service and API). */
final class PageNotesTest extends TestCase {
	private const USER = 'pageuser';
	private const OTHER = 'pageother';
	private const WEB_PASSWORD = 'web-password-123';
	private const API_PASSWORD = 'api-password-456';

	private static int $sequence = 0;
	/** @var array<string,int> */
	private static array $feedIds = [];

	#[\Override]
	public static function setUpBeforeClass(): void {
		self::useUser(TestEnvironment::USER);
		$apiHash = FreshRSS_password_Util::hash(self::API_PASSWORD);
		foreach ([self::USER, self::OTHER] as $user) {
			self::assertTrue(FreshRSS_user_Controller::createUser($user, null, self::WEB_PASSWORD,
				['apiPasswordHash' => $apiHash, 'language' => 'en'], false));
			self::useUser($user);
			$feed = new FreshRSS_Feed('http://fixtures/page-feed-' . $user . '.xml', false);
			$feed->_name('Page Feed ' . $user);
			$feed->_categoryId(FreshRSS_CategoryDAO::DEFAULTCATEGORYID);
			$id = FreshRSS_Factory::createFeedDao()->addFeedObject($feed);
			self::assertIsInt($id);
			self::$feedIds[$user] = $id;
		}
		self::useUser(TestEnvironment::USER);
	}

	#[\Override]
	public static function tearDownAfterClass(): void {
		self::useUser(TestEnvironment::USER);
		foreach ([self::USER, self::OTHER] as $user) {
			if (FreshRSS_user_Controller::userExists($user)) {
				FreshRSS_user_Controller::deleteUser($user);
			}
		}
	}

	#[\Override]
	protected function tearDown(): void {
		self::useUser(TestEnvironment::USER);
	}

	private static function useUser(string $user): void {
		Minz_User::change($user);
		cliInitUser($user);
	}

	private static function extension(): ArticleNotesExtension {
		$extension = Minz_ExtensionManager::findExtension('ArticleNotes');
		self::assertInstanceOf(ArticleNotesExtension::class, $extension);
		return new ArticleNotesExtension([
			'name' => 'ArticleNotes', 'entrypoint' => 'ArticleNotes', 'path' => $extension->getPath(), 'type' => 'system',
			'version' => $extension->getVersion(),
		]);
	}

	private static function service(string $user = self::USER, ?Closure $clock = null): NoteService {
		self::useUser($user);
		return new NoteService($user, self::extension()->ensureSchema(...), $clock);
	}

	private static function addEntry(string $user, string $link): string {
		self::useUser($user);
		$n = ++self::$sequence;
		$id = (string)(1731000000000000 + $n * 1000 + random_int(0, 999));
		self::assertTrue(FreshRSS_Factory::createEntryDao()->addEntry([
			'id' => $id, 'guid' => 'page-guid-' . $id, 'title' => 'Page article ' . $n, 'author' => 'Author',
			'content' => 'Content', 'link' => $link, 'date' => 1756717200 + $n, 'lastSeen' => time(),
			'hash' => md5($id), 'is_read' => false, 'is_favorite' => false, 'id_feed' => self::$feedIds[$user], 'tags' => '',
		], false));
		return $id;
	}

	private static function basic(string $user = self::USER): string {
		return 'Basic ' . base64_encode($user . ':' . self::API_PASSWORD);
	}

	/** @param array<string,string> $query */
	private static function call(
		string $path, string $method = 'GET', array $query = [], ?string $body = null, ?string $user = null,
		string $contentType = 'application/json', ?string $ifMatch = null,
	): ApiResponse {
		self::useUser(TestEnvironment::USER);
		Minz_User::change();
		FreshRSS_Context::clearUserConf();
		$response = self::extension()->api()->handle(new ApiRequest($method, $path, $query, self::basic($user ?? self::USER),
			$body ?? '', $body === null ? '' : $contentType, $ifMatch));
		self::useUser(TestEnvironment::USER);
		return $response;
	}

	/** @param array<string,mixed> $data */
	private static function putPage(string $url, array $data, ?string $user = null, ?string $ifMatch = null): ApiResponse {
		return self::call('v1/page-notes', 'PUT', ['url' => $url], (string)json_encode($data), $user, ifMatch: $ifMatch);
	}

	/** @return array<string,mixed> */
	private static function json(ApiResponse $response, int $status = 200): array {
		self::assertSame($status, $response->status, (string)json_encode($response->body));
		$decoded = json_decode($response->json(), true);
		self::assertIsArray($decoded);
		/** @var array<string,mixed> $decoded */
		return $decoded;
	}

	/**
	 * @param array<mixed> $body
	 * @return array<string,mixed>
	 */
	private static function noteOf(array $body, string $key = 'note'): array {
		self::assertIsArray($body[$key]);
		/** @var array<string,mixed> $note */
		$note = $body[$key];
		return $note;
	}

	/** @param array<string,mixed> $body */
	private static function errorCode(array $body): string {
		self::assertIsArray($body['error']);
		self::assertIsString($body['error']['code']);
		return $body['error']['code'];
	}

	// --- service ---

	public function testSaveUpdateAndDeleteAPageNote(): void {
		$now = 1800000000;
		$service = self::service(clock: static function () use (&$now): int {
			return $now;
		});
		$note = $service->savePageNote('https://www.example.org/page/one/?utm_source=x#top', '  first  ', 'The title');
		self::assertNotNull($note);
		self::assertNull($note->entryId);
		self::assertTrue($note->isPageNote());
		self::assertFalse($note->labelApplied);
		self::assertSame('first', $note->note);
		self::assertSame('The title', $note->entryTitle);
		self::assertSame('https://example.org/page/one', $note->normalizedUrl);
		self::assertSame('https://www.example.org/page/one/?utm_source=x#top', $note->url());

		$same = $service->getPageNote('https://example.org/page/one');
		self::assertSame($note->id, $same?->id, 'any form of the URL finds it');

		$updated = $service->savePageNote('https://example.org/page/one', 'second');
		self::assertSame($note->id, $updated?->id);
		self::assertSame('second', $updated->note);
		self::assertGreaterThan($note->updatedAt, $updated->updatedAt, 'strictly increasing');
		self::assertSame($note->createdAt, $updated->createdAt);

		self::assertTrue($service->deletePageNote('https://example.org/page/one'));
		self::assertNull($service->getPageNote('https://example.org/page/one'));
		self::assertFalse($service->deletePageNote('https://example.org/page/one'), 'already deleted');

		$revived = $service->savePageNote('https://example.org/page/one', 'third');
		self::assertSame($note->id, $revived?->id, 'the tombstone is revived, not duplicated');
		self::assertNull($revived->deletedAt);
		$service->deletePageNote('https://example.org/page/one');
	}

	public function testEmptyTextDeletesAndValidationFails(): void {
		$service = self::service();
		$service->savePageNote('https://example.org/page/empty', 'x');
		self::assertNull($service->savePageNote('https://example.org/page/empty', "  \n"));
		self::assertNull($service->getPageNote('https://example.org/page/empty'));

		foreach ([
			[NoteException::TOO_LONG, static fn () => $service->savePageNote('https://example.org/page/long', str_repeat('é', 20001))],
			[NoteException::TITLE_TOO_LONG, static fn () => $service->savePageNote('https://example.org/page/long', 'x', str_repeat('t', 501))],
			[NoteException::INVALID_URL, static fn () => $service->savePageNote('ftp://example.org/a', 'x')],
			] as [$code, $call]) {
			try {
				$call();
				self::fail('expected a NoteException');
			} catch (NoteException $exception) {
				self::assertSame($code, $exception->getCode());
			}
		}
		self::assertNotNull($service->savePageNote('https://example.org/page/max', str_repeat('é', 20000), str_repeat('t', 500)));
		$service->deletePageNote('https://example.org/page/max');
	}

	public function testRefusesAnArticleUrlAndPointsToTheArticle(): void {
		$entryId = self::addEntry(self::USER, 'https://example.org/page/article');
		$service = self::service();
		try {
			$service->savePageNote('http://www.example.org/page/article/?utm_medium=y', 'nope');
			self::fail('expected a NoteException');
		} catch (NoteException $exception) {
			self::assertSame(NoteException::URL_IS_ARTICLE, $exception->getCode());
			self::assertSame($entryId, $exception->entryId);
		}
		self::assertNull($service->getPageNote('https://example.org/page/article'));
	}

	public function testPageNotesNeverGetALabel(): void {
		$service = self::service();
		(new ArticleNotes\LabelApplier(self::USER, new ArticleNotes\Settings(static fn (): mixed => null, static function (): void {
		})))->listen($service);
		$note = $service->savePageNote('https://example.org/page/label', 'x');
		self::assertNotNull($note);
		self::assertFalse($note->labelApplied);
		self::assertNull(FreshRSS_Factory::createTagDao(self::USER)->searchByName('Annotated'));
		$service->deletePageNote('https://example.org/page/label');
	}

	// --- API ---

	public function testPageNoteEndpoints(): void {
		$url = 'https://example.org/api/page/one';
		self::assertSame('not_found', self::errorCode(self::json(self::call('v1/page-notes', 'GET', ['url' => $url]), 404)));

		$created = self::putPage($url, ['note' => 'hello <b>', 'title' => 'Page one']);
		$body = self::json($created, 201);
		$note = self::noteOf($body);
		self::assertSame('page', $note['kind']);
		self::assertNull($note['entry_id']);
		self::assertNull($note['article']);
		self::assertNull($note['greader_item_id']);
		self::assertSame($url, $note['url']);
		self::assertSame($url, $note['normalized_url']);
		self::assertSame('Page one', $note['title']);
		self::assertSame('hello <b>', $note['note']);
		$etag = $created->headers['ETag'] ?? '';
		self::assertNotSame('', $etag);

		$read = self::call('v1/page-notes', 'GET', ['url' => $url . '/?utm_source=z']);
		self::assertSame(['note' => $note], self::json($read));

		$updated = self::putPage($url, ['note' => 'changed'], ifMatch: $etag);
		self::assertSame('changed', self::noteOf(self::json($updated), 'note')['note']);
		self::assertSame('', self::noteOf(self::json($updated), 'note')['title'] ?? '', 'a title is replaced by the new body');

		self::assertSame('precondition_failed', self::errorCode(self::json(self::putPage($url, ['note' => 'x'], ifMatch: $etag), 412)));
		self::assertSame('precondition_failed', self::errorCode(self::json(
			self::call('v1/page-notes', 'DELETE', ['url' => $url], ifMatch: $etag), 412)));
		self::assertSame(204, self::call('v1/page-notes', 'DELETE', ['url' => $url])->status);
		self::assertSame('not_found', self::errorCode(self::json(self::call('v1/page-notes', 'DELETE', ['url' => $url]), 404)));
		self::assertSame('not_found', self::errorCode(self::json(self::call('v1/page-notes', 'GET', ['url' => $url]), 404)));
		self::assertSame(204, self::putPage($url, ['note' => ' '])->status, 'empty text on nothing');
	}

	public function testBodyAndParameterErrors(): void {
		$url = 'https://example.org/api/page/errors';
		self::assertSame('invalid_parameter', self::errorCode(self::json(self::call('v1/page-notes', 'GET'), 400)));
		self::assertSame('invalid_parameter', self::errorCode(self::json(self::putPage('ftp://x/a', ['note' => 'x']), 400)));
		self::assertSame('unsupported_media_type', self::errorCode(self::json(
			self::call('v1/page-notes', 'PUT', ['url' => $url], 'x', contentType: 'text/plain'), 415)));
		self::assertSame('payload_too_large', self::errorCode(self::json(
			self::putPage($url, ['note' => str_repeat('x', 70000)]), 413)));
		self::assertSame('invalid_json', self::errorCode(self::json(self::call('v1/page-notes', 'PUT', ['url' => $url], '{nope'), 400)));
		self::assertSame('invalid_body', self::errorCode(self::json(self::putPage($url, ['title' => 'x']), 400)));
		self::assertSame('invalid_body', self::errorCode(self::json(self::putPage($url, ['note' => 'x', 'title' => 5]), 400)));
		self::assertSame('note_too_long', self::errorCode(self::json(self::putPage($url, ['note' => str_repeat('x', 20001)]), 422)));
		self::assertSame('title_too_long', self::errorCode(self::json(self::putPage($url, ['note' => 'x', 'title' => str_repeat('x', 501)]), 422)));
		$post = self::call('v1/page-notes', 'POST', ['url' => $url]);
		self::assertSame(405, $post->status);
		self::assertSame('GET, HEAD, PUT, DELETE', $post->headers['Allow'] ?? '');
	}

	public function testArticleUrlIsRefusedWithAPointerToTheArticle(): void {
		$entryId = self::addEntry(self::USER, 'https://example.org/api/page/is-article');
		$body = self::json(self::putPage('https://example.org/api/page/is-article', ['note' => 'x']), 409);
		self::assertSame('url_is_article', self::errorCode($body));
		self::assertIsArray($body['error']);
		self::assertIsString($body['error']['message']);
		self::assertStringContainsString('v1/notes/' . $entryId, $body['error']['message']);
	}

	public function testLookupReturnsTheArticleAndThePageNote(): void {
		$url = 'https://example.org/api/page/lookup';
		self::assertNull(self::json(self::call('v1/lookup', 'GET', ['url' => $url]))['page_note']);

		self::putPage($url, ['note' => 'page note']);
		$entryId = self::addEntry(self::USER, $url);	// the article arrives later: both stay
		self::json(self::call('v1/notes/' . $entryId, 'PUT', [], (string)json_encode(['note' => 'article note'])), 201);

		$body = self::json(self::call('v1/lookup', 'GET', ['url' => $url]));
		self::assertSame('page note', self::noteOf($body, 'page_note')['note']);
		self::assertIsArray($body['matches']);
		self::assertCount(1, $body['matches']);
		self::assertIsArray($body['matches'][0]);
		self::assertSame($entryId, $body['matches'][0]['entry_id']);
		self::assertSame('article note', self::noteOf($body['matches'][0])['note']);

		self::assertNull(self::json(self::call('v1/lookup', 'GET', ['url' => $url], user: self::OTHER))['page_note'], 'other users see nothing');
	}

	public function testListingIncludesPageNotesWithKindAndTombstones(): void {
		$url = 'https://example.org/api/page/list';
		$entryId = self::addEntry(self::USER, 'https://example.org/api/page/list-article');
		self::json(self::call('v1/notes/' . $entryId, 'PUT', [], (string)json_encode(['note' => 'a'])), 201);
		self::putPage($url, ['note' => 'p', 'title' => 'T']);
		self::call('v1/page-notes', 'DELETE', ['url' => $url]);
		self::putPage($url . '/2', ['note' => 'p2']);

		$body = self::json(self::call('v1/notes', 'GET', ['since' => '0', 'limit' => '500']));
		self::assertIsArray($body['notes']);
		$byKind = ['article' => [], 'page' => []];
		foreach ($body['notes'] as $note) {
			self::assertIsArray($note);
			self::assertIsString($note['kind']);
			$byKind[$note['kind']][] = $note;
		}
		self::assertNotEmpty($byKind['article']);
		$page = array_values(array_filter($byKind['page'], static fn (array $n): bool => $n['url'] === $url));
		self::assertCount(1, $page);
		self::assertNotNull($page[0]['deleted_at'], 'tombstone listed');
		self::assertNull($page[0]['note']);
		self::assertNull($page[0]['entry_id']);
		self::assertNull($page[0]['article']);
		foreach ($byKind['article'] as $note) {
			self::assertIsArray($note['article']);
		}

		self::assertSame([], array_filter(
			self::noteList(self::json(self::call('v1/notes', 'GET', ['since' => '0'], user: self::OTHER))),
			static fn (array $n): bool => $n['kind'] === 'page'));
		self::call('v1/page-notes', 'DELETE', ['url' => $url . '/2']);
	}

	/**
	 * @param array<string,mixed> $body
	 * @return list<array<string,mixed>>
	 */
	private static function noteList(array $body): array {
		self::assertIsArray($body['notes']);
		/** @var list<array<string,mixed>> $notes */
		$notes = $body['notes'];
		return $notes;
	}

	public function testPageNotesAreIsolatedBetweenUsers(): void {
		$url = 'https://example.org/api/page/isolated';
		self::putPage($url, ['note' => 'mine']);
		self::json(self::call('v1/page-notes', 'GET', ['url' => $url]));
		self::assertSame(404, self::call('v1/page-notes', 'GET', ['url' => $url], user: self::OTHER)->status);
		self::assertSame(404, self::call('v1/page-notes', 'DELETE', ['url' => $url], user: self::OTHER)->status);
		self::json(self::putPage($url, ['note' => 'theirs'], self::OTHER), 201);
		self::assertSame('mine', self::noteOf(self::json(self::call('v1/page-notes', 'GET', ['url' => $url])))['note']);
		self::call('v1/page-notes', 'DELETE', ['url' => $url]);
		self::call('v1/page-notes', 'DELETE', ['url' => $url], user: self::OTHER);
	}
}
