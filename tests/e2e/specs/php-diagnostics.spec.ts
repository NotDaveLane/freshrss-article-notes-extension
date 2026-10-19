import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { expect, phpDiagnostics, test } from '../support/test';

// The guard in support/test.ts fails any test whose responses contain PHP diagnostics; these tests pin what it detects
// and that every spec runs under it.

test('PHP diagnostics are detected in their HTML and plain-text forms', () => {
	const html = '<br />\n<b>Warning</b>:  Undefined property: Minz_View::$rss_title in '
		+ '<b>/var/www/FreshRSS/app/layout/layout.phtml</b> on line <b>53</b><br />\n<!DOCTYPE html>';
	expect(phpDiagnostics(html)).toEqual([
		'Warning: Undefined property: Minz_View::$rss_title in /var/www/FreshRSS/app/layout/layout.phtml on line 53',
	]);
	expect(phpDiagnostics('Notice: Only variables should be passed by reference in /app/a.php on line 7\n{"ok":true}'))
		.toHaveLength(1);
	expect(phpDiagnostics('<b>Deprecated</b>:  strlen(): Passing null in <b>/x/y.php</b> on line <b>2</b>')).toHaveLength(1);
	expect(phpDiagnostics('PHP Fatal error:  Uncaught RuntimeException: boom in /x/Controller.php:12')).toHaveLength(1);
	expect(phpDiagnostics('<b>Fatal error</b>:  Allowed memory size exhausted in <b>/x/y.php</b> on line <b>9</b>'))
		.toHaveLength(1);
});

test('ordinary content mentioning warnings is not a PHP diagnostic', () => {
	expect(phpDiagnostics('<p>Warning: this article contains spoilers in the second half.</p>')).toEqual([]);
	expect(phpDiagnostics('Deprecated: the old API in v2 on line one')).toEqual([]);
	expect(phpDiagnostics('{"note":"Notice: see the docs"}')).toEqual([]);
});

test('every spec uses the guarded test', () => {
	const root = join(__dirname, '..');
	for (const dir of ['specs', 'release', 'screenshots']) {
		for (const file of readdirSync(join(root, dir)).filter((name) => name.endsWith('.spec.ts'))) {
			const source = readFileSync(join(root, dir, file), 'utf8');
			expect(source, `${dir}/${file} must import test from ../support/test`).not.toMatch(/from '@playwright\/test'/);
			expect(source, `${dir}/${file} must import test from ../support/test`).toMatch(/import \{[^}]*\btest\b[^}]*\} from '\.\.\/support\/test'/);
		}
	}
});
