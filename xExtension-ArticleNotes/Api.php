<?php

declare(strict_types=1);

namespace ArticleNotes;

/**
 * The read-only notes API (`p/api/misc.php/ArticleNotes/v1/...`). It authenticates with the user's FreshRSS
 * API password, like the Google Reader API, and only ever reads the authenticated user's notes.
 */
final class Api {
	public const API_VERSION = 1;
	public const DEFAULT_LIMIT = 100;
	public const MAX_LIMIT = 500;
	private const GREADER_ITEM_PREFIX = 'tag:google.com,2005:reader/item/';

	/**
	 * @param \Closure():NoteService $noteService the note service of the user authenticated by {@see handle()}
	 * @param (\Closure():int)|null $clock current Unix time (default: time())
	 */
	public function __construct(
		private readonly string $extensionName,
		private readonly string $extensionVersion,
		private readonly \Closure $noteService,
		private readonly ?\Closure $clock = null,
	) {
	}

	public function handle(ApiRequest $request): ApiResponse {
		$path = trim($request->path, '/');
		if ($path === 'v1/status') {
			$route = fn (): ApiResponse => $this->status();
		} elseif ($path === 'v1/notes') {
			$route = fn (): ApiResponse => $this->listNotes($request->query);
		} elseif (preg_match('#^v1/notes/(\d{1,20})$#', $path, $matches) === 1) {
			$entryId = $matches[1];
			$route = fn (): ApiResponse => $this->oneNote($entryId);
		} else {
			return ApiResponse::error(404, 'not_found', 'Unknown API path.');
		}

		if ($request->method !== 'GET' && $request->method !== 'HEAD') {
			return ApiResponse::error(405, 'method_not_allowed', 'This API is read-only: use GET or HEAD.', ['Allow' => 'GET, HEAD']);
		}

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
		} catch (\Throwable $exception) {
			\Minz_Log::error('ArticleNotes API failure for user ' . (\Minz_User::name() ?? '') . ': ' . $exception->getMessage());
			return ApiResponse::error(500, 'internal_error', 'The notes cannot be read right now.');
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
		return ApiResponse::ok(['note' => self::noteJson($note, $service->existingEntryIds([$note->entryId]) !== [])]);
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
		$existing = array_flip($service->existingEntryIds(array_map(static fn (Note $note): string => $note->entryId, $notes)));

		return ApiResponse::ok([
			'notes' => array_map(static fn (Note $note): array => self::noteJson($note, isset($existing[$note->entryId])), $notes),
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

	/** @return array<string,mixed> */
	private static function noteJson(Note $note, bool $articleExists): array {
		return [
			'id' => $note->id,
			'entry_id' => $note->entryId,
			'greader_item_id' => self::GREADER_ITEM_PREFIX . str_pad(dechex((int)$note->entryId), 16, '0', STR_PAD_LEFT),
			'note' => $note->isDeleted() ? null : $note->note,
			'created_at' => $note->createdAt,
			'created_at_iso' => self::iso($note->createdAt),
			'updated_at' => $note->updatedAt,
			'updated_at_iso' => self::iso($note->updatedAt),
			'deleted_at' => $note->deletedAt,
			'deleted_at_iso' => $note->deletedAt === null ? null : self::iso($note->deletedAt),
			'label_applied' => $note->labelApplied,
			'article' => [
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

	private static function iso(int $timestamp): string {
		return gmdate(DATE_ATOM, $timestamp);
	}

	private function service(): NoteService {
		return ($this->noteService)();
	}

	private function now(): int {
		return $this->clock === null ? time() : ($this->clock)();
	}
}
