# FAN-39: Show note and Notes page dates in FreshRSS's date format

## Why
The note block and the Notes page printed ISO dates next to FreshRSS's written-out dates and ignored the user's language.

## What changes
Visible dates use FreshRSS's `timestamptodate()`; `datetime` attributes are unchanged.
