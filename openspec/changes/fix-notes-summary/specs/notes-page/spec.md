## ADDED Requirements

### Requirement: Summary and export links follow the result count

The Notes page SHALL show a summary line with the number of matching articles and pages, worded "1 annotated article or
page" for exactly one and "N annotated articles and pages" otherwise, followed by the Markdown and JSON export links. When
nothing matches the search and filters, the page SHALL show neither the summary line nor the export links, only the empty
or no-results message.

#### Scenario: One group
- **WHEN** the search matches a single article
- **THEN** the summary reads "1 annotated article or page" and the export links are shown

#### Scenario: Many groups
- **WHEN** the search matches 31 articles and pages
- **THEN** the summary reads "31 annotated articles and pages" and the export links are shown

#### Scenario: Nothing matches
- **WHEN** the search matches nothing
- **THEN** "Nothing matches these filters." is shown and there is no summary line and no export link
