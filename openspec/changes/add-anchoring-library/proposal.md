# Add the shared text-anchoring library

## Why

ArticleNotes 1.1 highlights passages of articles, in FreshRSS and on the original web page (third-party clients such as a
browser extension). Both need the same way to describe a selection and to find it again after the content changed, so
that a highlight made on one side can be shown on the other and is never silently dropped.

## What changes

- `xExtension-ArticleNotes/static/anchoring.js`: a dependency-free ES module exporting `describe()`, `anchor()`,
  `highlight()`, `textOf()` and `AnchoringError`, served from the extension's static URL and reusable unmodified.
- Browser unit tests (`tests/e2e/specs/anchoring.spec.ts`, Chromium via Playwright) with V8 coverage of the module in
  `coverage/js/index.html`; ESLint for the extension's JavaScript in `bin/lint` and `bin/ci`.
- README "Anchoring library" section. New `anchoring` capability; `toolchain` gains the ESLint step and the JavaScript
  coverage report. No server changes.
