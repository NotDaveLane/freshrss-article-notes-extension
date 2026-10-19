<?php

declare(strict_types=1);

use ArticleNotes\NoteBlock;
use ArticleNotes\NoteException;

/** JSON endpoints used by the article note editor: ?c=ArticleNotes&a=save|delete (POST, CSRF token required). */
final class FreshExtension_ArticleNotes_Controller extends Minz_ActionController {
	/** @var array<string,mixed> the JSON body of the current response, written out by the view */
	private static array $body = [];

	#[\Override]
	public function firstAction(): void {
		$this->view->_layout(null);
		$this->view->_path('ArticleNotes/respond.phtml');
	}

	public function saveAction(): void {
		$this->respond($this->save());
	}

	public function deleteAction(): void {
		$this->respond($this->delete());
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
		$extension = Minz_ExtensionManager::findExtension('ArticleNotes');
		if (!$extension instanceof ArticleNotesExtension) {
			throw new RuntimeException('ArticleNotes extension is not loaded');
		}
		return $extension->noteService();
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
