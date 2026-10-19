## ADDED Requirements

### Requirement: Full-journey end-to-end test

The Playwright suite SHALL include a full-journey spec in which a user without an API password sets one in the profile,
adds a note in the UI, sees the article under the "Annotated" label, fetches the note through the API with HTTP Basic,
edits it and receives the newer `updated_at` through `since`, deletes it and receives the tombstone, and no longer finds
the article under the label.

#### Scenario: Journey
- **WHEN** `bin/e2e specs/full-journey.spec.ts` runs
- **THEN** every step of the journey passes against the fixture feed


### Requirement: Compatibility matrix

`bin/ci --compat` SHALL run `bin/ci --all-dbs` once per FreshRSS version in `COMPAT_VERSIONS` (default: 1.27.1 and the
default version), each in its own throwaway compose project with its own FreshRSS instance, without touching the dev stack,
and print a per-version PASS/FAIL summary. Linting (phpcs, PHPStan) SHALL run on the default version only; `bin/ci
--no-lint` skips it.

#### Scenario: Matrix
- **WHEN** running `bin/ci --compat`
- **THEN** PHPUnit on SQLite, MariaDB and PostgreSQL and the Playwright suite pass on FreshRSS 1.27.1 and 1.30.1, and it
  prints `bin/ci --compat: OK`


### Requirement: README screenshots

`bin/screenshots` SHALL capture the README pictures into `docs/screenshots/` (owned by the host user) from the dev
instance as the dev admin with the seeded Hacker News content, and delete the note it added for them.

#### Scenario: Refresh
- **WHEN** `bin/screenshots` runs with the stack up and the seed feeds fetched
- **THEN** `note-editor.png`, `note.png`, `annotated-label.png` and `settings.png` are written to `docs/screenshots/`

## MODIFIED Requirements

### Requirement: Throwaway e2e user

`bin/e2e` SHALL create a throwaway user per run via FreshRSS's CLI, subscribe it to `http://fixtures/feed.xml` (never the
live feeds), pass its credentials as `E2E_USER`/`E2E_PASSWORD` (and its API password as `E2E_API_PASSWORD`), and delete
it afterwards. It SHALL likewise create a second throwaway user without an API password for the full-journey spec
(`E2E_JOURNEY_USER`/`E2E_JOURNEY_PASSWORD`). The dev instance SHALL allow the `fixtures` host in `internal_host_allowlist`.

#### Scenario: Fixture articles
- **WHEN** the throwaway user signs in
- **THEN** the fixture feed's articles are listed
