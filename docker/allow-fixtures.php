<?php

declare(strict_types=1);

// FreshRSS blocks fetching from internal hosts by default. Allow the compose `fixtures` service
// (http://fixtures/feed.xml) in the system configuration so tests can subscribe to it (idempotent).
require '/var/www/FreshRSS/cli/_cli.php';

$entry = 'fixtures:80';
$conf = FreshRSS_Context::systemConf();
$allowlist = $conf->attributeArray('internal_host_allowlist') ?? [];
if (!in_array($entry, $allowlist, true)) {
	$allowlist[] = $entry;
	$conf->_attribute('internal_host_allowlist', $allowlist);
	$conf->save() or fail('Could not save the system configuration');
}
echo "Fixture host {$entry} allowed\n";
