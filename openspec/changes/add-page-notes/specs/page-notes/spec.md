## ADDED Requirements

### Requirement: Page note storage

The notes table SHALL allow a note without article: `entry_id` is nullable and unique when present, and `page_url_hash` (NULL on article notes) is unique, so a normalized URL has at most one page note. A page note SHALL keep the page URL in `entry_link`, the optional title (at most 500 characters) in `entry_title` and the `normalized_url`.

#### Scenario: Uniqueness
- **WHEN** a second page note is inserted for a normalized URL, or a second note for an article
- **THEN** the database rejects it

### Requirement: Migration 0005

Migration `0005` SHALL be idempotent and keep every note, tombstone and id. SQLite SHALL rebuild the table inside a transaction; MySQL/MariaDB and PostgreSQL SHALL alter it in place.

#### Scenario: Upgrading a database with notes
- **WHEN** the migration runs on a database with live notes and tombstones, then runs again
- **THEN** every row and id is unchanged, page notes can be inserted, and the second run changes nothing

### Requirement: Page note service

`NoteService::savePageNote(url, text, title)` SHALL create or update the page note of a normalized URL with the validation of article notes (trimmed text, at most 20,000 characters, empty text deletes), reviving a tombstone and keeping `id` and `created_at`; `deletePageNote(url)` SHALL tombstone it. A URL that is not http(s), or a title over 500 characters, SHALL be refused.

#### Scenario: Create, update, delete
- **WHEN** a page note is saved twice for variants of one URL, deleted, then saved again
- **THEN** one row is used throughout: same `id`, `updated_at` increasing, the tombstone revived

### Requirement: Page notes and articles

A page note SHALL be refused, with an error carrying the article's `entry_id`, when its URL matches a FreshRSS article (through the URL index, or a note on a purged article). Page notes SHALL never get a FreshRSS label. When an article matching an existing page note's URL arrives later, both SHALL be left as they are.

#### Scenario: URL of an article
- **WHEN** a page note is saved for the URL of a FreshRSS article
- **THEN** it is refused with the article's `entry_id` and nothing is stored

### Requirement: Page note endpoints

`GET v1/page-notes?url=` SHALL answer `{"note": <note object>}` with an `ETag`, or 404 `not_found`. `PUT` with `{"note", "title"}` (`title` optional) SHALL answer 201 or 200 (204 when the text is empty and deletes the note), and `DELETE` 204 or 404. A missing or non-http(s) `url` SHALL answer 400 `invalid_parameter`.

#### Scenario: Round trip
- **WHEN** a client PUTs a page note, reads it with a variant of the URL, and DELETEs it
- **THEN** the answers are 201, 200 with the same note and 204, and a later GET answers 404

#### Scenario: Another user
- **WHEN** user B reads or deletes the page note of user A
- **THEN** the response is 404

### Requirement: Page note write rules

Page note writes SHALL follow `PUT v1/notes/<entry_id>`: `ETag` / `If-Match` (412), body rules (415, 413, 400), 422 `note_too_long`, and 422 `title_too_long`. A URL that is a FreshRSS article SHALL answer 409 `url_is_article`, whose message names `PUT v1/notes/<entry_id>`.

#### Scenario: Article URL
- **WHEN** a client PUTs a page note on the URL of a FreshRSS article
- **THEN** the response is 409 `url_is_article` naming the article's `entry_id`
