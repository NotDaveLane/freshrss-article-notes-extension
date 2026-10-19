<?php

declare(strict_types=1);

use ArticleNotesTests\TestEnvironment;
use PHPUnit\Framework\TestCase;

/** `cli/delete-data.php`: the only way to delete ArticleNotes data permanently. */
final class DataDeletionTest extends TestCase {
	private const USER = 'notesdeletion';

	#[\Override]
	protected function setUp(): void {
		if (!FreshRSS_user_Controller::userExists(self::USER)) {
			self::assertTrue(FreshRSS_user_Controller::createUser(self::USER, null, 'deletion-password-123', ['language' => 'en'], false));
		}
		Minz_User::change(self::USER);
		cliInitUser(self::USER);
		$extension = new ArticleNotesExtension([
			'name' => 'ArticleNotes', 'entrypoint' => 'ArticleNotes', 'path' => dirname(__DIR__, 2) . '/xExtension-ArticleNotes',
			'type' => 'system',
		]);
		self::assertTrue($extension->ensureSchema());
	}

	#[\Override]
	protected function tearDown(): void {
		Minz_User::change(TestEnvironment::USER);
		cliInitUser(TestEnvironment::USER);
		if (FreshRSS_user_Controller::userExists(self::USER)) {
			FreshRSS_user_Controller::deleteUser(self::USER);
		}
	}

	/**
	 * @param list<string> $arguments
	 * @return array{int,string} exit code and output
	 */
	private function runScript(array $arguments): array {
		$command = array_merge([PHP_BINARY, dirname(__DIR__, 2) . '/xExtension-ArticleNotes/cli/delete-data.php'], $arguments);
		$environment = getenv();
		$environment['DATA_PATH'] = TestEnvironment::$dataPath;
		$environment['FRESHRSS_PATH'] = FRESHRSS_PATH;
		$process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
		self::assertIsResource($process);
		$output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
		return [proc_close($process), $output];
	}

	private function tableExists(): bool {
		$pdo = (new ArticleNotes\Storage(self::USER))->pdo();
		$previous = $pdo->getAttribute(PDO::ATTR_ERRMODE);
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		try {
			$statement = $pdo->query('SELECT COUNT(*) FROM ' . $pdo->prefix() . 'articlenotes_note');
			if ($statement !== false) {
				$statement->closeCursor();
			}
			return true;
		} catch (PDOException) {
			return false;
		} finally {
			$pdo->setAttribute(PDO::ATTR_ERRMODE, is_int($previous) ? $previous : PDO::ERRMODE_SILENT);
		}
	}

	/** @return array<string,mixed>|null the user's ArticleNotes configuration, as stored on disk */
	private function storedConfiguration(): ?array {
		$configuration = include TestEnvironment::$dataPath . '/users/' . self::USER . '/config.php';
		self::assertIsArray($configuration);
		$extensions = $configuration['extensions'] ?? [];
		self::assertIsArray($extensions);
		$mine = $extensions['ArticleNotes'] ?? null;
		self::assertTrue($mine === null || is_array($mine));
		/** @var array<string,mixed>|null $mine */
		return $mine;
	}

	public function testRefusesWithoutConfirmation(): void {
		[$status, $output] = $this->runScript(['--user', self::USER]);
		self::assertSame(1, $status);
		self::assertStringContainsString('--yes', $output);
		self::assertTrue($this->tableExists());
		self::assertNotNull($this->storedConfiguration());
	}

	public function testRefusesAnUnknownUser(): void {
		[$status, $output] = $this->runScript(['--yes', '--user', 'nosuchuser']);
		self::assertSame(1, $status);
		self::assertStringContainsString('nosuchuser', $output);
	}

	public function testDeletesTheTablesAndSettingsOfOneUser(): void {
		[$status, $output] = $this->runScript(['--yes', '--user', self::USER]);
		self::assertSame(0, $status, $output);
		self::assertStringContainsString(self::USER, $output);
		self::assertFalse($this->tableExists());
		self::assertNull($this->storedConfiguration());
		self::assertFileDoesNotExist(TestEnvironment::$dataPath . '/users/' . self::USER . '/articlenotes-migration.lock');
	}
}
