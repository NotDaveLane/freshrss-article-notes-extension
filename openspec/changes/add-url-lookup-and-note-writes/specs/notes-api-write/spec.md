## ADDED Requirements

### Requirement: Note upsert

`PUT v1/notes/<entry_id>` with the JSON body `{"note": "<text>"}` SHALL create the note of that article (201) or update it
(200) through `NoteService`, so validation and the "Annotated" label behave as in the UI, and SHALL answer
`{"note": <note object>}` with an `ETag` header. Empty text (after trimming) SHALL delete the note and answer 204. An unknown
article SHALL answer 404 `not_found`; text over 20,000 characters SHALL answer 422 `note_too_long`.

#### Scenario: Create then update
- **WHEN** a client PUTs a note on an article without one, then PUTs again
- **THEN** the first answer is 201 and the second 200, and both carry the note and its `ETag`

#### Scenario: Empty text
- **WHEN** a client PUTs `{"note": "  "}` on an article with a note
- **THEN** the response is 204 and the note is deleted

### Requirement: Note deletion

`DELETE v1/notes/<entry_id>` SHALL tombstone the live note, take back the label the extension applied, and answer 204;
without a live note it SHALL answer 404 `not_found`.

#### Scenario: Delete twice
- **WHEN** a client DELETEs the same note twice
- **THEN** the first answer is 204 and the second 404

### Requirement: Optimistic concurrency

Note responses SHALL carry `ETag: "<id>-<updated_at>"`, and `updated_at` SHALL increase with every save. `PUT` and `DELETE`
SHALL honor `If-Match` (a list of tags, or `*` for any live note; a weak prefix or a `-gzip`-style suffix added by a
compressing web server is ignored) and answer 412 `precondition_failed` without changing anything when it does not match the
live note, or when there is no live note to `PUT` over. Without `If-Match` they SHALL work unconditionally.

#### Scenario: Stale tag
- **WHEN** a client PUTs with an `If-Match` from before the last update
- **THEN** the response is 412 and the note is unchanged

#### Scenario: Current tag
- **WHEN** a client PUTs with the `ETag` of its last response
- **THEN** the note is updated and a new `ETag` is returned

### Requirement: Request body rules

Write bodies SHALL be `application/json` (parameters such as `charset` allowed; otherwise 415 `unsupported_media_type`), at
most 65,536 bytes (otherwise 413 `payload_too_large`), valid JSON (otherwise 400 `invalid_json`) and an object with a string
`note` (otherwise 400 `invalid_body`).

#### Scenario: Wrong media type
- **WHEN** a client PUTs `text/plain`
- **THEN** the response is 415 `unsupported_media_type`

#### Scenario: Oversized body
- **WHEN** a client PUTs a body of more than 65,536 bytes
- **THEN** the response is 413 `payload_too_large`

### Requirement: Credentials only from the header

Writes SHALL authenticate from the `Authorization` header only; a session cookie SHALL NOT authenticate a request, so no CSRF
token is needed.

#### Scenario: Cookie without credentials
- **WHEN** a browser session sends a `PUT` with its cookie and no `Authorization` header
- **THEN** the response is 401 `unauthorized` and nothing is written

### Requirement: Write isolation

Writes SHALL only create, change or delete the authenticated user's notes.

#### Scenario: Another user's note
- **WHEN** user B PUTs or DELETEs on an article that only user A has
- **THEN** the response is 404 and user A's note is unchanged
