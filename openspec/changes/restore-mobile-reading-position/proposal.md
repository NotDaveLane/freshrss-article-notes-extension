# Restore the reading position when the mobile highlight popover closes

## Why

On narrow screens the highlight popover is a bottom sheet, and opening it may scroll the page so the highlight shows above
the sheet (FAN-17). Closing the popover left the page scrolled, so the reader lost their place (FAN-20).

## What changes

- When opening the bottom sheet scrolled the page, dismissing the popover (save, close, Escape, delete, a tap outside it)
  scrolls back to the position the page had before it opened.
- Scrolling the page by hand (touch, wheel, scroll keys) while the popover is open cancels that: the page stays where the
  reader put it. Scrolling inside the sheet itself, programmatic scrolls and the virtual keyboard do not count.
- A popover replaced by another one (tapping another highlight) does not restore; the new popover applies its own rule.
- Desktop placement, where opening never scrolls, is unchanged.
