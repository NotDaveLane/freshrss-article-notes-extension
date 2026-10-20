## ADDED Requirements

### Requirement: Bottom sheet reading position

When opening the highlight popover as a bottom sheet scrolled the page, dismissing the popover (saving a note, Close,
Escape, deleting the highlight, or tapping outside it) SHALL return the page to the scroll position it had before the
popover opened. This refines the "Bottom sheet scrolling" rule that closing the popover does not scroll the page.

#### Scenario: Note added from the toolbar
- **WHEN** a user at 390x844 selects text low in the viewport, taps Add note, types a note and saves
- **THEN** the page scrolled to show the selection above the sheet, and after saving it is back where it was

#### Scenario: Popover dismissed
- **WHEN** a user taps a highlight behind the sheet and then taps Close, presses Escape or taps outside the popover
- **THEN** the page returns to where it was before the tap, and the highlight has the focus after Close or Escape

### Requirement: Manual scrolling keeps the page in place

The bottom sheet popover SHALL NOT restore the reading position when the reader scrolled the page (touch, wheel or
scroll keys outside the popover) while it was open, when it is replaced by another popover, or when opening it did not
scroll the page. Scrolling the popover's own content SHALL NOT count as scrolling the page.

#### Scenario: Reader scrolled while the popover was open
- **WHEN** a user taps a highlight behind the sheet, scrolls the page and then taps Close
- **THEN** the page stays where the user scrolled it

#### Scenario: Opening did not scroll
- **WHEN** a user taps a highlight well above the sheet and then taps Close
- **THEN** the page does not scroll

#### Scenario: Desktop
- **WHEN** a user at 1440x900 opens and closes the popover with the mouse and with the keyboard
- **THEN** the page does not scroll
