<?php

declare(strict_types=1);

// Enables an extension in the FreshRSS *system* configuration (idempotent).
// Usage: php enable-extension.php <ExtensionName>
require '/var/www/FreshRSS/cli/_cli.php';

$name = $_SERVER['argv'][1] ?? '';
if ($name === '') {
	fail('Usage: enable-extension.php <ExtensionName>');
}

$conf = FreshRSS_Context::systemConf();
$enabled = $conf->extensions_enabled;
if (($enabled[$name] ?? false) !== true) {
	$enabled[$name] = true;
	$conf->extensions_enabled = $enabled;
	$conf->save() or fail('Could not save the system configuration');
}
echo "Extension {$name} enabled\n";
