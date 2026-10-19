# AGENTS.md

Guidance for AI coding agents (and humans) working in this repository.

## Layout

- `xExtension-ArticleNotes/` – the installable FreshRSS extension (`metadata.json`, `extension.php`, `i18n/`).
  Everything else at the root is development tooling.
- `Dockerfile`, `docker-compose.yml`, `docker/` – dev FreshRSS image (stock FreshRSS + curl + seeding scripts).
- `tests/fixtures/feeds/feed.xml` – static fixture feed (stable GUIDs) served at `http://fixtures/feed.xml`.
- `openspec/` – `project.md` and capability specs in `openspec/specs/`.
- `CHANGELOG.md`, `README.md`, `LICENSE` (AGPL-3.0-or-later).

## Every tool runs in a container

Nothing (PHP, Composer, Node) is assumed on the host. Use throwaway containers, e.g.
`docker run --rm -v "$PWD":/app -w /app composer:2 ...`, and make sure created files are owned by
you (`--user "$(id -u):$(id -g)"` or a chown). Once test tooling exists, `bin/ci` is the single host-side
full-suite entry point (HTML coverage in `coverage/index.html`).

## Dev instance

```sh
docker compose up --build -d --wait      # ready instance, no manual steps
docker compose port freshrss 80          # where it listens (default 8080)
docker compose down -v                   # reset
```

Credentials (development only): `admin` / `freshrss-dev-password`, API password `freshrss-dev-api`
(see `.env.example`). Seed feeds: Hacker News, Lobsters. Tests must use `http://fixtures/feed.xml`,
never the live feeds. First boot is idempotent: seeding re-runs safely on every start.

Useful: `docker compose exec freshrss php cli/actualize-user.php --user admin`,
`docker compose logs freshrss`.

## FreshRSS extension conventions

- System extension (`"type": "system"`), enabled via `extensions_enabled`; per-user settings on the
  extension's own controller page linked via the `MenuConfigurationEntry` hook. API via `Minz_HookType::ApiMisc` (FreshRSS ≥ 1.27.0).
- PHP: tabs, `declare(strict_types=1);`, FreshRSS code style, `final` classes, `#[\Override]`.
- Translations through `registerTranslates()` and `i18n/<lang>/ext.php`; no hardcoded UI strings.
- CSRF tokens on every state-changing form/request; escape all output (notes are rendered escaped).
- Prepared statements only; portable SQL (SQLite, MySQL/MariaDB, PostgreSQL); per-user tables using
  FreshRSS's table prefix.
- Data is per user; never leak across users. Never modify FreshRSS core.

## Versioning

Semantic versioning in `xExtension-ArticleNotes/metadata.json`; record every user-visible change in
`CHANGELOG.md` (Keep a Changelog, under `## [Unreleased]`). Schema changes use numbered, idempotent
per-user migrations (FreshRSS has no extension upgrade hook).

## OpenSpec

Consult the relevant spec in `openspec/specs/` before changing covered behavior, and update the spec in
the same commit as the behavior change. Validate:

```sh
docker run --rm -v "$PWD":/work -w /work node:22 npx --yes @fission-ai/openspec validate --specs --strict
```

## Commits

Small, well-scoped commits referencing the task id (e.g. FAN-1).
