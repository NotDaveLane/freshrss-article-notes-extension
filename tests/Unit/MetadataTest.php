<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class MetadataTest extends TestCase {
	/** @return array<mixed> */
	private function metadata(): array {
		$json = file_get_contents(dirname(__DIR__, 2) . '/xExtension-ArticleNotes/metadata.json');
		self::assertIsString($json);
		$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		self::assertIsArray($data);
		return $data;
	}

	public function testDeclaresASystemExtensionNamedArticleNotes(): void {
		$metadata = $this->metadata();
		self::assertSame('ArticleNotes', $metadata['name']);
		self::assertSame('ArticleNotes', $metadata['entrypoint']);
		self::assertSame('system', $metadata['type']);
	}

	public function testVersionIsSemver(): void {
		$version = $this->metadata()['version'];
		self::assertIsString($version);
		self::assertMatchesRegularExpression(
			'/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(-[0-9A-Za-z.-]+)?(\+[0-9A-Za-z.-]+)?$/',
			$version
		);
	}

	public function testDescriptionMentionsHighlights(): void {
		$description = $this->metadata()['description'];
		self::assertIsString($description);
		self::assertStringContainsString('highlight', $description);
		$translated = require dirname(__DIR__, 2) . '/xExtension-ArticleNotes/i18n/en/ext.php';
		self::assertIsArray($translated);
		self::assertSame($description, $translated['article_notes']['description']);
	}
}
