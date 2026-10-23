# Make the saved note block readable in the Alternative Dark theme (FAN-38)

## Why
The "Your note" block used `--frss-background-color-transparent`, which FreshRSS's base theme sets to a half-transparent
white overlay. Dark themes that do not override it (Alternative Dark) drew a light grey box under their grey text, a
contrast of about 1.15:1. In Dark, the "Last updated" line's 75 % opacity brought it down to 3.5:1.

## What Changes
- The block is transparent, so it shows the article's own background with the theme's text colour; the border and the
  4px left border still set it apart. Hover highlights the border instead of tinting the background.
- The "Last updated" line keeps full opacity (still smaller).

## Impact
- `static/article-notes.css`; e2e `specs/note-themes.spec.ts`. No PHP or i18n change.
