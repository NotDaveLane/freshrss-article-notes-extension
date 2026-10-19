## MODIFIED Requirements

### Requirement: API routing

The extension SHALL answer `p/api/misc.php/ArticleNotes/<path>` (and `p/api/misc.php/<path>?ext=ArticleNotes`) through
the `ApiMisc` hook with the versioned paths `v1/status`, `v1/notes`, `v1/lookup`, `v1/page-notes` and `v1/notes/<entry_id>` (a trailing slash is
accepted), and SHALL answer 404 `not_found` to any other path, even without credentials.

#### Scenario: Unknown path
- **WHEN** a client requests `v2/notes`, `v1/notes/abc` or `v1/notes/1/2`
- **THEN** the response is 404 with error code `not_found`

#### Scenario: Query-string form
- **WHEN** a client requests `p/api/misc.php/v1/status?ext=ArticleNotes`
- **THEN** it gets the same answer as `p/api/misc.php/ArticleNotes/v1/status`

### Requirement: Methods per path

Every path SHALL accept `GET` and `HEAD` (`HEAD` answers like `GET` without a body); `v1/notes/<entry_id>` and `v1/page-notes` SHALL also accept
`PUT` and `DELETE`. Any other method on a path SHALL answer 405 `method_not_allowed` with an `Allow` header listing the
accepted methods (`GET, HEAD` or `GET, HEAD, PUT, DELETE`), before credentials are checked.

#### Scenario: Write method on a read-only path
- **WHEN** a client sends `POST`, `PUT`, `PATCH` or `DELETE` to `v1/notes`, `v1/status` or `v1/lookup`
- **THEN** the response is 405 with error code `method_not_allowed` and `Allow: GET, HEAD`

### Requirement: Note object

A note SHALL be serialized as `id`, `entry_id` (string), `kind` (`"article"` or `"page"`), `greader_item_id`
(`tag:google.com,2005:reader/item/<16 hex digits of entry_id>`), `url` (the article's original URL), `normalized_url` (its
normalized form, or null), `title`, `note` (null when deleted), `created_at`, `updated_at`, `deleted_at` (Unix seconds or null) with
ISO 8601 UTC `*_iso` counterparts, `label_applied`, and `article`. Single-note responses SHALL carry an `ETag` header.

#### Scenario: Live note
- **WHEN** a client fetches a live note
- **THEN** `note` holds the text as entered, `deleted_at`/`deleted_at_iso` are null, and `url`/`normalized_url` describe the article's URL

### Requirement: Page note object

For a page note, `entry_id`, `greader_item_id` and `article` SHALL be null, `url` SHALL be the page URL and `title` the page title (null when it has none).

#### Scenario: Page note
- **WHEN** a client fetches a page note
- **THEN** `kind` is `"page"`, `entry_id` and `article` are null, and `url`/`title` describe the page

### Requirement: Page notes in the listing

`GET v1/notes` SHALL list the user's page notes together with the article notes, tombstones included, in the same order.

#### Scenario: Page note in the listing
- **WHEN** a client lists notes and the user has a page note
- **THEN** it appears with `kind` `"page"`, `entry_id` null and `article` null, and article notes have `kind` `"article"`
