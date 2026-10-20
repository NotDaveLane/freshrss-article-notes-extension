## ADDED Requirements

### Requirement: Compact filters on small screens

The Notes page SHALL always show the search field and the Apply button, and SHALL place the kind, colour, feed and "only
with a note" filters behind a "Filters" toggle. The toggle SHALL be closed on screens narrower than 48rem unless one of
those filters is set, and on screens at least 48rem wide the filters SHALL be shown without the toggle. On touch screens
(`pointer: coarse`) the toggle, the "only with a note" row, the export links and the page's buttons SHALL be at least
44px high.

#### Scenario: Phone without filters
- **WHEN** the user opens the Notes page at 390x844 with no filter other than a search
- **THEN** the kind, colour, feed and note filters are hidden behind the Filters toggle and the first group's title is
  visible without scrolling

#### Scenario: Phone with a filter set
- **WHEN** the user opens the Notes page at 390x844 with the kind filter set
- **THEN** the filters are shown expanded

#### Scenario: Desktop
- **WHEN** the user opens the Notes page at 1440x900
- **THEN** every filter is shown and the Filters toggle is not

#### Scenario: Tap targets
- **WHEN** the Notes page is used on a touch screen
- **THEN** the Filters toggle, the "only with a note" row, the export links and the Edit and Open buttons are at least
  44px high
