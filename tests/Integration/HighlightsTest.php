<?php

declare(strict_types=1);

use ArticleNotes\ApiRequest;
use ArticleNotes\ApiResponse;
use ArticleNotes\HighlightException;
use ArticleNotes\HighlightService;
use ArticleNotesTests\TestEnvironment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Highlights: service, API and label bookkeeping. */
final class HighlightsTest extends TestCase {
	private const USER = 'hluser';
	private const OTHER = 'hlother';
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
			$feed = new FreshRSS_Feed('http://fixtures/hl-feed-' . $user . '.xml', false);
			$feed->_name('Highlight Feed ' . $user);
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

	private static function service(string $user = self::USER): HighlightService {
		self::useUser($user);
		return self::extension()->highlightService();
	}

	private static function addEntry(string $user, string $link): string {
		self::useUser($user);
		$n = ++self::$sequence;
		$id = (string)(1732000000000000 + $n * 1000 + random_int(0, 999));
		self::assertTrue(FreshRSS_Factory::createEntryDao()->addEntry([
			'id' => $id, 'guid' => 'hl-guid-' . $id, 'title' => 'Highlight article ' . $n, 'author' => 'Author',
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
		?string $ifMatch = null, string $contentType = 'application/json',
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
	private static function post(array $data, ?string $user = null): ApiResponse {
		return self::call('v1/highlights', 'POST', [], (string)json_encode($data), $user);
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
	private static function highlightOf(array $body): array {
		self::assertIsArray($body['highlight']);
		/** @var array<string,mixed> $highlight */
		$highlight = $body['highlight'];
		return $highlight;
	}

	/**
	 * @param array<mixed> $body
	 * @return list<array<string,mixed>>
	 */
	private static function listOf(array $body, string $key = 'highlights'): array {
		self::assertIsArray($body[$key]);
		/** @var list<array<string,mixed>> $list */
		$list = $body[$key];
		return $list;
	}

	/** @param array<string,mixed> $body */
	private static function errorCode(array $body): string {
		self::assertIsArray($body['error']);
		self::assertIsString($body['error']['code']);
		return $body['error']['code'];
	}

	private static function intOf(mixed $value): int {
		self::assertIsInt($value);
		return $value;
	}

	private static function stringOf(mixed $value): string {
		self::assertIsString($value);
		return $value;
	}

	/**
	 * @param array<string,mixed> $extra
	 * @return array<string,mixed>
	 */
	private static function payload(string $entryId, string $exact = 'a passage', array $extra = []): array {
		return $extra + ['entry_id' => $entryId, 'source' => 'freshrss', 'selector' => ['quote' => ['exact' => $exact]]];
	}

	private static function uuid(): string {
		$bytes = random_bytes(16);
		$bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
		$bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
	}

	private static function errorOf(callable $action): string {
		try {
			$action();
		} catch (HighlightException $exception) {
			return $exception->errorCode;
		}
		self::fail('A HighlightException was expected');
	}

	/** @return list<string> names of the labels on the article */
	private static function labelsOf(string $entryId): array {
		$names = [];
		foreach (FreshRSS_Factory::createTagDao()->getTagsForEntry($entryId) as $tag) {
			if ($tag['checked']) {
				$names[] = $tag['name'];
			}
		}
		return $names;
	}

	// --- creation and targets ---

	public function testCreateOnAnArticleSnapshotsIt(): void {
		$entryId = self::addEntry(self::USER, 'https://example.org/hl/a1');
		$body = self::json(self::post(self::payload($entryId, 'hello world', [
			'color' => 'green', 'note' => '<b>note</b>',
			'selector' => ['quote' => ['exact' => 'hello world', 'prefix' => 'say ', 'suffix' => '!'], 'position' => ['start' => 4, 'end' => 15], 'extra' => 1],
		])), 201);
		$highlight = self::highlightOf($body);
		self::assertSame('highlight', $highlight['kind']);
		self::assertSame($entryId, $highlight['entry_id']);
		self::assertSame('https://example.org/hl/a1', $highlight['url']);
		self::assertSame('freshrss', $highlight['source']);
		self::assertSame('green', $highlight['color']);
		self::assertSame('<b>note</b>', $highlight['note']);
		self::assertSame(
			['quote' => ['exact' => 'hello world', 'prefix' => 'say ', 'suffix' => '!'], 'position' => ['start' => 4, 'end' => 15]],
			$highlight['selector'],
		);
		self::assertSame(1, preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', self::stringOf($highlight['uuid'])));
		self::assertStringStartsWith('tag:google.com,2005:reader/item/', self::stringOf($highlight['greader_item_id']));
		self::assertIsArray($highlight['article']);
		self::assertSame('Highlight article ' . self::$sequence, $highlight['article']['title']);
		self::assertTrue($highlight['article']['exists']);
		self::assertSame('Highlight article ' . self::$sequence, $highlight['title']);
		self::assertNull($highlight['deleted_at']);
		self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT/', self::stringOf($highlight['created_at_iso']));
		$etag = self::call('v1/highlights/' . self::stringOf($highlight['uuid']))->headers['ETag'] ?? '';
		self::assertSame('"' . self::intOf($highlight['id']) . '-' . self::intOf($highlight['updated_at']) . '"', $etag);
	}

	public function testUrlOfAnArticleAttachesTheHighlightToIt(): void {
		$entryId = self::addEntry(self::USER, 'https://example.org/hl/article-url');
		$highlight = self::highlightOf(self::json(self::post([
			'url' => 'https://EXAMPLE.org/hl/article-url/#frag', 'source' => 'page', 'selector' => ['quote' => ['exact' => 'text']], 'title' => 'Page title',
		]), 201));
		self::assertSame($entryId, $highlight['entry_id']);
		self::assertSame('page', $highlight['source']);
		self::assertSame('Page title', $highlight['title']);
		self::assertSame('https://example.org/hl/article-url', $highlight['url']);
	}

	public function testPageHighlightOnAUrlThatIsNoArticle(): void {
		$url = 'https://example.org/hl/some-page';
		$highlight = self::highlightOf(self::json(self::post([
			'url' => $url, 'source' => 'page', 'selector' => ['quote' => ['exact' => 'text']],
		]), 201));
		self::assertNull($highlight['entry_id']);
		self::assertNull($highlight['article']);
		self::assertNull($highlight['greader_item_id']);
		self::assertSame($url, $highlight['url']);
		self::assertSame($url, $highlight['normalized_url']);

		$list = self::listOf(self::json(self::call('v1/highlights', 'GET', ['url' => $url . '#other'])));
		self::assertSame([$highlight['uuid']], array_column($list, 'uuid'));
		$lookup = self::json(self::call('v1/lookup', 'GET', ['url' => $url]));
		self::assertSame([$highlight['uuid']], array_column(self::listOf($lookup, 'page_highlights'), 'uuid'));
		self::assertSame([], self::listOf($lookup, 'matches'));
	}

	public function testLookupListsHighlightsOfEachArticleMatch(): void {
		$entryId = self::addEntry(self::USER, 'https://example.org/hl/lookup');
		$uuid = self::stringOf(self::highlightOf(self::json(self::post(self::payload($entryId)), 201))['uuid']);
		$lookup = self::json(self::call('v1/lookup', 'GET', ['url' => 'https://example.org/hl/lookup']));
		$matches = self::listOf($lookup, 'matches');
		self::assertCount(1, $matches);
		self::assertSame([$uuid], array_column(self::listOf($matches[0], 'highlights'), 'uuid'));
		self::assertSame([], self::listOf($lookup, 'page_highlights'));
	}

	public function testUnknownArticleIs404(): void {
		self::assertSame('unknown_entry', self::errorCode(self::json(self::post(self::payload('1')), 404)));
	}

	/** @return array<string,array{array<string,mixed>,string}> */
	public static function invalidPayloads(): array {
		$base = ['url' => 'https://example.org/hl/invalid', 'source' => 'page', 'selector' => ['quote' => ['exact' => 'x']]];
		return [
			'no target' => [['source' => 'page', 'selector' => ['quote' => ['exact' => 'x']]], 'invalid_target'],
			'bad url' => [['url' => 'ftp://example.org/'] + $base, 'invalid_url'],
			'bad entry id' => [['entry_id' => 'abc'] + $base, 'invalid_field'],
			'bad uuid' => [['uuid' => 'not-a-uuid'] + $base, 'invalid_uuid'],
			'uuid v1' => [['uuid' => '6ba7b810-9dad-11d1-80b4-00c04fd430c8'] + $base, 'invalid_uuid'],
			'no source' => [array_diff_key($base, ['source' => 1]), 'invalid_source'],
			'bad source' => [['source' => 'other'] + $base, 'invalid_source'],
			'bad color' => [['color' => 'red'] + $base, 'invalid_color'],
			'color type' => [['color' => 1] + $base, 'invalid_color'],
			'note type' => [['note' => 5] + $base, 'invalid_field'],
			'note too long' => [['note' => str_repeat('n', 20001)] + $base, 'note_too_long'],
			'title too long' => [['title' => str_repeat('t', 501)] + $base, 'title_too_long'],
			'no selector' => [array_diff_key($base, ['selector' => 1]), 'invalid_selector'],
			'selector not an object' => [['selector' => 'text'] + $base, 'invalid_selector'],
			'no quote' => [['selector' => ['position' => ['start' => 0, 'end' => 1]]] + $base, 'invalid_selector'],
			'no exact' => [['selector' => ['quote' => []]] + $base, 'invalid_selector'],
			'exact type' => [['selector' => ['quote' => ['exact' => 5]]] + $base, 'invalid_selector'],
			'exact empty' => [['selector' => ['quote' => ['exact' => '']]] + $base, 'invalid_selector'],
			'exact blank' => [['selector' => ['quote' => ['exact' => " \n "]]] + $base, 'invalid_selector'],
			'exact too long' => [['selector' => ['quote' => ['exact' => str_repeat('e', 2001)]]] + $base, 'invalid_selector'],
			'prefix too long' => [['selector' => ['quote' => ['exact' => 'x', 'prefix' => str_repeat('p', 65)]]] + $base, 'invalid_selector'],
			'suffix type' => [['selector' => ['quote' => ['exact' => 'x', 'suffix' => 3]]] + $base, 'invalid_selector'],
			'position start = end' => [['selector' => ['quote' => ['exact' => 'x'], 'position' => ['start' => 3, 'end' => 3]]] + $base, 'invalid_selector'],
			'position start > end' => [['selector' => ['quote' => ['exact' => 'x'], 'position' => ['start' => 5, 'end' => 3]]] + $base, 'invalid_selector'],
			'position negative' => [['selector' => ['quote' => ['exact' => 'x'], 'position' => ['start' => -1, 'end' => 3]]] + $base, 'invalid_selector'],
			'position type' => [['selector' => ['quote' => ['exact' => 'x'], 'position' => ['start' => '1', 'end' => 3]]] + $base, 'invalid_selector'],
		];
	}

	/** @param array<string,mixed> $payload */
	#[DataProvider('invalidPayloads')]
	public function testInvalidPayloadsAreRefused(array $payload, string $code): void {
		$response = self::post($payload);
		self::assertContains($response->status, [400, 422]);
		self::assertSame($code, self::errorCode(self::json($response, $response->status)));
		self::assertSame([], self::listOf(self::json(self::call('v1/highlights', 'GET', ['url' => 'https://example.org/hl/invalid']))));
	}

	public function testBoundaryValuesAreAccepted(): void {
		$url = 'https://example.org/hl/boundaries';
		self::json(self::post([
			'url' => $url, 'source' => 'page', 'title' => str_repeat('t', 500), 'note' => str_repeat('n', 20000), 'color' => 'purple',
			'selector' => [
				'quote' => ['exact' => str_repeat('é', 2000), 'prefix' => str_repeat('p', 64), 'suffix' => str_repeat('s', 64)],
				'position' => ['start' => 0, 'end' => 1],
			],
		]), 201);
	}

	public function testBodyMustBeJson(): void {
		self::assertSame(415, self::call('v1/highlights', 'POST', [], '{}', null, null, 'text/plain')->status);
		self::assertSame(400, self::call('v1/highlights', 'POST', [], '{broken')->status);
		self::assertSame(400, self::call('v1/highlights', 'POST', [], '[1]')->status);
	}

	public function testAtMostAThousandHighlightsPerTarget(): void {
		$entryId = self::addEntry(self::USER, 'https://example.org/hl/many');
		$pdo = (new ArticleNotes\Storage(self::USER))->pdo();
		self::useUser(self::USER);
		$service = self::service();
		$first = $service->create(self::payload($entryId))['highlight'];
		$table = $pdo->prefix() . 'articlenotes_highlight';
		$copy = $pdo->prepare('INSERT INTO ' . $table . ' (uuid, entry_id, url, normalized_url, url_hash, title, entry_guid, entry_link, '
			. 'entry_title, entry_author, entry_date, feed_id, feed_name, feed_url, source, selector, color, note, created_at, updated_at) '
			. 'SELECT :uuid, entry_id, url, normalized_url, url_hash, title, entry_guid, entry_link, entry_title, entry_author, entry_date, '
			. 'feed_id, feed_name, feed_url, source, selector, color, note, created_at, updated_at FROM ' . $table . ' WHERE uuid=:source');
		self::assertNotFalse($copy);
		for ($i = 1; $i < HighlightService::MAX_PER_TARGET; $i++) {
			$copy->execute([':uuid' => self::uuid(), ':source' => $first->uuid]);
		}
		self::assertSame('too_many_highlights', self::errorCode(self::json(self::post(self::payload($entryId)), 422)));

		// A tombstone frees a slot; another target is unaffected.
		self::assertSame(204, self::call('v1/highlights/' . $first->uuid, 'DELETE')->status);
		self::json(self::post(self::payload($entryId)), 201);
		self::assertSame('too_many_highlights', self::errorCode(self::json(self::post(self::payload($entryId)), 422)));
		self::json(self::post(self::payload(self::addEntry(self::USER, 'https://example.org/hl/many2'))), 201);
		$pdo->exec('DELETE FROM ' . $table . ' WHERE entry_id=' . $entryId);
	}

	// --- idempotency ---

	public function testCreateWithAUuidIsIdempotent(): void {
		$entryId = self::addEntry(self::USER, 'https://example.org/hl/idem');
		$uuid = self::uuid();
		$first = self::highlightOf(self::json(self::post(self::payload($entryId, 'once', ['uuid' => strtoupper($uuid)])), 201));
		self::assertSame($uuid, $first['uuid']);
		$second = self::highlightOf(self::json(self::post(self::payload($entryId, 'once', ['uuid' => $uuid])), 200));
		self::assertSame($first['id'], $second['id']);
		self::assertCount(1, self::listOf(self::json(self::call('v1/highlights', 'GET', ['entry_id' => $entryId]))));
	}

	public function testSameUuidOnAnotherTargetIs409(): void {
		$one = self::addEntry(self::USER, 'https://example.org/hl/conflict1');
		$two = self::addEntry(self::USER, 'https://example.org/hl/conflict2');
		$uuid = self::uuid();
		self::json(self::post(self::payload($one, 'x', ['uuid' => $uuid])), 201);
		self::assertSame('uuid_conflict', self::errorCode(self::json(self::post(self::payload($two, 'x', ['uuid' => $uuid])), 409)));
		self::assertSame('uuid_conflict', self::errorCode(self::json(self::post([
			'uuid' => $uuid, 'url' => 'https://example.org/hl/elsewhere', 'source' => 'page', 'selector' => ['quote' => ['exact' => 'x']],
		]), 409)));
	}

	public function testUuidIsUniquePerUserAndAcrossUsers(): void {
		$uuid = self::uuid();
		$mine = self::addEntry(self::USER, 'https://example.org/hl/mine');
		self::json(self::post(self::payload($mine, 'x', ['uuid' => $uuid])), 201);
		// Each user has a table of their own: the same uuid is a different highlight there.
		$theirs = self::addEntry(self::OTHER, 'https://example.org/hl/theirs');
		self::json(self::post(self::payload($theirs, 'x', ['uuid' => $uuid]), self::OTHER), 201);
		self::assertSame($theirs, self::highlightOf(self::json(self::call('v1/highlights/' . $uuid, 'GET', [], null, self::OTHER)))['entry_id']);
		self::assertSame($mine, self::highlightOf(self::json(self::call('v1/highlights/' . $uuid)))['entry_id']);
	}

	public function testRetryOfADeletedHighlightIs409(): void {
		$entryId = self::addEntry(self::USER, 'https://example.org/hl/retry-deleted');
		$uuid = self::uuid();
		self::json(self::post(self::payload($entryId, 'x', ['uuid' => $uuid])), 201);
		self::assertSame(204, self::call('v1/highlights/' . $uuid, 'DELETE')->status);
		self::assertSame('uuid_conflict', self::errorCode(self::json(self::post(self::payload($entryId, 'x', ['uuid' => $uuid])), 409)));
	}

	// --- read, update, delete ---

	public function testGetOneAndNotFound(): void {
		$entryId = self::addEntry(self::USER, 'https://example.org/hl/get');
		$uuid = self::stringOf(self::highlightOf(self::json(self::post(self::payload($entryId)), 201))['uuid']);
		self::assertSame($uuid, self::highlightOf(self::json(self::call('v1/highlights/' . $uuid)))['uuid']);
		self::assertSame($uuid, self::highlightOf(self::json(self::call('v1/highlights/' . strtoupper($uuid))))['uuid']);
		self::assertSame(404, self::call('v1/highlights/' . self::uuid())->status);
		self::assertSame(404, self::call('v1/highlights/not-a-uuid')->status);
		self::assertSame(404, self::call('v1/highlights/' . $uuid, 'GET', [], null, self::OTHER)->status);
	}

	public function testPatchChangesNoteAndColorOnly(): void {
		$entryId = self::addEntry(self::USER, 'https://example.org/hl/patch');
		$created = self::highlightOf(self::json(self::post(self::payload($entryId, 'x', ['note' => 'first'])), 201));
		$uuid = self::stringOf($created['uuid']);
		$path = 'v1/highlights/' . $uuid;

		$updated = self::highlightOf(self::json(self::call($path, 'PATCH', [], '{"color":"blue"}')));
		self::assertSame('blue', $updated['color']);
		self::assertSame('first', $updated['note'], 'untouched');
		$updated = self::highlightOf(self::json(self::call($path, 'PATCH', [], '{"note":"second"}')));
		self::assertSame('second', $updated['note']);
		self::assertSame('blue', $updated['color']);
		self::assertGreaterThan($created['updated_at'], $updated['updated_at']);
		$cleared = self::highlightOf(self::json(self::call($path, 'PATCH', [], '{"note":""}')));
		self::assertNull($cleared['note']);
		self::assertSame($created['selector'], $cleared['selector']);

		self::assertSame('selector_immutable', self::errorCode(self::json(self::call($path, 'PATCH', [], '{"selector":{"quote":{"exact":"y"}}}'), 422)));
		self::assertSame('invalid_color', self::errorCode(self::json(self::call($path, 'PATCH', [], '{"color":"red"}'), 422)));
		self::assertSame('note_too_long', self::errorCode(self::json(self::call($path, 'PATCH', [], '{"note":"' . str_repeat('n', 20001) . '"}'), 422)));
		self::assertSame('invalid_body', self::errorCode(self::json(self::call($path, 'PATCH', [], '{}'), 400)));
		self::assertSame('invalid_body', self::errorCode(self::json(self::call($path, 'PATCH', [], '{"note":5}'), 400)));
		self::assertSame(404, self::call('v1/highlights/' . self::uuid(), 'PATCH', [], '{"note":"x"}')->status);
		self::assertSame(404, self::call($path, 'PATCH', [], '{"note":"x"}', self::OTHER)->status);
	}

	public function testIfMatchGuardsPatchAndDelete(): void {
		$entryId = self::addEntry(self::USER, 'https://example.org/hl/etag');
		$uuid = self::stringOf(self::highlightOf(self::json(self::post(self::payload($entryId)), 201))['uuid']);
		$path = 'v1/highlights/' . $uuid;
		$etag = self::call($path)->headers['ETag'] ?? '';
		self::assertNotSame('', $etag);

		self::assertSame(412, self::call($path, 'PATCH', [], '{"note":"x"}', null, '"0-0"')->status);
		self::assertSame(412, self::call($path, 'DELETE', [], null, null, '"0-0"')->status);
		self::assertSame(200, self::call($path, 'PATCH', [], '{"note":"x"}', null, $etag)->status);
		self::assertSame(412, self::call($path, 'PATCH', [], '{"note":"y"}', null, $etag)->status, 'stale');
		self::assertSame(204, self::call($path, 'DELETE', [], null, null, '*')->status);
		self::assertSame(404, self::call($path, 'DELETE')->status);
	}

	public function testDeleteLeavesATombstoneForSync(): void {
		$entryId = self::addEntry(self::USER, 'https://example.org/hl/tomb');
		$uuid = self::stringOf(self::highlightOf(self::json(self::post(self::payload($entryId, 'x', ['note' => 'secret'])), 201))['uuid']);
		self::assertSame(204, self::call('v1/highlights/' . $uuid, 'DELETE')->status);
		self::assertSame(404, self::call('v1/highlights/' . $uuid)->status);
		self::assertSame([], self::listOf(self::json(self::call('v1/highlights', 'GET', ['entry_id' => $entryId]))));

		$changes = self::listOf(self::json(self::call('v1/highlights', 'GET', ['since' => (string)(time() - 60), 'include_deleted' => '1'])));
		$tombstone = array_values(array_filter($changes, static fn (array $h): bool => $h['uuid'] === $uuid))[0] ?? null;
		self::assertNotNull($tombstone);
		self::assertIsInt($tombstone['deleted_at']);
		self::assertNull($tombstone['note']);
		$live = self::listOf(self::json(self::call('v1/highlights', 'GET', ['since' => (string)(time() - 60), 'include_deleted' => '0'])));
		self::assertNotContains($uuid, array_column($live, 'uuid'));
	}

	public function testListOfATargetIsInDocumentOrder(): void {
		$entryId = self::addEntry(self::USER, 'https://example.org/hl/order');
		$clock = 1800000000;
		/** @var array<string,string> $uuids */
		$uuids = ['early' => '', 'mid' => '', 'late' => '', 'none' => '', 'none2' => ''];
		foreach ([['late', 50], ['none', null], ['early', 5], ['mid', 20], ['none2', null]] as [$text, $start]) {
			$selector = ['quote' => ['exact' => $text]] + ($start === null ? [] : ['position' => ['start' => $start, 'end' => $start + 3]]);
			$uuids[$text] = self::stringOf(self::highlightOf(self::json(self::post(self::payload($entryId, $text, ['selector' => $selector])), 201))['uuid']);
			$clock++;
		}
		$list = self::listOf(self::json(self::call('v1/highlights', 'GET', ['entry_id' => $entryId])));
		self::assertSame([$uuids['early'], $uuids['mid'], $uuids['late'], $uuids['none'], $uuids['none2']], array_column($list, 'uuid'));
		$byUrl = self::listOf(self::json(self::call('v1/highlights', 'GET', ['url' => 'https://example.org/hl/order'])));
		self::assertSame(array_column($list, 'uuid'), array_column($byUrl, 'uuid'));
	}

	public function testTargetQueryValidation(): void {
		self::assertSame(400, self::call('v1/highlights', 'GET', ['entry_id' => 'x'])->status);
		self::assertSame(400, self::call('v1/highlights', 'GET', ['url' => 'ftp://x'])->status);
		self::assertSame(400, self::call('v1/highlights', 'GET', ['url' => 'https://x.org/', 'entry_id' => '5'])->status);
	}

	// --- sync ---

	public function testChangedSincePagesLikeNotes(): void {
		$entryId = self::addEntry(self::USER, 'https://example.org/hl/sync');
		self::useUser(self::USER);
		$service = self::service();
		$uuids = [];
		for ($i = 0; $i < 5; $i++) {
			$uuids[] = $service->create(self::payload($entryId, 'p' . $i))['highlight']->uuid;
		}
		$seen = [];
		$query = ['since' => '0', 'limit' => '2'];
		$pages = 0;
		do {
			$body = self::json(self::call('v1/highlights', 'GET', $query));
			foreach (self::listOf($body) as $highlight) {
				$seen[] = self::stringOf($highlight['uuid']);
			}
			self::assertIsInt($body['server_time']);
			$continuation = $body['continuation'];
			$query = ['continuation' => is_string($continuation) ? $continuation : '', 'limit' => '2'];
			$pages++;
		} while ($continuation !== null && $pages < 50);
		foreach ($uuids as $uuid) {
			self::assertContains($uuid, $seen);
		}
		self::assertSame($seen, array_values(array_unique($seen)), 'no duplicates across pages');
		self::assertSame(400, self::call('v1/highlights', 'GET', ['continuation' => '!!'])->status);
		self::assertSame(400, self::call('v1/highlights', 'GET', ['limit' => '0'])->status);
	}

	public function testSyncIsPerUser(): void {
		$entryId = self::addEntry(self::USER, 'https://example.org/hl/private');
		$uuid = self::stringOf(self::highlightOf(self::json(self::post(self::payload($entryId)), 201))['uuid']);
		$other = self::listOf(self::json(self::call('v1/highlights', 'GET', ['since' => '0'], null, self::OTHER)));
		self::assertNotContains($uuid, array_column($other, 'uuid'));
		self::assertSame([], self::listOf(self::json(self::call('v1/highlights', 'GET', ['entry_id' => $entryId], null, self::OTHER))));
	}

	public function testMethodsAndAuthentication(): void {
		$response = self::call('v1/highlights', 'DELETE');
		self::assertSame(405, $response->status);
		self::assertSame('GET, HEAD, POST', $response->headers['Allow'] ?? '');
		$response = self::call('v1/highlights/' . self::uuid(), 'POST');
		self::assertSame(405, $response->status);
		self::assertSame('GET, HEAD, PATCH, DELETE', $response->headers['Allow'] ?? '');
		self::useUser(TestEnvironment::USER);
		Minz_User::change();
		$unauthenticated = self::extension()->api()->handle(new ApiRequest('POST', 'v1/highlights', [], '', '{}', 'application/json'));
		self::assertSame(401, $unauthenticated->status);
	}

	// --- labels ---

	private static function useLabel(string $name): void {
		self::useUser(self::USER);
		self::extension()->settings()->save(true, $name);
	}

	public function testLabelIsAppliedOnTheFirstHighlightAndRemovedWithTheLast(): void {
		$name = 'Annotated ' . random_int(1000, 9999);
		self::useLabel($name);
		$entryId = self::addEntry(self::USER, 'https://example.org/hl/label');
		$service = self::service();
		$first = $service->create(self::payload($entryId, 'one'))['highlight'];
		self::assertTrue($first->labelApplied);
		self::assertSame([$name], self::labelsOf($entryId));
		$second = $service->create(self::payload($entryId, 'two'))['highlight'];
		self::assertFalse($second->labelApplied);

		$service->delete($first->uuid);
		self::assertSame([$name], self::labelsOf($entryId), 'a live highlight remains');
		$service->delete($second->uuid);
		self::assertSame([], self::labelsOf($entryId));
	}

	public function testLabelReflectsNoteOrAnyLiveHighlight(): void {
		$name = 'Annotated ' . random_int(1000, 9999);
		self::useLabel($name);
		$entryId = self::addEntry(self::USER, 'https://example.org/hl/label-note');
		$highlights = self::service();
		$notes = self::extension()->noteService();
		$notes->save($entryId, 'a note');
		self::assertSame([$name], self::labelsOf($entryId));
		$highlight = $highlights->create(self::payload($entryId))['highlight'];
		self::assertFalse($highlight->labelApplied, 'not the first annotation');

		$notes->delete($entryId);
		self::assertSame([$name], self::labelsOf($entryId), 'the highlight keeps the label');
		$highlights->delete($highlight->uuid);
		self::assertSame([], self::labelsOf($entryId));

		// And the other way round: the note keeps the label that a highlight applied.
		$highlight = $highlights->create(self::payload($entryId, 'again'))['highlight'];
		self::assertTrue($highlight->labelApplied);
		$notes->save($entryId, 'a note again');
		$highlights->delete($highlight->uuid);
		self::assertSame([$name], self::labelsOf($entryId), 'the note keeps the label');
		$notes->delete($entryId);
		self::assertSame([], self::labelsOf($entryId));
	}

	public function testLabelTheUserAddedIsNeverRemoved(): void {
		$name = 'Mine ' . random_int(1000, 9999);
		self::useLabel($name);
		$tags = FreshRSS_Factory::createTagDao();
		$tagId = $tags->addTag(['name' => $name]);
		self::assertIsInt($tagId);
		$entryId = self::addEntry(self::USER, 'https://example.org/hl/label-user');
		self::assertTrue($tags->tagEntry($tagId, $entryId));
		$service = self::service();
		$highlight = $service->create(self::payload($entryId))['highlight'];
		self::assertFalse($highlight->labelApplied);
		$service->delete($highlight->uuid);
		self::assertSame([$name], self::labelsOf($entryId));
	}

	public function testNoLabelWhenDisabledOrOnAPage(): void {
		self::useUser(self::USER);
		self::extension()->settings()->save(false, 'Disabled label');
		$entryId = self::addEntry(self::USER, 'https://example.org/hl/label-off');
		$highlight = self::service()->create(self::payload($entryId))['highlight'];
		self::assertFalse($highlight->labelApplied);
		self::assertSame([], self::labelsOf($entryId));

		self::useLabel('Pages never labelled');
		$page = self::service()->create([
			'url' => 'https://example.org/hl/label-page', 'source' => 'page', 'selector' => ['quote' => ['exact' => 'x']],
		])['highlight'];
		self::assertFalse($page->labelApplied);
		self::service()->delete($page->uuid);
	}

	// --- service ---

	public function testServiceRefusesWithTypedErrors(): void {
		$service = self::service();
		self::assertSame('invalid_selector', self::errorOf(static fn () => $service->create(['url' => 'https://example.org/x', 'source' => 'page'])));
		self::assertSame('not_found', self::errorOf(static fn () => $service->update(self::uuid(), 'x', null)));
		self::assertFalse($service->delete(self::uuid()));
		self::assertSame(409, (new HighlightException('uuid_conflict', ''))->status());
	}

	public function testHighlightsSurviveTheirArticle(): void {
		$entryId = self::addEntry(self::USER, 'https://example.org/hl/purged');
		$uuid = self::stringOf(self::highlightOf(self::json(self::post(self::payload($entryId)), 201))['uuid']);
		self::useUser(self::USER);
		$pdo = (new ArticleNotes\Storage(self::USER))->pdo();
		$pdo->exec('DELETE FROM ' . $pdo->prefix() . 'entry WHERE id=' . $entryId);
		$highlight = self::highlightOf(self::json(self::call('v1/highlights/' . $uuid)));
		self::assertIsArray($highlight['article']);
		self::assertFalse($highlight['article']['exists']);
		self::assertSame('Highlight article ' . self::$sequence, $highlight['article']['title']);
	}
}
