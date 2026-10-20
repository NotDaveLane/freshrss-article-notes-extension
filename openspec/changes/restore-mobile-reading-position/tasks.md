## 1. Implementation

- [x] 1.1 Playwright specs at 390x844 (touch): save after Add note, Close, Escape and a tap outside restore the position;
  a manual scroll, or an open that did not scroll, leaves the page where it is; desktop never scrolls
- [x] 1.2 `openPopover()` records the position when `revealAboveSheet()` scrolls; manual scroll detection while open;
  `closePopover()` restores it unless the popover is replaced
- [x] 1.3 CHANGELOG `### Changed`, OpenSpec
