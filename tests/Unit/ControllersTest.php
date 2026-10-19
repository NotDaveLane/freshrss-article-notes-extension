<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ControllersTest extends TestCase {
	/** FreshRSS's layout reads FreshRSS_View properties: a controller built on Minz_ActionController gets a plain Minz_View. */
	public function testEveryControllerRendersWithFreshRssView(): void {
		$files = glob(dirname(__DIR__, 2) . '/xExtension-ArticleNotes/Controllers/*Controller.php');
		self::assertIsArray($files);
		self::assertNotEmpty($files);
		foreach ($files as $file) {
			$source = file_get_contents($file);
			self::assertIsString($source);
			self::assertMatchesRegularExpression('/\bclass \w+_Controller extends FreshRSS_ActionController\b/', $source, basename($file));
		}
	}
}
