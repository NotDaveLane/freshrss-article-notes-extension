# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Extension skeleton (`ArticleNotes`, system extension, English translations).
- Dockerized FreshRSS development instance with a seeded admin user, Hacker News
  and Lobsters feeds, and a local fixture feed for automated tests.
- Project documentation and the `dev-environment` OpenSpec capability.
- Test and quality toolchain: `dev` tooling image, PHPUnit (unit + integration, coverage in
  `coverage/index.html`), PHPStan level 10, PHP_CodeSniffer in FreshRSS's style, Playwright end-to-end
  tests, a SQLite/MariaDB/PostgreSQL database matrix, and the `bin/ci` entry point with the `bin/phpunit`,
  `bin/e2e` and `bin/lint` wrappers (`toolchain` OpenSpec capability).
- Versioning and upgrades: changelog/version consistency test, per-user schema migration framework
  (`Migrator`, baseline migration `0001`, run lazily and from FreshRSS's user-maintenance hook), FreshRSS
  version guard (API hook skipped below 1.27.0), `uninstall()` cleanup, and `bin/package` release zips
  (`versioning-and-migrations` OpenSpec capability).
