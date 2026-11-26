## ADDED Requirements

### Requirement: Configuration menu entries named by purpose

The extension SHALL add two entries to FreshRSS's Configuration menu, the list of the user's notes first, named "My notes"
(`?c=ArticleNotes&a=notes`), then the settings, named "Article notes settings" (`?c=ArticleNotes&a=settings`). Each page's
`<h1>` and document title SHALL match its menu entry, and the entry of the open page SHALL be marked active.

#### Scenario: Menu entries
- **WHEN** a signed-in user opens the Configuration menu, on a desktop or mobile screen
- **THEN** it lists "My notes" then "Article notes settings", linking to the Notes page and the settings page

#### Scenario: Headings match the menu
- **WHEN** the user opens "My notes" or "Article notes settings"
- **THEN** the page heading and the document title start with the same name, and that menu entry is active
