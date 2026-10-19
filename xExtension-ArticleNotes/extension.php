<?php

declare(strict_types=1);

require_once __DIR__ . '/Api.php';
require_once __DIR__ . '/ApiException.php';
require_once __DIR__ . '/ApiRequest.php';
require_once __DIR__ . '/ApiResponse.php';
require_once __DIR__ . '/ArticleSnapshot.php';
require_once __DIR__ . '/Highlight.php';
require_once __DIR__ . '/HighlightDAO.php';
require_once __DIR__ . '/HighlightException.php';
require_once __DIR__ . '/HighlightService.php';
require_once __DIR__ . '/InvalidParameterException.php';
require_once __DIR__ . '/LabelApplier.php';
require_once __DIR__ . '/Migration.php';
require_once __DIR__ . '/Migrator.php';
require_once __DIR__ . '/Note.php';
require_once __DIR__ . '/NoteBlock.php';
require_once __DIR__ . '/NoteDAO.php';
require_once __DIR__ . '/NoteException.php';
require_once __DIR__ . '/NoteService.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/Storage.php';
require_once __DIR__ . '/UrlIndex.php';
require_once __DIR__ . '/UrlLookup.php';
require_once __DIR__ . '/UrlMatch.php';
require_once __DIR__ . '/UrlNormalizer.php';

final class ArticleNotesExtension extends Minz_Extension {
	public const MIN_FRESHRSS_VERSION = '1.27.0';
	/** Articles indexed per user maintenance run. */
	private const MAINTENANCE_INDEX_BATCH = 2000;
	private const SCHEMA_VERSION_KEY = 'schema_version';

	/** @var array<string,bool> per-request memo: user => migrated successfully */
	private array $migrated = [];

	#[\Override]
	public function init(): void {
		parent::init();

		$this->registerTranslates();
		$this->registerController('ArticleNotes');
		$this->registerViews();
		Minz_View::appendStyle($this->getFileUrl('article-notes.css'));
		Minz_View::appendScript($this->getFileUrl('article-notes.js'));
		Minz_View::appendScript($this->getFileUrl('highlights.js'));
		$this->registerHooks(FRESHRSS_VERSION);
	}

	public static function isSupportedFreshRss(string $version): bool {
		return version_compare($version, self::MIN_FRESHRSS_VERSION, '>=');
	}

	/**
	 * Hooks are registered by name: FreshRSS 1.27 has no `Minz_HookType` enum, and newer versions map the
	 * names to its cases.
	 */
	public function registerHooks(string $freshrssVersion): void {
		$this->registerHook('freshrss_user_maintenance', [$this, 'maintain']);
		$this->registerHook('entry_before_update', [$this, 'entryBeforeUpdate']);
		$this->registerHook('entry_before_display', [$this, 'appendNote']);
		$this->registerHook('js_vars', [$this, 'jsVars']);
		$this->registerHook('menu_configuration_entry', [$this, 'menuEntry']);
		if (self::isSupportedFreshRss($freshrssVersion)) {
			$this->registerHook('api_misc', [$this, 'handleApi']);
		} else {
			Minz_Log::warning('ArticleNotes requires FreshRSS ' . self::MIN_FRESHRSS_VERSION . ' or newer (running '
				. $freshrssVersion . '): the API endpoint is disabled.');
		}
	}

	/**
	 * User maintenance (after FreshRSS refreshed the feeds, and from `cli/actualize-user.php`): migrates the user's
	 * schema, then keeps the URL index current. FreshRSS renumbers new articles when it commits them, so they cannot
	 * be indexed from the `entry_before_add` hook: this indexes them once they are stored.
	 */
	public function maintain(): void {
		if (!$this->ensureSchema()) {
			return;
		}
		try {
			$index = $this->noteService()->urlIndex();
			$index->prune();
			$index->backfill(self::MAINTENANCE_INDEX_BATCH);
		} catch (\Throwable $exception) {
			Minz_Log::error('ArticleNotes cannot update the URL index: ' . $exception->getMessage());
		}
	}

	/** An updated article may have a new link: its index rows are dropped and rebuilt by the next {@see maintain()}. */
	public function entryBeforeUpdate(FreshRSS_Entry $entry): FreshRSS_Entry {
		try {
			if ($this->ensureSchema()) {
				$this->noteService()->urlIndex()->forgetByGuid($entry->feedId(), $entry->guid());
			}
		} catch (\Throwable $exception) {
			Minz_Log::error('ArticleNotes cannot update the URL index of an article: ' . $exception->getMessage());
		}
		return $entry;
	}

	/** The entry of the Configuration menu that leads to the user's settings page. */
	public function menuEntry(): string {
		$active = Minz_Request::controllerName() === 'ArticleNotes' && Minz_Request::actionName() === 'settings';
		return '<li class="item' . ($active ? ' active' : '') . '"><a href="'
			. Minz_Url::display(['c' => 'ArticleNotes', 'a' => 'settings']) . '">'
			. htmlspecialchars(_t('ext.article_notes.settings.menu'), ENT_QUOTES, 'UTF-8') . '</a></li>';
	}

	/** The current user's settings. */
	public function settings(): ArticleNotes\Settings {
		return new ArticleNotes\Settings(
			fn (string $key): mixed => $this->getUserConfigurationValue($key),
			$this->setUserValue(...),
		);
	}

	/**
	 * Stores one value of the current user's extension configuration, on every supported FreshRSS version.
	 *
	 * @param non-empty-string $key
	 */
	private function setUserValue(string $key, mixed $value): void {
		if (is_callable([$this, 'setUserConfigurationValue'])) {	// recent FreshRSS
			$this->setUserConfigurationValue($key, $value);
		} else {
			$this->setUserValueLegacy($key, $value);
		}
	}

	/** {@see setUserValue()} for FreshRSS versions without `setUserConfigurationValue()` (1.27, 1.28). */
	private function setUserValueLegacy(string $key, mixed $value): void {
		$configuration = $this->getUserConfiguration();
		$configuration[$key] = $value;
		$this->setUserConfiguration($configuration);
	}

	/** The URL of the notes API of this instance. */
	public static function apiUrl(): string {
		return rtrim(FreshRSS_Context::systemConf()->base_url, '/') . '/api/misc.php/ArticleNotes/';
	}

	/** Answers `p/api/misc.php/ArticleNotes/...` (ApiMisc hook, FreshRSS >= 1.27.0). */
	public function handleApi(): void {
		$request = ArticleNotes\ApiRequest::fromGlobals($this->getName());
		$this->api()->handle($request)->send($request->method !== 'HEAD');
	}

	/** The read-only notes API; it authenticates the user itself. */
	public function api(): ArticleNotes\Api {
		return new ArticleNotes\Api($this->getName(), $this->getVersion(), fn (): ArticleNotes\NoteService => $this->noteService(),
			fn (): ArticleNotes\HighlightService => $this->highlightService());
	}

	/** Appends the user's note, when there is one, to the article content. */
	public function appendNote(FreshRSS_Entry $entry): FreshRSS_Entry {
		if (!FreshRSS_Auth::hasAccess()) {
			return $entry;
		}
		try {
			$note = $this->noteService()->get($entry->id());
		} catch (\Throwable $exception) {
			Minz_Log::error('ArticleNotes cannot load the note of article ' . $entry->id() . ': ' . $exception->getMessage());
			return $entry;
		}
		if ($note !== null) {
			$entry->_content($entry->originalContent() . ArticleNotes\NoteBlock::html($note));
		}
		return $entry;
	}

	/**
	 * Settings and translated strings for the editor script (`context.extensions.ArticleNotes`).
	 *
	 * @param array<string,mixed> $vars
	 * @return array<string,mixed>
	 */
	public function jsVars(array $vars): array {
		$vars['ArticleNotes'] = [
			'enabled' => FreshRSS_Auth::hasAccess(),
			'maxLength' => ArticleNotes\NoteService::MAX_LENGTH,
			'saveUrl' => Minz_Url::display(['c' => 'ArticleNotes', 'a' => 'save'], 'php'),
			'deleteUrl' => Minz_Url::display(['c' => 'ArticleNotes', 'a' => 'delete'], 'php'),
			'highlightsUrl' => Minz_Url::display(['c' => 'ArticleNotes', 'a' => 'highlights'], 'php'),
			'highlightCreateUrl' => Minz_Url::display(['c' => 'ArticleNotes', 'a' => 'highlightCreate'], 'php'),
			'highlightUpdateUrl' => Minz_Url::display(['c' => 'ArticleNotes', 'a' => 'highlightUpdate'], 'php'),
			'highlightDeleteUrl' => Minz_Url::display(['c' => 'ArticleNotes', 'a' => 'highlightDelete'], 'php'),
			'anchoringUrl' => html_entity_decode($this->getFileUrl('anchoring.js'), ENT_QUOTES, 'UTF-8'),
			'colors' => ArticleNotes\Highlight::COLORS,
			'maxQuoteLength' => 2000,
			'i18n' => [
				'action' => _t('ext.article_notes.note.action'),
				'actionTitle' => _t('ext.article_notes.note.action_title'),
				'label' => _t('ext.article_notes.note.label'),
				'save' => _t('ext.article_notes.note.save'),
				'cancel' => _t('ext.article_notes.note.cancel'),
				'delete' => _t('ext.article_notes.note.delete'),
				'confirmDelete' => _t('ext.article_notes.note.confirm_delete'),
				'counter' => _t('ext.article_notes.note.counter'),
				'saving' => _t('ext.article_notes.note.saving'),
				'network' => _t('ext.article_notes.error.network'),
				'highlight' => _t('ext.article_notes.highlight.highlight'),
				'addNote' => _t('ext.article_notes.highlight.add_note'),
				'color' => _t('ext.article_notes.highlight.color'),
				'tooLong' => _t('ext.article_notes.highlight.too_long'),
				'toolbar' => _t('ext.article_notes.highlight.toolbar'),
				'popoverTitle' => _t('ext.article_notes.highlight.popover'),
				'noteLabel' => _t('ext.article_notes.highlight.note_label'),
				'highlightSave' => _t('ext.article_notes.highlight.save'),
				'highlightDelete' => _t('ext.article_notes.highlight.delete'),
				'close' => _t('ext.article_notes.highlight.close'),
				'highlightFailed' => _t('ext.article_notes.highlight.failed'),
				'markLabel' => _t('ext.article_notes.highlight.mark_label'),
				'noteIndicator' => _t('ext.article_notes.highlight.has_note'),
				'panelTitle' => _t('ext.article_notes.highlight.panel_title'),
				'listTitle' => _t('ext.article_notes.highlight.list_title'),
				'orphan' => _t('ext.article_notes.highlight.orphan'),
				'jumpTo' => _t('ext.article_notes.highlight.jump_to'),
				'jumpToLabel' => _t('ext.article_notes.highlight.jump_to_label'),
				'edit' => _t('ext.article_notes.highlight.edit'),
				'colorYellow' => _t('ext.article_notes.highlight.color_yellow'),
				'colorGreen' => _t('ext.article_notes.highlight.color_green'),
				'colorBlue' => _t('ext.article_notes.highlight.color_blue'),
				'colorPink' => _t('ext.article_notes.highlight.color_pink'),
				'colorPurple' => _t('ext.article_notes.highlight.color_purple'),
			],
		];
		return $vars;
	}

	/**
	 * Brings the current user's schema up to date. Call before the first use of extension storage in a request;
	 * it is a no-op once the user is migrated.
	 *
	 * @return bool whether the schema is up to date
	 */
	public function ensureSchema(): bool {
		$user = Minz_User::name() ?? '';
		if ($user === '') {
			return false;
		}
		if (!isset($this->migrated[$user])) {
			try {
				$this->migrated[$user] = true;
				$this->migrator($user)->migrate();
			} catch (\Throwable $exception) {
				$this->migrated[$user] = false;
				Minz_Log::error('ArticleNotes migration failed for user ' . $user . ': ' . $exception->getMessage());
			}
		}
		return $this->migrated[$user];
	}

	/** The note service of the current user; it migrates the user's schema before first use. */
	public function noteService(): ArticleNotes\NoteService {
		$user = Minz_User::name() ?? '';
		$service = new ArticleNotes\NoteService($user, $this->ensureSchema(...));
		(new ArticleNotes\LabelApplier($user, $this->settings()))->listen($service);
		return $service;
	}

	/** The highlight service of the current user (it shares the note service's storage and label bookkeeping). */
	public function highlightService(): ArticleNotes\HighlightService {
		$user = Minz_User::name() ?? '';
		$service = new ArticleNotes\HighlightService($user, $this->noteService());
		(new ArticleNotes\LabelApplier($user, $this->settings()))->listenHighlights($service);
		return $service;
	}

	private function migrator(string $user): ArticleNotes\Migrator {
		return new ArticleNotes\Migrator(
			__DIR__ . '/Migrations',
			(new ArticleNotes\Storage($user))->pdo(),
			function (): int {
				$version = $this->getUserConfigurationValue(self::SCHEMA_VERSION_KEY);
				return is_numeric($version) ? (int)$version : 0;
			},
			fn (int $version) => $this->setUserValue(self::SCHEMA_VERSION_KEY, $version),
			DATA_PATH . '/users/' . $user . '/articlenotes-migration.lock',
		);
	}

	/**
	 * Keeps every user's notes and settings: FreshRSS calls uninstall() when an administrator *disables* the extension
	 * (and its "Remove" button only deletes the directory), so it must not destroy data. `cli/delete-data.php` deletes
	 * it on purpose ({@see deleteUserData()}).
	 */
	#[\Override]
	public function uninstall(): bool|string {
		return true;
	}

	/** Permanently deletes one user's ArticleNotes data: the extension's tables and the user's extension settings. */
	public static function deleteUserData(string $user): void {
		(new ArticleNotes\Storage($user))->dropTables();
		$configuration = self::userConfiguration($user);
		if ($configuration !== null && $configuration->hasParam('extensions')) {
			$extensions = $configuration->extensions;
			unset($extensions['ArticleNotes']);
			$configuration->extensions = $extensions;
			$configuration->save();
		}
		@unlink(DATA_PATH . '/users/' . $user . '/articlenotes-migration.lock');
	}

	/** A user's configuration (like `FreshRSS_UserConfiguration::getForUser()`, which FreshRSS 1.27 does not have). */
	public static function userConfiguration(string $user): ?FreshRSS_UserConfiguration {
		if (!FreshRSS_user_Controller::checkUsername($user)) {
			return null;
		}
		try {
			FreshRSS_UserConfiguration::register('user_' . $user, USERS_PATH . '/' . $user . '/config.php',
				FRESHRSS_PATH . '/config-user.default.php');
			return FreshRSS_UserConfiguration::get('user_' . $user);
		} catch (Minz_FileNotExistException) {
			return null;
		}
	}

	/**
	 * The users of this instance (like `FreshRSS_user_Controller::listUsers()`, which FreshRSS 1.27 does not have).
	 *
	 * @return list<string>
	 */
	public static function users(): array {
		$users = [];
		$names = scandir(DATA_PATH . '/users');
		foreach ($names === false ? [] : $names as $name) {
			if ($name[0] !== '.' && $name !== '_' && is_file(DATA_PATH . '/users/' . $name . '/config.php')) {
				$users[] = $name;
			}
		}
		return $users;
	}
}
