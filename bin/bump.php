<?php

declare(strict_types=1);

/**
 * Bumps the release version everywhere it is written and moves CHANGELOG.md's [Unreleased] entries under the new version
 * (merging repeated ### sections). Leaves docs/openapi.yaml's JSON copy to bin/openapi.php (bin/bump runs both).
 * Usage: php bin/bump.php [root] [patch|minor|major|X.Y.Z | --title TEXT] [--date YYYY-MM-DD]   (default: patch, today in
 * UTC). --title picks the bump from free text such as a task title ("Release minor", "Release v2.0.0"; none named: patch).
 * Exits 1 without changing anything when [Unreleased] is empty or the version is not newer, 2 on bad arguments.
 */

function fail(int $status, string $message): never {
	fwrite(STDERR, "error: $message\n");
	exit($status);
}

$usage = 'usage: bin/bump [patch|minor|major|X.Y.Z | --title TEXT] [--date YYYY-MM-DD] [--commit]';
$args = array_slice($argv, 1);
$root = rtrim(array_shift($args) ?? dirname(__DIR__), '/');
$part = 'patch';
$date = gmdate('Y-m-d');
while ($args !== []) {
	$arg = array_shift($args);
	if ($arg === '--date' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $args[0] ?? '') === 1) {
		$date = (string)array_shift($args);
	} elseif ($arg === '--title' && $args !== []) {	// e.g. a Throughput task title: "Release minor", "Release v2.0.0"
		preg_match_all('/\b(major|minor|patch)\b|\bv?(\d+\.\d+\.\d+)\b/i', (string)array_shift($args), $found, PREG_SET_ORDER);
		$named = array_values(array_unique(array_map(static fn (array $m): string => strtolower($m[2] ?? '') !== '' ? $m[2] : strtolower($m[1]), $found)));
		if (count($named) > 1) {
			fail(2, 'the title names more than one bump: ' . implode(', ', $named));
		}
		$part = $named[0] ?? 'patch';
	} elseif (in_array($arg, ['patch', 'minor', 'major'], true) || preg_match('/^\d+\.\d+\.\d+$/', $arg) === 1) {
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
if (preg_match('/^\d+\.\d+\.\d+$/', $part) === 1) {
	$new = $part;
} else {
	[$major, $minor, $patch] = array_map('intval', explode('.', $old));
	$new = match ($part) {
		'major' => ($major + 1) . '.0.0',
		'minor' => "$major." . ($minor + 1) . '.0',
		default => "$major.$minor." . ($patch + 1),
	};
}
if (version_compare($new, $old, '<=')) {
	fail(1, "the new version $new must be newer than $old");
}

// CHANGELOG.md: the [Unreleased] section (up to the next "## [" heading) becomes "## [new] - date".
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
		$sections[$current][] = $line;
	} elseif (trim($line) !== '') {
		fail(1, "CHANGELOG.md's [Unreleased] has text outside a ### section: $line");
	}
}
$body = [];
foreach ($sections as $heading => $lines) {
	$entries = trim(implode("\n", array_filter($lines, static fn (string $line): bool => trim($line) !== '')));
	if ($entries !== '') {
		$body[] = "$heading\n\n$entries";
	}
}
if ($body === []) {
	fail(1, "CHANGELOG.md's [Unreleased] has no entries: nothing to release");
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

echo "Bumped $old -> $new ($date): " . implode(', ', array_keys($files)) . "\n";
echo "Review the README's Upgrading section: add a \"To $new\" note if this release needs more than replacing the directory.\n";
