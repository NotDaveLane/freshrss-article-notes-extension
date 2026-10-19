# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- API: `GET v1/lookup?url=` finds the articles (and notes on purged articles) behind a web page URL, for third-party clients
  such as a browser extension; `PUT` and `DELETE v1/notes/<entry_id>` create, update and delete a note, with `ETag` /
  `If-Match`, JSON body validation (415/413/400) and a method list per path (`url-lookup`, `notes-api-write` OpenSpec
  capabilities).
- URL normalization (`UrlNormalizer`) and a per-user URL index (migration `0004`, `articlenotes_url`), kept current by
  FreshRSS's user maintenance and filled lazily on lookups; notes store their normalized URL.
- Anchoring library `static/anchoring.js` for highlights: a dependency-free ES module that describes a text selection as
  a W3C Web Annotation-style selector (quote with prefix/suffix context, and position), anchors a selector again
  (position, then context-scored quote search, then whitespace-insensitive search; `null` when the passage is gone),
  and draws and removes overlapping `<mark>` highlights. Reusable by third-party clients (README "Anchoring library",
  `anchoring` OpenSpec capability).
- ESLint for the extension's JavaScript (`bin/lint`, `bin/ci`), and JavaScript coverage of the anchoring library in
  `coverage/js/index.html`.

- Page notes: one note for a web page URL that matches no FreshRSS article (migration `0005`: `entry_id` becomes
  nullable, page notes are unique per normalized URL). API: `GET`/`PUT`/`DELETE v1/page-notes?url=`, `page_note` in
  `GET v1/lookup`, page notes in `GET v1/notes`; `NoteService::savePageNote()` / `deletePageNote()` (`page-notes`
  OpenSpec capability).

- Highlights: passages selected on a FreshRSS article or any web page, with an optional note, colour and a W3C-style
  selector (migration `0006`, `articlenotes_highlight`; `HighlightService` is the single write path). API:
  `POST v1/highlights` (idempotent by `uuid`), `GET`/`PATCH`/`DELETE v1/highlights/<uuid>`, `GET v1/highlights` by
  `entry_id`, by `url` or as a changed-since sync, `highlights` / `page_highlights` in `GET v1/lookup`. FreshRSS-side
  controller actions (`highlights`, `highlightCreate`, `highlightUpdate`, `highlightDelete`; CSRF-protected) for the
  upcoming UI (`highlights` OpenSpec capability).

- Highlighting in the FreshRSS article view: selecting text inside an article shows a toolbar (below the selection on touch
  devices) to highlight it in one of five colours or add a note; highlights are drawn when the article is displayed (normal,
  reader and global views, infinite scroll), anchored with the shared anchoring library, with a dashed underline for those
  with a note. A popover edits the note and colour or deletes a highlight; an "Annotations" panel under the article lists the
  article note and the highlights in reading order, with a "Jump to" action and orphaned highlights flagged "Passage not
  found" (`highlighting-ui` OpenSpec capability).

### Changed

- The "Annotated" label now follows article notes and highlights together: it is applied with an article's first note or
  highlight and removed (only if ArticleNotes applied it) when neither remains.

- Note objects gain `kind` (`article` | `page`) and `title`; `entry_id`, `greader_item_id` and `article` are `null` on page
  notes.
- The API is no longer read-only: `v1/notes/<entry_id>` accepts `PUT` and `DELETE` (other paths still answer `405` to writes).
- Note objects gain `url` and `normalized_url`; a note's `updated_at` now always increases when it is saved.

### Fixed

- The extension's pages (settings) no longer print "Warning: Undefined property: Minz_View::$rss_title" above FreshRSS's
  header: the controller now renders with FreshRSS's view (`FreshRSS_ActionController`). The test suite now fails on PHP
  warnings, notices and deprecations: in every end-to-end response, in FreshRSS's log after the end-to-end run
  (`bin/check-php-log`), and in PHPUnit.

## [1.0.0] - 2026-10-19

First public release.

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
  version guard (API hook skipped below 1.27.0), and `bin/package` release zips (`versioning-and-migrations` OpenSpec
  capability).
- Note storage: per-user `articlenotes_note` table (migration `0002`) with article snapshots and deletion
  tombstones, `NoteDAO` and `NoteService` (validation, 20,000-character limit, save/delete listeners)
  (`notes-storage` OpenSpec capability).
- Note editor: a "Note" action on every article opens an inline editor (add, edit, delete, character counter,
  keyboard accessible) in the normal, reading and global views and for dynamically loaded articles; the note is
  shown under the article (escaped); `save`/`delete` JSON endpoints with CSRF protection
  (`note-editor` OpenSpec capability).
- Settings and label: a per-user settings page in the Configuration menu ("Add a label to articles I annotate",
  "Label name", API endpoint URL); annotating an article applies the "Annotated" label (created when missing) and
  deleting the note removes only the label the extension applied; migration `0003` (`label_id`)
  (`settings-and-label` OpenSpec capability).
- Notes API: read-only JSON on `p/api/misc.php/ArticleNotes/v1/` (`status`, `notes` with `since`, `limit`,
  `continuation` and `include_deleted` for incremental sync with tombstones, `notes/<entry_id>`), authenticated with
  the FreshRSS API password (HTTP Basic) or a Google Reader API token (`notes-api` OpenSpec capability).
- Full-journey end-to-end test: set an API password, annotate, see the label, fetch through the API, follow an edit with
  `since`, delete and receive the tombstone, label removed.
- Support for FreshRSS 1.27.x (tested with 1.27.1 and 1.30.1 on SQLite, MariaDB and PostgreSQL), and `bin/ci --compat` to
  run that version and database matrix (`bin/ci --no-lint` skips linting).
- `cli/delete-data.php --yes [--user <name>]` permanently deletes the extension's tables and settings (of every user or
  one user). Disabling or removing the extension keeps the data.
- `bin/verify-release` installs a release zip into a clean `freshrss/freshrss` container and checks it end to end;
  `bin/screenshots` captures the README pictures.
- README for first-time installers: screenshots, requirements, installation (release zip, git, Docker), upgrading,
  uninstalling, FAQ and troubleshooting; draft entry for the FreshRSS community extension list.
