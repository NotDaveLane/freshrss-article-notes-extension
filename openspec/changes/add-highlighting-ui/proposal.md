# Add highlighting in the FreshRSS article view

## Why

Highlights can be stored and fetched through the API; users need to create, see and edit them where they read: the
FreshRSS article view, on phone and desktop.

## What changes

- `static/highlights.js` (classic script, loads the shared `anchoring.js` module): selection toolbar, optimistic create with
  rollback, drawing with `describe()` / `anchor()` / `highlight()`, edit popover, Annotations panel; works for articles shown
  later (infinite scroll, global view) and in the reader view.
- The article-notes script tells the panel when the article note changes; the note block moves into the panel.
- `jsVars` exposes the highlight URLs, colors and translated strings; new `highlight` strings in `i18n/en`.
- Styles in `article-notes.css` for the five colors, note indicator, toolbar, popover (bottom sheet on small screens) and panel.
- README "Using highlights", CHANGELOG under `[Unreleased]`, Playwright spec `highlights.spec.ts`.
