## ADDED Requirements

### Requirement: Searchable quote

The extension SHALL store the selected text of every highlight (`selector.quote.exact`) in a `quote` column of the highlights
table, set when the highlight is created and filled by migration `0007` for existing highlights, so annotations can be
searched by their quote without matching the selector's keys or context.

#### Scenario: Backfill
- **WHEN** migration `0007` runs on highlights stored without a `quote`
- **THEN** each gets the `exact` text of its selector, and running it again changes nothing
