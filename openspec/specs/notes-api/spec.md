# notes-api

## Purpose

An authenticated JSON API for other systems to fetch and sync each user's notes (writes: `notes-api-write`; URL lookup: `url-lookup`).

## Requirements

### Requirement: API routing

The extension SHALL answer `p/api/misc.php/ArticleNotes/<path>` (and `p/api/misc.php/<path>?ext=ArticleNotes`) through
the `ApiMisc` hook with the versioned paths `v1/status`, `v1/notes`, `v1/lookup` and `v1/notes/<entry_id>` (a trailing slash is
accepted), and SHALL answer 404 `not_found` to any other path, even without credentials.

#### Scenario: Unknown path
- **WHEN** a client requests `v2/notes`, `v1/notes/abc` or `v1/notes/1/2`
- **THEN** the response is 404 with error code `not_found`

#### Scenario: Query-string form
- **WHEN** a client requests `p/api/misc.php/v1/status?ext=ArticleNotes`
- **THEN** it gets the same answer as `p/api/misc.php/ArticleNotes/v1/status`

### Requirement: Methods per path

Every path SHALL accept `GET` and `HEAD` (`HEAD` answers like `GET` without a body); `v1/notes/<entry_id>` SHALL also accept
`PUT` and `DELETE`. Any other method on a path SHALL answer 405 `method_not_allowed` with an `Allow` header listing the
accepted methods (`GET, HEAD` or `GET, HEAD, PUT, DELETE`), before credentials are checked.

#### Scenario: Write method on a read-only path
- **WHEN** a client sends `POST`, `PUT`, `PATCH` or `DELETE` to `v1/notes`, `v1/status` or `v1/lookup`
- **THEN** the response is 405 with error code `method_not_allowed` and `Allow: GET, HEAD`

#### Scenario: Unsupported method on a note
- **WHEN** a client sends `POST` or `PATCH` to `v1/notes/<entry_id>`
- **THEN** the response is 405 with `Allow: GET, HEAD, PUT, DELETE`

### Requirement: JSON responses

Every API response, errors included, SHALL be JSON with `Content-Type: application/json; charset=UTF-8` and
`Cache-Control: no-store`; errors SHALL have the shape `{"error": {"code": "...", "message": "..."}}`.

#### Scenario: Error body
- **WHEN** a request fails with 400, 401, 404 or 405
- **THEN** the body is a JSON object with `error.code` and `error.message`, with the same headers as successful answers

### Requirement: API credentials

The API SHALL authenticate every request on its own (no session) with the credentials of FreshRSS's Google Reader API —
`Authorization: GoogleLogin auth=<user>/<token>` with the ClientLogin token `sha1(system salt . user . apiPasswordHash)`
compared with `hash_equals`, or HTTP Basic with the user's API password checked with `password_verify` — and SHALL make
the authenticated user the current FreshRSS user.

#### Scenario: Both credential forms
- **WHEN** a client sends Basic credentials with the API password, or the ClientLogin token returned by `greader.php`
- **THEN** `v1/status` answers 200 for that user

### Requirement: Rejected credentials

The API SHALL answer 401 `unauthorized` with `WWW-Authenticate: Basic realm="ArticleNotes"` to missing or malformed
credentials, unknown or invalid user names, disabled users, users without an API password, a wrong token or API
password, and the web login password, and SHALL log the failure to FreshRSS's API log without passwords or tokens.

#### Scenario: Web password
- **WHEN** a client sends Basic credentials with the user's web login password
- **THEN** the response is 401 with `WWW-Authenticate: Basic realm="ArticleNotes"`

#### Scenario: Disabled user or no API password
- **WHEN** the user is disabled, or has no API password, whatever the credentials
- **THEN** the response is 401

### Requirement: Status endpoint

`GET v1/status` SHALL answer `{"extension": "ArticleNotes", "version": <metadata version>, "api_version": 1,
"user": <user name>, "note_count": <number of live notes>}` for the authenticated user.

#### Scenario: Status
- **WHEN** an authenticated client requests `v1/status`
- **THEN** it receives the version from `metadata.json`, `api_version` 1, its user name and its live note count

### Requirement: Notes listing

`GET v1/notes` SHALL return the authenticated user's notes, after running pending migrations, ordered by `updated_at`
then `id`, as `{"notes": [...], "continuation": <token or null>, "server_time": <Unix seconds>}`, with `limit` (1–500,
default 100) notes at most, and SHALL answer 400 `invalid_parameter` to invalid parameter values.

#### Scenario: Invalid parameter
- **WHEN** `limit` is 0 or 501, `since` is negative or not a number, `include_deleted` is 2, or `continuation` is garbage
- **THEN** the response is 400 with error code `invalid_parameter`

### Requirement: Pagination

When more notes follow a page, `continuation` SHALL be an opaque token that, passed back as `continuation`, returns the
next page with the same filters; following it until it is null SHALL return every matching note exactly once.

#### Scenario: Every note once
- **WHEN** a client follows `continuation` with `limit=1`, `limit=2` or the default limit until it is null
- **THEN** it receives every live note exactly once, in order

### Requirement: Incremental sync

`since` (Unix seconds) SHALL keep the notes updated at or after it; `include_deleted` (`0`/`1`) SHALL default to 1 when
`since` is given and 0 otherwise, so a client storing `server_time` and passing it as `since` receives edits and
tombstones.

#### Scenario: Updates and tombstones
- **WHEN** a note was edited and another deleted after time T, and the client requests `since=T`
- **THEN** both are returned, the deleted one as a tombstone (`note` null, `deleted_at` set)

#### Scenario: Default excludes tombstones
- **WHEN** a client requests `v1/notes` without `since` or `include_deleted`
- **THEN** deleted notes are not returned

### Requirement: Single note

`GET v1/notes/<entry_id>` SHALL return `{"note": <note object>}` for the authenticated user's live note of that article,
and 404 `not_found` when the user has no live note on it.

#### Scenario: Deleted or missing note
- **WHEN** the article has no note, or its note was deleted
- **THEN** the response is 404 with error code `not_found`

### Requirement: Note object

A note SHALL be serialized as `id`, `entry_id` (string), `greader_item_id`
(`tag:google.com,2005:reader/item/<16 hex digits of entry_id>`), `url` (the article's original URL), `normalized_url` (its
normalized form, or null), `note` (null when deleted), `created_at`, `updated_at`, `deleted_at` (Unix seconds or null) with
ISO 8601 UTC `*_iso` counterparts, `label_applied`, and `article`. Single-note responses SHALL carry an `ETag` header.

#### Scenario: Live note
- **WHEN** a client fetches a live note
- **THEN** `note` holds the text as entered, `deleted_at`/`deleted_at_iso` are null, and `url`/`normalized_url` describe the article's URL

### Requirement: Article snapshot

The `article` of a note SHALL hold the note's snapshot — `guid`, `url`, `title`, `author`, `published_at`,
`published_at_iso`, `feed` {`id`, `name`, `url`} — and `exists`, whether FreshRSS still has the article.

#### Scenario: Purged article
- **WHEN** FreshRSS purged the article of a note
- **THEN** the note is still returned with its article snapshot and `article.exists` false

### Requirement: Per-user isolation

The API SHALL only ever read the authenticated user's notes.

#### Scenario: Another user's note
- **WHEN** user B requests the entry id of user A's note, or lists notes
- **THEN** B gets 404 for A's note and A's notes never appear in B's list or note count
