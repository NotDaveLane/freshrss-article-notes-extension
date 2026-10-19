# toolchain Specification

## Purpose

The test, coverage and quality toolchain, and `bin/ci`, the single host-side full-suite entry point. Every tool runs in a
container; nothing (PHP, Composer, Node) is assumed on the host.

## Requirements

### Requirement: Dev tooling image

A `dev` build target (compose service `dev`, profile `tools`) SHALL be based on the same pinned FreshRSS image
(`FRESHRSS_VERSION`), add Composer and PCOV, provide the SQLite, MySQL and PostgreSQL PDO drivers, mount the repository at
`/workspace`, and keep FreshRSS's sources at `/var/www/FreshRSS` for tests and static analysis.

#### Scenario: Tools available
- **WHEN** running `php -m` in the `dev` service
- **THEN** `pcov`, `pdo_sqlite`, `pdo_mysql` and `pdo_pgsql` are loaded

### Requirement: Single full-suite entry point

`bin/ci` SHALL run on the host, fail fast with a clear message and exit code 2 when the dev stack is not up and healthy, and
otherwise run inside containers, in order: PHP_CodeSniffer, PHPStan, PHPUnit with coverage, and the Playwright suite. It
SHALL run every step even after a failure, print a per-step PASS/FAIL summary, and exit non-zero if any step failed.

#### Scenario: Green run
- **WHEN** the stack is up and `bin/ci` runs on a clean tree
- **THEN** it exits 0 and prints `bin/ci: OK`

#### Scenario: Stack down
- **WHEN** the `freshrss` service is not healthy
- **THEN** `bin/ci` exits 2 with a message pointing at `docker compose up --build -d --wait`

#### Scenario: Failing check
- **WHEN** a test fails, a style rule is violated or PHPStan reports an error
- **THEN** `bin/ci` exits non-zero and marks that step FAIL

### Requirement: Coverage report

The PHPUnit step SHALL write an HTML coverage report to `coverage/index.html` (owned by the host user) and a text summary to
the console. Coverage SHALL include `xExtension-ArticleNotes/` only.

#### Scenario: Report written
- **WHEN** `bin/ci` completes the PHPUnit step
- **THEN** `coverage/index.html` exists and reports `xExtension-ArticleNotes`

### Requirement: PHPUnit suites on real FreshRSS

`phpunit.xml.dist` SHALL define `unit` (`tests/Unit`) and `integration` (`tests/Integration`) suites. The bootstrap SHALL
install FreshRSS from the image into an isolated temporary data directory, create a throwaway user, and load the extension
from the repository, so integration tests run against real FreshRSS classes and a real database. The data directory (and, for
server databases, the user's tables) SHALL be removed on exit.

#### Scenario: Isolation
- **WHEN** the suite runs
- **THEN** neither the dev instance's data nor the host's files other than `coverage/` and `.phpunit.cache/` are modified

### Requirement: Database matrix

The environment variable `TEST_DB` (`sqlite` default, `mariadb`, `postgres`) SHALL select the database engine of the
integration tests. Compose profile `db-matrix` SHALL provide `mariadb` and `postgres` services with healthchecks.
`bin/ci --all-dbs` SHALL additionally run the integration suite against MariaDB and PostgreSQL; plain `bin/ci` SHALL use SQLite only.

#### Scenario: All engines
- **WHEN** running `bin/ci --all-dbs`
- **THEN** the integration suite passes on SQLite, MariaDB and PostgreSQL, each reported as its own step

### Requirement: Static analysis and style

PHPStan SHALL run at level 10 over `xExtension-ArticleNotes/` and `tests/` with FreshRSS's sources scanned, and without a
baseline. PHP_CodeSniffer SHALL use FreshRSS's own `phpcs.xml` as its base (tabs, PSR-12 derived).

#### Scenario: Violation detected
- **WHEN** a file violates the style or has a type error
- **THEN** `bin/lint` and `bin/ci` exit non-zero

### Requirement: End-to-end tests

An `e2e` compose service (profile `tools`) SHALL use the official `mcr.microsoft.com/playwright` image, pinned to the exact
`@playwright/test` version in `tests/e2e/package.json`, and reach FreshRSS at `http://freshrss`.

#### Scenario: Smoke test
- **WHEN** the Playwright suite runs
- **THEN** the dev admin can sign in and ArticleNotes is shown as an enabled system extension

### Requirement: Throwaway e2e user

`bin/e2e` SHALL create a throwaway user per run via FreshRSS's CLI, subscribe it to `http://fixtures/feed.xml` (never the
live feeds), pass its credentials as `E2E_USER`/`E2E_PASSWORD`, and delete it afterwards. The dev instance SHALL allow the
`fixtures` host in `internal_host_allowlist`.

#### Scenario: Fixture articles
- **WHEN** the throwaway user signs in
- **THEN** the fixture feed's articles are listed

### Requirement: Single-run wrappers

`bin/phpunit [args]` (no coverage; honours `TEST_DB`), `bin/e2e [spec]` and `bin/lint` SHALL run one tool in its container.

#### Scenario: One test
- **WHEN** running `bin/phpunit tests/Unit/MetadataTest.php`
- **THEN** only that file's tests run
