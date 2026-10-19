# FreshRSS Article Notes

**ArticleNotes** is an open-source [FreshRSS](https://freshrss.org/) extension that lets you
attach a personal note to any article for future reference, and exposes those notes through an
authenticated FreshRSS API endpoint so other systems can fetch and analyze them later.

> **Status: in development.** The extension currently installs and loads but has no user-facing
> features yet. The list below is the plan.

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

- FreshRSS **1.27.0 or newer** (tested with 1.30.1).
- API access enabled (Configuration → Authentication → "Allow API access", plus an API password
  in your profile) to use the notes API.

## Installation

1. Download the release zip, or copy the `xExtension-ArticleNotes/` directory of this repository.
2. Place the `xExtension-ArticleNotes/` directory in FreshRSS's `extensions/` directory
   (in the official Docker image: `/var/www/FreshRSS/extensions`).
3. As an **administrator**, open Configuration → Extensions and enable **ArticleNotes**. It is a
   *system* extension, so it is enabled once for the whole instance.

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

See [AGENTS.md](AGENTS.md) for conventions, specs and the full command list.

## License

AGPL-3.0-or-later, like FreshRSS. See [LICENSE](LICENSE).
