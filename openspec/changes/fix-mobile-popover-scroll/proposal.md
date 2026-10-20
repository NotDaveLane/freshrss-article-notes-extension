# Stop the page jumping each time a highlight is tapped on mobile

## Why

On narrow screens the highlight popover is a bottom sheet (`position: fixed`). Focusing it (and restoring focus when it
closed) let mobile browsers scroll the document, and every tap on the same highlight rebuilt and refocused the popover, so
the page moved down again on each tap (FAN-17).

## What changes

- The popover, its note field and the opener are focused with `preventScroll`.
- When the popover is shown as the bottom sheet, the page scrolls just enough to show the element that opened it above
  the sheet, and not at all when it is already visible, so repeated taps leave the page where it is.
- Desktop placement (the popover next to the highlight) is unchanged.
