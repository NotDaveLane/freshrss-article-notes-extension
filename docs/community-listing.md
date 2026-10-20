# Community extension list entry (draft)

Draft for listing ArticleNotes in [FreshRSS/Extensions](https://github.com/FreshRSS/Extensions), the source of
FreshRSS's in-app list of community extensions. Not submitted yet: open the pull request once the repository is public
and `v1.1.1` (with `ArticleNotes-1.1.1.zip` attached) is published.

## `repositories.json`

FreshRSS/Extensions builds its `extensions.json` from the repositories listed in `repositories.json`, reading the
`metadata.json` of every `xExtension-*` directory at a repository's root (`xExtension-ArticleNotes/` here). Add one entry,
keeping the file's existing style (tabs, `}, {` separators):

```json
{
	"url": "<public repository URL>",
	"type": "git"
}
```

The generated entry then takes its name (`ArticleNotes`), author, description, version (`1.1.1`), entrypoint and type
(`system`) from `xExtension-ArticleNotes/metadata.json`.

## README "Third-party extensions" bullet

```markdown
* [ArticleNotes](<public repository URL>): Attach a personal note to any article (one per article, kept with a snapshot of
  the article and an optional "Annotated" label) and fetch your notes, deletions included, through an authenticated JSON
  API. System extension, FreshRSS ≥ 1.27.0.
```

## Pull request text

> **Add ArticleNotes**
>
> ArticleNotes lets each user attach a personal note to any article and fetch the notes through an authenticated,
> read-only JSON API on `api/misc.php/ArticleNotes/` (FreshRSS API password or Google Reader token), with incremental
> sync and deletion tombstones. Notes keep a snapshot of their article so they survive purges; an optional per-user
> "Annotated" label keeps annotated articles browsable and out of the archiving purge.
>
> - Type: system (the `api_misc` hook only runs for system-enabled extensions); per-user settings on its own page.
> - FreshRSS ≥ 1.27.0, tested on 1.27.1 and 1.30.1 with SQLite, MariaDB and PostgreSQL.
> - License: AGPL-3.0-or-later.
