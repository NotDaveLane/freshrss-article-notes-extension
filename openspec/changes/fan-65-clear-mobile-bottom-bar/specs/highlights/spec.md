## ADDED Requirements

### Requirement: Selection toolbar clear of FreshRSS's bottom navigation
The selection toolbar SHALL lie inside the visible part of the viewport, above any fixed navigation bar FreshRSS draws over the bottom of the screen: on touch screens below the selection when it fits there, else above it; with a fine pointer above it when it fits there, else below it.

#### Scenario: Selection near the bottom of a phone screen
- **WHEN** the user selects text about 100px above the bottom of the screen at 390×844 with touch
- **THEN** the toolbar's bounding box is inside the viewport and above FreshRSS's bottom navigation bar, and Highlight, Add note and every swatch receive taps

### Requirement: Compact selection toolbar on phones
On touch screens and narrow viewports the selection toolbar SHALL show Highlight and Add note side by side, the colour swatches following as one group, so no row holds a single button beside empty space.

#### Scenario: Toolbar rows on a phone
- **WHEN** the toolbar is shown at 390×844
- **THEN** it has two rows of 44px controls: Highlight and Add note, then the swatches
