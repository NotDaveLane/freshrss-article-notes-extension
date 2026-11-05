## Why
A failed save's error stayed on screen while the user fixed the text, and over-limit notes were only rejected by the server.

## What Changes
The editor and highlight popover hide their error on edit; the editor flags over-limit text (aria-invalid, Save disabled) and links a shown error via aria-describedby; counter numbers are locale-formatted.
