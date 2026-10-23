<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** bin/bump.php, run on a copy of the files it edits. */
final class BumpTest extends TestCase {
	private const FILES = ['xExtension-ArticleNotes/metadata.json', 'CHANGELOG.md', 'docs/openapi.yaml', 'README.md', 'docs/community-listing.md'];

	private string $root = '';
	private string $version = '';

	#[\Override]
	protected function setUp(): void {
		$source = dirname(__DIR__, 2);
		$this->root = sys_get_temp_dir() . '/articlenotes-bump-' . bin2hex(random_bytes(4));
		foreach (self::FILES as $file) {
			$target = $this->root . '/' . $file;
			if (!is_dir(dirname($target))) {
				mkdir(dirname($target), 0777, true);
			}
			copy($source . '/' . $file, $target);
		}
		$metadata = json_decode($this->read('xExtension-ArticleNotes/metadata.json'), true, 512, JSON_THROW_ON_ERROR);
		self::assertIsArray($metadata);
		self::assertIsString($metadata['version']);
		$this->version = $metadata['version'];
		// The repository's [Unreleased] may be empty right after a release: give it entries, with Fixed twice.
		$this->write('CHANGELOG.md', preg_replace('/^## \[Unreleased\]\n+/m', "## [Unreleased]\n\n### Fixed\n\n- First fix.\n\n"
			. "### Added\n\n- A feature.\n\n### Fixed\n\n- Second fix.\n\n", $this->read('CHANGELOG.md'), 1) ?? '');
	}

	#[\Override]
	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->root));
	}

	private function read(string $file): string {
		$text = file_get_contents($this->root . '/' . $file);
		self::assertIsString($text);
		return $text;
	}

	private function write(string $file, string $text): void {
		file_put_contents($this->root . '/' . $file, $text);
	}

	/** @return array{status:int,output:string} */
	private function bump(string ...$args): array {
		$command = array_map('escapeshellarg', [PHP_BINARY, dirname(__DIR__, 2) . '/bin/bump.php', $this->root, ...$args]);
		$output = [];
		$status = 0;
		exec(implode(' ', $command) . ' 2>&1', $output, $status);
		return ['status' => $status, 'output' => implode("\n", $output)];
	}

	/** @param 0|1|2 $part */
	private function next(int $part): string {
		$numbers = array_map('intval', explode('.', $this->version));
		$numbers[$part]++;
		for ($i = $part + 1; $i < 3; $i++) {
			$numbers[$i] = 0;
		}
		return implode('.', $numbers);
	}

	/** @return list<string> the README's Upgrading notes ("**To X.Y.Z** …"), which are history */
	private function upgradingNotes(): array {
		preg_match_all('/^\*\*To \d+\.\d+\.\d+\*\*.*$/m', $this->read('README.md'), $matches);
		return $matches[0];
	}

	public function testPatchIsTheDefault(): void {
		$notes = $this->upgradingNotes();
		self::assertNotSame([], $notes);
		$result = $this->bump('--date', '2026-11-02');
		self::assertSame(0, $result['status'], $result['output']);
		$new = $this->next(2);
		self::assertStringContainsString('"version": "' . $new . '"', $this->read('xExtension-ArticleNotes/metadata.json'));
		$openapi = $this->read('docs/openapi.yaml');
		self::assertStringContainsString("\n  version: $new\n", $openapi);
		self::assertStringContainsString("examples: [$new]", $openapi);
		self::assertStringNotContainsString($this->version, $openapi);
		$readme = $this->read('README.md');
		self::assertStringContainsString("ArticleNotes-$new.zip", $readme);
		self::assertStringContainsString("`v$new`", $readme);
		self::assertStringContainsString("\"version\": \"$new\"", $readme);
		self::assertSame($notes, $this->upgradingNotes(), 'the Upgrading history is left alone');
		$listing = $this->read('docs/community-listing.md');
		self::assertStringContainsString("ArticleNotes-$new.zip", $listing);
		self::assertStringContainsString("(`$new`)", $listing);
		self::assertStringContainsString("Bumped {$this->version} -> $new", $result['output']);
	}

	public function testTheChangelogMovesUnreleasedUnderTheNewVersionAndMergesRepeatedSections(): void {
		$result = $this->bump('patch', '--date', '2026-11-02');
		self::assertSame(0, $result['status'], $result['output']);
		$new = $this->next(2);
		self::assertStringContainsString("## [Unreleased]\n\n## [$new] - 2026-11-02\n\n### Fixed\n\n- First fix.\n- Second fix.\n\n"
			. "### Added\n\n- A feature.\n\n## [{$this->version}] - ", $this->read('CHANGELOG.md'));
	}

	public function testMinorMajorAndExplicitVersions(): void {
		self::assertSame(0, $this->bump('minor', '--date', '2026-11-02')['status']);
		self::assertStringContainsString('"version": "' . $this->next(1) . '"', $this->read('xExtension-ArticleNotes/metadata.json'));
		$this->tearDown();
		$this->setUp();
		self::assertSame(0, $this->bump('major', '--date', '2026-11-02')['status']);
		self::assertStringContainsString('"version": "' . $this->next(0) . '"', $this->read('xExtension-ArticleNotes/metadata.json'));
		$this->tearDown();
		$this->setUp();
		self::assertSame(0, $this->bump('9.8.7', '--date', '2026-11-02')['status']);
		self::assertStringContainsString('"version": "9.8.7"', $this->read('xExtension-ArticleNotes/metadata.json'));
	}

	public function testRefusesAnEmptyUnreleasedSectionAndChangesNothing(): void {
		$this->write('CHANGELOG.md', preg_replace('/(## \[Unreleased\]\n).*?(?=^## \[)/ms', "$1\n", $this->read('CHANGELOG.md'), 1) ?? '');
		$before = array_map($this->read(...), self::FILES);
		$result = $this->bump();
		self::assertSame(1, $result['status'], $result['output']);
		self::assertStringContainsString('[Unreleased] has no entries', $result['output']);
		self::assertSame($before, array_map($this->read(...), self::FILES));
	}

	public function testRefusesAVersionThatIsNotNewer(): void {
		$result = $this->bump($this->version);
		self::assertSame(1, $result['status'], $result['output']);
		self::assertStringContainsString('must be newer than', $result['output']);
	}

	/** @return array<string,array{string,string}> title => [expected version kind, explicit version or ''] */
	public static function titles(): array {
		return [
			'bare' => ['Release', 'patch'],
			'patch' => ['Release patch', 'patch'],
			'minor' => ['Release minor version', 'minor'],
			'major, any case' => ['MAJOR release', 'major'],
			'explicit' => ['Release 9.8.7', '9.8.7'],
			'explicit with v' => ['Ship v9.8.7 to users', '9.8.7'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('titles')]
	public function testTheTitlePicksTheBump(string $title, string $expected): void {
		$result = $this->bump('--title', $title, '--date', '2026-11-02');
		self::assertSame(0, $result['status'], $result['output']);
		$version = match ($expected) {
			'patch' => $this->next(2),
			'minor' => $this->next(1),
			'major' => $this->next(0),
			default => $expected,
		};
		self::assertStringContainsString('"version": "' . $version . '"', $this->read('xExtension-ArticleNotes/metadata.json'));
	}

	public function testATitleNamingTwoBumpsIsRefused(): void {
		$result = $this->bump('--title', 'Release minor or major');
		self::assertSame(2, $result['status'], $result['output']);
		self::assertStringContainsString('names more than one', $result['output']);
	}

	public function testRefusesAnInvalidArgument(): void {
		$result = $this->bump('1.2');
		self::assertSame(2, $result['status'], $result['output']);
		self::assertStringContainsString('usage', $result['output']);
	}
}
