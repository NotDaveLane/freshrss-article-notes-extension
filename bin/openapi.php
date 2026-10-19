<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/**
 * Writes xExtension-ArticleNotes/openapi.json (served at v1/openapi.json) from docs/openapi.yaml, the source of truth.
 * Usage: php bin/openapi.php [root]
 */

$root = rtrim($argv[1] ?? dirname(__DIR__), '/');
require $root . '/vendor/autoload.php';

$document = Yaml::parseFile($root . '/docs/openapi.yaml', Yaml::PARSE_OBJECT_FOR_MAP);
$json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
file_put_contents($root . '/xExtension-ArticleNotes/openapi.json', $json . "\n");
echo "Wrote xExtension-ArticleNotes/openapi.json\n";
