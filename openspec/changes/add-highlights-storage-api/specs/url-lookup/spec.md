## ADDED Requirements

### Requirement: Highlights in lookup

`GET v1/lookup` SHALL add `highlights` (the live highlights of the article, in document order) to each match and a top-level
`page_highlights` list with the live highlights that have no article at the looked-up URL.

#### Scenario: Article highlights
- **WHEN** a client looks up the URL of an article that has highlights
- **THEN** the match's `highlights` lists them and `page_highlights` is empty

#### Scenario: Page highlights
- **WHEN** a client looks up a URL that is not an article and has highlights
- **THEN** `matches` is empty and `page_highlights` lists them
