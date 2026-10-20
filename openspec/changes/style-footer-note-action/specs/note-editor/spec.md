## ADDED Requirements

### Requirement: Footer Note action

The "Note" action in the article footer SHALL show an icon before its label, like FreshRSS's other footer items, keep at
least 8px between itself and the article date, be at least 44x44 px on touch screens and narrow viewports, and indicate
when the article already has a note (bold label, filled icon, an "Edit your note on this article" title instead of "Add a
note to this article"). The indicator SHALL follow saving and deleting the note without reloading the page.

#### Scenario: Spacing and tap target
- **WHEN** an article is shown at 1440x900 or 390x844
- **THEN** the Note action has an icon hidden from assistive technology, keeps the accessible name "Note", is at least 8px
  away from the date, and at 390x844 is at least 44x44 px

#### Scenario: Note indicator
- **WHEN** the user saves a note on an article, reloads, and then deletes the note
- **THEN** the Note action is marked as having a note after saving and after reloading, and is unmarked after deleting
