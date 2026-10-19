# FreshRSS Article Notes

**ArticleNotes** is an open-source [FreshRSS](https://freshrss.org/) extension that lets you
attach a personal note to any article for future reference, and exposes those notes through an
authenticated FreshRSS API endpoint so other systems can fetch and analyze them later.

## Features

- One note per article per user, editable and deletable (plain Markdown-ish text, up to 20,000
  characters, always displayed escaped) — see [Using notes](#using-notes).
- Notes keep a snapshot of their article (title, link, feed, …) so they stay meaningful after
  FreshRSS purges the article.
- An optional "Annotated" label applied to annotated articles, configurable per user — see
  [Configuration](#configuration).
- A read-only, authenticated JSON [API](#api) to fetch and sync your notes, deletions included.

## Requirements

- FreshRSS **1.27.0 or newer** (tested with 1.30.1). On older versions the extension logs a warning and
  does not register its API endpoint.
- API access enabled (Configuration → Authentication → "Allow API access", plus an API password
  in your profile) to use the notes API.

## Installation

1. Download the release zip, or copy the `xExtension-ArticleNotes/` directory of this repository.
2. Place the `xExtension-ArticleNotes/` directory in FreshRSS's `extensions/` directory
   (in the official Docker image: `/var/www/FreshRSS/extensions`).
3. As an **administrator**, open Configuration → Extensions and enable **ArticleNotes**. It is a
   *system* extension, so it is enabled once for the whole instance.

## Using notes

Open an article and click **Note** in its action bar (next to the read, favourite and sharing icons; also in the
reading and global views). Type your note and press **Save**; **Cancel** (or Escape) closes the editor, and **Delete**
removes an existing note. Notes are plain text of up to 20,000 characters, shown under the article in a "Your note" block
with the time of the last update; HTML and Markdown are never rendered. Saving an empty note deletes it.

## Configuration

Every user has a settings page: Configuration → **Article notes** (no administrator needed).

- **Add a label to articles I annotate** (on by default): adding a note applies a FreshRSS label to the article, so annotated
  articles are listed under it in the sidebar. Deleting the note removes the label again, but only if the extension applied
  it (a label you had added yourself stays).
- **Label name** (default `Annotated`, 1–63 characters): the label is created the first time it is needed.

Turning the label off or renaming it affects notes you add from then on; already labelled articles keep their label.
Labelled articles are kept by FreshRSS's default archiving setting "keep labelled articles" (Configuration → Archiving),
so annotated articles are not purged. The page also shows your API endpoint, `<FreshRSS base URL>/api/misc.php/ArticleNotes/`.

## API

ArticleNotes serves each user's notes as read-only JSON on FreshRSS's extension API endpoint:

```
https://freshrss.example.net/api/misc.php/ArticleNotes/v1/...
```

(`/api/misc.php/v1/...?ext=ArticleNotes` works too.) Only `GET` and `HEAD` are accepted.

### Authentication

The API uses the same credentials as FreshRSS's Google Reader API, never your web login password:

1. An administrator allows API access (Configuration → Authentication → "Allow API access").
2. Each user sets an **API password** under Configuration → Profile ("External access via API" → "API password").
   Use HTTPS: the password travels with every request.

Then authenticate every request with either:

- **HTTP Basic** with your user name and API password:

  ```sh
  curl -u alice:my-api-password https://freshrss.example.net/api/misc.php/ArticleNotes/v1/status
  ```

- **A Google Reader API token**, as returned by FreshRSS's ClientLogin:

  ```sh
  curl -d Email=alice -d Passwd=my-api-password https://freshrss.example.net/api/greader.php/accounts/ClientLogin
  # … Auth=alice/0123abcd…
  curl -H 'Authorization: GoogleLogin auth=alice/0123abcd…' \
    https://freshrss.example.net/api/misc.php/ArticleNotes/v1/status
  ```

Wrong or missing credentials, disabled users and users without an API password get `401` with
`WWW-Authenticate: Basic realm="ArticleNotes"`. A user only ever sees their own notes.

### Endpoints

Every response, errors included, is JSON (`application/json; charset=UTF-8`, `Cache-Control: no-store`). Errors look
like `{"error": {"code": "not_found", "message": "…"}}` with the codes `invalid_parameter` (400), `unauthorized` (401),
`not_found` (404), `method_not_allowed` (405) and `internal_error` (500).

- `GET v1/status` — a cheap credentials check:
  `{"extension": "ArticleNotes", "version": "0.1.0", "api_version": 1, "user": "alice", "note_count": 12}`
  (`note_count` counts live notes).
- `GET v1/notes` — your notes, oldest change first (ordered by `updated_at`, then `id`):
  `{"notes": [ … ], "continuation": "…" or null, "server_time": 1760900000}`. Parameters:
  - `limit`: notes per page, 1–500 (default 100);
  - `since`: Unix time; only notes created, edited or deleted at or after it;
  - `include_deleted`: `1` to include deleted notes (tombstones), `0` to leave them out; defaults to `1` when `since`
    is given, `0` otherwise;
  - `continuation`: the `continuation` of the previous page, to get the next one (it keeps the filters of the first
    request). It is `null` on the last page.
- `GET v1/notes/<entry_id>` — `{"note": { … }}`, the live note of one article; `404` when there is none.

### The note object

```json
{
  "id": 7,
  "entry_id": "1760891234567890",
  "greader_item_id": "tag:google.com,2005:reader/item/00064185708646d2",
  "note": "Worth re-reading.\nSee also the follow-up post.",
  "created_at": 1760891300, "created_at_iso": "2025-10-19T16:28:20+00:00",
  "updated_at": 1760895000, "updated_at_iso": "2025-10-19T17:30:00+00:00",
  "deleted_at": null, "deleted_at_iso": null,
  "label_applied": false,
  "article": {
    "guid": "https://example.org/post/42",
    "url": "https://example.org/post/42",
    "title": "An interesting post",
    "author": "Jane Doe",
    "published_at": 1760880000, "published_at_iso": "2025-10-19T13:20:00+00:00",
    "feed": {"id": 3, "name": "Example blog", "url": "https://example.org/feed.xml"},
    "exists": true
  }
}
```

- `entry_id` is FreshRSS's article id, as a string; `greader_item_id` is the same article in the Google Reader API's
  long form, so you can correlate notes with items fetched through `greader.php`.
- `note` is the text exactly as entered (plain text: escape it when you display it), or `null` for a deleted note.
- Timestamps are Unix seconds, with an ISO 8601 (UTC) `*_iso` twin.
- `article` is a snapshot of the article taken when the note was last saved; it stays available after FreshRSS
  purges the article, and `exists` tells whether FreshRSS still has it.

### Keeping a copy in sync

1. First sync: page through `GET v1/notes` (following `continuation` until it is `null`) and remember the
   `server_time` of the **first** page.
2. Next syncs: `GET v1/notes?since=<that server_time>`, again following `continuation`, and remember the new first
   page's `server_time`. Changes since then include deleted notes: when `deleted_at` is set, delete your copy.
3. Apply notes by `entry_id` (or `id`): `since` is inclusive, so a note can come again; treat it as an update.

```sh
curl -u alice:my-api-password 'https://freshrss.example.net/api/misc.php/ArticleNotes/v1/notes?limit=500'
curl -u alice:my-api-password 'https://freshrss.example.net/api/misc.php/ArticleNotes/v1/notes?since=1760900000'
curl -u alice:my-api-password 'https://freshrss.example.net/api/misc.php/ArticleNotes/v1/notes/1760891234567890'
```

## Upgrading

Replace the `xExtension-ArticleNotes/` directory with the new release. Nothing else is needed: the
extension tracks a schema version per user and applies any missing database migrations automatically,
the first time it uses its storage and during FreshRSS's regular feed refresh (cron). Versions and
changes are listed in [CHANGELOG.md](CHANGELOG.md). Disabling the extension keeps your data; removing it
through FreshRSS's uninstall drops its tables and settings for every user.

## Development

Everything runs in containers; you only need Docker with Compose. No PHP, Composer or Node on the host.

```sh
docker compose up --build -d --wait   # builds, installs, seeds, and waits until ready
```

- URL: find the port with `docker compose port freshrss 80` (default <http://localhost:8080/>;
  override with `FRESHRSS_PORT`).
- Login: `admin` / `freshrss-dev-password` (API password `freshrss-dev-api`). These are
  development-only defaults; override them by copying `.env.example` to `.env`.
- The instance is subscribed to Hacker News and Lobsters (empty if you are offline) and the
  extension is mounted from `xExtension-ArticleNotes/` and enabled.
- A fixture feed is served inside the compose network at `http://fixtures/feed.xml`.

```sh
docker compose down        # stop, keep data
docker compose down -v     # stop and reset everything (fresh install on next start)
```

### Tests and quality checks

With the stack up, one command runs everything in containers (code style, static analysis, PHPUnit with
coverage, Playwright end-to-end tests):

```sh
bin/ci               # SQLite only; HTML coverage report in coverage/index.html
bin/ci --all-dbs     # also run the integration tests on MariaDB and PostgreSQL
```

Single runs: `bin/phpunit tests/Unit/MetadataTest.php`, `TEST_DB=postgres bin/phpunit --testsuite integration`,
`bin/e2e specs/smoke.spec.ts`, `bin/lint`. End-to-end tests use a throwaway user subscribed to the local
fixture feed, never the live feeds.

### Releasing

Maintainers: bump `version` in `xExtension-ArticleNotes/metadata.json`, move the `[Unreleased]` entries of
`CHANGELOG.md` under a new `## [X.Y.Z] - YYYY-MM-DD` heading, commit, tag `vX.Y.Z`, then run `bin/package`
to build `dist/ArticleNotes-X.Y.Z.zip` (it contains only `xExtension-ArticleNotes/`).

See [AGENTS.md](AGENTS.md) for conventions, specs and the full command list.

## License

AGPL-3.0-or-later, like FreshRSS. See [LICENSE](LICENSE).
