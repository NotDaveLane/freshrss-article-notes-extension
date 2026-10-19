<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap.
 *
 * Runs a real FreshRSS install (the one in the image, at FRESHRSS_PATH) against an isolated, throwaway
 * data directory, creates a throwaway user, and loads FreshRSS's classes so integration tests exercise
 * the extension against real FreshRSS code and a real database.
 *
 * Database engine: env TEST_DB = sqlite (default) | mariadb | postgres.
 */

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/Support/TestEnvironment.php';

ArticleNotesTests\TestEnvironment::boot(dirname(__DIR__));
