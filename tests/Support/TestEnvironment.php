<?php

declare(strict_types=1);

namespace ArticleNotesTests;

/** Prepares the isolated FreshRSS instance used by the test suites. */
final class TestEnvironment {
	public const USER = 'notestest';

	public static string $dataPath = '';
	public static string $engine = 'sqlite';

	public static function boot(string $repositoryRoot): void {
		$freshrss = self::env('FRESHRSS_PATH', '/var/www/FreshRSS');
		if (!is_file($freshrss . '/constants.php')) {
			throw new \RuntimeException('FreshRSS not found at ' . $freshrss . ' (set FRESHRSS_PATH)');
		}
		self::$engine = self::env('TEST_DB', 'sqlite');

		$base = sys_get_temp_dir() . '/articlenotes-test-' . getmypid() . '-' . bin2hex(random_bytes(3));
		self::$dataPath = $base . '/data';
		$extensionsPath = $base . '/extensions';
		foreach (['cache', 'favicons', 'tokens', 'users'] as $directory) {
			mkdir(self::$dataPath . '/' . $directory, 0777, true);
		}
		mkdir($extensionsPath, 0777, true);
		symlink($repositoryRoot . '/xExtension-ArticleNotes', $extensionsPath . '/xExtension-ArticleNotes');
		register_shutdown_function(static function () use ($base): void {
			self::teardown($base);
		});

		putenv('DATA_PATH=' . self::$dataPath);
		self::runCli($freshrss, 'do-install.php', self::installArguments());
		self::runCli($freshrss, 'create-user.php', ['--user' => self::USER, '--password' => 'test-password-123', '--language' => 'en']);

		define('THIRDPARTY_EXTENSIONS_PATH', $extensionsPath);
		define('COPY_LOG_TO_SYSLOG', false);
		require $freshrss . '/cli/_cli.php';	// constants, autoloader, session, system config, extension manager
		cliInitUser(self::USER);
	}

	private static function env(string $name, string $default): string {
		$value = getenv($name);
		return is_string($value) && $value !== '' ? $value : $default;
	}

	/** @return array<string,string> */
	private static function installArguments(): array {
		$arguments = ['--default-user' => self::USER, '--language' => 'en', '--api-enabled' => '', '--db-type' => 'sqlite'];
		switch (self::$engine) {
			case 'sqlite':
				break;
			case 'mariadb':
				$arguments = array_merge($arguments, [
					'--db-type' => 'mysql', '--db-host' => 'mariadb', '--db-user' => 'freshrss',
					'--db-password' => 'freshrss', '--db-base' => 'freshrss_test',
				]);
				break;
			case 'postgres':
				$arguments = array_merge($arguments, [
					'--db-type' => 'pgsql', '--db-host' => 'postgres', '--db-user' => 'freshrss',
					'--db-password' => 'freshrss', '--db-base' => 'freshrss_test',
				]);
				break;
			default:
				throw new \RuntimeException('Unknown TEST_DB "' . self::$engine . '" (sqlite, mariadb, postgres)');
		}
		if (self::$engine !== 'sqlite') {
			// Unique prefix so concurrent or aborted runs never collide on a shared database.
			$arguments['--db-prefix'] = 't' . bin2hex(random_bytes(3)) . '_';
		}
		return $arguments;
	}

	/** @param array<string,string> $arguments */
	private static function runCli(string $freshrss, string $script, array $arguments): void {
		$command = [PHP_BINARY, $freshrss . '/cli/' . $script];
		foreach ($arguments as $name => $value) {
			$command[] = $value === '' ? $name : $name . '=' . $value;
		}
		$process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		if (!is_resource($process)) {
			throw new \RuntimeException('Cannot run ' . $script);
		}
		$output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
		if (proc_close($process) !== 0) {
			throw new \RuntimeException('FreshRSS ' . $script . ' failed: ' . $output);
		}
	}

	private static function teardown(string $base): void {
		if (self::$engine !== 'sqlite' && function_exists('cliInitUser')) {
			// Drop the per-user tables from the shared database server.
			try {
				\FreshRSS_user_Controller::deleteUser(self::USER);
			} catch (\Throwable $exception) {
				fwrite(STDERR, 'Test user cleanup failed: ' . $exception->getMessage() . "\n");
			}
		}
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($items as $item) {
			if (!$item instanceof \SplFileInfo) {
				continue;
			}
			if ($item->isDir() && !$item->isLink()) {
				@rmdir($item->getPathname());
			} else {
				@unlink($item->getPathname());
			}
		}
		@rmdir($base);
	}
}
