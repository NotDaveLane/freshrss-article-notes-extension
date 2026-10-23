<?php

declare(strict_types=1);

/**
 * Bumps the release version everywhere it is written and moves CHANGELOG.md's [Unreleased] entries under the new version
 * (merging repeated ### sections). Leaves docs/openapi.yaml's JSON copy to bin/openapi.php (bin/bump runs both).
 * Usage: php bin/bump.php [root] [auto|patch|minor|major|X.Y.Z | --title TEXT] [--date YYYY-MM-DD] [--dry-run]
 *
 * auto (the default) follows semver from the [Unreleased] sections: ### Removed or an entry starting "**Breaking:**" is
 * major, ### Added or ### Deprecated is minor, anything else patch; below 1.0.0 each moves down one (breaking is minor,
 * features are patch). --title picks the bump from free text such as a task title ("Release minor", "Release v2.0.0";
 * none named: auto). --dry-run prints the version it would release and changes nothing. The date defaults to today in UTC.
 * Exits 1 without changing anything when [Unreleased] is empty or the version is not newer, 2 on bad arguments.
 */

function fail(int $status, string $message): never {
	fwrite(STDERR, "error: $message\n");
	exit($status);
}

$usage = 'usage: bin/bump [auto|patch|minor|major|X.Y.Z | --title TEXT] [--date YYYY-MM-DD] [--dry-run] [--commit]';
$args = array_slice($argv, 1);
$root = rtrim(array_shift($args) ?? dirname(__DIR__), '/');
$part = 'auto';
$date = gmdate('Y-m-d');
$dryRun = false;
while ($args !== []) {
	$arg = array_shift($args);
	if ($arg === '--date' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $args[0] ?? '') === 1) {
		$date = (string)array_shift($args);
	} elseif ($arg === '--dry-run') {
		$dryRun = true;
	} elseif ($arg === '--title' && $args !== []) {	// e.g. a Throughput task title: "Release minor", "Release v2.0.0"
		preg_match_all('/\b(major|minor|patch)\b|\bv?(\d+\.\d+\.\d+)\b/i', (string)array_shift($args), $found, PREG_SET_ORDER);
		$named = array_values(array_unique(array_map(static fn (array $m): string => strtolower($m[2] ?? '') !== '' ? $m[2] : strtolower($m[1]), $found)));
		if (count($named) > 1) {
			fail(2, 'the title names more than one bump: ' . implode(', ', $named));
		}
		$part = $named[0] ?? 'auto';
	} elseif (in_array($arg, ['auto', 'patch', 'minor', 'major'], true) || preg_match('/^\d+\.\d+\.\d+$/', $arg) === 1) {
		$part = $arg;
	} else {
		fail(2, $usage);
	}
}

$read = static function (string $file) use ($root): string {
	$text = file_get_contents("$root/$file");
	return $text === false ? fail(1, "cannot read $file") : $text;
};

$metadata = json_decode($read('xExtension-ArticleNotes/metadata.json'), true);
$old = is_array($metadata) && is_string($metadata['version'] ?? null) ? $metadata['version'] : fail(1, 'no version in metadata.json');

// CHANGELOG.md: the [Unreleased] section (up to the next "## [" heading), as ### heading => entry lines.
$changelog = $read('CHANGELOG.md');
if (preg_match('/^## \[Unreleased\]\n(.*?)(?=^## \[|\z)/ms', $changelog, $match, PREG_OFFSET_CAPTURE) !== 1) {
	fail(1, 'CHANGELOG.md has no ## [Unreleased] section');
}
$sections = [];
$current = null;
foreach (explode("\n", $match[1][0]) as $line) {
	if (str_starts_with($line, '### ')) {
		$current = $line;
		$sections[$current] ??= [];
	} elseif ($current !== null) {
		if (trim($line) !== '') {
			$sections[$current][] = $line;
		}
	} elseif (trim($line) !== '') {
		fail(1, "CHANGELOG.md's [Unreleased] has text outside a ### section: $line");
	}
}
$sections = array_filter($sections, static fn (array $lines): bool => $lines !== []);
if ($sections === []) {
	fail(1, "CHANGELOG.md's [Unreleased] has no entries: nothing to release");
}

[$major, $minor, $patch] = array_map('intval', explode('.', $old));
if ($part === 'auto') {
	$has = static fn (string $heading): bool => isset($sections["### $heading"]);
	$breaking = array_filter(array_merge(...array_values($sections)), static fn (string $line): bool => preg_match('/^- \*\*Breaking:?\*\*/i', $line) === 1);
	[$level, $reason] = match (true) {
		$has('Removed') => ['major', '### Removed'],
		$breaking !== [] => ['major', '**Breaking:** entry'],
		$has('Added') => ['minor', '### Added'],
		$has('Deprecated') => ['minor', '### Deprecated'],
		default => ['patch', 'no breaking change or feature'],
	};
	if ($major === 0 && $level !== 'patch') {	// semver 0.x: breaking changes bump the minor, features the patch
		[$level, $reason] = [$level === 'major' ? 'minor' : 'patch', "$reason, before 1.0.0"];
	}
	$part = $level;
	$why = "$level: $reason";
} else {
	$why = "as requested: $part";
}
$new = match ($part) {
	'major' => ($major + 1) . '.0.0',
	'minor' => "$major." . ($minor + 1) . '.0',
	'patch' => "$major.$minor." . ($patch + 1),
	default => $part,
};
if (version_compare($new, $old, '<=')) {
	fail(1, "the new version $new must be newer than $old");
}
if ($dryRun) {
	echo "Would bump $old -> $new ($why)\n";
	exit(0);
}

$body = [];
foreach ($sections as $heading => $lines) {
	$body[] = "$heading\n\n" . implode("\n", $lines);
}
$release = "## [Unreleased]\n\n## [$new] - $date\n\n" . implode("\n\n", $body) . "\n\n";
$changelog = substr_replace($changelog, $release, $match[0][1], strlen($match[0][0]));

// Every other place the current version is written, as an exact pattern, so history (e.g. "**To 1.1.1**") is kept.
$edits = [
	'xExtension-ArticleNotes/metadata.json' => ["\"version\": \"$old\""],
	'docs/openapi.yaml' => ["\n  version: $old\n", "examples: [$old]"],
	'README.md' => ["ArticleNotes-$old.zip", "`v$old`", "\"version\": \"$old\""],
	'docs/community-listing.md' => ["`v$old`", "ArticleNotes-$old.zip", "(`$old`)"],
];
$files = ['CHANGELOG.md' => $changelog];
foreach ($edits as $file => $patterns) {
	$text = $read($file);
	foreach ($patterns as $pattern) {
		if (!str_contains($text, $pattern)) {
			fail(1, "$file does not contain $pattern");
		}
		$text = str_replace($pattern, str_replace($old, $new, $pattern), $text);
	}
	$files[$file] = $text;
}
foreach ($files as $file => $text) {	// written only once every check passed
	file_put_contents("$root/$file", $text);
}

echo "Bumped $old -> $new ($why): " . implode(', ', array_keys($files)) . "\n";
echo "Review the README's Upgrading section: add a \"To $new\" note if this release needs more than replacing the directory.\n";
