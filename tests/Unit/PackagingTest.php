<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PackagingTest extends TestCase {
	public function testPackageContainsOnlyTheExtensionDirectory(): void {
		$root = dirname(__DIR__, 2);
		$json = file_get_contents($root . '/xExtension-ArticleNotes/metadata.json');
		self::assertIsString($json);
		$metadata = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		self::assertIsArray($metadata);
		$version = $metadata['version'];
		self::assertIsString($version);

		$output = [];
		$status = 0;
		exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/package.php') . ' ' . escapeshellarg($root) . ' 2>&1', $output, $status);
		self::assertSame(0, $status, implode("\n", $output));

		$zip = new ZipArchive();
		self::assertTrue($zip->open($root . '/dist/ArticleNotes-' . $version . '.zip') === true);
		$names = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$names[] = (string)$zip->getNameIndex($i);
		}
		$zip->close();

		self::assertContains('xExtension-ArticleNotes/metadata.json', $names);
		self::assertContains('xExtension-ArticleNotes/extension.php', $names);
		self::assertContains('xExtension-ArticleNotes/Migrations/0001_baseline.php', $names);
		foreach ($names as $name) {
			self::assertStringStartsWith('xExtension-ArticleNotes/', $name);
		}
	}
}
