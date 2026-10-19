<?php

declare(strict_types=1);

use ArticleNotes\ApiRequest;
use ArticleNotes\ApiResponse;
use ArticleNotes\NoteService;
use ArticleNotes\Storage;
use ArticleNotes\UrlIndex;
use ArticleNotes\UrlNormalizer;
use ArticleNotesTests\TestEnvironment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** URL lookup, the URL index and the note write endpoints. */
final class UrlLookupApiTest extends TestCase {
	private const USER = 'urluser';
	private const OTHER = 'urlother';
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
		}
		foreach ([self::USER, self::OTHER, TestEnvironment::USER] as $user) {
			self::useUser($user);
			$feed = new FreshRSS_Feed('http://fixtures/url-feed-' . $user . '.xml', false);
			$feed->_name('URL Feed ' . $user);
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

	private static function service(string $user): NoteService {
		self::useUser($user);
		return new NoteService($user, self::extension()->ensureSchema(...));
	}

	private static function index(string $user): UrlIndex {
		self::useUser($user);
		self::extension()->ensureSchema();
		return new UrlIndex((new Storage($user))->pdo());
	}

	/** Adds an article the way FreshRSS stores it (no index row yet). */
	private static function addEntry(string $user, string $link, ?string $guid = null, int $date = 0): string {
		self::useUser($user);
		$n = ++self::$sequence;
		$id = (string)(1730000000000000 + $n * 1000 + random_int(0, 999));
		self::assertTrue(FreshRSS_Factory::createEntryDao()->addEntry([
			'id' => $id, 'guid' => $guid ?? 'guid-' . $id, 'title' => 'URL article ' . $n, 'author' => 'Author',
			'content' => 'Content', 'link' => $link, 'date' => $date > 0 ? $date : 1756717200 + $n, 'lastSeen' => time(),
			'hash' => md5($id), 'is_read' => false, 'is_favorite' => false, 'id_feed' => self::$feedIds[$user], 'tags' => '',
		], false));
		return $id;
	}

	private static function purge(string $user, string $entryId): void {
		self::useUser($user);
		$pdo = (new Storage($user))->pdo();
		$pdo->exec('DELETE FROM ' . $pdo->prefix() . 'entry WHERE id=' . (int)$entryId);
	}

	private static function basic(string $user = self::USER): string {
		return 'Basic ' . base64_encode($user . ':' . self::API_PASSWORD);
	}

	/** @param array<string,string> $query */
	private static function call(
		string $path, string $method = 'GET', array $query = [], ?string $body = null, ?string $authorization = null,
		string $contentType = 'application/json', ?string $ifMatch = null,
	): ApiResponse {
		self::useUser(TestEnvironment::USER);
		Minz_User::change();
		FreshRSS_Context::clearUserConf();
		$response = self::extension()->api()->handle(new ApiRequest($method, $path, $query, $authorization ?? self::basic(),
			$body ?? '', $body === null ? '' : $contentType, $ifMatch));
		self::useUser(TestEnvironment::USER);
		return $response;
	}

	private static function put(string $entryId, string $note, ?string $ifMatch = null, ?string $user = null): ApiResponse {
		$body = (string)json_encode(['note' => $note], JSON_UNESCAPED_UNICODE);
		return self::call('v1/notes/' . $entryId, 'PUT', [], $body, $user === null ? null : self::basic($user), ifMatch: $ifMatch);
	}

	/** @return array<string,mixed> */
	private static function json(ApiResponse $response, int $status = 200): array {
		self::assertSame($status, $response->status, (string)json_encode($response->body));
		$decoded = json_decode($response->json(), true);
		self::assertIsArray($decoded);
		/** @var array<string,mixed> $decoded */
		return $decoded;
	}

	/** @param array<string,mixed> $body */
	private static function errorCode(array $body): string {
		self::assertIsArray($body['error']);
		self::assertIsString($body['error']['code']);
		return $body['error']['code'];
	}

	/**
	 * @param array<string,mixed> $body
	 * @return list<array<string,mixed>>
	 */
	private static function matchList(array $body): array {
		self::assertIsArray($body['matches']);
		/** @var list<array<string,mixed>> $matches */
		$matches = $body['matches'];
		return $matches;
	}

	private static function intValue(mixed $value): int {
		self::assertIsInt($value);
		return $value;
	}

	/** @return list<string> */
	private static function lookupIds(string $url, string $user = self::USER): array {
		$body = self::json(self::call('v1/lookup', 'GET', ['url' => $url], authorization: self::basic($user)));
		$ids = [];
		foreach (self::matchList($body) as $match) {
			self::assertIsString($match['entry_id']);
			$ids[] = $match['entry_id'];
		}
		return $ids;
	}

	// --- URL index ---

	public function testBackfillIndexesEntriesAndReportsCompleteness(): void {
		$index = self::index(self::USER);
		$index->backfill(100000);	// whatever other tests left
		$entryId = self::addEntry(self::USER, 'https://example.org/index/1', 'https://example.org/guid/1');
		self::assertFalse($index->isComplete());
		self::assertSame(1, $index->backfill(10));
		self::assertTrue($index->isComplete());
		$hash = UrlNormalizer::hash('https://example.org/index/1');
		self::assertSame([$entryId], array_column($index->find($hash, 5), 'entry_id'));
		self::assertSame([$entryId], array_column($index->find(UrlNormalizer::hash('https://example.org/guid/1'), 5), 'entry_id'), 'GUID URL');
		self::assertSame(0, $index->backfill(10), 'nothing left to index');
	}

	public function testBackfillIsBoundedAndNewestFirst(): void {
		$index = self::index(self::USER);
		$index->backfill(100000);
		$first = self::addEntry(self::USER, 'https://example.org/bounded/1');
		$second = self::addEntry(self::USER, 'https://example.org/bounded/2');
		self::assertSame(1, $index->backfill(1));
		self::assertSame([$second], array_column($index->find(UrlNormalizer::hash('https://example.org/bounded/2'), 5), 'entry_id'));
		self::assertSame([], $index->find(UrlNormalizer::hash('https://example.org/bounded/1'), 5));
		self::assertFalse($index->isComplete());
		$index->backfill(10);
		self::assertSame([$first], array_column($index->find(UrlNormalizer::hash('https://example.org/bounded/1'), 5), 'entry_id'));
	}

	public function testEntriesWithoutUrlAreIndexedOnlyOnce(): void {
		$index = self::index(self::USER);
		$index->backfill(100000);
		self::addEntry(self::USER, '', 'tag:example.org,2025:no-url');
		self::assertSame(1, $index->backfill(10));
		self::assertTrue($index->isComplete());
		self::assertSame(0, $index->backfill(10));
	}

	public function testPruneRemovesPurgedEntries(): void {
		$index = self::index(self::USER);
		$index->backfill(100000);
		$entryId = self::addEntry(self::USER, 'https://example.org/prune/1');
		$index->backfill(10);
		self::purge(self::USER, $entryId);
		self::assertGreaterThanOrEqual(1, $index->prune());
		self::assertSame([], $index->find(UrlNormalizer::hash('https://example.org/prune/1'), 5));
		self::assertSame(0, $index->prune());
	}

	public function testMaintenanceHookIndexesAndPrunes(): void {
		self::useUser(TestEnvironment::USER);
		$extension = self::extension();
		$extension->maintain();
		$entryId = self::addEntry(TestEnvironment::USER, 'https://example.org/maintenance/1');
		$extension->maintain();
		$index = new UrlIndex((new Storage(TestEnvironment::USER))->pdo());
		self::assertSame([$entryId], array_column($index->find(UrlNormalizer::hash('https://example.org/maintenance/1'), 5), 'entry_id'));
		self::purge(TestEnvironment::USER, $entryId);
		$extension->maintain();
		self::assertSame([], $index->find(UrlNormalizer::hash('https://example.org/maintenance/1'), 5));
	}

	public function testUpdatedEntryIsReindexedWithItsNewLink(): void {
		$index = self::index(self::USER);
		$entryId = self::addEntry(self::USER, 'https://example.org/old-link', 'update-guid-1');
		$index->backfill(100000);
		$entry = FreshRSS_Factory::createEntryDao()->searchById($entryId);
		self::assertNotNull($entry);
		self::extension()->entryBeforeUpdate($entry);
		self::assertSame([], $index->find(UrlNormalizer::hash('https://example.org/old-link'), 5));
		$pdo = (new Storage(self::USER))->pdo();
		$pdo->exec('UPDATE ' . $pdo->prefix() . "entry SET link='https://example.org/new-link' WHERE id=" . (int)$entryId);
		self::assertSame([$entryId], self::lookupIds('https://example.org/new-link'), 'lazy backfill on lookup');
		self::assertSame([], self::lookupIds('https://example.org/old-link'));
	}

	public function testStaleRowsAreNeverReturned(): void {
		$index = self::index(self::USER);
		$entryId = self::addEntry(self::USER, 'https://example.org/stale-before');
		$index->backfill(100000);
		$pdo = (new Storage(self::USER))->pdo();	// link changed behind the index's back
		$pdo->exec('UPDATE ' . $pdo->prefix() . "entry SET link='https://example.org/stale-after' WHERE id=" . (int)$entryId);
		self::assertSame([], self::lookupIds('https://example.org/stale-before'));
		self::assertSame([$entryId], self::lookupIds('https://example.org/stale-after'));
	}

	// --- lookup ---

	public function testLookupFindsAnArticleByVariantUrls(): void {
		$entryId = self::addEntry(self::USER, 'https://www.example.org/lookup/post/?utm_source=feed#top');
		foreach ([
			'https://example.org/lookup/post',
			'http://example.org/lookup/post/',
			'HTTP://WWW.EXAMPLE.ORG:80/lookup/post?fbclid=abc&utm_medium=x#frag',
			] as $variant) {
			self::assertSame([$entryId], self::lookupIds($variant), $variant);
		}
		self::assertSame([], self::lookupIds('https://example.org/lookup/other'));
	}

	public function testLookupResponseShape(): void {
		$entryId = self::addEntry(self::USER, 'https://example.org/shape/1', date: 1756717200);
		$url = 'http://www.example.org/shape/1/?utm_source=x';
		$body = self::json(self::call('v1/lookup', 'GET', ['url' => $url]));
		self::assertSame($url, $body['url']);
		self::assertSame('https://example.org/shape/1', $body['normalized_url']);
		self::assertIsBool($body['index_complete']);
		$matches = self::matchList($body);
		self::assertCount(1, $matches);
		self::assertSame($entryId, $matches[0]['entry_id']);
		self::assertSame('tag:google.com,2005:reader/item/' . str_pad(dechex((int)$entryId), 16, '0', STR_PAD_LEFT), $matches[0]['greader_item_id']);
		self::assertSame('https://example.org/shape/1', $matches[0]['url']);
		self::assertSame(1756717200, $matches[0]['published_at']);
		self::assertSame(['id' => self::$feedIds[self::USER], 'name' => 'URL Feed ' . self::USER,
			'url' => 'http://fixtures/url-feed-' . self::USER . '.xml'], $matches[0]['feed']);
		self::assertNull($matches[0]['note']);
	}

	public function testLookupIncludesTheNoteAndItsUrls(): void {
		$entryId = self::addEntry(self::USER, 'https://example.org/with-note/1');
		self::json(self::put($entryId, 'my note'), 201);
		$matches = self::matchList(self::json(self::call('v1/lookup', 'GET', ['url' => 'https://example.org/with-note/1?utm_source=a'])));
		self::assertIsArray($matches[0]['note']);
		self::assertSame('my note', $matches[0]['note']['note']);
		self::assertSame('https://example.org/with-note/1', $matches[0]['note']['url']);
		self::assertSame('https://example.org/with-note/1', $matches[0]['note']['normalized_url']);
		$note = self::json(self::call('v1/notes/' . $entryId))['note'];
		self::assertIsArray($note);
		self::assertSame('https://example.org/with-note/1', $note['url']);
		self::assertSame('https://example.org/with-note/1', $note['normalized_url']);
	}

	public function testANoteOnAPurgedArticleIsStillFound(): void {
		$entryId = self::addEntry(self::USER, 'https://example.org/purged/1');
		self::json(self::put($entryId, 'survives'), 201);
		self::purge(self::USER, $entryId);
		self::index(self::USER)->prune();
		$matches = self::matchList(self::json(self::call('v1/lookup', 'GET', ['url' => 'http://www.example.org/purged/1/'])));
		self::assertCount(1, $matches);
		self::assertSame($entryId, $matches[0]['entry_id']);
		self::assertSame('survives', is_array($matches[0]['note']) ? $matches[0]['note']['note'] : null);
		self::assertIsString($matches[0]['title']);
		self::assertStringStartsWith('URL article', $matches[0]['title']);
		self::assertIsArray($matches[0]['note']);
		self::assertIsArray($matches[0]['note']['article']);
		self::assertFalse($matches[0]['note']['article']['exists']);

		self::json(self::call('v1/notes/' . $entryId, 'DELETE'), 204);
		self::assertSame([], self::lookupIds('https://example.org/purged/1'), 'a deleted note is not found');
	}

	public function testMatchesAreMostRecentFirstAndCapped(): void {
		$ids = [];
		for ($i = 1; $i <= 22; $i++) {
			$ids[$i] = self::addEntry(self::USER, 'https://example.org/capped/?utm_source=' . $i, date: 1700000000 + $i);
		}
		$found = self::lookupIds('https://example.org/capped');
		self::assertCount(20, $found);
		self::assertSame(array_slice(array_reverse(array_values($ids)), 0, 20), $found);
	}

	public function testLookupReportsAnIncompleteIndex(): void {
		self::index(self::USER)->backfill(100000);
		for ($i = 0; $i < 3; $i++) {
			self::addEntry(self::USER, 'https://example.org/incomplete/' . $i);
		}
		// The lazy backfill is bounded by UrlLookup::LAZY_BATCH, so only a huge backlog stays incomplete; here it completes.
		self::assertTrue(self::json(self::call('v1/lookup', 'GET', ['url' => 'https://example.org/incomplete/1']))['index_complete']);
	}

	/** @return iterable<string,array{0:array<string,string>}> */
	public static function badLookups(): iterable {
		yield 'no url' => [[]];
		yield 'empty url' => [['url' => '']];
		yield 'not http' => [['url' => 'ftp://example.org/a']];
		yield 'javascript' => [['url' => 'javascript:alert(1)']];
		yield 'relative' => [['url' => '/a/b']];
	}

	/** @param array<string,string> $query */
	#[DataProvider('badLookups')]
	public function testLookupRejectsBadUrls(array $query): void {
		self::assertSame('invalid_parameter', self::errorCode(self::json(self::call('v1/lookup', 'GET', $query), 400)));
	}

	public function testLookupRequiresCredentialsAndReadMethods(): void {
		$unauthorized = self::call('v1/lookup', 'GET', ['url' => 'https://example.org/'], authorization: '');
		self::assertSame('unauthorized', self::errorCode(self::json($unauthorized, 401)));
		foreach (['POST', 'PUT', 'DELETE', 'PATCH'] as $method) {
			$response = self::call('v1/lookup', $method, ['url' => 'https://example.org/']);
			self::assertSame('method_not_allowed', self::errorCode(self::json($response, 405)));
			self::assertSame('GET, HEAD', $response->headers['Allow']);
		}
		self::assertSame(200, self::call('v1/lookup', 'HEAD', ['url' => 'https://example.org/'])->status);
	}

	// --- writes ---

	public function testPutCreatesThenUpdatesAndReturnsTheNote(): void {
		$entryId = self::addEntry(self::USER, 'https://example.org/put/1');
		$created = self::put($entryId, "first <b>note</b>\n");
		$body = self::json($created, 201);
		self::assertIsArray($body['note']);
		self::assertSame('first <b>note</b>', $body['note']['note'], 'trimmed, stored as entered otherwise');
		self::assertSame($entryId, $body['note']['entry_id']);
		self::assertIsInt($body['note']['id']);
		self::assertIsInt($body['note']['updated_at']);
		self::assertSame(sprintf('"%d-%d"', self::intValue($body['note']['id']), self::intValue($body['note']['updated_at'])),
			$created->headers['ETag']);

		$updated = self::put($entryId, 'second');
		$body = self::json($updated, 200);
		self::assertIsArray($body['note']);
		self::assertSame('second', $body['note']['note']);
		self::assertSame(sprintf('"%d-%d"', self::intValue($body['note']['id']), self::intValue($body['note']['updated_at'])),
			$updated->headers['ETag']);

		$read = self::call('v1/notes/' . $entryId);
		self::assertSame($updated->headers['ETag'], $read->headers['ETag']);
		self::assertSame($updated->headers['ETag'], self::call('v1/notes/' . $entryId, 'HEAD')->headers['ETag']);
	}

	public function testPutAppliesTheAnnotatedLabel(): void {
		$entryId = self::addEntry(self::USER, 'https://example.org/put/label');
		$note = self::json(self::put($entryId, 'labelled'), 201)['note'];
		self::assertIsArray($note);
		self::assertTrue($note['label_applied']);
		self::json(self::call('v1/notes/' . $entryId, 'DELETE'), 204);
		$note = self::service(self::USER)->changedSince(0, 1000, 0, true);
		$deleted = array_values(array_filter($note, static fn (ArticleNotes\Note $n): bool => $n->entryId === $entryId));
		self::assertFalse($deleted[0]->labelApplied, 'label taken back');
	}

	public function testPutWithEmptyTextDeletes(): void {
		$entryId = self::addEntry(self::USER, 'https://example.org/put/empty');
		self::json(self::put($entryId, 'something'), 201);
		$response = self::put($entryId, "  \n ");
		self::assertSame(204, $response->status);
		self::assertSame([], $response->body);
		self::json(self::call('v1/notes/' . $entryId), 404);
		self::assertSame(204, self::put($entryId, '')->status, 'idempotent');
	}

	public function testPutValidation(): void {
		$entryId = self::addEntry(self::USER, 'https://example.org/put/validation');
		self::assertSame('not_found', self::errorCode(self::json(self::put('999999999999999999', 'x'), 404)));
		self::assertSame('note_too_long', self::errorCode(self::json(self::put($entryId, str_repeat('é', 20001)), 422)));
		self::assertSame(201, self::put($entryId, str_repeat('é', 20000))->status, 'exactly the limit');
	}

	public function testRequestBodyRules(): void {
		$entryId = self::addEntry(self::USER, 'https://example.org/put/body');
		$path = 'v1/notes/' . $entryId;
		self::assertSame('unsupported_media_type', self::errorCode(self::json(
			self::call($path, 'PUT', [], '{"note":"x"}', contentType: 'text/plain'), 415)));
		self::assertSame('unsupported_media_type', self::errorCode(self::json(
			self::call($path, 'PUT', [], '{"note":"x"}', contentType: 'application/x-www-form-urlencoded'), 415)));
		self::assertSame('unsupported_media_type', self::errorCode(self::json(self::call($path, 'PUT', []), 415)), 'no body, no type');
		self::assertSame(201, self::call($path, 'PUT', [], '{"note":"ok"}', contentType: 'Application/JSON; charset=utf-8')->status);

		self::assertSame('payload_too_large', self::errorCode(self::json(
			self::call($path, 'PUT', [], (string)json_encode(['note' => str_repeat('a', ApiRequest::MAX_BODY_BYTES)])), 413)));
		self::assertSame(200, self::call($path, 'PUT', [], str_pad('{"note":"fits"}', ApiRequest::MAX_BODY_BYTES, ' '))->status, 'exactly 64 KB');

		foreach (['{', '', 'not json', '{"note":'] as $invalid) {
			self::assertSame('invalid_json', self::errorCode(self::json(self::call($path, 'PUT', [], $invalid), 400)), $invalid);
		}
		foreach (['[]', '"text"', '{}', '{"note":5}', '{"note":null}', '{"text":"x"}', '42'] as $wrong) {
			self::assertSame('invalid_body', self::errorCode(self::json(self::call($path, 'PUT', [], $wrong), 400)), $wrong);
		}
	}

	public function testMethodsPerPath(): void {
		$entryId = self::addEntry(self::USER, 'https://example.org/methods/1');
		foreach (['POST', 'PATCH'] as $method) {
			$response = self::call('v1/notes/' . $entryId, $method);
			self::assertSame('method_not_allowed', self::errorCode(self::json($response, 405)));
			self::assertSame('GET, HEAD, PUT, DELETE', $response->headers['Allow']);
		}
		foreach (['v1/notes', 'v1/status'] as $path) {
			foreach (['PUT', 'DELETE', 'POST'] as $method) {
				$response = self::call($path, $method, [], '{"note":"x"}');
				self::assertSame(405, $response->status);
				self::assertSame('GET, HEAD', $response->headers['Allow']);
			}
		}
		self::assertSame(405, self::call('v1/notes/' . $entryId, 'POST', [], authorization: '')->status, 'method is checked before credentials');
	}

	public function testWritesNeedCredentialsAndNeverUseCookies(): void {
		$entryId = self::addEntry(self::USER, 'https://example.org/auth/1');
		$_COOKIE['FreshRSS'] = 'session';
		$_SERVER['HTTP_COOKIE'] = 'FreshRSS=session';
		try {
			foreach (['PUT', 'DELETE'] as $method) {
				self::assertSame('unauthorized', self::errorCode(self::json(
					self::call('v1/notes/' . $entryId, $method, [], '{"note":"x"}', authorization: ''), 401)));
			}
			$web = 'Basic ' . base64_encode(self::USER . ':' . self::WEB_PASSWORD);
			self::assertSame(401, self::call('v1/notes/' . $entryId, 'PUT', [], '{"note":"x"}', authorization: $web)->status);
		} finally {
			unset($_COOKIE['FreshRSS'], $_SERVER['HTTP_COOKIE']);
		}
		self::assertNull(self::service(self::USER)->get($entryId));
	}

	public function testDelete(): void {
		$entryId = self::addEntry(self::USER, 'https://example.org/delete/1');
		self::assertSame('not_found', self::errorCode(self::json(self::call('v1/notes/' . $entryId, 'DELETE'), 404)));
		self::json(self::put($entryId, 'to delete'), 201);
		$response = self::call('v1/notes/' . $entryId, 'DELETE');
		self::assertSame(204, $response->status);
		self::assertSame([], $response->body);
		self::assertSame('no-store', $response->headers['Cache-Control']);
		self::json(self::call('v1/notes/' . $entryId), 404);
		self::assertSame(404, self::call('v1/notes/' . $entryId, 'DELETE')->status, 'already deleted');
		// the tombstone is visible to syncing clients
		$tombstones = self::json(self::call('v1/notes', 'GET', ['since' => '0']))['notes'];
		self::assertIsArray($tombstones);
		$mine = array_values(array_filter($tombstones, static fn (mixed $n): bool => is_array($n) && $n['entry_id'] === $entryId));
		self::assertNotEmpty($mine[0]['deleted_at']);
		self::assertSame(201, self::put($entryId, 'revived')->status, 'a tombstoned article can be annotated again');
	}

	public function testIfMatch(): void {
		$entryId = self::addEntry(self::USER, 'https://example.org/ifmatch/1');
		$path = 'v1/notes/' . $entryId;
		self::assertSame(412, self::put($entryId, 'x', '"1-1"')->status, 'create with If-Match: nothing to match');
		self::assertSame(412, self::put($entryId, 'x', '*')->status);
		self::assertNull(self::service(self::USER)->get($entryId));

		$etag = self::put($entryId, 'v1')->headers['ETag'];
		$stale = self::put($entryId, 'blocked', '"0-0"');
		self::assertSame('precondition_failed', self::errorCode(self::json($stale, 412)));
		self::assertSame('v1', self::service(self::USER)->get($entryId)?->note);

		$next = self::put($entryId, 'v2', $etag);
		self::assertSame(200, $next->status);
		self::assertSame(412, self::put($entryId, 'v3', $etag)->status, 'the etag is stale after an update');
		$current = $next->headers['ETag'];
		self::assertSame(200, self::put($entryId, 'v3', '"0-0", ' . $current)->status, 'one of a list');
		self::assertSame(412, self::put($entryId, 'v4', 'W/' . $etag)->status, 'a weak tag of an old version');
		$etag = self::call($path)->headers['ETag'];
		self::assertSame(200, self::put($entryId, 'v4', 'W/' . $etag)->status, 'mod_deflate makes tags weak');
		$etag = self::call($path)->headers['ETag'];
		self::assertSame(200, self::put($entryId, 'v5', substr($etag, 0, -1) . '-gzip"')->status, 'mod_deflate appends -gzip');
		self::assertSame(200, self::put($entryId, 'v6', '*')->status);

		self::assertSame(412, self::call($path, 'DELETE', ifMatch: '"0-0"')->status);
		self::assertNotNull(self::service(self::USER)->get($entryId));
		self::assertSame(204, self::call($path, 'DELETE', ifMatch: self::call($path)->headers['ETag'])->status);
		self::assertNull(self::service(self::USER)->get($entryId));
		self::assertSame(404, self::call($path, 'DELETE', ifMatch: '*')->status);
	}

	// --- isolation ---

	public function testUsersAreIsolated(): void {
		$mine = self::addEntry(self::USER, 'https://example.org/isolation/shared');
		$theirs = self::addEntry(self::OTHER, 'https://example.org/isolation/shared');
		self::json(self::put($mine, 'A private note', user: self::USER), 201);

		self::assertSame([$theirs], self::lookupIds('https://example.org/isolation/shared', self::OTHER));
		$other = self::matchList(self::json(self::call('v1/lookup', 'GET', ['url' => 'https://example.org/isolation/shared'],
			authorization: self::basic(self::OTHER))));
		self::assertNull($other[0]['note']);

		// user B cannot read, overwrite, or delete user A's note, nor annotate A's article
		self::json(self::call('v1/notes/' . $mine, 'GET', authorization: self::basic(self::OTHER)), 404);
		self::json(self::call('v1/notes/' . $mine, 'DELETE', authorization: self::basic(self::OTHER)), 404);
		self::json(self::put($mine, 'hijack', user: self::OTHER), 404);
		self::assertSame('A private note', self::service(self::USER)->get($mine)?->note);
		self::assertNull(self::service(self::OTHER)->get($mine));

		// user A's note on a purged article is not found by user B
		self::purge(self::USER, $mine);
		self::index(self::USER)->prune();
		self::assertSame([$mine], self::lookupIds('https://example.org/isolation/shared'));
		self::assertSame([$theirs], self::lookupIds('https://example.org/isolation/shared', self::OTHER));

		// index rows are per user
		$rows = self::index(self::OTHER)->find(UrlNormalizer::hash('https://example.org/isolation/shared'), 5);
		self::assertSame([$theirs], array_column($rows, 'entry_id'));
	}
}
