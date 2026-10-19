<?php

declare(strict_types=1);

use ArticleNotesTests\TestEnvironment;
use PHPUnit\Framework\TestCase;

final class ExtensionSchemaTest extends TestCase {
	private function extension(): ArticleNotesExtension {
		$extension = Minz_ExtensionManager::findExtension('ArticleNotes');
		self::assertInstanceOf(ArticleNotesExtension::class, $extension);
		return $extension;
	}

	public function testEnsureSchemaRecordsLatestVersionInUserConfiguration(): void {
		$extension = $this->extension();
		self::assertTrue($extension->ensureSchema());
		self::assertGreaterThanOrEqual(1, $extension->getUserConfigurationValue('schema_version'));
		// Persisted in the user's FreshRSS configuration, not only in memory.
		$stored = ArticleNotesExtension::userConfiguration(TestEnvironment::USER);
		self::assertNotNull($stored);
		self::assertIsArray($stored->extensions['ArticleNotes'] ?? null);
		self::assertGreaterThanOrEqual(1, $stored->extensions['ArticleNotes']['schema_version'] ?? 0);
	}

	public function testMaintenanceHookMigratesTheUser(): void {
		$extension = $this->extension();
		$extension->init();
		Minz_ExtensionManager::callHookVoid('freshrss_user_maintenance');
		self::assertGreaterThanOrEqual(1, $extension->getUserConfigurationValue('schema_version'));
	}

	public function testUninstallKeepsTheData(): void {
		// FreshRSS calls uninstall() when an administrator disables the extension: notes must survive it.
		$extension = $this->extension();
		self::assertTrue($extension->ensureSchema());
		$count = (new ArticleNotes\NoteDAO((new ArticleNotes\Storage(TestEnvironment::USER))->pdo()))->count(true);
		self::assertTrue($extension->uninstall());
		self::assertSame($count, (new ArticleNotes\NoteDAO((new ArticleNotes\Storage(TestEnvironment::USER))->pdo()))->count(true));
		$stored = ArticleNotesExtension::userConfiguration(TestEnvironment::USER);
		self::assertNotNull($stored);
		self::assertIsArray($stored->extensions['ArticleNotes'] ?? null);
	}
}
