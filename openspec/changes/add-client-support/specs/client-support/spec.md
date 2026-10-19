## ADDED Requirements

### Requirement: Allowed client origins

An administrator SHALL be able to set "Allowed client origins" on the extension's configure page (administrators only): one exact origin per line, empty by default. Wildcards, paths and unsupported schemes SHALL be refused and nothing saved.

#### Scenario: Accepted origins
- **WHEN** the list holds `https://Notes.example.com`, `http://localhost:8000`, `moz-extension://<uuid>` and `chrome-extension://<id>`
- **THEN** they are stored in canonical form (lowercase scheme and host, no default port)

#### Scenario: Wildcard
- **WHEN** an administrator saves a list containing `*` or `https://*.example.com`
- **THEN** the page shows an error naming the refused lines and the stored list is unchanged

#### Scenario: Not an administrator
- **WHEN** a user who is not an administrator opens the configure page
- **THEN** the answer is 403

### Requirement: CORS headers only for allowed origins

The API SHALL send CORS headers only to requests whose `Origin` is in the allowed list, and SHALL never send `Access-Control-Allow-Credentials`.

#### Scenario: Default
- **WHEN** a request with an `Origin` header reaches an instance with no allowed origins
- **THEN** the response has no `Access-Control-*` header and no `Vary: Origin`, and a preflight is answered `405`

#### Scenario: Preflight from an allowed origin
- **WHEN** an allowed origin sends `OPTIONS` for a known path, without credentials
- **THEN** the answer is `204` with `Access-Control-Allow-Origin` (the request's origin), `Access-Control-Allow-Methods` (the path's methods), `Access-Control-Allow-Headers: Authorization, Content-Type, If-Match`, `Access-Control-Max-Age` and `Vary: Origin`

#### Scenario: Response to an allowed origin
- **WHEN** an allowed origin calls the API (any status, errors included)
- **THEN** the response carries `Access-Control-Allow-Origin`, `Vary: Origin` and `Access-Control-Expose-Headers: ETag`

#### Scenario: Another origin
- **WHEN** an origin that is not listed (or that only starts like a listed one) calls the API
- **THEN** it gets no CORS header

### Requirement: Annotated targets

`GET v1/annotated` SHALL list every article or page with a live article note, page note or highlight, whatever the "Annotated" label says, one entry per target and only for the authenticated user, newest annotation first.

#### Scenario: Each kind of target
- **WHEN** an article has only highlights, one only a note, one both, and a page has a note and a highlight
- **THEN** each appears once with `kind`, `entry_id` (null for a page), `greader_item_id`, `url`, `normalized_url`, `title`, `article` (null for a page), `note`, `highlight_count`, `highlight_colors` (distinct, palette order), `first_annotated_at` and `last_annotated_at` (latest `updated_at` of its live note and highlights)

#### Scenario: Order and paging
- **WHEN** a client follows `continuation` with `limit=1`, `3` or `5`
- **THEN** it sees every target exactly once, in the order of a large limit: `last_annotated_at` descending, then article id and page key descending

#### Scenario: Filters and errors
- **WHEN** a client passes `kind` (`article` or `page`) or `feed_id`
- **THEN** only matching targets are listed, the continuation keeps the filters, and invalid parameters answer `400 invalid_parameter`

#### Scenario: Another user
- **WHEN** user B lists targets while user A has annotations
- **THEN** none of A's targets or text appears

### Requirement: Annotated targets since a time

With `since`, `v1/annotated` SHALL list the targets with a live annotation and one created, edited or deleted at or after it, and a `removed` list for targets that changed but have no live annotation left.

#### Scenario: Last annotation deleted
- **WHEN** the only note of an article (or the only highlight of a page) is deleted after `since`
- **THEN** the target is absent from `targets` and present in `removed` (`kind`, `entry_id`, `greader_item_id`, `url`, `normalized_url`); annotating it again lists it in `targets` instead

#### Scenario: No since
- **WHEN** `since` is not given
- **THEN** the response has no `removed` key

### Requirement: Annotated targets are cheap

`v1/annotated` SHALL use one grouped query over the user's notes and highlights plus a fixed number of queries for the page of results, portable across SQLite, MySQL/MariaDB and PostgreSQL, with prepared statements only.

#### Scenario: Query count
- **WHEN** the user has 6 targets, then 36
- **THEN** listing them takes the same number of SQL statements

### Requirement: W3C Web Annotation export over the API

`GET v1/annotations?format=w3c` SHALL answer an `AnnotationCollection` (`application/ld+json`) of the user's live notes and highlights, built with `WebAnnotation`, with the extra members `continuation` and `server_time`.

#### Scenario: Export
- **WHEN** a user with a note, a highlight and a page note requests the export
- **THEN** the collection (`@context` `http://www.w3.org/ns/anno.jsonld`) holds three `Annotation` objects, with `TextQuoteSelector` and `TextPositionSelector` on the highlight, and no tombstones

#### Scenario: Paging with since
- **WHEN** a client pages through the export with `limit=3` and a `since`
- **THEN** every annotation changed since then appears exactly once, oldest change first

#### Scenario: One target
- **WHEN** a client passes `entry_id` or `url`
- **THEN** it gets everything on that article or page, and an unknown `format` or invalid parameter answers `400 invalid_parameter`

### Requirement: OpenAPI description

`docs/openapi.yaml` SHALL be an OpenAPI 3.1 description of every `v1` route, with `info.version` equal to the extension's version, served as JSON at `GET v1/openapi.json` without authentication.

#### Scenario: Served without credentials
- **WHEN** an unauthenticated client requests `v1/openapi.json`
- **THEN** it receives the document, naming the instance's API URL in `servers`, while every other path answers `401`

#### Scenario: Drift
- **WHEN** a route is added to the router without being described, a described operation is not a route, or the YAML changes without regenerating the JSON copy
- **THEN** a test fails

#### Scenario: Validity
- **WHEN** CI validates the document
- **THEN** it is valid against the OpenAPI 3.1 schema, every reference resolves and operation ids are unique

### Requirement: Reference client

`examples/notes-client.mjs` SHALL be a dependency-free ES module for browsers and Node wrapping every client-facing endpoint (status, lookup, notes, page notes, highlights, changed-since syncs and annotated targets, following `continuation`), and `examples/highlight-page.html` SHALL use it with the anchoring library; both SHALL be tested against the dev instance.

#### Scenario: Stale ETag
- **WHEN** a client saves a note with an ETag that is no longer current
- **THEN** it gets a `PreconditionFailedError`, refetches, and saves again

#### Scenario: Idempotent create
- **WHEN** a client creates a highlight twice with the same uuid
- **THEN** the second call returns the first highlight and no duplicate exists

#### Scenario: Example page
- **WHEN** the example page is served from an allowed origin, a passage is highlighted and the page is reloaded
- **THEN** the highlight is stored with source `page` and drawn again, and a stored highlight whose passage is gone is listed as not found

### Requirement: Client guidance and upgrade

The README SHALL contain a "Building a client" guide for browser-extension authors and cover upgrading to 1.1.0, and upgrading a 1.0.0 install with notes SHALL run the migrations, keep the notes and serve the new API.

#### Scenario: Guide
- **WHEN** a developer reads "Building a client"
- **THEN** it covers lookup on tab change, anchoring with `source: "page"`, creating highlights with a client uuid, `If-Match` and 412 handling, API password authentication and its reach, `browser.storage.local`, HTTPS, host permissions, when CORS is needed, debouncing and anchoring the main content

#### Scenario: Upgrade
- **WHEN** a 1.0.0 install holding a note is replaced by the 1.1.0 release
- **THEN** the note is intact, `v1/status` reports 1.1.0, and highlights, page notes, `v1/annotated` and `v1/openapi.json` work
