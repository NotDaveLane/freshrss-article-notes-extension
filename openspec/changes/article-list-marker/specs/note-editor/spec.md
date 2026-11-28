## ADDED Requirements

### Requirement: Collapsed article rows mark annotated articles
The normal view SHALL show a non-interactive mark in the collapsed row of every article that has a note or highlights, and no mark on other rows. The mark SHALL be labelled for screen readers ("Has a note", "1 highlight", "N highlights") and SHALL follow saves and deletes of the note and of highlights without a reload.

#### Scenario: Annotated article listed
- **WHEN** the article list renders an article with a note and two highlights
- **THEN** its row header contains a mark labelled "Has a note, 2 highlights"

#### Scenario: Annotations removed
- **WHEN** the user deletes the note and all highlights of an article
- **THEN** the mark disappears from its row
