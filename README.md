# FreshRSS Article Notes

**ArticleNotes** is an open-source [FreshRSS](https://freshrss.org/) extension that lets you attach a personal note to any
article for future reference, and exposes those notes through an authenticated FreshRSS API endpoint so other systems can
fetch and analyze them later.

![Writing a note under a Hacker News article](docs/screenshots/note-editor.png)

## Features

- **One note per article per user**, editable and deletable, from a **Note** action on every article — see
  [Using notes](#using-notes). Notes are plain Markdown-ish text of up to 20,000 characters, always displayed escaped.
- **Snapshots**: every note keeps a copy of its article's title, link, author, date and feed, so it stays meaningful after
  FreshRSS purges the article.
- **An "Annotated" label** on the articles you annotate (optional and renamable per user), so you can browse them in the
  sidebar and FreshRSS keeps them when it archives old articles — see [Configuration](#configuration).
- **A read-only JSON [API](#api)** to fetch and sync your notes, deletions included, with your FreshRSS API password.
- Per-user data, SQLite / MySQL / MariaDB / PostgreSQL, English interface; licensed AGPL-3.0-or-later like FreshRSS.

| The note under its article | Annotated articles in the sidebar | Per-user settings |
| --- | --- | --- |
| ![A saved note under its article](docs/screenshots/note.png) | ![The Annotated label lists annotated articles](docs/screenshots/annotated-label.png) | ![The Article notes settings page](docs/screenshots/settings.png) |

## Requirements

- **FreshRSS 1.27.0 or newer** (tested with 1.27.1 and 1.30.1). On older versions the extension logs a warning and does
  not register its API endpoint.
- **PHP**: whatever your FreshRSS version requires (PHP 8.1 or newer for FreshRSS 1.27–1.30); no extra PHP extension.
- **Database**: any database FreshRSS supports (SQLite, MySQL/MariaDB, PostgreSQL); the extension adds one table per user.
- **For the API only**: API access allowed by the administrator (Configuration → Authentication → "Allow API access"),
  and an API password for each user who uses it.

## Installation

ArticleNotes is a single directory, `xExtension-ArticleNotes/`, that goes into FreshRSS's `extensions/` directory
(`/var/www/FreshRSS/extensions` in the official Docker image). Pick one way to get it there:

**a) Release zip.** Download `ArticleNotes-X.Y.Z.zip` from the Releases (or Tags) page of this repository and unpack it
into `extensions/`; the zip contains just `xExtension-ArticleNotes/`:

```sh
cd /path/to/FreshRSS/extensions
unzip ~/Downloads/ArticleNotes-1.0.0.zip      # creates xExtension-ArticleNotes/
```

**b) Git.** Clone the repository (optionally check out a release tag such as `v1.0.0`) and copy the extension directory
(the rest of the repository is development tooling):

```sh
git clone <repository URL> freshrss-article-notes
cp -r freshrss-article-notes/xExtension-ArticleNotes /path/to/FreshRSS/extensions/
```

**c) Docker** ([`freshrss/freshrss`](https://hub.docker.com/r/freshrss/freshrss)). Bind-mount the unpacked
`xExtension-ArticleNotes/` into the container's extensions directory, for example with Docker Compose:

```yaml
services:
  freshrss:
    image: freshrss/freshrss:1.30.1
    ports:
      - "8080:80"
    volumes:
      - freshrss_data:/var/www/FreshRSS/data
      - ./xExtension-ArticleNotes:/var/www/FreshRSS/extensions/xExtension-ArticleNotes:ro
volumes:
  freshrss_data:
```

(or `docker run … -v "$PWD/xExtension-ArticleNotes:/var/www/FreshRSS/extensions/xExtension-ArticleNotes:ro" freshrss/freshrss`).
If you already mount a whole directory on `/var/www/FreshRSS/extensions` (as FreshRSS's documentation suggests), unpack
the zip into that directory instead.

The files must be readable by the web server (`www-data` in the Docker image). Then:

1. **Enable it, as an administrator**: Configuration → Extensions → **ArticleNotes** → enable. It is a *system* extension:
   it is enabled once for the whole instance, and every user gets their own notes and settings.
2. **To use the API**, the administrator allows API access (Configuration → Authentication → "Allow API access"), and each
   user sets an API password: Configuration → Profile → "External access via API" → **API password**, then Submit.

## Using notes

Open an article and click **Note** in its action bar (next to the read, favourite and sharing icons; also in the reading
and global views). Type your note and press **Save**; **Cancel** (or Escape) closes the editor, and **Delete** removes an
existing note. Notes are plain text of up to 20,000 characters, shown under the article in a "Your note" block with the
time of the last update; HTML and Markdown are never rendered. Saving an empty note deletes it.

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
2. Each user sets an **API password** under Configuration → Profile, section "External access via API" → "API password".
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
  `{"extension": "ArticleNotes", "version": "1.0.0", "api_version": 1, "user": "alice", "note_count": 12}`
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

Replace the `xExtension-ArticleNotes/` directory with the new release (unpack the new zip over it, `git pull` and copy
again, or update your bind mount's source). Nothing else is needed: the extension keeps a schema version per user and
applies any missing database migrations automatically, the first time it uses its storage for that user and during
FreshRSS's regular feed refresh (cron). Your notes and settings are kept. Versions and changes are listed in
[CHANGELOG.md](CHANGELOG.md).

## Uninstalling

- **Disabling** the extension (Configuration → Extensions, administrator) keeps every user's notes and settings; enabling it
  again brings everything back. FreshRSS's **Remove** button deletes the `xExtension-ArticleNotes/` directory and also
  keeps the data (as does deleting the directory yourself).
- **To delete the data permanently**, run the extension's script from the FreshRSS directory *before* removing the
  extension, as the web server user:

  ```sh
  cd /var/www/FreshRSS
  sudo -u www-data php extensions/xExtension-ArticleNotes/cli/delete-data.php --yes                # every user
  sudo -u www-data php extensions/xExtension-ArticleNotes/cli/delete-data.php --yes --user alice   # one user
  # Docker: docker exec -u www-data freshrss php extensions/xExtension-ArticleNotes/cli/delete-data.php --yes
  ```

  It drops the notes table (`articlenotes_note` with FreshRSS's per-user prefix: every note and tombstone) and removes the
  extension's settings of those users. The labels it created (e.g. "Annotated") are ordinary FreshRSS labels and stay,
  with their articles; delete them in Subscription management → Label management if you no longer want them.

## FAQ and troubleshooting

- **There is no "Note" button.** The extension is not enabled (Configuration → Extensions, as an administrator), or the
  page was opened before it was enabled: reload it.
- **The API answers `404` with the plain text `Not Found!`** (FreshRSS 1.28 and newer). ArticleNotes is not enabled in
  the *system* configuration (an administrator must enable it), or the extension name in the URL is not spelled exactly
  `ArticleNotes`. If your web server does not pass the path after `misc.php` (`PATH_INFO`), use
  `…/api/misc.php/v1/status?ext=ArticleNotes`.
- **The API answers `503` (`Service Unavailable!`).** API access is disabled (Configuration → Authentication → "Allow API
  access"). FreshRSS 1.27 also answers `503` when ArticleNotes is not enabled system-wide or the name is misspelled.
- **`api/misc.php` does not exist** (a web server 404 page): FreshRSS is older than 1.27.0; upgrade FreshRSS.
- **The API answers `401` (JSON `unauthorized`).** The user name or API password is wrong; you used your web login password
  instead of the API password; the user has no API password yet (Profile → "External access via API"); the user is
  disabled; or a reverse proxy / PHP-FPM setup drops the `Authorization` header (forward it, e.g. Apache's
  `CGIPassAuth On`; FreshRSS's own Google Reader API needs it too).
- **`404` with the JSON `not_found` error** is the extension speaking: an unknown path, or no live note for that
  `entry_id`.
- **Where are errors logged?** In FreshRSS's logs (Configuration → Logs), in messages starting with `ArticleNotes`.

## Development

Everything runs in containers; you only need Docker with Compose. No PHP, Composer or Node on the host.

```sh
docker compose up --build -d --wait   # builds, installs, seeds, and waits until ready
```

- URL: find the port with `docker compose port freshrss 80` (default <http://localhost:8080/>;
  override with `FRESHRSS_PORT`). Another FreshRSS version: `docker compose down -v`, then
  `FRESHRSS_VERSION=1.27.1 docker compose up --build -d --wait`.
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
bin/ci --compat      # the matrix: bin/ci --all-dbs on FreshRSS 1.27.1 and 1.30.1, each in a throwaway stack
```

`bin/ci --compat` does not need (or touch) the dev stack; set `COMPAT_VERSIONS` (e.g. `COMPAT_VERSIONS="1.27.1"`) to pick
versions. It lints on the default version only, as FreshRSS's APIs and coding standard differ between versions.

Single runs: `bin/phpunit tests/Unit/MetadataTest.php`, `TEST_DB=postgres bin/phpunit --testsuite integration`,
`FRESHRSS_VERSION=1.27.1 bin/phpunit`, `bin/e2e specs/full-journey.spec.ts`, `bin/lint`. End-to-end tests use throwaway
users subscribed to the local fixture feed, never the live feeds.

`bin/screenshots` refreshes the pictures in `docs/screenshots/` from the dev instance (seeded Hacker News content).

### Releasing

Maintainers:

1. Bump `version` in `xExtension-ArticleNotes/metadata.json` and move the `[Unreleased]` entries of `CHANGELOG.md` under a
   new `## [X.Y.Z] - YYYY-MM-DD` heading (a test fails if the two disagree).
2. Run `bin/ci --compat`, commit, and tag `vX.Y.Z`.
3. `bin/package` builds `dist/ArticleNotes-X.Y.Z.zip` (only `xExtension-ArticleNotes/`); `bin/verify-release` installs
   that zip into a clean `freshrss/freshrss` container, enables it as the administrator, adds a note and fetches it
   through the API. Attach the zip to the release.

See [AGENTS.md](AGENTS.md) for conventions, specs and the full command list.

## License

AGPL-3.0-or-later, like FreshRSS. See [LICENSE](LICENSE).
