## ADDED Requirements

### Requirement: Editor clear of FreshRSS's bottom navigation
When the note editor opens, its Save and Cancel buttons SHALL be fully visible and not covered by any fixed navigation bar FreshRSS draws over the bottom of the screen (`#nav_entries` in the normal view on phones); where that bar does not cover the article column, as on desktop, placement SHALL be unchanged.

#### Scenario: Opening the editor near the bottom of a phone screen
- **WHEN** the user taps "Note" at 390×844 with touch while the article's action bar is about 110px above the bottom of the screen
- **THEN** the page scrolls so that Save and Cancel are above FreshRSS's bottom navigation bar, and tapping their centre reaches the button itself

#### Scenario: Opening the editor on desktop
- **WHEN** the user opens the editor at 1440×900
- **THEN** it is scrolled into view as before, the navigation under the sidebar not counting
