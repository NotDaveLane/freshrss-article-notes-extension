## ADDED Requirements

### Requirement: End-to-end PHP diagnostics guard

Every Playwright spec SHALL use the guarded `test` from `tests/e2e/support/test.ts`, which fails a test when any response
received by its browser context or its `request` fixture contains a PHP diagnostic (`Warning`, `Notice`, `Deprecated`,
`Fatal error`, `Parse error`) in PHP's HTML or plain-text format.

#### Scenario: Warning in a page
- **WHEN** a page loaded by a spec prints a PHP warning
- **THEN** that test fails and lists the URL and the warning

### Requirement: PHP log check

After the end-to-end suite, `bin/ci` SHALL run `bin/check-php-log` over the FreshRSS container log written during the run
and fail with the offending lines when a PHP diagnostic comes from `xExtension-ArticleNotes/` or from a request to the
extension's pages (`c=ArticleNotes`) or API (`/api/misc.php/ArticleNotes`). The dev image SHALL tag each error-log line
with its request URI. Other diagnostics SHALL be listed as ignored; known exceptions go in the script's allow-list.

#### Scenario: Warning logged on an extension page
- **WHEN** the log holds a PHP warning from a request to `?c=ArticleNotes&a=settings`
- **THEN** `bin/check-php-log` prints it and exits 1, and `bin/ci` exits non-zero

#### Scenario: Unrelated diagnostic
- **WHEN** the log holds a PHP deprecation from FreshRSS core on a request that is not one of the extension's
- **THEN** `bin/check-php-log` lists it as ignored and exits 0

### Requirement: PHPUnit fails on PHP diagnostics

PHPUnit SHALL fail the run on PHP warnings, notices and deprecations triggered during the tests.

#### Scenario: Deprecation in a test
- **WHEN** code under test triggers a PHP deprecation
- **THEN** `bin/phpunit` exits non-zero
