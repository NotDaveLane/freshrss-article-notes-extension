## ADDED Requirements

### Requirement: Highlight tap targets on touch
On touch screens and narrow viewports (`(pointer: coarse), (max-width: 40em)`) every button and link of the selection toolbar, the highlight bottom sheet and the Annotations panel, colour swatches and "All my notes" included, SHALL be at least 44×44 CSS pixels, without horizontal overflow; with a fine pointer on a wide viewport they SHALL keep their regular size.

#### Scenario: Controls on a phone
- **WHEN** the user selects text, then taps a highlight, at 390×844 with touch
- **THEN** every button and link in the toolbar, the bottom sheet and the Annotations panel is at least 44×44px
- **AND** the swatches are at least 0.4rem apart and the toolbar wraps onto several rows inside the viewport without horizontal overflow
- **AND** the decorative colour dots in the Annotations panel list, which are not controls, stay 1rem wide

#### Scenario: Controls on desktop
- **WHEN** the user selects text and clicks a highlight at 1440×900 with a mouse
- **THEN** the toolbar's Highlight button and swatches and the popover's Close button keep their 40px height
