# url-lookup Specification

## Purpose

Recognize which FreshRSS article (or note) a web page URL stands for: URL normalization, the per-user URL index and the lookup endpoint.

## Requirements

### Requirement: URL normalization

`UrlNormalizer::normalize()` SHALL lowercase the scheme and host (IDN hosts as punycode), drop credentials, default ports
(80 for `http`, 443 for `https`) and the fragment, remove the `utm_*`, `fbclid`, `gclid`, `mc_cid`, `mc_eid` and `ref_src`
query parameters, sort the remaining ones, strip a trailing slash except on the root path, and decode percent-encoded
unreserved characters (upper-casing the other escapes).

#### Scenario: Variants of one page
- **WHEN** `http://WWW.Example.com:80/post/42/?utm_source=x&b=2&a=1#top` is normalized
- **THEN** the result is `https://example.com/post/42?a=1&b=2`

### Requirement: Equivalent URLs and rejected input

For matching, `http` and `https`, and a leading `www.`, SHALL be equivalent: the normalized form is written with `https` and
without `www.` (the original URL is kept for display). Input that is not an http(s) URL with a host (empty, relative,
another scheme, invalid port, control characters, over 16,383 characters) SHALL normalize to null.

#### Scenario: Not a web page
- **WHEN** `ftp://example.com/a`, `javascript:alert(1)` or `/relative` is normalized
- **THEN** the result is null

### Requirement: URL index table

The extension SHALL keep a per-user table `articlenotes_url` (migration `0004`, portable across SQLite, MySQL/MariaDB and
PostgreSQL) mapping the indexed hash of each article's normalized link, and of its GUID when that is a different URL, to the
article id, together with the article's link and GUID.

#### Scenario: Link and GUID
- **WHEN** an article links to `https://example.org/a` and has the GUID `https://example.org/guid`
- **THEN** both URLs find the article

### Requirement: URL index upkeep

Articles SHALL be indexed after FreshRSS stores them (it renumbers new articles when committing them, so
`entry_before_add` cannot know the final id): by the `freshrss_user_maintenance` hook (2,000 per run) and lazily on
`v1/lookup` (at most 500 per request, newest first). Maintenance SHALL prune rows of purged articles, `entry_before_update`
SHALL drop the rows of an updated article so they are rebuilt, and rows that no longer describe their article SHALL never be returned.

#### Scenario: New articles
- **WHEN** FreshRSS stores new articles and user maintenance (or a lookup) runs
- **THEN** each article is findable by the normalized form of its link

#### Scenario: Purged article
- **WHEN** FreshRSS purges an article and user maintenance runs
- **THEN** its index rows are removed

### Requirement: Notes keep their normalized URL

Migration `0004` SHALL add `normalized_url` and an indexed `url_hash` to the notes table and backfill them for existing notes
(the link, or the GUID when it is the only URL); saving a note SHALL store them, so notes stay findable by URL after FreshRSS
purges their article.

#### Scenario: Upgrade from schema 0002
- **WHEN** the migrator upgrades a database at schema `0002` that has notes
- **THEN** the notes are kept and their normalized URLs are filled in, and running `0004` again changes nothing

### Requirement: URL lookup

`GET v1/lookup?url=<page url>` SHALL answer `{"url", "normalized_url", "index_complete", "page_note", "matches"}`: up to 20 `matches`,
most recently published first, from the URL index plus the articles with a live note whose stored normalized URL matches.
`index_complete` SHALL be false while articles remain unindexed. Later versions add keys, never change these.

#### Scenario: Match shape
- **WHEN** a client looks up a URL that matches an article
- **THEN** each match is `{"entry_id", "greader_item_id", "title", "url", "published_at", "feed": {"id", "name", "url"}, "note"}` with `note` the note object or null

#### Scenario: Variant URL
- **WHEN** a client looks up `http://www.example.com/post/42/?utm_source=x` and FreshRSS has an article linking to `https://example.com/post/42`
- **THEN** the article is a match

#### Scenario: Purged article with a note
- **WHEN** a client looks up the URL of a purged article that has a note
- **THEN** the match comes from the note's snapshot and carries the note

#### Scenario: Another user's data
- **WHEN** user B looks up a URL that only user A has articles or notes for
- **THEN** `matches` is empty and `page_note` is null

### Requirement: Lookup parameter

A missing, non-http(s) or malformed `url` SHALL answer 400 `invalid_parameter`.

#### Scenario: Not a web page
- **WHEN** a client looks up `ftp://example.com/a`
- **THEN** the response is 400 with error code `invalid_parameter`

### Requirement: Page note in lookup

`page_note` SHALL be the user's live page note at the looked-up URL (a note object), or null, and SHALL be returned even when an article matches the URL too.

#### Scenario: Article and page note
- **WHEN** a page note exists for a URL and an article with that URL is fetched later
- **THEN** the lookup returns the article in `matches` and the page note in `page_note`
