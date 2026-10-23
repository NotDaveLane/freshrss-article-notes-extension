# List highlights in the Annotations panel in reading order (FAN-42)

## Why
The Annotations panel under an opened article listed drawn highlights bottom-up: `sortedItems()` returned `1` when the
second highlight follows the first in the text. README promises reading order, and the Notes page already lists the same
highlights top-down.

## What Changes
- Drawn highlights are compared by their place in the text (first mark), first one first.
- When either highlight is not drawn ("Passage not found"), they are compared by `position.start` (none: after the
  others), then by creation, as before.

## Impact
- `static/highlights.js`; e2e `specs/highlights.spec.ts`. No PHP, markup or i18n change.
