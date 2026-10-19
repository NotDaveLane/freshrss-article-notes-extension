<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class VersionGuardTest extends TestCase {
	private function extension(): ArticleNotesExtension {
		$extension = Minz_ExtensionManager::findExtension('ArticleNotes');
		self::assertInstanceOf(ArticleNotesExtension::class, $extension);
		return $extension;
	}

	/** @return int number of ApiMisc hooks currently registered */
	private function apiHookCount(): int {
		$property = new ReflectionProperty(Minz_ExtensionManager::class, 'hook_list');
		$lists = $property->getValue();
		self::assertIsArray($lists);
		$entry = $lists[Minz_HookType::ApiMisc->value] ?? null;
		return is_array($entry) && is_array($entry['list'] ?? null) ? count($entry['list']) : 0;
	}

	public function testSupportedVersionRegistersApiHook(): void {
		$before = $this->apiHookCount();
		$this->extension()->registerHooks('1.27.0');
		self::assertSame($before + 1, $this->apiHookCount());
	}

	public function testOldVersionSkipsApiHookAndLogsWarning(): void {
		$before = $this->apiHookCount();
		$logFile = join_path(USERS_PATH, Minz_User::name() ?? Minz_User::INTERNAL_USER, LOG_FILENAME);
		@unlink($logFile);
		$this->extension()->registerHooks('1.26.9');
		self::assertSame($before, $this->apiHookCount());
		$log = is_file($logFile) ? file_get_contents($logFile) : '';
		self::assertIsString($log);
		self::assertStringContainsString('requires FreshRSS 1.27.0', $log);
	}

	public function testCurrentFreshRssIsSupported(): void {
		self::assertTrue(ArticleNotesExtension::isSupportedFreshRss(FRESHRSS_VERSION));
		self::assertFalse(ArticleNotesExtension::isSupportedFreshRss('1.26.3'));
		self::assertTrue(ArticleNotesExtension::isSupportedFreshRss('1.28.0-dev'));
	}
}
