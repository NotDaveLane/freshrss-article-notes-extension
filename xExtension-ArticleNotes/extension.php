<?php

declare(strict_types=1);

require_once __DIR__ . '/Migration.php';
require_once __DIR__ . '/Migrator.php';
require_once __DIR__ . '/Note.php';
require_once __DIR__ . '/NoteDAO.php';
require_once __DIR__ . '/NoteException.php';
require_once __DIR__ . '/NoteService.php';
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
		$this->registerHooks(FRESHRSS_VERSION);
	}

	public static function isSupportedFreshRss(string $version): bool {
		return version_compare($version, self::MIN_FRESHRSS_VERSION, '>=');
	}

	public function registerHooks(string $freshrssVersion): void {
		$this->registerHook(Minz_HookType::FreshrssUserMaintenance, [$this, 'ensureSchema']);
		if (self::isSupportedFreshRss($freshrssVersion)) {
			$this->registerHook(Minz_HookType::ApiMisc, [$this, 'handleApi']);
		} else {
			Minz_Log::warning('ArticleNotes requires FreshRSS ' . self::MIN_FRESHRSS_VERSION . ' or newer (running '
				. $freshrssVersion . '): the API endpoint is disabled.');
		}
	}

	/** Placeholder for the notes API (ApiMisc hook, FreshRSS >= 1.27.0). */
	public function handleApi(): void {
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
		return new ArticleNotes\NoteService(Minz_User::name() ?? '', $this->ensureSchema(...));
	}

	private function migrator(string $user): ArticleNotes\Migrator {
		return new ArticleNotes\Migrator(
			__DIR__ . '/Migrations',
			(new ArticleNotes\Storage($user))->pdo(),
			fn (): int => $this->getUserConfigurationInt(self::SCHEMA_VERSION_KEY) ?? 0,
			function (int $version): void {
				$this->setUserConfigurationValue(self::SCHEMA_VERSION_KEY, $version);
			},
			DATA_PATH . '/users/' . $user . '/articlenotes-migration.lock',
		);
	}

	#[\Override]
	public function uninstall(): bool|string {
		foreach (FreshRSS_user_Controller::listUsers() as $user) {
			try {
				(new ArticleNotes\Storage($user))->dropTables();
				$configuration = FreshRSS_UserConfiguration::getForUser($user);
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
}
