## MODIFIED Requirements

### Requirement: API routing

The extension SHALL answer `p/api/misc.php/ArticleNotes/<path>` (and `p/api/misc.php/<path>?ext=ArticleNotes`) through
the `ApiMisc` hook with the versioned paths `v1/status`, `v1/notes`, `v1/lookup`, `v1/page-notes`, `v1/highlights`, `v1/notes/<entry_id>` and
`v1/highlights/<uuid>` (a trailing slash is accepted), and SHALL answer 404 `not_found` to any other path, even without credentials.

#### Scenario: Unknown path
- **WHEN** a client requests `v2/notes`, `v1/notes/abc` or `v1/notes/1/2`
- **THEN** the response is 404 with error code `not_found`

#### Scenario: Query-string form
- **WHEN** a client requests `p/api/misc.php/v1/status?ext=ArticleNotes`
- **THEN** it gets the same answer as `p/api/misc.php/ArticleNotes/v1/status`

### Requirement: Methods per path

Every path SHALL accept `GET` and `HEAD` (`HEAD` answers like `GET` without a body); `v1/notes/<entry_id>` and `v1/page-notes` SHALL also accept
`PUT` and `DELETE`, `v1/highlights` SHALL also accept `POST`, and `v1/highlights/<uuid>` SHALL also accept `PATCH` and `DELETE`. Any other
method on a path SHALL answer 405 `method_not_allowed` with an `Allow` header listing the accepted methods (for example
`GET, HEAD` or `GET, HEAD, PUT, DELETE`), before credentials are checked.

#### Scenario: Write method on a read-only path
- **WHEN** a client sends `POST`, `PUT`, `PATCH` or `DELETE` to `v1/notes`, `v1/status` or `v1/lookup`
- **THEN** the response is 405 with error code `method_not_allowed` and `Allow: GET, HEAD`
