<?php

declare(strict_types=1);

namespace ArticleNotes;

/**
 * The notes API (`p/api/misc.php/ArticleNotes/v1/...`). It authenticates every request with the user's FreshRSS
 * API password, like the Google Reader API (never with a session cookie, so writes need no CSRF token), and only ever
 * reads or writes the authenticated user's notes.
 */
final class Api {
	public const API_VERSION = 1;
	public const DEFAULT_LIMIT = 100;
	public const MAX_LIMIT = 500;
	private const GREADER_ITEM_PREFIX = 'tag:google.com,2005:reader/item/';

	/**
	 * @param \Closure():NoteService $noteService the note service of the user authenticated by {@see handle()}
	 * @param \Closure():HighlightService $highlightService the highlight service of that user
	 * @param (\Closure():int)|null $clock current Unix time (default: time())
	 */
	public function __construct(
		private readonly string $extensionName,
		private readonly string $extensionVersion,
		private readonly \Closure $noteService,
		private readonly \Closure $highlightService,
		private readonly ?\Closure $clock = null,
	) {
	}

	public function handle(ApiRequest $request): ApiResponse {
		$path = trim($request->path, '/');
		$read = null;
		$routes = [];
		if ($path === 'v1/status') {
			$read = fn (): ApiResponse => $this->status();
		} elseif ($path === 'v1/notes') {
			$read = fn (): ApiResponse => $this->listNotes($request->query);
		} elseif ($path === 'v1/lookup') {
			$read = fn (): ApiResponse => $this->lookup($request->query);
		} elseif ($path === 'v1/page-notes') {
			$read = fn (): ApiResponse => $this->onePageNote($request->query);
			$routes['PUT'] = fn (): ApiResponse => $this->putPageNote($request);
			$routes['DELETE'] = fn (): ApiResponse => $this->deletePageNote($request);
		} elseif ($path === 'v1/highlights') {
			$read = fn (): ApiResponse => $this->listHighlights($request->query);
			$routes['POST'] = fn (): ApiResponse => $this->createHighlight($request);
		} elseif (preg_match('#^v1/highlights/([0-9a-fA-F-]{1,64})$#', $path, $matches) === 1) {
			$uuid = $matches[1];
			$read = fn (): ApiResponse => $this->oneHighlight($uuid);
			$routes['PATCH'] = fn (): ApiResponse => $this->patchHighlight($uuid, $request);
			$routes['DELETE'] = fn (): ApiResponse => $this->deleteHighlight($uuid, $request);
		} elseif (preg_match('#^v1/notes/(\d{1,20})$#', $path, $matches) === 1) {
			$entryId = $matches[1];
			$read = fn (): ApiResponse => $this->oneNote($entryId);
			$routes['PUT'] = fn (): ApiResponse => $this->putNote($entryId, $request);
			$routes['DELETE'] = fn (): ApiResponse => $this->deleteNote($entryId, $request);
		} else {
			return ApiResponse::error(404, 'not_found', 'Unknown API path.');
		}
		$routes = ['GET' => $read, 'HEAD' => $read] + $routes;

		if (!isset($routes[$request->method])) {
			$allow = implode(', ', array_keys($routes));
			return ApiResponse::error(405, 'method_not_allowed', 'Use one of: ' . $allow . '.', ['Allow' => $allow]);
		}
		$route = $routes[$request->method];

		if ($this->authenticate($request->authorization) === null) {
			\Minz_User::change();
			\FreshRSS_Context::clearUserConf();
			return ApiResponse::error(401, 'unauthorized',
				'Authenticate with HTTP Basic (user name and API password) or a Google Reader API token.',
				['WWW-Authenticate' => 'Basic realm="' . $this->extensionName . '"']);
		}

		try {
			return $route();
		} catch (InvalidParameterException $exception) {
			return ApiResponse::error(400, 'invalid_parameter', $exception->getMessage());
		} catch (ApiException $exception) {
			return ApiResponse::error($exception->status, $exception->errorCode, $exception->getMessage());
		} catch (HighlightException $exception) {
			return ApiResponse::error($exception->status(), $exception->errorCode, $exception->getMessage());
		} catch (\Throwable $exception) {
			\Minz_Log::error('ArticleNotes API failure for user ' . (\Minz_User::name() ?? '') . ': ' . $exception->getMessage());
			return ApiResponse::error(500, 'internal_error', 'The request cannot be served right now.');
		}
	}

	/**
	 * Accepts what FreshRSS's Google Reader API accepts: `GoogleLogin auth=<user>/<token>` (the ClientLogin token)
	 * or HTTP Basic with the user's API password. Never the web login password.
	 *
	 * @return string|null the authenticated user, now the current FreshRSS user
	 */
	private function authenticate(#[\SensitiveParameter] string $authorization): ?string {
		if (preg_match('#^GoogleLogin\s+auth=([^/\s]+)/(\S+)$#i', trim($authorization), $matches) === 1) {
			[, $user, $token] = $matches;
			if (!$this->initUser($user)) {
				return null;
			}
			$expected = sha1(\FreshRSS_Context::systemConf()->salt . $user . \FreshRSS_Context::userConf()->apiPasswordHash);
			if (hash_equals($expected, $token)) {
				return $user;
			}
			self::logFailure('Invalid API authorisation for user ' . $user);
			return null;
		}

		if (preg_match('#^Basic\s+(\S+)$#i', trim($authorization), $matches) === 1) {
			$credentials = base64_decode($matches[1], true);
			if ($credentials === false || !str_contains($credentials, ':')) {
				self::logFailure('Malformed Basic credentials');
				return null;
			}
			[$user, $password] = explode(':', $credentials, 2);
			if (!$this->initUser($user)) {
				return null;
			}
			if (password_verify($password, \FreshRSS_Context::userConf()->apiPasswordHash)) {
				return $user;
			}
			self::logFailure('Password API mismatch for user ' . $user);
			return null;
		}

		self::logFailure($authorization === '' ? 'Missing credentials' : 'Unsupported authorization scheme');
		return null;
	}

	/** Makes `$user` the current FreshRSS user if it exists, is enabled and has an API password. */
	private function initUser(string $user): bool {
		if (!\FreshRSS_user_Controller::checkUsername($user)) {
			self::logFailure('Invalid API user name');
			return false;
		}
		\FreshRSS_Context::initUser($user);
		if (!\FreshRSS_Context::hasUserConf() || !\FreshRSS_Context::hasSystemConf()) {
			self::logFailure('Invalid API user ' . $user . ': configuration cannot be found.');
			return false;
		}
		if (!\FreshRSS_Context::userConf()->enabled) {
			self::logFailure('Invalid API user ' . $user . ': disabled.');
			return false;
		}
		if (\FreshRSS_Context::userConf()->apiPasswordHash === '') {
			self::logFailure('Invalid API user ' . $user . ': no API password.');
			return false;
		}
		return true;
	}

	private static function logFailure(string $message): void {
		\Minz_Log::warning('ArticleNotes API: ' . $message, API_LOG);
	}

	private function status(): ApiResponse {
		return ApiResponse::ok([
			'extension' => $this->extensionName,
			'version' => $this->extensionVersion,
			'api_version' => self::API_VERSION,
			'user' => \Minz_User::name(),
			'note_count' => $this->service()->count(),
		]);
	}

	private function oneNote(string $entryId): ApiResponse {
		$service = $this->service();
		$note = $service->get($entryId);
		if ($note === null) {
			return ApiResponse::error(404, 'not_found', 'There is no note on this article.');
		}
		return self::noteResponse(200, $note, $service->existingEntryIds([$note->entryId ?? '']) !== []);
	}

	/** Creates, updates (or, with empty text, deletes) the note of an article. */
	private function putNote(string $entryId, ApiRequest $request): ApiResponse {
		$text = self::noteText($request);
		$service = $this->service();
		$current = $service->get($entryId);
		if (self::preconditionFails($request->ifMatch, self::etagOf($current))) {
			return self::preconditionFailed();
		}
		try {
			$note = $service->save($entryId, $text);
		} catch (NoteException $exception) {
			return $exception->getCode() === NoteException::UNKNOWN_ENTRY
				? ApiResponse::error(404, 'not_found', 'There is no such article.')
				: ApiResponse::error(422, 'note_too_long', 'A note is limited to ' . NoteService::MAX_LENGTH . ' characters.');
		}
		if ($note === null) {
			return ApiResponse::noContent();
		}
		return self::noteResponse($current === null ? 201 : 200, $note, true);
	}

	private function deleteNote(string $entryId, ApiRequest $request): ApiResponse {
		$service = $this->service();
		$current = $service->get($entryId);
		if ($current === null) {
			return ApiResponse::error(404, 'not_found', 'There is no note on this article.');
		}
		if (self::preconditionFails($request->ifMatch, self::etagOf($current))) {
			return self::preconditionFailed();
		}
		$service->delete($entryId);
		return ApiResponse::noContent();
	}

	/**
	 * The note text of a write request.
	 *
	 * @throws ApiException a body that is not JSON (415, 413, 400)
	 */
	private static function noteText(ApiRequest $request): string {
		$text = self::noteBody($request)['note'];
		if (!is_string($text)) {
			throw new ApiException(400, 'invalid_body', 'The body must be a JSON object with a "note" string.');
		}
		return $text;
	}

	/**
	 * The decoded JSON object of a write request, which has a "note" entry.
	 *
	 * @return array<string,mixed>
	 * @throws ApiException a body that is not JSON (415, 413, 400)
	 */
	private static function noteBody(ApiRequest $request): array {
		$body = self::jsonBody($request);
		if (!isset($body['note']) || !is_string($body['note'])) {
			throw new ApiException(400, 'invalid_body', 'The body must be a JSON object with a "note" string.');
		}
		return $body;
	}

	/**
	 * The decoded JSON object of a write request.
	 *
	 * @return array<string,mixed>
	 * @throws ApiException a body that is not a JSON object (415, 413, 400)
	 */
	private static function jsonBody(ApiRequest $request): array {
		$mediaType = strtolower(trim(explode(';', $request->contentType, 2)[0]));
		if ($mediaType !== 'application/json') {
			throw new ApiException(415, 'unsupported_media_type', 'Send the body as application/json.');
		}
		if (strlen($request->body) > ApiRequest::MAX_BODY_BYTES) {
			throw new ApiException(413, 'payload_too_large', 'The request body is limited to ' . ApiRequest::MAX_BODY_BYTES . ' bytes.');
		}
		try {
			$body = json_decode($request->body, true, 8, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			throw new ApiException(400, 'invalid_json', 'The request body is not valid JSON.');
		}
		if (!is_array($body) || ($body !== [] && array_is_list($body))) {
			throw new ApiException(400, 'invalid_body', 'The body must be a JSON object.');
		}
		return array_combine(array_map('strval', array_keys($body)), $body);
	}

	/**
	 * The normalized form of the `url` parameter.
	 *
	 * @param array<string,string> $query
	 * @throws InvalidParameterException
	 */
	private static function pageUrl(array $query): string {
		return UrlNormalizer::normalize($query['url'] ?? '') ?? throw new InvalidParameterException('"url" must be an http or https URL.');
	}

	/** @param array<string,string> $query */
	private function onePageNote(array $query): ApiResponse {
		$normalized = self::pageUrl($query);
		$note = $this->service()->getPageNote($normalized);
		if ($note === null) {
			return ApiResponse::error(404, 'not_found', 'There is no page note on this URL.');
		}
		return self::noteResponse(200, $note, false);
	}

	/** Creates, updates (or, with empty text, deletes) the note of a web page that is no article. */
	private function putPageNote(ApiRequest $request): ApiResponse {
		self::pageUrl($request->query);
		$body = self::noteBody($request);
		$text = $body['note'];
		$title = $body['title'] ?? '';
		if (!is_string($text) || !is_string($title)) {
			throw new ApiException(400, 'invalid_body', 'The body must be a JSON object with a "note" string and an optional "title" string.');
		}
		$service = $this->service();
		$current = $service->getPageNote($request->query['url'] ?? '');
		if (self::preconditionFails($request->ifMatch, self::etagOf($current))) {
			return self::preconditionFailed();
		}
		try {
			$note = $service->savePageNote($request->query['url'] ?? '', $text, $title);
		} catch (NoteException $exception) {
			return match ($exception->getCode()) {
				NoteException::URL_IS_ARTICLE => ApiResponse::error(409, 'url_is_article',
					'This URL is a FreshRSS article: PUT v1/notes/' . ($exception->entryId ?? '') . ' instead.'),
				NoteException::TITLE_TOO_LONG => ApiResponse::error(422, 'title_too_long',
					'A page title is limited to ' . NoteService::MAX_TITLE_LENGTH . ' characters.'),
				default => ApiResponse::error(422, 'note_too_long', 'A note is limited to ' . NoteService::MAX_LENGTH . ' characters.'),
			};
		}
		if ($note === null) {
			return ApiResponse::noContent();
		}
		return self::noteResponse($current === null ? 201 : 200, $note, false);
	}

	private function deletePageNote(ApiRequest $request): ApiResponse {
		self::pageUrl($request->query);
		$service = $this->service();
		$current = $service->getPageNote($request->query['url'] ?? '');
		if ($current === null) {
			return ApiResponse::error(404, 'not_found', 'There is no page note on this URL.');
		}
		if (self::preconditionFails($request->ifMatch, self::etagOf($current))) {
			return self::preconditionFailed();
		}
		$service->deletePageNote($request->query['url'] ?? '');
		return ApiResponse::noContent();
	}

	private static function etag(Note $note): string {
		return '"' . $note->id . '-' . $note->updatedAt . '"';
	}

	private static function etagOf(?Note $note): ?string {
		return $note === null ? null : self::etag($note);
	}

	/**
	 * Whether an `If-Match` header is present and does not match the live note or highlight (`*` matches any). Web servers
	 * that compress responses (Apache's mod_deflate) turn the ETag into a weak one or add a `-gzip` suffix, and the
	 * client sends back what it received: both forms are accepted.
	 */
	private static function preconditionFails(?string $ifMatch, ?string $currentEtag): bool {
		if ($ifMatch === null) {
			return false;
		}
		if ($currentEtag === null) {
			return true;
		}
		foreach (explode(',', $ifMatch) as $candidate) {
			$candidate = preg_replace('/^W\/|-(?:gzip|br|deflate|zstd)(?="$)/', '', trim($candidate)) ?? '';
			if ($candidate === '*' || $candidate === $currentEtag) {
				return false;
			}
		}
		return true;
	}

	private static function preconditionFailed(): ApiResponse {
		return ApiResponse::error(412, 'precondition_failed', 'The note changed since the version in If-Match.');
	}

	private static function noteResponse(int $status, Note $note, bool $articleExists): ApiResponse {
		return ApiResponse::withStatus($status, ['note' => self::noteJson($note, $articleExists)], ['ETag' => self::etag($note)]);
	}

	/** @param array<string,string> $query */
	private function lookup(array $query): ApiResponse {
		$url = $query['url'] ?? '';
		$normalized = UrlNormalizer::normalize($url);
		if ($normalized === null) {
			throw new InvalidParameterException('"url" must be an http or https URL.');
		}
		$service = $this->service();
		$result = (new UrlLookup($service))->find($normalized);
		$existing = array_flip($service->existingEntryIds(array_map(static fn (UrlMatch $match): string => $match->entryId, $result['matches'])));
		$pageNote = $service->getPageNote($normalized);
		$highlights = $this->highlights();
		return ApiResponse::ok([
			'url' => $url,
			'normalized_url' => $normalized,
			'page_note' => $pageNote === null ? null : self::noteJson($pageNote, false),
			'page_highlights' => array_map(static fn (Highlight $highlight): array => $highlight->toArray(false), $highlights->forPage($normalized)),
			'index_complete' => $result['index_complete'],
			'matches' => array_map(static fn (UrlMatch $match): array => [
				'entry_id' => $match->entryId,
				'greader_item_id' => self::greaderItemId($match->entryId),
				'title' => $match->title,
				'url' => $match->url,
				'published_at' => $match->publishedAt,
				'feed' => ['id' => $match->feedId, 'name' => $match->feedName, 'url' => $match->feedUrl],
				'note' => $match->note === null ? null : self::noteJson($match->note, isset($existing[$match->entryId])),
				'highlights' => array_map(static fn (Highlight $highlight): array => $highlight->toArray(isset($existing[$match->entryId])),
					$highlights->forEntry($match->entryId)),
			], $result['matches']),
		]);
	}

	public static function greaderItemId(string $entryId): string {
		return self::GREADER_ITEM_PREFIX . str_pad(dechex((int)$entryId), 16, '0', STR_PAD_LEFT);
	}

	/** @param array<string,string> $query */
	private function listNotes(array $query): ApiResponse {
		$serverTime = $this->now();
		$limit = self::intParameter($query, 'limit', 1, self::MAX_LIMIT) ?? self::DEFAULT_LIMIT;
		$since = self::intParameter($query, 'since', 0, PHP_INT_MAX);
		$includeDeleted = self::intParameter($query, 'include_deleted', 0, 1);
		$position = [$since ?? 0, 0, $includeDeleted === null ? $since !== null : $includeDeleted === 1];
		if (isset($query['continuation'])) {
			$position = self::decodeContinuation($query['continuation']);
		}
		[$since, $afterId, $withDeleted] = $position;

		$service = $this->service();
		$notes = $service->changedSince($since, $limit + 1, $afterId, $withDeleted);
		$continuation = null;
		if (count($notes) > $limit) {
			$notes = array_slice($notes, 0, $limit);
			$last = $notes[$limit - 1];
			$continuation = self::encodeContinuation($last->updatedAt, $last->id, $withDeleted);
		}
		$existing = array_flip($service->existingEntryIds(self::entryIds($notes)));

		return ApiResponse::ok([
			'notes' => array_map(static fn (Note $note): array => self::noteJson($note, isset($existing[$note->entryId ?? ''])), $notes),
			'continuation' => $continuation,
			'server_time' => $serverTime,
		]);
	}

	/**
	 * @param array<string,string> $query
	 * @throws InvalidParameterException
	 */
	private static function intParameter(array $query, string $name, int $min, int $max): ?int {
		if (!isset($query[$name])) {
			return null;
		}
		$value = $query[$name];
		if (preg_match('/^\d{1,18}$/', $value) !== 1 || (int)$value < $min || (int)$value > $max) {
			throw new InvalidParameterException('"' . $name . '" must be an integer from ' . $min . ' to ' . $max . '.');
		}
		return (int)$value;
	}

	private static function encodeContinuation(int $updatedAt, int $id, bool $includeDeleted): string {
		return rtrim(strtr(base64_encode($updatedAt . ':' . $id . ':' . ($includeDeleted ? 1 : 0)), '+/', '-_'), '=');
	}

	/**
	 * @return array{int,int,bool} since, after id, include deleted
	 * @throws InvalidParameterException
	 */
	private static function decodeContinuation(string $continuation): array {
		$decoded = base64_decode(strtr($continuation, '-_', '+/'), true);
		if ($decoded === false || preg_match('/^(\d{1,18}):(\d{1,18}):([01])$/', $decoded, $matches) !== 1) {
			throw new InvalidParameterException('"continuation" must be a value returned by a previous request.');
		}
		return [(int)$matches[1], (int)$matches[2], $matches[3] === '1'];
	}

	/**
	 * @param list<Note> $notes
	 * @return list<string> the ids of the articles the notes are on
	 */
	private static function entryIds(array $notes): array {
		$ids = [];
		foreach ($notes as $note) {
			if ($note->entryId !== null) {
				$ids[] = $note->entryId;
			}
		}
		return $ids;
	}

	/** @return array<string,mixed> */
	private static function noteJson(Note $note, bool $articleExists): array {
		return [
			'id' => $note->id,
			'entry_id' => $note->entryId,
			'kind' => $note->isPageNote() ? 'page' : 'article',
			'greader_item_id' => $note->entryId === null ? null : self::greaderItemId($note->entryId),
			'url' => $note->url(),
			'normalized_url' => $note->normalizedUrl === '' ? null : $note->normalizedUrl,
			'note' => $note->isDeleted() ? null : $note->note,
			'created_at' => $note->createdAt,
			'created_at_iso' => self::iso($note->createdAt),
			'updated_at' => $note->updatedAt,
			'updated_at_iso' => self::iso($note->updatedAt),
			'deleted_at' => $note->deletedAt,
			'deleted_at_iso' => $note->deletedAt === null ? null : self::iso($note->deletedAt),
			'label_applied' => $note->labelApplied,
			'title' => $note->isPageNote() ? ($note->entryTitle === '' ? null : $note->entryTitle) : $note->entryTitle,
			'article' => $note->isPageNote() ? null : [
				'guid' => $note->entryGuid,
				'url' => $note->entryLink,
				'title' => $note->entryTitle,
				'author' => $note->entryAuthor,
				'published_at' => $note->entryDate,
				'published_at_iso' => self::iso($note->entryDate),
				'feed' => [
					'id' => $note->feedId,
					'name' => $note->feedName,
					'url' => $note->feedUrl,
				],
				'exists' => $articleExists,
			],
		];
	}

	public static function iso(int $timestamp): string {
		return gmdate(DATE_ATOM, $timestamp);
	}

	/** @param array<string,string> $query */
	private function listHighlights(array $query): ApiResponse {
		if (isset($query['entry_id']) || isset($query['url'])) {
			return $this->highlightsOfTarget($query);
		}
		$serverTime = $this->now();
		$limit = self::intParameter($query, 'limit', 1, self::MAX_LIMIT) ?? self::DEFAULT_LIMIT;
		$since = self::intParameter($query, 'since', 0, PHP_INT_MAX);
		$includeDeleted = self::intParameter($query, 'include_deleted', 0, 1);
		$position = [$since ?? 0, 0, $includeDeleted === null ? $since !== null : $includeDeleted === 1];
		if (isset($query['continuation'])) {
			$position = self::decodeContinuation($query['continuation']);
		}
		[$since, $afterId, $withDeleted] = $position;

		$service = $this->highlights();
		$highlights = $service->changedSince($since, $limit + 1, $afterId, $withDeleted);
		$continuation = null;
		if (count($highlights) > $limit) {
			$highlights = array_slice($highlights, 0, $limit);
			$last = $highlights[$limit - 1];
			$continuation = self::encodeContinuation($last->updatedAt, $last->id, $withDeleted);
		}
		return ApiResponse::ok([
			'highlights' => $this->highlightsJson($highlights),
			'continuation' => $continuation,
			'server_time' => $serverTime,
		]);
	}

	/**
	 * The live highlights of an article (`entry_id`) or of what a URL stands for (`url`), in document order.
	 *
	 * @param array<string,string> $query
	 */
	private function highlightsOfTarget(array $query): ApiResponse {
		if (isset($query['entry_id']) && isset($query['url'])) {
			throw new InvalidParameterException('Give "entry_id" or "url", not both.');
		}
		$service = $this->highlights();
		if (isset($query['entry_id'])) {
			if (preg_match('/^[1-9]\d{0,19}$/', $query['entry_id']) !== 1) {
				throw new InvalidParameterException('"entry_id" must be an article id.');
			}
			$highlights = $service->forEntry($query['entry_id']);
		} else {
			$highlights = $service->forUrl($query['url'] ?? '');
		}
		return ApiResponse::ok(['highlights' => $this->highlightsJson($highlights)]);
	}

	/**
	 * @param list<Highlight> $highlights
	 * @return list<array<string,mixed>>
	 */
	private function highlightsJson(array $highlights): array {
		$existing = array_flip($this->highlights()->existingEntryIds(array_values(array_filter(
			array_map(static fn (Highlight $highlight): ?string => $highlight->entryId, $highlights),
			static fn (?string $id): bool => $id !== null,
		))));
		$json = [];
		foreach ($highlights as $highlight) {
			$json[] = $highlight->toArray(isset($existing[$highlight->entryId ?? '']));
		}
		return $json;
	}

	private function oneHighlight(string $uuid): ApiResponse {
		$service = $this->highlights();
		$highlight = $service->get($uuid);
		if ($highlight === null) {
			return ApiResponse::error(404, 'not_found', 'There is no such highlight.');
		}
		return $this->highlightResponse(200, $highlight);
	}

	private function createHighlight(ApiRequest $request): ApiResponse {
		$body = self::jsonBody($request);
		$result = $this->highlights()->create($body);
		return $this->highlightResponse($result['created'] ? 201 : 200, $result['highlight']);
	}

	private function patchHighlight(string $uuid, ApiRequest $request): ApiResponse {
		$body = self::jsonBody($request);
		if (array_key_exists('selector', $body)) {
			throw new HighlightException(HighlightException::SELECTOR_IMMUTABLE,
				'The selector cannot change: delete the highlight and create a new one.');
		}
		$note = $body['note'] ?? null;
		$color = $body['color'] ?? null;
		if (($note === null && $color === null) || ($note !== null && !is_string($note)) || ($color !== null && !is_string($color))) {
			throw new ApiException(400, 'invalid_body', 'The body must be a JSON object with a "note" and/or a "color" string.');
		}
		$service = $this->highlights();
		$current = $service->get($uuid);
		if ($current === null) {
			return ApiResponse::error(404, 'not_found', 'There is no such highlight.');
		}
		if (self::preconditionFails($request->ifMatch, $current->etag())) {
			return self::highlightPreconditionFailed();
		}
		return $this->highlightResponse(200, $service->update($uuid, $note, $color));
	}

	private function deleteHighlight(string $uuid, ApiRequest $request): ApiResponse {
		$service = $this->highlights();
		$current = $service->get($uuid);
		if ($current === null) {
			return ApiResponse::error(404, 'not_found', 'There is no such highlight.');
		}
		if (self::preconditionFails($request->ifMatch, $current->etag())) {
			return self::highlightPreconditionFailed();
		}
		$service->delete($uuid);
		return ApiResponse::noContent();
	}

	private static function highlightPreconditionFailed(): ApiResponse {
		return ApiResponse::error(412, 'precondition_failed', 'The highlight changed since the version in If-Match.');
	}

	private function highlightResponse(int $status, Highlight $highlight): ApiResponse {
		$exists = $highlight->entryId !== null && $this->highlights()->existingEntryIds([$highlight->entryId]) !== [];
		return ApiResponse::withStatus($status, ['highlight' => $highlight->toArray($exists)], ['ETag' => $highlight->etag()]);
	}

	private function highlights(): HighlightService {
		return ($this->highlightService)();
	}

	private function service(): NoteService {
		return ($this->noteService)();
	}

	private function now(): int {
		return $this->clock === null ? time() : ($this->clock)();
	}
}
