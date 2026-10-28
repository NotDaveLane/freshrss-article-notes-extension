# FAN-60: Note action on every article load

The extension scripts load `async`, so they could run before FreshRSS's `main.js` set up `context`; the one initial scan then decorated nothing. Both scripts now wait until the extension config is available (DOMContentLoaded, `freshrss:globalContextLoaded`, `load`, or polling) before starting.
