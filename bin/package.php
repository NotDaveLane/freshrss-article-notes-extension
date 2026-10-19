<?php

declare(strict_types=1);

/** Builds dist/ArticleNotes-<version>.zip containing only xExtension-ArticleNotes/. Usage: php bin/package.php [root] */

$root = rtrim($argv[1] ?? dirname(__DIR__), '/');
$source = $root . '/xExtension-ArticleNotes';
$json = file_get_contents($source . '/metadata.json');
$metadata = $json === false ? null : json_decode($json, true);
if (!is_array($metadata) || !is_string($metadata['version'] ?? null)) {
	fwrite(STDERR, "Cannot read the version from metadata.json\n");
	exit(1);
}
if (!is_dir($root . '/dist')) {
	mkdir($root . '/dist', 0777, true);
}
$target = $root . '/dist/ArticleNotes-' . $metadata['version'] . '.zip';
@unlink($target);

$zip = new ZipArchive();
if ($zip->open($target, ZipArchive::CREATE) !== true) {
	fwrite(STDERR, "Cannot create $target\n");
	exit(1);
}
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));
$names = [];
foreach ($files as $file) {
	if ($file instanceof SplFileInfo && $file->isFile()) {
		$names[substr($file->getPathname(), strlen($root) + 1)] = $file->getPathname();
	}
}
ksort($names);
foreach ($names as $name => $path) {
	$zip->addFile($path, $name);
}
$zip->close();
echo 'Built ' . substr($target, strlen($root) + 1) . ' (' . count($names) . " files)\n";
