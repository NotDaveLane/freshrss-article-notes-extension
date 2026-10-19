# Project: FreshRSS Article Notes

Open-source FreshRSS extension (`ArticleNotes`, system type, AGPL-3.0-or-later) letting each user attach a
personal note to articles and exposing notes via an authenticated FreshRSS API endpoint.

## Conventions

- Extension code in `xExtension-ArticleNotes/`; tooling at the repository root.
- Minimum FreshRSS 1.27.0; dev/test image `freshrss/freshrss:1.30.1`.
- All tools run in containers; `bin/ci` is the full-suite entry point once it exists.
- Red/green TDD; specs in `openspec/specs/` are updated with every behavior change.
- Semantic versioning (`metadata.json`) and Keep a Changelog.
