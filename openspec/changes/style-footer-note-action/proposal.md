# Give the footer Note action spacing, an icon, a tap target and a note indicator

## Why

In the article footer (`ul.bottom`) the **Note** action was a bare text link run together with the date
(`Note9 September 2025 at 09:00`), had no icon unlike FreshRSS's other footer items, measured 30x34 px on mobile (under
the 44x44 tap size) and did not show whether the article already had a note (FAN-23, from UX review FAN-21).

## What changes

- The Note item keeps at least 8px between itself and the date at desktop and mobile widths.
- The action shows a note icon (inline SVG in the text colour, so it follows light and dark themes) before its label;
  the accessible name stays "Note".
- On touch screens and narrow viewports the action is at least 44x44 px.
- When the article has a note the action is bold with a filled icon and its title reads "Edit your note on this
  article" (otherwise "Add a note to this article"); the state follows saves and deletes without reloading.
