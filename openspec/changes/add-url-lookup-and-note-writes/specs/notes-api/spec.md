## REMOVED Requirements

### Requirement: Read-only methods
**Reason**: The API now accepts writes on `v1/notes/<entry_id>`; see "Methods per path".
**Migration**: Use `PUT`/`DELETE v1/notes/<entry_id>` to write; every other path still accepts only `GET` and `HEAD`.

## ADDED Requirements

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

## MODIFIED Requirements

### Requirement: Note object

A note SHALL be serialized as `id`, `entry_id` (string), `greader_item_id`
(`tag:google.com,2005:reader/item/<16 hex digits of entry_id>`), `url` (the article's original URL), `normalized_url` (its
normalized form, or null), `note` (null when deleted), `created_at`, `updated_at`, `deleted_at` (Unix seconds or null) with
ISO 8601 UTC `*_iso` counterparts, `label_applied`, and `article`. Single-note responses SHALL carry an `ETag` header.

#### Scenario: Live note
- **WHEN** a client fetches a live note
- **THEN** `note` holds the text as entered, `deleted_at`/`deleted_at_iso` are null, and `url`/`normalized_url` describe the article's URL
