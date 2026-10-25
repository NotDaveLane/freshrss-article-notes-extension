## ADDED Requirements

### Requirement: Highlight tap targets on touch
On touch screens and narrow viewports (`(pointer: coarse), (max-width: 40em)`) every button and link of the selection toolbar, the highlight bottom sheet and the Annotations panel (including the colour swatches and "All my notes") SHALL be at least 44×44 CSS pixels, with at least 0.4rem between swatches; the toolbar SHALL wrap within the viewport. With a fine pointer on a wide viewport the controls SHALL keep their regular size.

#### Scenario: Controls on a phone
- **WHEN** the user selects text, then taps a highlight, at 390×844 with touch
- **THEN** every button and link in the toolbar, the bottom sheet and the Annotations panel is at least 44×44px
- **AND** the toolbar wraps onto several rows inside the viewport without horizontal overflow

#### Scenario: Controls on desktop
- **WHEN** the user selects text and clicks a highlight at 1440×900 with a mouse
- **THEN** the toolbar's Highlight button and swatches and the popover's Close button keep their 40px height
