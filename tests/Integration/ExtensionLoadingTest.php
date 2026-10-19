<?php

declare(strict_types=1);

use ArticleNotesTests\TestEnvironment;
use PHPUnit\Framework\TestCase;

final class ExtensionLoadingTest extends TestCase {
	public function testExtensionIsDiscoveredByFreshRss(): void {
		$extension = Minz_ExtensionManager::findExtension('ArticleNotes');
		self::assertInstanceOf(ArticleNotesExtension::class, $extension);
		self::assertSame('system', $extension->getType());
	}

	public function testInitRegistersTranslations(): void {
		$extension = Minz_ExtensionManager::findExtension('ArticleNotes');
		self::assertNotNull($extension);
		$extension->init();
		Minz_Translate::registerPath($extension->getPath() . '/i18n');
		Minz_Translate::reset('en');
		self::assertSame('ArticleNotes', _t('ext.article_notes.name'));
	}

	public function testRunsAgainstARealDatabase(): void {
		$dao = FreshRSS_Factory::createEntryDao();
		self::assertSame(0, $dao->count());
		$expected = ['sqlite' => 'sqlite', 'mariadb' => 'mysql', 'postgres' => 'pgsql'][TestEnvironment::$engine] ?? null;
		self::assertNotNull($expected);
		self::assertSame($expected, FreshRSS_Context::systemConf()->db['type']);
	}
}
