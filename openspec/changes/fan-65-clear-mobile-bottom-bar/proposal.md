# FAN-65: Note editor and highlight toolbar clear of FreshRSS's mobile bottom bar

On phones FreshRSS's normal view has a fixed previous / up / next bar (`#nav_entries`) over the bottom of the screen. The
note editor was scrolled so its Save and Cancel buttons ended up under that bar, and the selection toolbar was always
placed below the selection on touch screens, off-screen or under the bar near the bottom. At phone width the toolbar also
left Add note alone on a second row.

A shared helper (`static/viewport.js`) measures where the uncovered part of the viewport ends (the top of a fixed bottom
bar over the given columns); the editor scrolls just enough to clear it and the toolbar picks the side that fits and is
clamped inside it. On touch screens and narrow viewports the toolbar puts Highlight and Add note side by side, the
swatches following as one group. Desktop placement is unchanged: there the bar sits under the sidebar.
