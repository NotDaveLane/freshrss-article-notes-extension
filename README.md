# FreshRSS Article Notes

**ArticleNotes** is an open-source [FreshRSS](https://freshrss.org/) extension that lets you
attach a personal note to any article for future reference, and exposes those notes through an
authenticated FreshRSS API endpoint so other systems can fetch and analyze them later.

> **Status: in development.** You can already add, edit and delete notes (see [Using notes](#using-notes));
> the remaining items below are planned.

## Planned features (in development)

- One note per article per user, editable and deletable (plain Markdown-ish text, up to 20,000
  characters, always displayed escaped).
- Notes keep a snapshot of their article (title, link, feed, …) so they stay meaningful after
  FreshRSS purges the article.
- An optional "Annotated" label applied automatically to annotated articles (configurable per user),
  which also protects them from FreshRSS's archiving purge.
- A per-user settings page, linked from the Configuration menu.
- An authenticated API endpoint (`p/api/misc.php`) to fetch notes, including deletions.

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
