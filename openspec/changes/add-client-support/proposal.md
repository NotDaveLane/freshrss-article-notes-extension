# Add client support and release 1.1.0

## Why

Third-party clients, such as a Firefox or Chromium extension that shows and edits notes and highlights for the page being
viewed, needed more than endpoints: a way to call the API from a web page, one call to discover everything annotated, an
interoperable export, a machine-readable API description, and a documented, tested example.

## What changes

- CORS: an administrator setting "Allowed client origins" (the extension's configure page), preflight answers and response
  headers for listed origins only (`Cors`).
- `GET v1/annotated` (`AnnotatedTargets`): one entry per annotated article or page, with counts, colors, timestamps, paging and
  a `removed` list for sync.
- `GET v1/annotations?format=w3c`: notes and highlights as W3C Web Annotation JSON-LD (`WebAnnotation`); the context URL now
  is the real `http://www.w3.org/ns/anno.jsonld`.
- OpenAPI 3.1 description `docs/openapi.yaml`, served as JSON at `v1/openapi.json` without authentication; the router's
  route table (`Api::ROUTES`) is checked against it in CI.
- `examples/notes-client.mjs` and `examples/highlight-page.html`, tested against the dev instance.
- README "Building a client", API sections and upgrade notes; release 1.1.0, `bin/verify-upgrade`.
