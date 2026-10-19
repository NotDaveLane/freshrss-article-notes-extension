<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ChangelogTest extends TestCase {
	private function root(): string {
		return dirname(__DIR__, 2);
	}

	private function version(): string {
		$json = file_get_contents($this->root() . '/xExtension-ArticleNotes/metadata.json');
		self::assertIsString($json);
		$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		self::assertIsArray($data);
		self::assertIsString($data['version']);
		return $data['version'];
	}

	/** @return list<string> released versions, newest first as written in the file */
	private function releasedVersions(): array {
		$changelog = file_get_contents($this->root() . '/CHANGELOG.md');
		self::assertIsString($changelog);
		self::assertStringContainsString('## [Unreleased]', $changelog);
		preg_match_all('/^## \[(\d+\.\d+\.\d+[^\]]*)\] - \d{4}-\d{2}-\d{2}$/m', $changelog, $matches);
		return $matches[1];
	}

	public function testVersionMatchesNewestReleaseOrIsPreRelease(): void {
		$released = $this->releasedVersions();
		$version = $this->version();
		if ($released === []) {
			self::assertMatchesRegularExpression('/^0\./', $version, 'Only [Unreleased] exists: the version must be a 0.x pre-release');
			return;
		}
		self::assertSame($released[0], $version);
	}

	public function testReleasedVersionsAreStrictlyDescending(): void {
		$released = $this->releasedVersions();
		$sorted = $released;
		usort($sorted, static fn (string $a, string $b): int => version_compare($b, $a));
		self::assertSame($sorted, $released);
	}
}
