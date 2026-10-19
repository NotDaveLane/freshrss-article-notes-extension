<?php

declare(strict_types=1);

/*
 * Permanently deletes ArticleNotes data (the notes table, with every note and tombstone, and the extension settings) of
 * every user, or of one user. Disabling or removing the extension in FreshRSS keeps that data. Run it from the FreshRSS
 * directory as the web server user, for example:
 *   sudo -u www-data php extensions/xExtension-ArticleNotes/cli/delete-data.php --yes [--user <name>]
 * FRESHRSS_PATH overrides the FreshRSS directory (by default the one containing extensions/xExtension-ArticleNotes).
 */

$freshrssPath = getenv('FRESHRSS_PATH');
$freshrssPath = is_string($freshrssPath) && $freshrssPath !== '' ? $freshrssPath : dirname(__DIR__, 3);
require $freshrssPath . '/cli/_cli.php';
if (!class_exists('ArticleNotesExtension', false)) {
	require dirname(__DIR__) . '/extension.php';
}

$confirmed = false;
$user = null;
$arguments = array_slice($argv, 1);
while (($argument = array_shift($arguments)) !== null) {
	if ($argument === '--yes') {
		$confirmed = true;
	} elseif ($argument === '--user') {
		$user = array_shift($arguments) ?? fail('--user needs a user name');
	} elseif (str_starts_with($argument, '--user=')) {
		$user = substr($argument, strlen('--user='));
	} else {
		fail('Unknown option ' . $argument . "\nUsage: php " . $argv[0] . ' --yes [--user <name>]');
	}
}

if ($user !== null && !FreshRSS_user_Controller::userExists($user)) {
	fail('Unknown user: ' . $user);
}
$users = $user === null ? ArticleNotesExtension::users() : [$user];
if (!$confirmed) {
	fail('This permanently deletes the ArticleNotes notes and settings of ' . ($user ?? 'every user')
		. '. Add --yes to confirm.');
}

foreach ($users as $name) {
	try {
		ArticleNotesExtension::deleteUserData($name);
		echo 'Deleted the ArticleNotes data of ', $name, "\n";
	} catch (Throwable $exception) {
		fail('Cannot delete the ArticleNotes data of ' . $name . ': ' . $exception->getMessage());
	}
}
