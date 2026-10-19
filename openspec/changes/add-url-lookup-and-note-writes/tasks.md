## 1. Implementation

- [x] 1.1 `UrlNormalizer` with unit tests
- [x] 1.2 Migration `0004`, `UrlIndex`, `UrlLookup`, normalized URL on notes
- [x] 1.3 Index maintenance: `freshrss_user_maintenance`, `entry_before_update`, lazy backfill on lookup
- [x] 1.4 `GET v1/lookup`, `PUT`/`DELETE v1/notes/<entry_id>`, ETag / `If-Match`, body rules, methods per path
- [x] 1.5 PHPUnit integration tests (index, lookup, writes, isolation, migration from schema 0002) and a Playwright spec
- [x] 1.6 README API section, CHANGELOG, OpenSpec
