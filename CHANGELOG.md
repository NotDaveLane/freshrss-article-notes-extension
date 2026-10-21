# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Clicking or tapping an article's note (under the article or in the Annotations panel) opens its editor, like the
  **Note** action. The note block can also be focused with Tab and opened with Enter or Space; selecting text in it to
  copy it still works. Its tooltip reads "Edit your note", and screen readers announce it as "Your note" with the
  description "Edit your note".

### Changed

- On the Notes page, Delete asks for confirmation (also in the highlight popover), is red, and the edit form has a
  Cancel button, a visible label naming the item being edited, and no longer repeats the note text above it.
- The **Note** action in the article footer has a note icon, space between it and the date, and a 44x44 px tap target on
  touch screens. It is bold with a filled icon when the article already has a note.
- The note editor opens in place of the article's note (or above the Annotations panel when there is no note), in view
  and aligned with the article text, instead of below the Annotations panel; Cancel or Escape shows the note again.
- On small screens, closing the highlight popover (saving, closing, Escape, deleting or tapping outside it) returns the
  page to the reading position it had before the popover scrolled it, unless you scrolled the page while it was open.
- On small screens the Notes page shows the search and the Apply button and keeps the kind, colour, feed and "only with a
  note" filters behind a **Filters** toggle (opened when one of them is set), so the first note is visible right away.
- On touch screens the Notes page's Filters toggle, "only with a note" checkbox row, export links and buttons are at
  least 44px high.

### Fixed

- The Notes page summary reads "1 annotated article or page" for a single result, and the summary and the export links
  are no longer shown when nothing is listed (they only downloaded an empty file).

## [1.1.1] - 2026-10-19

### Fixed

- On small screens, tapping a highlight no longer scrolls the page again on every tap: the page moves at most once, just
  enough to show the highlight above the popover's bottom sheet, and not at all when the highlight is already visible.
- The administrator's notification about refused CORS origin lines escapes the lines it repeats.

## [1.1.0] - 2026-10-19

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

- The Notes page (Configuration → Notes, `?c=ArticleNotes&a=notes`): everything the user annotated — article notes, page
  notes and highlights — grouped by article or page (most recent first, 25 per page), with search over notes, quotes and
  titles, filters (kind, colour, feed, has a note) that survive pagination, edit-in-place (note text, highlight colour,
  delete) through `NoteService` / `HighlightService`, an "Open article at this highlight" link that opens the article and
  scrolls to the highlight, "Passage not found" for highlights whose quote left the article, and export of the filtered
  annotations as Markdown or W3C Web Annotation JSON-LD (`WebAnnotation`, reusable by the API). Linked from the
  Configuration menu, the settings page and the article Annotations panel ("All my notes") (`notes-page` OpenSpec
  capability).
- Migration `0007`: highlights keep their selected text in a `quote` column, searchable by the Notes page.

- Client support (`client-support` OpenSpec capability):
  - CORS for web-page clients: the administrator setting "Allowed client origins" (Configuration → Extensions → ArticleNotes),
    one exact origin per line (`https://…`, `moz-extension://<uuid>`, `chrome-extension://<id>`; wildcards are refused). Empty
    by default, which sends no CORS header at all. Listed origins get `OPTIONS` preflight answers and
    `Access-Control-Allow-Origin`, `Vary: Origin` and `Access-Control-Expose-Headers: ETag`; credentials are never allowed.
  - `GET v1/annotated`: every article and page the user annotated in one call (counts, highlight colours, first and last
    annotation time, the note), newest first, with `since` / `removed` for sync, paging and `kind` / `feed_id` filters.
  - `GET v1/annotations?format=w3c`: notes and highlights as W3C Web Annotation JSON-LD, paged and filterable like the native
    lists.
  - An OpenAPI 3.1 description of the whole API in `docs/openapi.yaml`, served without authentication at `v1/openapi.json`
    (`bin/openapi` regenerates the served copy); CI validates it and checks that it describes exactly the routes the API handles.
  - Reference client `examples/notes-client.mjs` and example page `examples/highlight-page.html`, tested in CI; README
    "Building a client".
  - `bin/verify-upgrade` checks that upgrading a 1.0.0 install keeps its notes and runs the migrations.


### Changed

- The "Annotated" label now follows article notes and highlights together: it is applied with an article's first note or
  highlight and removed (only if ArticleNotes applied it) when neither remains.

- Note objects gain `kind` (`article` | `page`) and `title`; `entry_id`, `greader_item_id` and `article` are `null` on page
  notes.
- The API is no longer read-only: `v1/notes/<entry_id>` accepts `PUT` and `DELETE` (other paths still answer `405` to writes).
- Note objects gain `url` and `normalized_url`; a note's `updated_at` now always increases when it is saved.

### Fixed

- The W3C Web Annotation JSON-LD context URL was misspelled (`anna.jsonld`); it is now the real
  `http://www.w3.org/ns/anno.jsonld`, in the Notes page export too.
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
