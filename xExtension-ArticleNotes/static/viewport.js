/* ArticleNotes: the part of the viewport FreshRSS leaves uncovered; shared by article-notes.js and highlights.js. */
(function () {
	'use strict';

	// Fixed bars FreshRSS draws over the bottom of the page: the previous / up / next navigation of the normal view.
	const BOTTOM_BARS = '#nav_entries';

	/**
	 * Where the visible, uncovered part of the viewport ends between `left` and `right` (viewport coordinates, like
	 * getBoundingClientRect()): the top of any fixed bar over the bottom of the screen, else the bottom of the screen. On
	 * wide screens FreshRSS's navigation sits under the sidebar, beside the articles: it does not count there.
	 */
	function visibleBottom(left, right) {
		const screen = window.visualViewport;
		// The layout viewport, where fixed elements sit, is taller than the screen when the page is wider than it.
		let bottom = screen ? Math.min(window.innerHeight, screen.offsetTop + screen.height) : window.innerHeight;
		document.querySelectorAll(BOTTOM_BARS).forEach(function (bar) {
			const style = window.getComputedStyle(bar);
			if (style.position !== 'fixed' || style.visibility === 'hidden') {
				return;
			}
			const rect = bar.getBoundingClientRect();
			if (rect.height > 0 && rect.bottom >= window.innerHeight - 1 && rect.left < right && rect.right > left) {
				bottom = Math.min(bottom, rect.top);
			}
		});
		return bottom;
	}

	window.ArticleNotesViewport = { visibleBottom: visibleBottom };
}());
