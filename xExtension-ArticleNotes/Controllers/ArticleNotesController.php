<?php

declare(strict_types=1);

use ArticleNotes\HighlightException;
use ArticleNotes\NoteBlock;
use ArticleNotes\NoteException;
use ArticleNotes\NotesBrowser;
use ArticleNotes\NotesExport;
use ArticleNotes\NotesFilter;
use ArticleNotes\NotesPage;

/**
 * JSON endpoints used by the article note editor: ?c=ArticleNotes&a=save|delete (POST, CSRF token required),
 * the per-user settings page (?c=ArticleNotes&a=settings), and the Notes page: ?c=ArticleNotes&a=notes (list, GET),
 * notesEdit (POST, CSRF token required) and notesExport (GET, a file download).
 */
final class FreshExtension_ArticleNotes_Controller extends FreshRSS_ActionController {
	/** @var array<string,mixed> the JSON body of the current response, written out by the view */
	private static array $body = [];
	private static ?NotesPage $page = null;
	/** @var array{type:string,filename:string,body:string} the file of the current export, written out by the view */
	private static array $file = ['type' => 'text/plain', 'filename' => '', 'body' => ''];

	#[\Override]
	public function firstAction(): void {
		$action = Minz_Request::actionName();
		if (in_array($action, ['settings', 'notes'], true)) {
			if (!FreshRSS_Auth::hasAccess()) {
				Minz_Error::error(403);
			}
			return;
		}
		if ($action === 'notesExport') {
			if (!FreshRSS_Auth::hasAccess()) {
				Minz_Error::error(403);
			}
			$this->view->_layout(null);
			$this->view->_path('ArticleNotes/export.phtml');
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

	public function notesAction(): void {
		self::$page = $this->notesPage();
		FreshRSS_View::prependTitle(_t('ext.article_notes.notes.title') . ' · ');
	}

	/** The page of the Notes list that the request asks for (filters and `page` in the query string). */
	public function notesPage(): NotesPage {
		$filter = NotesFilter::fromParams(self::filterParams());
		$browser = new NotesBrowser(self::extension()->noteService()->pdo());
		$number = max(1, Minz_Request::paramInt('page'));
		$result = $browser->groups($filter, $number);
		$last = max(1, (int)ceil($result['total'] / NotesBrowser::PER_PAGE));
		if ($number > $last) {	// e.g. after deleting the last annotation of the last page
			$number = $last;
			$result = $browser->groups($filter, $number);
		}
		return new NotesPage($filter, $result['groups'], $result['total'], $number, $browser->feeds());
	}

	public static function page(): NotesPage {
		return self::$page ?? throw new RuntimeException('The Notes page is not loaded');
	}

	/** @return array<string,string> the filter parameters of the request */
	private static function filterParams(): array {
		$params = [];
		foreach (['q', 'kind', 'color', 'feed', 'has_note'] as $name) {
			if (Minz_Request::hasParam($name)) {
				$params[$name] = Minz_Request::paramString($name, true);
			}
		}
		return $params;
	}

	/**
	 * Edits or deletes one annotation of the Notes page, then shows the same page of the list again.
	 *
	 * Parameters: `type` (article, page or highlight), the target (`id` of the article, `url` of the page, `uuid` of the
	 * highlight), `op` (save or delete), `note`, and for a highlight `color`; `back` is the query string to return to.
	 */
	public function notesEditAction(): void {
		$error = $this->notesEdit();
		$back = [];
		parse_str(Minz_Request::paramString('back', true), $back);
		$params = [];
		foreach ($back as $name => $value) {
			if (is_string($name) && is_string($value)) {
				$params[$name] = $value;
			}
		}
		/** @var array<string,string> $query */
		$query = NotesFilter::fromParams($params)->toParams();
		if (ctype_digit($params['page'] ?? '') && (int)$params['page'] > 1) {
			$query['page'] = (string)(int)$params['page'];
		}
		$url = ['c' => 'ArticleNotes', 'a' => 'notes', 'params' => $query];
		if ($error === null) {
			Minz_Request::good(_t('ext.article_notes.notes.' . (Minz_Request::paramString('op', true) === 'delete' ? 'deleted' : 'saved')), $url);
		} else {
			Minz_Request::bad($error, $url);
		}
	}

	/** Applies the edit of {@see notesEditAction()}. @return string|null the error message, null when done */
	public function notesEdit(): ?string {
		if (!FreshRSS_Auth::hasAccess()) {
			return _t('ext.article_notes.error.signed_out');
		}
		if (!Minz_Request::isPost() || !FreshRSS_Auth::isCsrfOk()) {
			return _t('ext.article_notes.error.csrf');
		}
		$delete = Minz_Request::paramString('op', true) === 'delete';
		$note = Minz_Request::paramString('note', true);
		try {
			switch (Minz_Request::paramString('type', true)) {
				case 'article':
					$entryId = Minz_Request::paramString('id', true);
					if (!ctype_digit($entryId)) {
						return _t('ext.article_notes.error.unknown_entry');
					}
					$delete ? self::service()->delete($entryId) : self::service()->save($entryId, $note);
					break;
				case 'page':
					$url = Minz_Request::paramString('url', true);
					$existing = self::service()->getPageNote($url);
					if ($existing === null) {
						return _t('ext.article_notes.notes.not_found');
					}
					$delete ? self::service()->deletePageNote($url) : self::service()->savePageNote($url, $note, $existing->entryTitle);
					break;
				case 'highlight':
					$service = self::extension()->highlightService();
					$uuid = Minz_Request::paramString('uuid', true);
					if ($delete) {
						if (!$service->delete($uuid)) {
							return _t('ext.article_notes.notes.not_found');
						}
					} else {
						$service->update($uuid, $note, Minz_Request::hasParam('color') ? Minz_Request::paramString('color', true) : null);
					}
					break;
				default:
					return _t('ext.article_notes.error.bad_request');
			}
		} catch (NoteException $exception) {
			return $exception->getCode() === NoteException::TOO_LONG
				? _t('ext.article_notes.error.too_long') : _t('ext.article_notes.error.bad_request');
		} catch (HighlightException $exception) {
			return match ($exception->errorCode) {
				HighlightException::NOT_FOUND => _t('ext.article_notes.notes.not_found'),
				HighlightException::INVALID_COLOR, HighlightException::NOTE_TOO_LONG => _t('ext.article_notes.error.highlight_' . $exception->errorCode),
				default => _t('ext.article_notes.error.bad_request'),
			};
		} catch (\Throwable $exception) {
			Minz_Log::error('ArticleNotes request failed: ' . $exception->getMessage());
			return _t('ext.article_notes.error.server');
		}
		return null;
	}

	public function notesExportAction(): void {
		self::$file = $this->export();
		header('Content-Type: ' . self::$file['type']);
		header('Content-Disposition: attachment; filename="' . self::$file['filename'] . '"');
		header('X-Content-Type-Options: nosniff');
	}

	/**
	 * The download of everything the current filters match: `format` markdown (default) or json (W3C Web Annotation).
	 *
	 * @return array{type:string,filename:string,body:string}
	 */
	public function export(): array {
		$groups = (new NotesBrowser(self::extension()->noteService()->pdo()))->all(NotesFilter::fromParams(self::filterParams()));
		$name = 'articlenotes-' . gmdate('Y-m-d');
		if (Minz_Request::paramString('format', true) === 'json') {
			return ['type' => 'application/ld+json; profile="http://www.w3.org/ns/anno.jsonld"; charset=UTF-8', 'filename' => $name . '.jsonld',
				'body' => NotesExport::webAnnotations($groups)];
		}
		return ['type' => 'text/markdown; charset=UTF-8', 'filename' => $name . '.md',
			'body' => NotesExport::markdown($groups, _t('ext.article_notes.notes.export_heading'))];
	}

	/** @return array{type:string,filename:string,body:string} */
	public static function file(): array {
		return self::$file;
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
