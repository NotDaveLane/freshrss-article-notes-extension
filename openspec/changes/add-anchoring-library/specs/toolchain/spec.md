## MODIFIED Requirements

### Requirement: Single full-suite entry point

`bin/ci` SHALL run on the host, fail fast with a clear message and exit code 2 when the dev stack is not up and healthy, and
otherwise run inside containers, in order: PHP_CodeSniffer, PHPStan, ESLint, PHPUnit with coverage, and the Playwright suite.
It SHALL run every step even after a failure, print a per-step PASS/FAIL summary, and exit non-zero if any step failed.

#### Scenario: Green run
- **WHEN** the stack is up and `bin/ci` runs on a clean tree
- **THEN** it exits 0 and prints `bin/ci: OK`

#### Scenario: Stack down
- **WHEN** the `freshrss` service is not healthy
- **THEN** `bin/ci` exits 2 with a message pointing at `docker compose up --build -d --wait`

#### Scenario: Failing check
- **WHEN** a test fails, a style rule is violated, PHPStan reports an error or ESLint reports a problem
- **THEN** `bin/ci` exits non-zero and marks that step FAIL

### Requirement: Static analysis and style

PHPStan SHALL run at level 10 over `xExtension-ArticleNotes/` and `tests/` with FreshRSS's sources scanned, and without a
baseline. PHP_CodeSniffer SHALL use FreshRSS's own `phpcs.xml` as its base (tabs, PSR-12 derived). ESLint (pinned in
`tests/e2e/package.json`, configured in `tests/e2e/eslint.config.mjs`, run in the `e2e` service) SHALL check
`xExtension-ArticleNotes/static/` with its recommended rules and no warnings allowed.

#### Scenario: Violation detected
- **WHEN** a file violates the style, has a type error, or a JavaScript file has an ESLint problem
- **THEN** `bin/lint` and `bin/ci` exit non-zero

## ADDED Requirements

### Requirement: JavaScript coverage report

The Playwright suite SHALL collect the V8 coverage of the anchoring library during its unit tests and `bin/e2e` SHALL write
an HTML report to `coverage/js/index.html` (owned by the host user) and a summary to the console; `bin/ci` SHALL print the
report's path.

#### Scenario: Report written
- **WHEN** `bin/e2e specs/anchoring.spec.ts` completes
- **THEN** `coverage/js/index.html` reports `xExtension-ArticleNotes/static/anchoring.js`
