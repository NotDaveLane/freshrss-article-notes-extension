# dev-environment Specification

## Purpose

A fully dockerized FreshRSS instance for developing and testing the ArticleNotes extension.

## Requirements

### Requirement: One-command dev instance

`docker compose up --build -d --wait` SHALL bring up a ready FreshRSS instance (service `freshrss`, based on
`freshrss/freshrss` at `FRESHRSS_VERSION`, default 1.30.1) without manual steps, on port `${FRESHRSS_PORT:-8080}`.

#### Scenario: Clean start
- **WHEN** the command runs from a clean checkout without a `.env` file
- **THEN** it returns successfully only after FreshRSS is installed, the dev user exists and seeding is complete
- **AND** the login page is served at `/i/`

### Requirement: Seeded user and API

The instance SHALL use SQLite, have the API enabled and an administrator user whose password and API password
come from `.env` (defaults documented as development-only in `.env.example`).

#### Scenario: Dev login
- **WHEN** signing in as `admin` with the configured password
- **THEN** sign-in succeeds, and the API password authenticates against the Google Reader API

### Requirement: Seed feeds

The dev user SHALL be subscribed to `https://news.ycombinator.com/rss` and `https://lobste.rs/rss`, which are fetched during boot.

#### Scenario: Offline boot
- **WHEN** the internet is unreachable
- **THEN** boot still succeeds and the feeds stay empty

### Requirement: Extension mounted and enabled

`xExtension-ArticleNotes/` SHALL be bind-mounted into FreshRSS's `extensions/` directory and enabled in the system configuration.

#### Scenario: Listed as enabled
- **WHEN** an administrator opens Configuration → Extensions
- **THEN** ArticleNotes is listed as an enabled system extension

### Requirement: Idempotent boot

Restarting the stack, or recreating containers on an existing data volume, SHALL NOT duplicate users or subscriptions.
`docker compose down -v` followed by `up` SHALL produce a fresh working instance.

#### Scenario: Restart
- **WHEN** the stack is restarted
- **THEN** the user and the two subscriptions exist exactly once

### Requirement: Fixture feed

A `fixtures` service SHALL serve `tests/fixtures/feeds/` on the compose network so `http://fixtures/feed.xml` returns a
static feed of about ten articles with stable GUIDs; automated tests MUST NOT depend on live feeds.

#### Scenario: Reachable from FreshRSS
- **WHEN** running `curl http://fixtures/feed.xml` inside the `freshrss` container
- **THEN** the fixture RSS feed is returned

### Requirement: Fixture host allowed

Because FreshRSS refuses to fetch from internal hosts, the dev instance SHALL add `fixtures:80` to the system
`internal_host_allowlist` during seeding (idempotently).

#### Scenario: Subscribe to the fixture feed
- **WHEN** a user is subscribed to `http://fixtures/feed.xml` and feeds are actualized
- **THEN** the fixture articles are fetched
