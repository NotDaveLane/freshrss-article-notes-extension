## MODIFIED Requirements

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

### Requirement: Page note in lookup

`page_note` SHALL be the user's live page note at the looked-up URL (a note object), or null, and SHALL be returned even when an article matches the URL too.

#### Scenario: Article and page note
- **WHEN** a page note exists for a URL and an article with that URL is fetched later
- **THEN** the lookup returns the article in `matches` and the page note in `page_note`
