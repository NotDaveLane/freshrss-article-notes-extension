# Name the saved note block "Your note" (FAN-31)

## Why
The note block's `title` and `aria-label` were "Tap to edit note": a desktop mouse user saw a touch-only tooltip, and
assistive technology announced a region named "Tap to edit note", overriding the visible "Your note" heading.

## What Changes
- The `edit_hint` translation reads "Edit your note" (device-neutral); it stays the block's `title` tooltip.
- The block is a region labelled by its "Your note" heading (`aria-labelledby`) and described by a hidden "Edit your
  note" hint (`aria-describedby`) instead of carrying an `aria-label`. Enter, Space, click and tap still open the editor.

## Impact
- `NoteBlock.php`, `i18n/en/ext.php`; `tests/Integration/NoteDisplayTest.php`, e2e `specs/note-editor.spec.ts`.
