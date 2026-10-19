<?php

declare(strict_types=1);

use ArticleNotes\ApiRequest;
use ArticleNotes\ApiResponse;
use ArticleNotes\NoteService;
use ArticleNotesTests\TestEnvironment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApiTest extends TestCase {
	private const USER = 'apiuser';
	private const OTHER = 'apiother';
	private const DISABLED = 'apidisabled';
	private const NO_API_PASSWORD = 'apinopw';
	private const WEB_PASSWORD = 'web-password-123';
	private const API_PASSWORD = 'api-password-456';

	private static int $sequence = 0;
	/** @var array<string,int> feed id per user */
	private static array $feedIds = [];

	#[\Override]
	public static function setUpBeforeClass(): void {
		self::useUser(TestEnvironment::USER);
		$apiHash = FreshRSS_password_Util::hash(self::API_PASSWORD);
		$users = [
			self::USER => ['apiPasswordHash' => $apiHash],
			self::OTHER => ['apiPasswordHash' => $apiHash],
			self::DISABLED => ['apiPasswordHash' => $apiHash, 'enabled' => false],
			self::NO_API_PASSWORD => [],
		];
		foreach ($users as $user => $config) {
			self::assertTrue(FreshRSS_user_Controller::createUser($user, null, self::WEB_PASSWORD, $config, false));
		}
		foreach ([self::USER, self::OTHER] as $user) {
			self::useUser($user);
			$feed = new FreshRSS_Feed('http://fixtures/api-feed-' . $user . '.xml', false);
			$feed->_name('API Feed ' . $user);
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
		foreach ([self::USER, self::OTHER, self::DISABLED, self::NO_API_PASSWORD] as $user) {
			if (FreshRSS_user_Controller::userExists($user)) {
				FreshRSS_user_Controller::deleteUser($user);
			}
		}
	}

	#[\Override]
	protected function tearDown(): void {
		self::useUser(TestEnvironment::USER);
		$_SERVER = array_diff_key($_SERVER, array_flip([
			'REQUEST_METHOD', 'PATH_INFO', 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'PHP_AUTH_USER', 'PHP_AUTH_PW',
		]));
		$_GET = [];
	}

	private static function useUser(string $user): void {
		Minz_User::change($user);
		cliInitUser($user);
	}

	/** A fresh extension instance: extensions cache the user's configuration, and tests switch users in-process. */
	private static function extension(): ArticleNotesExtension {
		$extension = Minz_ExtensionManager::findExtension('ArticleNotes');
		self::assertInstanceOf(ArticleNotesExtension::class, $extension);
		return new ArticleNotesExtension([
			'name' => 'ArticleNotes', 'entrypoint' => 'ArticleNotes', 'path' => $extension->getPath(), 'type' => 'system',
			'version' => $extension->getVersion(),
		]);
	}

	private static function service(string $user, ?Closure $clock = null): NoteService {
		self::useUser($user);
		return new NoteService($user, self::extension()->ensureSchema(...), $clock);
	}

	private static function addEntry(string $user): string {
		self::useUser($user);
		$n = ++self::$sequence;
		$id = (string)(1720000000000000 + $n * 1000 + random_int(0, 999));
		self::assertTrue(FreshRSS_Factory::createEntryDao()->addEntry([
			'id' => $id, 'guid' => 'guid-' . $id, 'title' => 'API article ' . $n, 'author' => 'Author ' . $n,
			'content' => 'Content', 'link' => 'http://fixtures/articles/' . $id, 'date' => 1756717200 + $n, 'lastSeen' => time(),
			'hash' => md5($id), 'is_read' => false, 'is_favorite' => false, 'id_feed' => self::$feedIds[$user], 'tags' => '',
		], false));
		return $id;
	}

	private static function basic(string $user = self::USER, string $password = self::API_PASSWORD): string {
		return 'Basic ' . base64_encode($user . ':' . $password);
	}

	private static function googleLogin(string $user = self::USER, ?string $token = null): string {
		self::useUser($user);
		$token ??= sha1(FreshRSS_Context::systemConf()->salt . $user . FreshRSS_Context::userConf()->apiPasswordHash);
		return 'GoogleLogin auth=' . $user . '/' . $token;
	}

	/** @param array<string,string> $query */
	private static function call(string $path, array $query = [], ?string $authorization = null, string $method = 'GET'): ApiResponse {
		$authorization ??= self::basic();
		self::useUser(TestEnvironment::USER);
		Minz_User::change();	// like misc.php: no user until the API authenticates one
		FreshRSS_Context::clearUserConf();
		$response = self::extension()->api()->handle(new ApiRequest($method, $path, $query, $authorization));
		self::useUser(TestEnvironment::USER);
		return $response;
	}

	/** @return array<string,mixed> */
	private static function json(ApiResponse $response, int $status = 200): array {
		self::assertSame($status, $response->status, (string)json_encode($response->body));
		self::assertSame('application/json; charset=UTF-8', $response->headers['Content-Type']);
		self::assertSame('no-store', $response->headers['Cache-Control']);
		$decoded = json_decode($response->json(), true);
		self::assertIsArray($decoded);
		/** @var array<string,mixed> $decoded */
		return $decoded;
	}

	/** @param array<string,mixed> $body */
	private static function errorCode(array $body): string {
		self::assertIsArray($body['error']);
		self::assertIsString($body['error']['code']);
		self::assertIsString($body['error']['message']);
		self::assertNotSame('', $body['error']['message']);
		return $body['error']['code'];
	}

	/**
	 * @param array<string,mixed> $body
	 * @return list<array<string,mixed>>
	 */
	private static function notes(array $body): array {
		self::assertIsArray($body['notes']);
		/** @var list<array<string,mixed>> $notes */
		$notes = $body['notes'];
		return $notes;
	}

	/**
	 * @param array<string,string> $query
	 * @return list<string> entry ids of every page of `v1/notes`
	 */
	private static function allPages(array $query): array {
		$ids = [];
		$pages = 0;
		do {
			$body = self::json(self::call('v1/notes', $query));
			foreach (self::notes($body) as $note) {
				self::assertIsString($note['entry_id']);
				$ids[] = $note['entry_id'];
			}
			$continuation = $body['continuation'];
			if ($continuation !== null) {
				self::assertIsString($continuation);
				$query['continuation'] = $continuation;
			}
			self::assertLessThan(1000, ++$pages);
		} while ($continuation !== null);
		return $ids;
	}

	public function testBothAuthenticationFormsSucceed(): void {
		foreach ([self::basic(), self::googleLogin()] as $authorization) {
			$body = self::json(self::call('v1/status', [], $authorization));
			self::assertSame('ArticleNotes', $body['extension']);
			self::assertSame(self::extension()->getVersion(), $body['version']);
			self::assertSame(1, $body['api_version']);
			self::assertSame(self::USER, $body['user']);
			self::assertIsInt($body['note_count']);
		}
	}

	/** @return iterable<string,array{0:Closure():string}> */
	public static function badCredentials(): iterable {
		yield 'no credentials' => [static fn (): string => ''];
		yield 'wrong token' => [static fn (): string => self::googleLogin(self::USER, str_repeat('0', 40))];
		yield 'token of another user' => [static fn (): string => 'GoogleLogin auth=' . self::USER . '/'
			. explode('/', self::googleLogin(self::OTHER))[1]];
		yield 'malformed GoogleLogin' => [static fn (): string => 'GoogleLogin auth=' . self::USER];
		yield 'invalid user name' => [static fn (): string => self::basic('../etc', self::API_PASSWORD)];
		yield 'unknown user' => [static fn (): string => self::basic('nosuchuser', self::API_PASSWORD)];
		yield 'wrong API password' => [static fn (): string => self::basic(self::USER, 'wrong')];
		yield 'web password' => [static fn (): string => self::basic(self::USER, self::WEB_PASSWORD)];
		yield 'malformed Basic' => [static fn (): string => 'Basic !!!'];
		yield 'unknown scheme' => [static fn (): string => 'Bearer ' . self::API_PASSWORD];
		yield 'disabled user (Basic)' => [static fn (): string => self::basic(self::DISABLED)];
		yield 'disabled user (GoogleLogin)' => [static fn (): string => self::googleLogin(self::DISABLED)];
		yield 'no API password (Basic)' => [static fn (): string => self::basic(self::NO_API_PASSWORD, '')];
		yield 'no API password (GoogleLogin)' => [static fn (): string => self::googleLogin(self::NO_API_PASSWORD)];
	}

	/** @param Closure():string $authorization */
	#[DataProvider('badCredentials')]
	public function testBadCredentialsAreRejected(Closure $authorization): void {
		$response = self::call('v1/status', [], $authorization());
		self::assertSame('unauthorized', self::errorCode(self::json($response, 401)));
		self::assertSame('Basic realm="ArticleNotes"', $response->headers['WWW-Authenticate']);
	}

	public function testFailuresAreLoggedWithoutSecrets(): void {
		@unlink(API_LOG);
		self::call('v1/status', [], self::basic(self::USER, 'secret-guess-789'));
		$log = (string)@file_get_contents(API_LOG);
		self::assertStringContainsString(self::USER, $log);
		self::assertStringNotContainsString('secret-guess-789', $log);
	}

	public function testNoteObject(): void {
		$entryId = self::addEntry(self::USER);
		self::service(self::USER, static fn (): int => 1756800000)->save($entryId, "<b>note</b> ü\nline");
		$body = self::json(self::call('v1/notes/' . $entryId));
		self::assertIsArray($body['note']);
		$note = $body['note'];
		self::assertIsInt($note['id']);
		self::assertSame($entryId, $note['entry_id']);
		self::assertSame('tag:google.com,2005:reader/item/' . str_pad(dechex((int)$entryId), 16, '0', STR_PAD_LEFT),
			$note['greader_item_id']);
		self::assertSame("<b>note</b> ü\nline", $note['note']);
		self::assertSame(1756800000, $note['created_at']);
		self::assertSame('2025-09-02T08:00:00+00:00', $note['created_at_iso']);
		self::assertSame(1756800000, $note['updated_at']);
		self::assertSame('2025-09-02T08:00:00+00:00', $note['updated_at_iso']);
		self::assertNull($note['deleted_at']);
		self::assertNull($note['deleted_at_iso']);
		self::assertFalse($note['label_applied']);
		self::assertIsArray($note['article']);
		$article = $note['article'];
		self::assertSame('guid-' . $entryId, $article['guid']);
		self::assertSame('http://fixtures/articles/' . $entryId, $article['url']);
		self::assertIsString($article['title']);
		self::assertStringStartsWith('API article ', $article['title']);
		self::assertIsString($article['author']);
		self::assertStringStartsWith('Author ', $article['author']);
		self::assertIsInt($article['published_at']);
		self::assertIsString($article['published_at_iso']);
		self::assertSame(['id' => self::$feedIds[self::USER], 'name' => 'API Feed ' . self::USER,
			'url' => 'http://fixtures/api-feed-' . self::USER . '.xml'], $article['feed']);
		self::assertTrue($article['exists']);

		self::useUser(self::USER);
		$pdo = (new ArticleNotes\Storage(self::USER))->pdo();
		$pdo->exec('DELETE FROM ' . $pdo->prefix() . 'entry WHERE id=' . (int)$entryId);
		$note = self::json(self::call('v1/notes/' . $entryId))['note'];
		self::assertIsArray($note);
		self::assertIsArray($note['article']);
		self::assertFalse($note['article']['exists'], 'purged article');
		self::assertSame('guid-' . $entryId, $note['article']['guid'], 'snapshot survives the purge');
	}

	public function testSingleNoteNotFound(): void {
		$entryId = self::addEntry(self::USER);
		self::assertSame('not_found', self::errorCode(self::json(self::call('v1/notes/' . $entryId), 404)));
		self::service(self::USER)->save($entryId, 'soon gone');
		self::service(self::USER)->delete($entryId);
		self::assertSame('not_found', self::errorCode(self::json(self::call('v1/notes/' . $entryId), 404)), 'tombstone');
	}

	public function testUsersOnlySeeTheirOwnNotes(): void {
		$mine = self::addEntry(self::USER);
		self::service(self::USER)->save($mine, 'mine');
		$theirs = self::addEntry(self::OTHER);
		self::service(self::OTHER)->save($theirs, 'theirs');

		$other = self::basic(self::OTHER);
		self::assertSame('not_found', self::errorCode(self::json(self::call('v1/notes/' . $mine, [], $other), 404)));
		$ids = array_column(self::notes(self::json(self::call('v1/notes', ['limit' => '500'], $other))), 'entry_id');
		self::assertContains($theirs, $ids);
		self::assertNotContains($mine, $ids);
		$status = self::json(self::call('v1/status', [], $other));
		self::assertSame(self::OTHER, $status['user']);
		self::assertSame(self::service(self::OTHER)->count(), $status['note_count']);
		self::assertSame(self::service(self::USER)->count(), self::json(self::call('v1/status'))['note_count']);
	}

	public function testPaginationCoversEveryNoteExactlyOnce(): void {
		$service = self::service(self::USER, static fn (): int => 1700000000);	// same updated_at: ordered by id
		for ($i = 0; $i < 5; $i++) {
			$service->save(self::addEntry(self::USER), 'page note ' . $i);
		}
		$expected = array_map(static fn (ArticleNotes\Note $note): string => $note->entryId,
			array_values(array_filter(self::service(self::USER)->changedSince(0, 100000),
				static fn (ArticleNotes\Note $note): bool => !$note->isDeleted())));
		self::assertGreaterThanOrEqual(5, count($expected));

		self::assertSame($expected, self::allPages(['limit' => '2']));
		self::assertSame($expected, self::allPages(['limit' => '1']));
		self::assertSame($expected, self::allPages([]));

		$first = self::json(self::call('v1/notes', ['limit' => (string)count($expected)]));
		self::assertNull($first['continuation'], 'no continuation once everything is returned');
		self::assertIsInt($first['server_time']);
		self::assertEqualsWithDelta(time(), $first['server_time'], 5);
	}

	public function testSinceReturnsUpdatesAndTombstones(): void {
		$base = 9000000000;
		$a = self::addEntry(self::USER);
		$b = self::addEntry(self::USER);
		$c = self::addEntry(self::USER);
		self::service(self::USER, static fn (): int => $base + 10)->save($a, 'a');
		self::service(self::USER, static fn (): int => $base + 10)->save($b, 'b');
		self::service(self::USER, static fn (): int => $base + 20)->save($c, 'c');
		self::service(self::USER, static fn (): int => $base + 30)->save($b, 'b edited');
		self::service(self::USER, static fn (): int => $base + 40)->delete($a);

		$notes = self::notes(self::json(self::call('v1/notes', ['since' => (string)($base + 20)])));
		self::assertSame([$c, $b, $a], array_column($notes, 'entry_id'));
		self::assertSame(['c', 'b edited', null], array_column($notes, 'note'));
		self::assertSame([null, null, $base + 40], array_column($notes, 'deleted_at'));
		self::assertSame($base + 40, $notes[2]['updated_at']);

		$live = self::notes(self::json(self::call('v1/notes', ['since' => (string)($base + 20), 'include_deleted' => '0'])));
		self::assertSame([$c, $b], array_column($live, 'entry_id'));

		self::assertSame([$a], array_column(self::notes(self::json(self::call('v1/notes', ['since' => (string)($base + 31)]))),
			'entry_id'));
		self::assertSame([], self::notes(self::json(self::call('v1/notes', ['since' => (string)($base + 41)]))));

		$withoutSince = array_column(self::notes(self::json(self::call('v1/notes', ['limit' => '500']))), 'entry_id');
		self::assertNotContains($a, $withoutSince, 'no tombstones by default without since');
		$withTombstones = self::allPages(['include_deleted' => '1', 'limit' => '2']);
		self::assertContains($a, $withTombstones, 'the continuation keeps include_deleted');
	}

	/** @return iterable<string,array{0:array<string,string>}> */
	public static function invalidQueries(): iterable {
		yield 'limit 0' => [['limit' => '0']];
		yield 'limit 501' => [['limit' => '501']];
		yield 'limit text' => [['limit' => 'ten']];
		yield 'negative since' => [['since' => '-1']];
		yield 'since text' => [['since' => 'yesterday']];
		yield 'include_deleted 2' => [['include_deleted' => '2']];
		yield 'bad continuation' => [['continuation' => 'garbage']];
	}

	/** @param array<string,string> $query */
	#[DataProvider('invalidQueries')]
	public function testInvalidParametersAreRejected(array $query): void {
		self::assertSame('invalid_parameter', self::errorCode(self::json(self::call('v1/notes', $query), 400)));
	}

	/** @return iterable<string,array{0:string}> */
	public static function unknownPaths(): iterable {
		foreach (['', 'v1', 'v2/notes', 'v1/note', 'v1/notes/abc', 'v1/notes/1/2', 'v1/status/x', 'notes'] as $path) {
			yield '"' . $path . '"' => [$path];
		}
	}

	#[DataProvider('unknownPaths')]
	public function testUnknownPathsAreNotFound(string $path): void {
		self::assertSame('not_found', self::errorCode(self::json(self::call($path), 404)));
		self::assertSame('not_found', self::errorCode(self::json(self::call($path, [], ''), 404)), 'even unauthenticated');
	}

	public function testOnlyGetAndHeadAreAllowed(): void {
		foreach (['POST', 'PUT', 'DELETE', 'PATCH'] as $method) {
			$response = self::call('v1/notes', [], null, $method);
			self::assertSame('method_not_allowed', self::errorCode(self::json($response, 405)));
			self::assertSame('GET, HEAD', $response->headers['Allow']);
		}
		$head = self::call('v1/status', [], null, 'HEAD');
		self::assertSame(200, $head->status);
		self::assertSame('application/json; charset=UTF-8', $head->headers['Content-Type']);
	}

	public function testTrailingSlashIsAccepted(): void {
		self::assertSame(self::USER, self::json(self::call('v1/status/'))['user']);
	}

	public function testRequestFromGlobals(): void {
		$_SERVER['REQUEST_METHOD'] = 'HEAD';
		$_SERVER['PATH_INFO'] = '/ArticleNotes/v1/notes/12';
		$_SERVER['HTTP_AUTHORIZATION'] = 'GoogleLogin auth=a/b';
		$_GET = ['limit' => '5', 'ext' => 'ArticleNotes'];
		$request = ApiRequest::fromGlobals('ArticleNotes');
		self::assertSame('HEAD', $request->method);
		self::assertSame('v1/notes/12', $request->path);
		self::assertSame('5', $request->query['limit']);
		self::assertSame('GoogleLogin auth=a/b', $request->authorization);

		// ?ext=ArticleNotes with the API path in PATH_INFO, credentials decoded by the web server
		$_SERVER['PATH_INFO'] = '/v1/status';
		unset($_SERVER['HTTP_AUTHORIZATION']);
		$_SERVER['PHP_AUTH_USER'] = 'alice';
		$_SERVER['PHP_AUTH_PW'] = 'pw:with:colons';
		$request = ApiRequest::fromGlobals('ArticleNotes');
		self::assertSame('v1/status', $request->path);
		self::assertSame('Basic ' . base64_encode('alice:pw:with:colons'), $request->authorization);
	}

	public function testHookHandlerAnswersWithJson(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['PATH_INFO'] = '/ArticleNotes/v1/status';
		$_SERVER['HTTP_AUTHORIZATION'] = self::basic();
		Minz_User::change();	// like misc.php: no user until the API authenticates one
		FreshRSS_Context::clearUserConf();
		ob_start();
		self::extension()->handleApi();
		$output = (string)ob_get_clean();
		$body = json_decode($output, true);
		self::assertIsArray($body, $output);
		self::assertSame(self::USER, $body['user']);
	}
}
