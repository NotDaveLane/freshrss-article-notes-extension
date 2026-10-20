## ADDED Requirements

### Requirement: Bottom sheet scrolling

When the highlight popover is shown as a bottom sheet (small screens), opening it SHALL scroll the page only when the
highlight (or the button that opened it) would be hidden behind the sheet, and then just enough to show it above the
sheet. Opening the popover again for a visible highlight, and closing it, SHALL NOT scroll the page. Where the popover is
placed next to the highlight (larger screens), opening it SHALL NOT scroll the page.

#### Scenario: Highlight behind the sheet
- **WHEN** a user taps a highlight in the lower part of a 390x844 viewport
- **THEN** the page scrolls so the highlight is above the sheet, and tapping it again does not scroll the page

#### Scenario: Highlight already visible
- **WHEN** a user taps a highlight well above where the sheet appears
- **THEN** the page does not scroll

#### Scenario: Desktop
- **WHEN** a user clicks a highlight twice at 1440x900
- **THEN** the page does not scroll and the popover is shown next to the highlight
