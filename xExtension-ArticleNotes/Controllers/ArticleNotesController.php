<?php

declare(strict_types=1);

use ArticleNotes\HighlightException;
use ArticleNotes\NoteBlock;
use ArticleNotes\NoteException;

/**
 * JSON endpoints used by the article note editor: ?c=ArticleNotes&a=save|delete (POST, CSRF token required),
 * and the per-user settings page: ?c=ArticleNotes&a=settings.
 */
final class FreshExtension_ArticleNotes_Controller extends FreshRSS_ActionController {
	/** @var array<string,mixed> the JSON body of the current response, written out by the view */
	private static array $body = [];

	#[\Override]
	public function firstAction(): void {
		if (Minz_Request::actionName() === 'settings') {
			if (!FreshRSS_Auth::hasAccess()) {
				Minz_Error::error(403);
			}
			return;
		}
		$this->view->_layout(null);
		$this->view->_path('ArticleNotes/respond.phtml');
	}

	public function settingsAction(): void {
		if (Minz_Request::isPost()) {
			$error = $this->saveSettings();
			$url = ['c' => 'ArticleNotes', 'a' => 'settings'];
			if ($error === null) {
				Minz_Request::good(_t('feedback.conf.updated'), $url);
			} else {
				Minz_Request::bad($error, $url);
			}
		}
		FreshRSS_View::prependTitle(_t('ext.article_notes.settings.title') . ' · ');
	}

	/** Validates and stores the submitted settings. @return string|null the error message, null when saved */
	public function saveSettings(): ?string {
		if (!FreshRSS_Auth::hasAccess()) {
			return _t('ext.article_notes.error.signed_out');
		}
		if (!Minz_Request::isPost() || !FreshRSS_Auth::isCsrfOk()) {
			return _t('ext.article_notes.error.csrf');
		}
		try {
			self::extension()->settings()->save(
				Minz_Request::paramBoolean('label_enabled'),
				Minz_Request::paramString('label_name', true),
			);
		} catch (\InvalidArgumentException) {
			return _t('ext.article_notes.settings.label_name_invalid', ArticleNotes\Settings::MAX_LABEL_NAME_LENGTH);
		}
		return null;
	}

	public function saveAction(): void {
		$this->respond($this->save());
	}

	public function deleteAction(): void {
		$this->respond($this->delete());
	}

	public function highlightsAction(): void {
		$this->respond($this->highlights());
	}

	public function highlightCreateAction(): void {
		$this->respond($this->highlightCreate());
	}

	public function highlightUpdateAction(): void {
		$this->respond($this->highlightUpdate());
	}

	public function highlightDeleteAction(): void {
		$this->respond($this->highlightDelete());
	}

	/**
	 * The live highlights of an article (`entry_id`) in document order; a read, so no CSRF token is needed.
	 *
	 * @return array{status:int,body:array<string,mixed>}
	 */
	public function highlights(): array {
		if (!FreshRSS_Auth::hasAccess()) {
			return self::error(403, 'signed_out');
		}
		$entryId = Minz_Request::paramString('entry_id', true);
		if (!ctype_digit($entryId)) {
			return self::error(404, 'unknown_entry');
		}
		try {
			$service = self::extension()->highlightService();
			$highlights = $service->forEntry($entryId);
			$exists = $highlights !== [] && $service->existingEntryIds([$entryId]) !== [];
		} catch (\Throwable $exception) {
			return self::failure($exception);
		}
		return ['status' => 200, 'body' => ['ok' => true, 'highlights' => array_map(
			static fn (ArticleNotes\Highlight $highlight): array => $highlight->toArray($exists), $highlights)]];
	}

	/**
	 * Creates a highlight from `entry_id`, `source`, `selector` (JSON), and optionally `color`, `note`, `uuid`, `title`.
	 *
	 * @return array{status:int,body:array<string,mixed>}
	 */
	public function highlightCreate(): array {
		return $this->highlightWrite(function (ArticleNotes\HighlightService $service): array {
			$input = [];
			foreach (['uuid', 'entry_id', 'url', 'title', 'source', 'color', 'note'] as $name) {
				if (Minz_Request::hasParam($name)) {
					$input[$name] = Minz_Request::paramString($name, true);
				}
			}
			$selector = Minz_Request::paramString('selector', true);
			$input['selector'] = $selector === '' ? null : json_decode($selector, true);
			$result = $service->create($input);
			return [$result['highlight'], $result['created'] ? 201 : 200];
		});
	}

	/**
	 * Changes the `note` and/or `color` of the highlight `uuid`.
	 *
	 * @return array{status:int,body:array<string,mixed>}
	 */
	public function highlightUpdate(): array {
		return $this->highlightWrite(static function (ArticleNotes\HighlightService $service): array {
			if (Minz_Request::hasParam('selector')) {
				throw new HighlightException(HighlightException::SELECTOR_IMMUTABLE, 'The selector cannot change.');
			}
			$note = Minz_Request::hasParam('note') ? Minz_Request::paramString('note', true) : null;
			$color = Minz_Request::hasParam('color') ? Minz_Request::paramString('color', true) : null;
			if ($note === null && $color === null) {
				throw new HighlightException(HighlightException::INVALID_FIELD, 'Give a note and/or a color.');
			}
			return [$service->update(Minz_Request::paramString('uuid', true), $note, $color), 200];
		});
	}

	/** @return array{status:int,body:array<string,mixed>} */
	public function highlightDelete(): array {
		$denied = $this->guard();
		if ($denied !== null) {
			return $denied;
		}
		try {
			self::extension()->highlightService()->delete(Minz_Request::paramString('uuid', true));
		} catch (\Throwable $exception) {
			return self::failure($exception);
		}
		return ['status' => 200, 'body' => ['ok' => true]];
	}

	/**
	 * Runs a highlight write behind the sign-in, POST and CSRF checks.
	 *
	 * @param \Closure(ArticleNotes\HighlightService):array{ArticleNotes\Highlight,int} $write
	 * @return array{status:int,body:array<string,mixed>}
	 */
	private function highlightWrite(\Closure $write): array {
		$denied = $this->guard();
		if ($denied !== null) {
			return $denied;
		}
		try {
			$service = self::extension()->highlightService();
			[$highlight, $status] = $write($service);
			$exists = $highlight->entryId !== null && $service->existingEntryIds([$highlight->entryId]) !== [];
		} catch (HighlightException $exception) {
			return ['status' => $exception->status(), 'body' => [
				'ok' => false, 'code' => $exception->errorCode, 'error' => _t('ext.article_notes.error.highlight_' . $exception->errorCode),
			]];
		} catch (\Throwable $exception) {
			return self::failure($exception);
		}
		return ['status' => $status, 'body' => ['ok' => true, 'highlight' => $highlight->toArray($exists)]];
	}

	/** @return array<string,mixed> */
	public static function body(): array {
		return self::$body;
	}

	/** @return array{status:int,body:array<string,mixed>} */
	public function save(): array {
		$denied = $this->guard();
		if ($denied !== null) {
			return $denied;
		}
		$entryId = Minz_Request::paramString('id', true);
		if (!ctype_digit($entryId)) {
			return self::error(404, 'unknown_entry');
		}
		try {
			$note = self::service()->save($entryId, Minz_Request::paramString('note', true));
		} catch (NoteException $exception) {
			return match ($exception->getCode()) {
				NoteException::TOO_LONG => self::error(422, 'too_long'),
				default => self::error(404, 'unknown_entry'),
			};
		} catch (\Throwable $exception) {
			return self::failure($exception);
		}
		return ['status' => 200, 'body' => ['ok' => true, 'html' => $note === null ? '' : NoteBlock::html($note)]];
	}

	/** @return array{status:int,body:array<string,mixed>} */
	public function delete(): array {
		$denied = $this->guard();
		if ($denied !== null) {
			return $denied;
		}
		$entryId = Minz_Request::paramString('id', true);
		if (!ctype_digit($entryId)) {
			return self::error(404, 'unknown_entry');
		}
		try {
			self::service()->delete($entryId);
		} catch (\Throwable $exception) {
			return self::failure($exception);
		}
		return ['status' => 200, 'body' => ['ok' => true, 'html' => '']];
	}

	/** @return array{status:int,body:array<string,mixed>}|null the refusal, or null when the request may proceed */
	private function guard(): ?array {
		if (!FreshRSS_Auth::hasAccess()) {
			return self::error(403, 'signed_out');
		}
		if (!Minz_Request::isPost()) {
			return self::error(405, 'bad_request');
		}
		if (!FreshRSS_Auth::isCsrfOk()) {
			return self::error(403, 'csrf');
		}
		return null;
	}

	private static function service(): ArticleNotes\NoteService {
		return self::extension()->noteService();
	}

	private static function extension(): ArticleNotesExtension {
		$extension = Minz_ExtensionManager::findExtension('ArticleNotes');
		if (!$extension instanceof ArticleNotesExtension) {
			throw new RuntimeException('ArticleNotes extension is not loaded');
		}
		return $extension;
	}

	/** @return array{status:int,body:array<string,mixed>} */
	private static function error(int $status, string $key): array {
		return ['status' => $status, 'body' => ['ok' => false, 'error' => _t('ext.article_notes.error.' . $key)]];
	}

	/** @return array{status:int,body:array<string,mixed>} */
	private static function failure(\Throwable $exception): array {
		Minz_Log::error('ArticleNotes request failed: ' . $exception->getMessage());
		return self::error(500, 'server');
	}

	/** @param array{status:int,body:array<string,mixed>} $response */
	private function respond(array $response): void {
		http_response_code($response['status']);
		header('Content-Type: application/json; charset=UTF-8');
		self::$body = $response['body'];
	}
}
