<?php

declare(strict_types=1);

use ArticleNotes\AnnotatedTargets;
use ArticleNotes\Api;
use ArticleNotes\ApiRequest;
use ArticleNotes\ApiResponse;
use ArticleNotes\HighlightService;
use ArticleNotes\NoteService;
use ArticleNotesTests\TestEnvironment;
use PHPUnit\Framework\TestCase;

/** CORS, the W3C export, annotated targets and the OpenAPI document served by the API. */
final class ClientSupportApiTest extends TestCase {
	private const USER = 'cspuser';
	private const OTHER = 'cspother';
	private const WEB_PASSWORD = 'web-password-123';
	private const API_PASSWORD = 'api-password-456';
	private const ORIGIN = 'https://notes.example.com';

	private static int $sequence = 0;
	/** @var array<string,int> */
	private static array $feedIds = [];
	private int $now = 1_800_000_000;

	#[\Override]
	public static function setUpBeforeClass(): void {
		self::useUser(TestEnvironment::USER);
		$apiHash = FreshRSS_password_Util::hash(self::API_PASSWORD);
		foreach ([self::USER, self::OTHER] as $user) {
			self::assertTrue(FreshRSS_user_Controller::createUser($user, null, self::WEB_PASSWORD,
				['apiPasswordHash' => $apiHash, 'language' => 'en'], false));
			self::useUser($user);
			$feed = new FreshRSS_Feed('http://fixtures/csp-feed-' . $user . '.xml', false);
			$feed->_name('Client Feed ' . $user);
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
		self::extension()->saveAllowedOrigins('');
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

	private static function addEntry(string $user): string {
		self::useUser($user);
		$n = ++self::$sequence;
		$id = (string)(1740000000000000 + $n * 1000 + random_int(0, 999));
		self::assertTrue(FreshRSS_Factory::createEntryDao()->addEntry([
			'id' => $id, 'guid' => 'guid-' . $id, 'title' => 'Client article ' . $n, 'author' => 'Author ' . $n,
			'content' => 'Content', 'link' => 'http://fixtures/csp/' . $id, 'date' => 1756717200 + $n, 'lastSeen' => time(),
			'hash' => md5($id), 'is_read' => false, 'is_favorite' => false, 'id_feed' => self::$feedIds[$user], 'tags' => '',
		], false));
		return $id;
	}

	/** Services without label side effects, on a clock the test controls. */
	private function notes(string $user = self::USER): NoteService {
		self::useUser($user);
		return new NoteService($user, self::extension()->ensureSchema(...), fn (): int => $this->now);
	}

	private function highlights(string $user = self::USER): HighlightService {
		return new HighlightService($user, $this->notes($user), fn (): int => $this->now);
	}

	private function highlight(string $user, ?string $entryId, string $text, string $color = 'yellow', string $note = '', string $url = ''): string {
		$uuid = $this->uuid();
		$input = ['uuid' => $uuid, 'source' => $entryId === null ? 'page' : 'freshrss', 'color' => $color, 'note' => $note,
			'selector' => ['quote' => ['exact' => $text], 'position' => ['start' => 3, 'end' => 3 + strlen($text)]]];
		$this->highlights($user)->create($input + ($entryId === null ? ['url' => $url, 'title' => 'Page ' . $url] : ['entry_id' => $entryId]));
		return $uuid;
	}

	private function uuid(): string {
		$bytes = random_bytes(16);
		$bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
		$bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
	}

	/** @param list<string> $origins */
	private function api(array $origins = []): Api {
		return new Api('ArticleNotes', '1.1.1',
			fn (): NoteService => $this->notes(Minz_User::name() ?? self::USER),
			fn (): HighlightService => $this->highlights(Minz_User::name() ?? self::USER),
			fn (): int => $this->now,
			fn (): array => $origins,
			fn (): array => self::extension()->openApiDocument(),
		);
	}

	private static function basic(string $user = self::USER): string {
		return 'Basic ' . base64_encode($user . ':' . self::API_PASSWORD);
	}

	/**
	 * @param array<string,string> $query
	 * @param list<string> $origins
	 */
	private function call(string $path, array $query = [], string $method = 'GET', ?string $authorization = null, ?string $origin = null,
		array $origins = [self::ORIGIN], string $user = self::USER): ApiResponse {
		self::useUser(TestEnvironment::USER);
		Minz_User::change();
		FreshRSS_Context::clearUserConf();
		$response = $this->api($origins)->handle(new ApiRequest($method, $path, $query, $authorization ?? self::basic($user), origin: $origin));
		self::useUser(TestEnvironment::USER);
		return $response;
	}

	/** @return array<string,mixed> */
	private static function body(ApiResponse $response, int $status = 200): array {
		self::assertSame($status, $response->status, (string)json_encode($response->body));
		$decoded = json_decode($response->json(), true);
		self::assertIsArray($decoded);
		/** @var array<string,mixed> $decoded */
		return $decoded;
	}

	/** The value at a path of keys in decoded JSON. */
	private static function at(mixed $data, string|int ...$path): mixed {
		foreach ($path as $key) {
			self::assertIsArray($data);
			self::assertArrayHasKey($key, $data);
			$data = $data[$key];
		}
		return $data;
	}

	private static function text(mixed $data, string|int ...$path): string {
		$value = self::at($data, ...$path);
		self::assertIsScalar($value);
		return (string)$value;
	}

	/** @return list<array<string,mixed>> */
	private static function items(mixed $data, string|int ...$path): array {
		$value = self::at($data, ...$path);
		self::assertIsArray($value);
		/** @var list<array<string,mixed>> $value */
		return $value;
	}

	// --- CORS ---

	public function testNoCorsHeadersByDefault(): void {
		foreach ([self::ORIGIN, null] as $origin) {
			$response = $this->call('v1/status', origin: $origin, origins: []);
			self::assertSame(200, $response->status);
			foreach (array_keys($response->headers) as $name) {
				self::assertStringStartsNotWith('Access-Control-', $name);
				self::assertNotSame('Vary', $name);
			}
		}
		$preflight = $this->call('v1/status', method: 'OPTIONS', authorization: '', origin: self::ORIGIN, origins: []);
		self::assertSame(405, $preflight->status);
		self::assertArrayNotHasKey('Access-Control-Allow-Origin', $preflight->headers);
	}

	public function testAllowedOriginGetsAPreflightAnswerWithoutCredentials(): void {
		$response = $this->call('v1/notes/123', method: 'OPTIONS', authorization: '', origin: self::ORIGIN);
		self::assertSame(204, $response->status);
		self::assertSame(self::ORIGIN, $response->headers['Access-Control-Allow-Origin']);
		self::assertSame('GET, HEAD, PUT, DELETE, OPTIONS', $response->headers['Access-Control-Allow-Methods']);
		self::assertSame('Authorization, Content-Type, If-Match', $response->headers['Access-Control-Allow-Headers']);
		self::assertSame('600', $response->headers['Access-Control-Max-Age']);
		self::assertSame('Origin', $response->headers['Vary']);
		self::assertSame('ETag', $response->headers['Access-Control-Expose-Headers']);
		self::assertArrayNotHasKey('Access-Control-Allow-Credentials', $response->headers);
	}

	public function testResponsesToAnAllowedOriginCarryCorsHeaders(): void {
		$ok = $this->call('v1/status', origin: self::ORIGIN);
		$denied = $this->call('v1/status', authorization: 'Basic ' . base64_encode('x:y'), origin: self::ORIGIN);
		foreach ([[$ok, 200], [$denied, 401]] as [$response, $status]) {
			self::assertSame($status, $response->status);
			self::assertSame(self::ORIGIN, $response->headers['Access-Control-Allow-Origin']);
			self::assertSame('Origin', $response->headers['Vary']);
			self::assertSame('ETag', $response->headers['Access-Control-Expose-Headers']);
			self::assertArrayNotHasKey('Access-Control-Allow-Credentials', $response->headers);
		}
		$unknown = $this->call('v1/nothing', origin: self::ORIGIN);
		self::assertSame(404, $unknown->status);
		self::assertSame(self::ORIGIN, $unknown->headers['Access-Control-Allow-Origin']);
	}

	public function testOtherOriginsGetNoCorsHeaders(): void {
		foreach (['https://evil.example.com', 'null', 'https://notes.example.com.evil.com', 'http://notes.example.com'] as $origin) {
			$response = $this->call('v1/status', origin: $origin);
			self::assertSame(200, $response->status);
			self::assertArrayNotHasKey('Access-Control-Allow-Origin', $response->headers, $origin);
			self::assertArrayNotHasKey('Vary', $response->headers);
			$preflight = $this->call('v1/status', method: 'OPTIONS', authorization: '', origin: $origin);
			self::assertSame(405, $preflight->status, $origin);
			self::assertArrayNotHasKey('Access-Control-Allow-Origin', $preflight->headers);
		}
	}

	public function testExtensionSettingRejectsWildcardsAndKeepsTheStoredList(): void {
		$extension = self::extension();
		self::assertSame([], $extension->allowedOrigins());
		self::assertSame([], $extension->saveAllowedOrigins("https://notes.example.com\nmoz-extension://0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0"));
		self::assertSame(['https://notes.example.com', 'moz-extension://0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0'], $extension->allowedOrigins());
		self::assertSame("https://notes.example.com\nmoz-extension://0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0", $extension->allowedOriginsText());

		self::assertSame(['*', 'https://*.example.com'], $extension->saveAllowedOrigins("https://other.example\n*\nhttps://*.example.com"));
		self::assertSame(['https://notes.example.com', 'moz-extension://0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0'], self::extension()->allowedOrigins());

		self::assertSame([], $extension->saveAllowedOrigins(''));
		self::assertSame([], self::extension()->allowedOrigins());
	}

	public function testRefusedLinesAreEscapedInTheNotification(): void {
		$extension = self::extension();
		$line = '<img src=x onerror=alert(1)>"\'';
		self::assertSame([$line], $extension->saveAllowedOrigins($line));
		Minz_Translate::registerPath($extension->getPath() . '/i18n');
		Minz_Translate::reset('en');
		$message = $extension->invalidOriginsMessage([$line]);
		self::assertStringNotContainsString('<img', $message);
		self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $message);
	}

	public function testTheExtensionAppliesTheStoredOrigins(): void {
		self::extension()->saveAllowedOrigins(self::ORIGIN);
		self::useUser(TestEnvironment::USER);
		Minz_User::change();
		FreshRSS_Context::clearUserConf();
		$request = new ApiRequest('OPTIONS', 'v1/status', [], '', origin: self::ORIGIN);
		self::assertSame(204, self::extension()->api()->handle($request)->status);
		$other = new ApiRequest('OPTIONS', 'v1/status', [], '', origin: 'https://evil.example.com');
		self::assertSame(405, self::extension()->api()->handle($other)->status);
	}

	// --- Router and OpenAPI ---

	public function testEveryRouteAnswersExactlyItsMethods(): void {
		$parameters = ['entry_id' => '123', 'uuid' => '3f1c9a52-8d3e-4c8e-9a53-5e8f0b6f7a10'];
		foreach (Api::ROUTES as $template => $methods) {
			$path = preg_replace_callback('/\{(\w+)\}/', static fn (array $m): string => $parameters[$m[1]], $template);
			self::assertIsString($path);
			foreach (['GET', 'HEAD', 'PUT', 'POST', 'PATCH', 'DELETE'] as $method) {
				$status = $this->call($path, method: $method, authorization: '')->status;
				$allowed = array_map(strval(...), $methods);
				$handled = in_array($method === 'HEAD' ? 'GET' : $method, $allowed, true);
				if ($template === 'v1/openapi.json') {
					self::assertSame($handled ? 200 : 405, $status, $method . ' ' . $path);
				} else {
					self::assertSame($handled ? 401 : 405, $status, $method . ' ' . $path);
				}
			}
		}
		self::assertSame(404, $this->call('v1/unknown', authorization: '')->status);
		self::assertSame(404, $this->call('v1/notes/abc', authorization: '')->status);
	}

	public function testOpenApiIsServedWithoutAuthentication(): void {
		$body = self::body($this->call('v1/openapi.json', authorization: ''));
		self::assertSame('3.1.0', $body['openapi']);
		self::assertIsArray($body['paths']);
		self::assertArrayHasKey('/v1/annotated', $body['paths']);
		self::assertSame(rtrim(ArticleNotesExtension::apiUrl(), '/'), self::at($body, 'servers', 0, 'url'));
		self::assertIsArray($body['security']);
		// `security: []` and empty schemas must stay JSON objects/arrays, not turn into the wrong type.
		$json = $this->call('v1/openapi.json', authorization: '')->json();
		self::assertStringContainsString('"security":[]', $json);
	}

	// --- W3C export ---

	public function testW3cExportOfNotesAndHighlights(): void {
		$entry = self::addEntry(self::USER);
		$this->notes()->save($entry, 'Article note');
		$uuid = $this->highlight(self::USER, $entry, 'quoted text', 'green', 'because');
		$this->notes()->savePageNote('https://example.org/page-w3c', 'Page note', 'Page w3c');
		$other = self::addEntry(self::OTHER);
		$this->notes(self::OTHER)->save($other, 'secret of the other user');

		$response = $this->call('v1/annotations', ['format' => 'w3c']);
		self::assertSame('application/ld+json; profile="http://www.w3.org/ns/anno.jsonld"; charset=UTF-8', $response->headers['Content-Type']);
		$collection = self::body($response);
		self::assertSame('http://www.w3.org/ns/anno.jsonld', $collection['@context']);
		self::assertSame('AnnotationCollection', $collection['type']);
		self::assertSame(3, $collection['total']);
		self::assertNull($collection['continuation']);
		self::assertSame($this->now, $collection['server_time']);
		self::assertSame('AnnotationPage', self::at($collection, 'first', 'type'));
		$items = self::items($collection, 'first', 'items');
		self::assertCount(3, $items);
		self::assertStringNotContainsString('secret of the other user', (string)json_encode($collection));
		$byId = [];
		foreach ($items as $item) {
			self::assertSame('Annotation', $item['type']);
			self::assertArrayNotHasKey('@context', $item);
			self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT/', self::text($item, 'created'));
			self::assertIsString(self::at($item, 'target', 'source'));
			$byId[self::text($item, 'id')] = $item;
		}
		$highlight = $byId['urn:uuid:' . $uuid];
		self::assertSame(['highlighting', 'commenting'], $highlight['motivation']);
		self::assertSame('because', self::at($highlight, 'body', 'value'));
		self::assertSame('TextQuoteSelector', self::at($highlight, 'target', 'selector', 0, 'type'));
		self::assertSame('quoted text', self::at($highlight, 'target', 'selector', 0, 'exact'));
		self::assertSame('TextPositionSelector', self::at($highlight, 'target', 'selector', 1, 'type'));
		$notes = array_filter($byId, static fn (array $item): bool => str_starts_with(self::text($item, 'id'), 'urn:articlenotes:note:'));
		self::assertCount(2, $notes);
		foreach ($notes as $note) {
			self::assertSame('commenting', $note['motivation']);
		}
	}

	public function testW3cExportPagesWithSinceAndContinuation(): void {
		$this->now = 1_810_000_000;
		$expected = [];
		for ($i = 0; $i < 4; $i++) {
			$this->now += 10;
			$entry = self::addEntry(self::USER);
			$this->notes()->save($entry, 'paged note ' . $i);
			$expected[] = 'paged note ' . $i;
			$this->now += 5;
			$uuid = $this->highlight(self::USER, $entry, 'paged quote ' . $i);
			$expected[] = 'urn:uuid:' . $uuid;
		}
		$this->now += 1;
		$seen = [];
		$query = ['since' => '1810000000', 'limit' => '3'];
		$pages = 0;
		do {
			$collection = self::body($this->call('v1/annotations', $query));
			foreach (self::items($collection, 'first', 'items') as $item) {
				$value = isset($item['body']) ? self::text($item, 'body', 'value') : '';
				$seen[] = str_starts_with($value, 'paged note') ? $value : self::text($item, 'id');
			}
			$continuation = $collection['continuation'];
			if ($continuation !== null) {
				self::assertIsString($continuation);
				self::assertArrayNotHasKey('total', $collection);
				$query['continuation'] = $continuation;
			}
		} while ($continuation !== null && ++$pages < 20);
		self::assertSame($expected, $seen, 'every annotation exactly once, oldest change first');
		self::assertSame(2, $pages, 'eight annotations in pages of three');

		// Only what changed later.
		$later = self::body($this->call('v1/annotations', ['since' => '1810000031']));
		self::assertCount(4, self::items($later, 'first', 'items'));
	}

	public function testW3cExportOfOneTargetAndOmitsDeletions(): void {
		$entry = self::addEntry(self::USER);
		$this->notes()->save($entry, 'target note');
		$kept = $this->highlight(self::USER, $entry, 'kept');
		$gone = $this->highlight(self::USER, $entry, 'gone');
		$this->highlights()->delete($gone);
		$otherEntry = self::addEntry(self::USER);
		$this->highlight(self::USER, $otherEntry, 'elsewhere');

		$collection = self::body($this->call('v1/annotations', ['entry_id' => $entry]));
		$ids = array_map(static fn (array $item): string => self::text($item, 'id'), self::items($collection, 'first', 'items'));
		self::assertCount(2, $ids);
		self::assertContains('urn:uuid:' . $kept, $ids);
		self::assertNotContains('urn:uuid:' . $gone, $ids);

		$page = 'https://example.org/page-target-w3c';
		$this->notes()->savePageNote($page, 'page note', 'T');
		$this->highlight(self::USER, null, 'on the page', url: $page);
		$byUrl = self::body($this->call('v1/annotations', ['url' => $page . '#frag']));
		self::assertCount(2, self::items($byUrl, 'first', 'items'));

		foreach ([['format' => 'jsonld'], ['entry_id' => 'x'], ['url' => 'ftp://x'], ['limit' => '0'], ['continuation' => 'nope'],
			['entry_id' => '1', 'url' => 'https://example.org/']] as $query) {
			self::assertSame(400, $this->call('v1/annotations', $query)->status, (string)json_encode($query));
		}
	}

	// --- Annotated targets ---

	/**
	 * @param array<string,mixed> $body
	 * @return list<array<string,mixed>>
	 */
	private static function targets(array $body): array {
		return self::items($body, 'targets');
	}

	public function testAnnotatedTargetsListEachTargetOnceWithCounts(): void {
		$this->now = 1_820_000_000;
		$onlyHighlights = self::addEntry(self::USER);
		$onlyNote = self::addEntry(self::USER);
		$both = self::addEntry(self::USER);
		$unannotated = self::addEntry(self::USER);
		$page = 'https://example.org/annotated-page';

		$this->now += 10;
		$this->highlight(self::USER, $onlyHighlights, 'first', 'green');
		$this->now += 10;
		$this->highlight(self::USER, $onlyHighlights, 'second', 'yellow');
		$this->highlight(self::USER, $onlyHighlights, 'third', 'green');
		$this->now += 10;
		$this->notes()->save($onlyNote, 'just a note');
		$this->now += 10;
		$this->notes()->save($both, 'note and highlight');
		$this->now += 10;
		$this->highlight(self::USER, $both, 'pink one', 'pink');
		$this->now += 10;
		$this->notes()->savePageNote($page, 'a page note', 'Annotated page');
		$this->now += 10;
		$this->highlight(self::USER, null, 'page quote', 'blue', url: $page);
		$this->now += 10;
		$otherEntry = self::addEntry(self::OTHER);
		$this->notes(self::OTHER)->save($otherEntry, 'not yours');

		$body = self::body($this->call('v1/annotated', ['since' => '1820000000']));
		$targets = self::targets($body);
		self::assertCount(4, $targets);
		self::assertSame([], $body['removed']);
		self::assertNull($body['continuation']);
		self::assertSame($this->now, $body['server_time']);
		self::assertNotContains($unannotated, array_column($targets, 'entry_id'));
		self::assertNotContains($otherEntry, array_column($targets, 'entry_id'));

		// Most recently annotated first.
		self::assertSame(['page', 'article', 'article', 'article'], array_column($targets, 'kind'));
		[$pageTarget, $bothTarget, $noteTarget, $highlightTarget] = $targets;
		self::assertSame([null, $both, $onlyNote, $onlyHighlights], array_column($targets, 'entry_id'));

		self::assertSame('https://example.org/annotated-page', $pageTarget['url']);
		self::assertSame('https://example.org/annotated-page', $pageTarget['normalized_url']);
		self::assertSame('Annotated page', $pageTarget['title']);
		self::assertNull($pageTarget['article']);
		self::assertNull($pageTarget['greader_item_id']);
		self::assertSame(1, $pageTarget['highlight_count']);
		self::assertSame(['blue'], $pageTarget['highlight_colors']);
		self::assertIsArray($pageTarget['note']);
		self::assertSame('a page note', $pageTarget['note']['note']);
		self::assertSame('page', $pageTarget['note']['kind']);
		self::assertSame(1_820_000_060, $pageTarget['first_annotated_at']);
		self::assertSame(1_820_000_070, $pageTarget['last_annotated_at']);

		self::assertSame(1, $bothTarget['highlight_count']);
		self::assertSame(['pink'], $bothTarget['highlight_colors']);
		self::assertIsArray($bothTarget['note']);
		self::assertSame('note and highlight', $bothTarget['note']['note']);
		self::assertSame(1_820_000_040, $bothTarget['first_annotated_at']);
		self::assertSame(1_820_000_050, $bothTarget['last_annotated_at']);
		self::assertTrue(self::at($bothTarget, 'article', 'exists'));
		self::assertSame('Client Feed ' . self::USER, self::at($bothTarget, 'article', 'feed', 'name'));
		self::assertStringStartsWith('Client article', self::text($bothTarget, 'title'));
		self::assertSame('http://fixtures/csp/' . $both, $bothTarget['url']);
		self::assertSame(Api::greaderItemId($both), $bothTarget['greader_item_id']);

		self::assertSame('just a note', self::at($noteTarget, 'note', 'note'));
		self::assertSame(0, $noteTarget['highlight_count']);
		self::assertSame([], $noteTarget['highlight_colors']);
		self::assertSame(1_820_000_030, $noteTarget['last_annotated_at']);

		self::assertNull($highlightTarget['note']);
		self::assertSame(3, $highlightTarget['highlight_count']);
		self::assertSame(['yellow', 'green'], $highlightTarget['highlight_colors']);
		self::assertSame(1_820_000_010, $highlightTarget['first_annotated_at']);
		self::assertSame(1_820_000_020, $highlightTarget['last_annotated_at']);
	}

	public function testAnnotatedTargetsSinceAndRemoved(): void {
		$this->now = 1_830_000_000;
		$stays = self::addEntry(self::USER);
		$goes = self::addEntry(self::USER);
		$page = 'https://example.org/removed-page';
		$this->now += 10;
		$this->notes()->save($stays, 'stays');
		$this->now += 10;
		$this->notes()->save($goes, 'goes');
		$this->now += 10;
		$pageHighlight = $this->highlight(self::USER, null, 'page quote', url: $page);

		$all = self::body($this->call('v1/annotated', ['limit' => '500']));
		self::assertContains($stays, array_column(self::targets($all), 'entry_id'));
		self::assertArrayNotHasKey('removed', $all, 'no removed list without since');

		$changed = self::body($this->call('v1/annotated', ['since' => '1830000020']));
		self::assertSame([null, $goes], array_column(self::targets($changed), 'entry_id'));
		self::assertSame([], $changed['removed']);

		$this->now += 10;
		$this->notes()->delete($goes);
		$this->now += 10;
		$this->highlights()->delete($pageHighlight);
		$this->now += 10;
		$after = self::body($this->call('v1/annotated', ['since' => '1830000035']));
		self::assertSame([], self::targets($after));
		$removed = array_column(self::items($after, 'removed'), null, 'kind');
		self::assertCount(2, $removed);
		self::assertSame($goes, $removed['article']['entry_id']);
		self::assertSame(Api::greaderItemId($goes), $removed['article']['greader_item_id']);
		self::assertNull($removed['page']['entry_id']);
		self::assertSame($page, $removed['page']['normalized_url']);

		$everything = self::body($this->call('v1/annotated', ['limit' => '500']));
		self::assertContains($stays, array_column(self::targets($everything), 'entry_id'));
		self::assertNotContains($goes, array_column(self::targets($everything), 'entry_id'));
		self::assertArrayNotHasKey('removed', $everything);

		// A target that was annotated again is listed, not removed.
		$this->now += 10;
		$this->notes()->save($goes, 'back');
		$again = self::body($this->call('v1/annotated', ['since' => '1830000035']));
		self::assertSame([$goes], array_column(self::targets($again), 'entry_id'));
		self::assertCount(1, self::items($again, 'removed'));
	}

	public function testAnnotatedTargetsPageThroughEveryTargetOnce(): void {
		$this->now = 1_840_000_000;
		$expected = [];
		for ($i = 0; $i < 7; $i++) {
			if ($i % 3 === 0) {
				$this->now += 10;	// some targets share their last annotation time: the tiebreaker must keep the order stable
			}
			$entry = self::addEntry(self::USER);
			$this->highlight(self::USER, $entry, 'q' . $i);
			$expected[] = $entry;
			$url = 'https://example.org/paging-' . $i;
			$this->highlight(self::USER, null, 'p' . $i, url: $url);
			$expected[] = $url;
		}
		$all = array_map(
			static fn (array $target): string => self::text($target['entry_id'] ?? $target['normalized_url']),
			self::targets(self::body($this->call('v1/annotated', ['since' => '1840000000']))),
		);
		self::assertCount(14, $all);
		self::assertEqualsCanonicalizing($expected, $all);

		foreach ([1, 3, 5] as $limit) {
			$seen = [];
			$query = ['since' => '1840000000', 'limit' => (string)$limit];
			$pages = 0;
			do {
				$body = self::body($this->call('v1/annotated', $query));
				foreach (self::targets($body) as $target) {
					$seen[] = self::text($target['entry_id'] ?? $target['normalized_url']);
				}
				$continuation = $body['continuation'];
				$query = ['continuation' => is_string($continuation) ? $continuation : ''];
			} while ($continuation !== null && ++$pages < 30);
			self::assertSame($all, $seen, 'limit ' . $limit . ': same order, each target exactly once');
		}
	}

	public function testAnnotatedTargetsFilters(): void {
		$this->now = 1_850_000_000;
		$entry = self::addEntry(self::USER);
		$this->notes()->save($entry, 'filtered');
		$this->highlight(self::USER, null, 'page', url: 'https://example.org/filter-page');

		$articles = self::targets(self::body($this->call('v1/annotated', ['kind' => 'article', 'since' => '1850000000'])));
		self::assertSame([$entry], array_column($articles, 'entry_id'));
		$pages = self::targets(self::body($this->call('v1/annotated', ['kind' => 'page', 'since' => '1850000000'])));
		self::assertSame(['page'], array_column($pages, 'kind'));
		$feed = self::targets(self::body($this->call('v1/annotated', ['feed_id' => (string)self::$feedIds[self::USER], 'since' => '1850000000'])));
		self::assertSame([$entry], array_column($feed, 'entry_id'));
		self::assertSame([], self::targets(self::body($this->call('v1/annotated', ['feed_id' => '999999']))));

		// The continuation keeps the filters.
		$first = self::body($this->call('v1/annotated', ['kind' => 'page', 'limit' => '1', 'since' => '1850000000']));
		self::assertNull($first['continuation']);

		foreach ([['kind' => 'both'], ['feed_id' => '0'], ['feed_id' => 'x'], ['limit' => '501'], ['since' => '-1'], ['continuation' => '!!']] as $query) {
			self::assertSame(400, $this->call('v1/annotated', $query)->status, (string)json_encode($query));
		}
	}

	public function testAnnotatedTargetsAreIsolatedPerUser(): void {
		$this->now = 1_860_000_000;
		$mine = self::addEntry(self::USER);
		$theirs = self::addEntry(self::OTHER);
		$this->notes()->save($mine, 'mine');
		$this->notes(self::OTHER)->save($theirs, 'theirs');
		$this->highlight(self::OTHER, null, 'their page', url: 'https://example.org/their-page');

		$mineTargets = self::targets(self::body($this->call('v1/annotated', ['since' => '1860000000'])));
		self::assertSame([$mine], array_column($mineTargets, 'entry_id'));
		$theirTargets = self::targets(self::body($this->call('v1/annotated', ['since' => '1860000000'], user: self::OTHER)));
		self::assertEqualsCanonicalizing([$theirs, null], array_column($theirTargets, 'entry_id'));
		self::assertStringNotContainsString('mine', (string)json_encode($theirTargets));
	}

	public function testAnnotatedTargetsUseAConstantNumberOfQueries(): void {
		$this->now = 1_870_000_000;
		self::useUser(self::USER);
		$measure = function (): int {
			$targets = new AnnotatedTargets($this->notes()->pdo());
			$found = $targets->page(1_870_000_000, 500);
			$targets->removedSince(1_870_000_000);
			self::assertNotEmpty($found);
			return $targets->queries;
		};
		for ($i = 0; $i < 2; $i++) {
			$entry = self::addEntry(self::USER);
			$this->notes()->save($entry, 'n' . $i);
			$this->highlight(self::USER, $entry, 'h' . $i, 'pink');
			$this->highlight(self::USER, null, 'p' . $i, url: 'https://example.org/queries-' . $i);
		}
		$few = $measure();
		for ($i = 2; $i < 12; $i++) {
			$entry = self::addEntry(self::USER);
			$this->notes()->save($entry, 'n' . $i);
			$this->highlight(self::USER, $entry, 'h' . $i, 'blue');
			$this->highlight(self::USER, null, 'p' . $i, url: 'https://example.org/queries-' . $i);
			$this->notes()->savePageNote('https://example.org/queries-note-' . $i, 'page note', 'T');
		}
		$many = $measure();
		self::assertSame($few, $many, 'the number of queries does not depend on the number of targets');
		self::assertLessThanOrEqual(5, $many);
	}
}
