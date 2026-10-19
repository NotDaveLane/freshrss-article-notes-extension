<?php

declare(strict_types=1);

require_once __DIR__ . '/Api.php';
require_once __DIR__ . '/ApiRequest.php';
require_once __DIR__ . '/ApiResponse.php';
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

final class ArticleNotesExtension extends Minz_Extension {
	public const MIN_FRESHRSS_VERSION = '1.27.0';
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
		$this->registerHook('freshrss_user_maintenance', [$this, 'ensureSchema']);
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
		return new ArticleNotes\Api($this->getName(), $this->getVersion(), fn (): ArticleNotes\NoteService => $this->noteService());
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

	#[\Override]
	public function uninstall(): bool|string {
		foreach (self::users() as $user) {
			try {
				(new ArticleNotes\Storage($user))->dropTables();
				$configuration = self::userConfiguration($user);
				if ($configuration !== null && $configuration->hasParam('extensions')) {
					$extensions = $configuration->extensions;
					unset($extensions[$this->getName()]);
					$configuration->extensions = $extensions;
					$configuration->save();
				}
				@unlink(DATA_PATH . '/users/' . $user . '/articlenotes-migration.lock');
			} catch (\Throwable $exception) {
				return 'Cannot remove ArticleNotes data of user ' . $user . ': ' . $exception->getMessage();
			}
		}
		$this->removeUserConfiguration();
		$this->removeSystemConfiguration();
		return true;
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
	private static function users(): array {
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
