/* ArticleNotes: highlights in FreshRSS articles (normal, reader and global views; works with dynamically loaded articles).
 * Selection toolbar, drawing and anchoring (through the shared anchoring.js), the edit popover and the Annotations panel. */
(function () {
	'use strict';

	const UI_SELECTOR = '[data-articlenotes-ui], .articlenotes-note, .articlenotes-editor';
	const MARK_SELECTOR = 'mark.articlenotes-hl';
	const EXCERPT_LENGTH = 140;
	const LABEL_EXCERPT_LENGTH = 60;
	const SCROLL_KEYS = ['PageUp', 'PageDown', 'Home', 'End', 'ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight', ' '];

	/** @type {WeakMap<Element, object>} per article element: its root, highlights and panel */
	const states = new WeakMap();
	let library = null;
	let toolbar = null;
	let popover = null;
	let selectionTimer = 0;
	let pointerDown = false;
	let lastSelection = '';

	function settings() {
		return (typeof context !== 'undefined' && context.extensions && context.extensions.ArticleNotes) || null;
	}

	function element(tag, className, attributes) {
		const el = document.createElement(tag);
		if (className) {
			el.className = className;
		}
		Object.keys(attributes || {}).forEach(function (name) {
			el.setAttribute(name, attributes[name]);
		});
		return el;
	}

	function button(label, className, attributes) {
		const el = element('button', className, Object.assign({ type: 'button' }, attributes || {}));
		el.textContent = label;
		return el;
	}

	function format(template, value) {
		return template.replace('%s', value);
	}

	function excerpt(text, length) {
		const chars = Array.from(text);
		return chars.length > length ? chars.slice(0, length).join('') + '…' : text;
	}

	function uuid() {
		const bytes = new Uint8Array(16);
		crypto.getRandomValues(bytes);
		bytes[6] = (bytes[6] & 0x0f) | 0x40;
		bytes[8] = (bytes[8] & 0x3f) | 0x80;
		const hex = Array.from(bytes, function (b) {
			return b.toString(16).padStart(2, '0');
		}).join('');
		return [hex.slice(0, 8), hex.slice(8, 12), hex.slice(12, 16), hex.slice(16, 20), hex.slice(20)].join('-');
	}

	function loadLibrary() {
		if (library === null) {
			library = import(settings().anchoringUrl);
		}
		return library;
	}

	function post(url, params) {
		const body = new URLSearchParams(params);
		body.set('_csrf', context.csrf);
		return fetch(url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
			body: body,
		}).then(function (response) {
			return response.json().catch(function () {
				return { ok: false, error: null };
			});
		}).catch(function () {
			return { ok: false, error: null };
		});
	}

	function fluxOf(node) {
		const el = node instanceof Element ? node : node && node.parentElement;
		return el ? el.closest('.flux[data-entry]') : null;
	}

	function rootOf(flux) {
		return flux.querySelector('.content .text');
	}

	function isVisible(el) {
		return el.getClientRects().length > 0;
	}

	// --- Articles ---------------------------------------------------------------------------------------------------

	function stateOf(flux) {
		return states.get(flux) || null;
	}

	function sortKey(item) {
		const position = item.data.source === 'freshrss' && item.data.selector.position;
		return position ? position.start : Number.MAX_SAFE_INTEGER;
	}

	function sortedItems(state) {
		return Array.from(state.items.values()).sort(function (a, b) {
			if (a.marks.length > 0 && b.marks.length > 0) {
				return a.marks[0].compareDocumentPosition(b.marks[0]) & Node.DOCUMENT_POSITION_FOLLOWING ? 1 : -1;
			}
			return sortKey(a) - sortKey(b) || a.data.created_at - b.data.created_at;
		});
	}

	function process(flux) {
		const config = settings();
		const root = rootOf(flux);
		if (!config || !config.enabled || !root || flux.dataset.articlenotesHl || !isVisible(root)) {
			return;
		}
		flux.dataset.articlenotesHl = 'loading';
		const url = new URL(config.highlightsUrl, window.location.href);
		url.searchParams.set('entry_id', flux.getAttribute('data-entry'));
		Promise.all([
			loadLibrary(),
			fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
				.then(function (response) {
					return response.json();
				}),
		]).then(function (results) {
			if (!results[1].ok) {
				throw new Error('highlights');
			}
			const state = { entryId: flux.getAttribute('data-entry'), flux: flux, root: root, items: new Map(), panel: null };
			states.set(flux, state);
			results[1].highlights.forEach(function (data) {
				const item = newItem(data);
				state.items.set(data.uuid, item);
				draw(state, item, results[0]);
			});
			flux.dataset.articlenotesHl = 'done';
			syncPanel(state);
			jumpToRequested(state);
		}).catch(function () {
			delete flux.dataset.articlenotesHl;	// tried again when the article is displayed again
		});
	}

	function newItem(data) {
		return { data: data, marks: [], drawn: null, orphan: false, removed: false, queue: Promise.resolve(true) };
	}

	// --- Drawing ----------------------------------------------------------------------------------------------------

	function draw(state, item, lib) {
		undraw(item);
		// Positions only mean something within the source that made them.
		const selector = item.data.source === 'freshrss' ? item.data.selector : { quote: item.data.selector.quote };
		let range;
		try {
			range = lib.anchor(selector, state.root);
		} catch {
			range = null;
		}
		item.orphan = range === null;
		if (range === null) {
			return;
		}
		item.drawn = lib.highlight(range, { uuid: item.data.uuid, color: item.data.color, hasNote: item.data.note ? 'true' : null });
		item.marks = item.drawn.marks;
		describeMarks(item);
	}

	function undraw(item) {
		if (item.drawn) {
			item.drawn.remove();
		}
		item.drawn = null;
		item.marks = [];
	}

	/** Colour, note indicator and accessible name of a drawn highlight; the first mark is the keyboard stop. */
	function describeMarks(item) {
		const t = settings().i18n;
		item.marks.forEach(function (mark, index) {
			mark.dataset.color = item.data.color;
			if (item.data.note) {
				mark.dataset.hasNote = 'true';
			} else {
				delete mark.dataset.hasNote;
			}
			if (index === 0) {
				const label = format(t.markLabel, excerpt(item.data.selector.quote.exact, LABEL_EXCERPT_LENGTH));
				mark.setAttribute('tabindex', '0');
				mark.setAttribute('role', 'button');
				mark.setAttribute('aria-label', item.data.note ? label + ' (' + t.noteIndicator + ')' : label);
			}
		});
	}

	function itemOfMark(mark) {
		const flux = fluxOf(mark);
		const state = flux && stateOf(flux);
		return state && state.items.get(mark.dataset.uuid) ? { state: state, item: state.items.get(mark.dataset.uuid) } : null;
	}

	// --- Requests ---------------------------------------------------------------------------------------------------

	function selectorParam(selector) {
		const quote = { exact: selector.quote.exact };
		if (selector.quote.prefix) {
			quote.prefix = selector.quote.prefix;
		}
		if (selector.quote.suffix) {
			quote.suffix = selector.quote.suffix;
		}
		const result = { quote: quote };
		if (selector.position) {
			result.position = selector.position;
		}
		return JSON.stringify(result);
	}

	function create(state, selector, color, withNote, rect) {
		const config = settings();
		const data = {
			uuid: uuid(), entry_id: state.entryId, source: 'freshrss', selector: selector, color: color, note: null,
			created_at: Math.floor(Date.now() / 1000),
		};
		const item = newItem(data);
		state.items.set(data.uuid, item);
		loadLibrary().then(function (lib) {
			draw(state, item, lib);
			if (item.orphan) {	// cannot happen for a selector that was just described
				state.items.delete(data.uuid);
				return;
			}
			syncPanel(state);
			if (withNote) {
				openPopover(state, item, item.marks[0], true);
			}
		});
		item.queue = post(config.highlightCreateUrl, {
			entry_id: state.entryId, source: 'freshrss', selector: selectorParam(selector), color: color, uuid: data.uuid,
		}).then(function (result) {
			if (result.ok) {
				item.data = Object.assign({}, result.highlight, { note: item.data.note, color: item.data.color });
				return true;
			}
			rollback(state, item, result.error, rect);
			return false;
		});
	}

	function rollback(state, item, message, rect) {
		item.removed = true;
		undraw(item);
		state.items.delete(item.data.uuid);
		if (popover && popover.item === item) {
			closePopover(false);
		}
		syncPanel(state);
		notice(message || settings().i18n.highlightFailed, rect);
	}

	/** Sends a change of a highlight once its creation has finished; resolves with the response (or a failure). */
	function send(item, params) {
		const request = item.queue.then(function (created) {
			if (!created || item.removed) {
				return { ok: false, error: null };
			}
			return post(settings().highlightUpdateUrl, Object.assign({ uuid: item.data.uuid }, params));
		});
		item.queue = request.then(function () {
			return true;
		});
		return request;
	}

	// --- Selection toolbar ------------------------------------------------------------------------------------------

	function hideToolbar() {
		if (toolbar) {
			toolbar.remove();
			toolbar = null;
		}
		lastSelection = '';
	}

	/** Gives a body-level element the text and background colours of the article it belongs to (FreshRSS's dark themes need this). */
	function themeFloating(el, reference) {
		const source = reference instanceof Element ? reference : document.querySelector('.flux.current .flux_content') || document.body;
		const text = getComputedStyle(source).color;
		let surface = 'rgb(255, 255, 255)';
		for (let node = source; node; node = node.parentElement) {
			const background = getComputedStyle(node).backgroundColor;
			if (background && background !== 'transparent' && !/^rgba\(.*,\s*0\)$/.test(background)) {
				surface = background;
				break;
			}
		}
		el.style.setProperty('--articlenotes-surface', surface);
		el.style.setProperty('--articlenotes-text', text);
	}

	function placeFloating(el, rect, below) {
		const margin = 8;
		const width = el.offsetWidth;
		const height = el.offsetHeight;
		const left = Math.max(margin, Math.min(rect.left + window.scrollX, window.scrollX + document.documentElement.clientWidth - width - margin));
		let top = below ? rect.bottom + window.scrollY + margin : rect.top + window.scrollY - height - margin;
		if (top < window.scrollY) {
			top = rect.bottom + window.scrollY + margin;
		}
		el.style.left = left + 'px';
		el.style.top = top + 'px';
	}

	function coarsePointer() {
		return window.matchMedia && window.matchMedia('(pointer: coarse)').matches;
	}

	function targetOf(range) {
		const flux = fluxOf(range.startContainer);
		if (!flux || fluxOf(range.endContainer) !== flux) {
			return null;
		}
		const root = rootOf(flux);
		const inside = function (node) {
			const el = node instanceof Element ? node : node.parentElement;
			return root !== null && root.contains(node) && el !== null && el.closest(UI_SELECTOR) === null;
		};
		return root !== null && inside(range.startContainer) && inside(range.endContainer) ? { flux: flux, root: root } : null;
	}

	function evaluateSelection() {
		const config = settings();
		const selection = window.getSelection();
		if (!config || !config.enabled || !library || !selection || selection.rangeCount === 0 || selection.isCollapsed) {
			hideToolbar();
			return;
		}
		const range = selection.getRangeAt(0);
		const target = targetOf(range);
		const state = target && stateOf(target.flux);
		if (!target || !state) {
			hideToolbar();
			return;
		}
		library.then(function (lib) {
			let selector;
			try {
				selector = lib.describe(range, state.root);
			} catch (error) {
				if (error && error.code === 'too-long') {
					showToolbar(null, range, state);
				} else {
					hideToolbar();
				}
				return;
			}
			showToolbar(selector, range, state);
		});
	}

	function showToolbar(selector, range, state) {
		const config = settings();
		const t = config.i18n;
		const key = selector ? selector.quote.exact + '@' + (selector.position ? selector.position.start : '') : 'too-long';
		const rect = range.getBoundingClientRect();
		if (toolbar && key === lastSelection) {
			placeFloating(toolbar, rect, coarsePointer());
			return;
		}
		hideToolbar();
		lastSelection = key;
		toolbar = element('div', 'articlenotes-toolbar', { 'data-articlenotes-ui': '', role: selector ? 'toolbar' : 'status' });
		if (selector) {
			toolbar.setAttribute('aria-label', t.toolbar);
			toolbar.appendChild(button(t.highlight, 'btn articlenotes-tb-highlight'));
			const colors = element('span', 'articlenotes-colors', { role: 'group', 'aria-label': t.color });
			config.colors.forEach(function (color) {
				colors.appendChild(swatch(color, t, false));
			});
			toolbar.appendChild(colors);
			toolbar.appendChild(button(t.addNote, 'btn articlenotes-tb-note'));
			const make = function (color, withNote) {
				const saved = { selector: selector, state: state, rect: rect };
				hideToolbar();
				window.getSelection().removeAllRanges();
				create(saved.state, saved.selector, color, withNote, saved.rect);
			};
			toolbar.addEventListener('click', function (event) {
				const target = event.target instanceof Element ? event.target.closest('button') : null;
				if (!target) {
					return;
				}
				if (target.classList.contains('articlenotes-tb-highlight')) {
					make('yellow', false);
				} else if (target.classList.contains('articlenotes-tb-note')) {
					make('yellow', true);
				} else if (target.dataset.color) {
					make(target.dataset.color, false);
				}
			});
		} else {
			toolbar.textContent = t.tooLong;
		}
		// Keep the selection when a button is pressed.
		toolbar.addEventListener('mousedown', function (event) {
			event.preventDefault();
		});
		toolbar.addEventListener('keydown', function (event) {
			event.stopPropagation();
		});
		document.body.appendChild(toolbar);
		themeFloating(toolbar, state && state.root);
		placeFloating(toolbar, rect, coarsePointer());
	}

	function swatch(color, t, pressed) {
		const label = t['color' + color.charAt(0).toUpperCase() + color.slice(1)] || color;
		const el = button('', 'articlenotes-swatch', { 'data-color': color, title: label, 'aria-label': label });
		if (pressed !== null) {
			el.setAttribute('aria-pressed', pressed ? 'true' : 'false');
		}
		return el;
	}

	/** An inline, dismissable error (or message) near `rect`. */
	function notice(message, rect) {
		const el = element('div', 'articlenotes-toolbar articlenotes-notice', { 'data-articlenotes-ui': '', role: 'alert' });
		el.textContent = message;
		document.body.appendChild(el);
		themeFloating(el, null);
		if (rect) {
			placeFloating(el, rect, true);
		} else {
			el.style.left = '8px';
			el.style.top = window.scrollY + 8 + 'px';
		}
		const remove = function () {
			el.remove();
		};
		el.addEventListener('click', remove);
		window.setTimeout(remove, 8000);
	}

	// --- Popover ----------------------------------------------------------------------------------------------------

	/**
	 * Closes the popover. Unless another popover replaces it, the page goes back to where it was before the popover scrolled
	 * it above the bottom sheet, if the reader has not scrolled since.
	 */
	function closePopover(restoreFocus, replaced) {
		if (!popover) {
			return;
		}
		let opener = popover.opener;
		if (opener && !opener.isConnected && popover.state.panel) {	// the panel list was rebuilt
			opener = popover.state.panel.querySelector('li[data-uuid="' + popover.item.data.uuid + '"] .articlenotes-edit');
		}
		const restoreScrollY = replaced ? null : popover.restoreScrollY;
		popover.unwatch();
		popover.el.remove();
		popover = null;
		if (restoreFocus && opener && opener.isConnected) {
			opener.focus({ preventScroll: true });
		}
		if (restoreScrollY !== null) {
			// After the caller's panel updates and any layout shift from the virtual keyboard closing.
			window.requestAnimationFrame(function () {
				if (!popover) {
					window.scrollTo({ top: restoreScrollY, behavior: 'instant' });
				}
			});
		}
	}

	/** Forgets the reading position to go back to as soon as the reader scrolls the page while the popover is open. */
	function watchManualScroll(el) {
		const scrolled = function (event) {
			if (popover && popover.el === el && !(event.type === 'touchmove' && event.target instanceof Node && el.contains(event.target))) {
				popover.restoreScrollY = null;
			}
		};
		const key = function (event) {
			if (SCROLL_KEYS.includes(event.key) && !(event.target instanceof Node && el.contains(event.target))) {
				scrolled(event);
			}
		};
		document.addEventListener('touchmove', scrolled, { passive: true });
		document.addEventListener('wheel', scrolled, { passive: true });
		document.addEventListener('keydown', key, true);
		return function () {
			document.removeEventListener('touchmove', scrolled, { passive: true });
			document.removeEventListener('wheel', scrolled, { passive: true });
			document.removeEventListener('keydown', key, true);
		};
	}

	function openPopover(state, item, opener, focusNote) {
		const config = settings();
		const t = config.i18n;
		closePopover(false, true);
		const id = 'articlenotes-pop-' + item.data.uuid;
		const el = element('div', 'articlenotes-popover', {
			'data-articlenotes-ui': '', role: 'dialog', 'aria-label': t.popoverTitle, tabindex: '-1',
		});
		el.appendChild(element('p', 'articlenotes-pop-quote')).textContent = excerpt(item.data.selector.quote.exact, EXCERPT_LENGTH);

		const colors = element('div', 'articlenotes-colors', { role: 'group', 'aria-label': t.color });
		config.colors.forEach(function (color) {
			colors.appendChild(swatch(color, t, color === item.data.color));
		});
		el.appendChild(colors);

		el.appendChild(element('label', 'articlenotes-label', { 'for': id })).textContent = t.noteLabel;
		const textarea = element('textarea', 'articlenotes-textarea', { id: id, name: 'note', rows: '4' });
		textarea.value = item.data.note || '';
		el.appendChild(textarea);
		const error = element('p', 'articlenotes-error', { role: 'alert' });
		error.hidden = true;
		el.appendChild(error);
		const actions = element('div', 'articlenotes-actions');
		const save = button(t.highlightSave, 'btn btn-important articlenotes-pop-save');
		const remove = button(t.highlightDelete, 'btn btn-attention articlenotes-pop-delete');
		const close = button(t.close, 'btn articlenotes-pop-close');
		actions.appendChild(save);
		actions.appendChild(remove);
		actions.appendChild(close);
		el.appendChild(actions);

		const showError = function (message) {
			error.textContent = message || t.highlightFailed;
			error.hidden = false;
		};
		const busy = function (isBusy) {
			el.querySelectorAll('button, textarea').forEach(function (control) {
				control.disabled = isBusy;
			});
		};
		colors.addEventListener('click', function (event) {
			const target = event.target instanceof Element ? event.target.closest('button') : null;
			if (!target || !target.dataset.color || target.dataset.color === item.data.color) {
				return;
			}
			const previous = item.data.color;
			error.hidden = true;
			item.data.color = target.dataset.color;	// optimistic
			describeMarks(item);
			colors.querySelectorAll('button').forEach(function (b) {
				b.setAttribute('aria-pressed', b === target ? 'true' : 'false');
			});
			renderPanel(state);
			send(item, { color: target.dataset.color }).then(function (result) {
				if (result.ok) {
					item.data = Object.assign({}, result.highlight, { note: item.data.note });
					return;
				}
				item.data.color = previous;
				describeMarks(item);
				renderPanel(state);
				colors.querySelectorAll('button').forEach(function (b) {
					b.setAttribute('aria-pressed', b.dataset.color === previous ? 'true' : 'false');
				});
				showError(result.error);
			});
		});
		save.addEventListener('click', function () {
			error.hidden = true;
			busy(true);
			send(item, { note: textarea.value }).then(function (result) {
				busy(false);
				if (!result.ok) {
					showError(result.error);
					return;
				}
				item.data = result.highlight;
				describeMarks(item);
				renderPanel(state);
				closePopover(true);
			});
		});
		remove.addEventListener('click', function () {
			if (!window.confirm(t.confirmHighlightDelete)) {
				return;
			}
			error.hidden = true;
			busy(true);
			item.queue = item.queue.then(function (created) {
				return created && !item.removed ? post(config.highlightDeleteUrl, { uuid: item.data.uuid }) : { ok: false, error: null };
			}).then(function (result) {
				busy(false);
				if (!result.ok) {
					showError(result.error);
					return true;
				}
				item.removed = true;
				undraw(item);
				state.items.delete(item.data.uuid);
				closePopover(false);
				syncPanel(state);
				return true;
			});
		});
		close.addEventListener('click', function () {
			closePopover(true);
		});
		// Keep typing from reaching FreshRSS's keyboard shortcuts.
		el.addEventListener('keydown', function (event) {
			event.stopPropagation();
			if (event.key === 'Escape') {
				event.preventDefault();
				closePopover(true);
			}
		});

		document.body.appendChild(el);
		themeFloating(el, state.root);
		popover = { el: el, item: item, state: state, opener: opener, restoreScrollY: null, unwatch: watchManualScroll(el) };
		const rect = (opener && opener.getBoundingClientRect()) || state.root.getBoundingClientRect();
		placeFloating(el, rect, true);
		(focusNote ? textarea : el).focus({ preventScroll: true });
		const scrollY = window.scrollY;
		if (revealAboveSheet(el, rect)) {
			popover.restoreScrollY = scrollY;
		}
	}

	/**
	 * On small screens the popover is a bottom sheet (see article-notes.css): scrolls just enough to show the highlight above
	 * it, and not at all when it already is, so tapping the same highlight again leaves the page where it is. Returns
	 * whether it scrolled.
	 */
	function revealAboveSheet(el, rect) {
		if (window.getComputedStyle(el).position !== 'fixed') {
			return false;
		}
		const margin = 8;
		const hidden = rect.bottom + margin - el.getBoundingClientRect().top;
		const before = window.scrollY;
		if (hidden > 0) {
			window.scrollBy(0, Math.min(hidden, Math.max(0, rect.top - margin)));
		}
		return window.scrollY !== before;
	}

	// --- Annotations panel ------------------------------------------------------------------------------------------

	/** Creates, refreshes or removes the panel: it exists while the article has highlights, and holds the article note. */
	function syncPanel(state) {
		const t = settings().i18n;
		const block = state.flux.querySelector('.articlenotes-note');
		if (state.items.size === 0) {
			if (state.panel) {
				if (block) {
					state.root.appendChild(block);	// back where the server put it
				}
				state.panel.remove();
				state.panel = null;
			}
			return;
		}
		if (!state.panel) {
			const panel = element('section', 'articlenotes-panel', { 'data-articlenotes-ui': '' });
			panel.appendChild(element('h4', 'articlenotes-panel-title')).textContent = t.panelTitle;
			if (settings().notesUrl) {
				const link = element('a', 'articlenotes-notes-link', { 'href': settings().notesUrl });
				link.textContent = t.allMyNotes;
				panel.appendChild(element('p', 'articlenotes-notes-link-line')).appendChild(link);
			}
			panel.appendChild(element('div', 'articlenotes-panel-list'));
			state.root.appendChild(panel);
			state.panel = panel;
			panel.addEventListener('keydown', function (event) {
				event.stopPropagation();
			});
			panel.addEventListener('click', function (event) {
				const notes = event.target instanceof Element ? event.target.closest('a.articlenotes-notes-link') : null;
				if (notes) {	// FreshRSS cancels clicks on links inside article content: navigate ourselves
					event.preventDefault();
					window.location.assign(notes.href);
					return;
				}
				const target = event.target instanceof Element ? event.target.closest('button[data-action]') : null;
				const li = target && target.closest('li[data-uuid]');
				const item = li && state.items.get(li.dataset.uuid);
				if (!item) {
					return;
				}
				if (target.dataset.action === 'jump' && item.marks.length > 0) {
					item.marks[0].scrollIntoView({ block: 'center' });
					item.marks[0].focus();
				} else if (target.dataset.action === 'edit') {
					openPopover(state, item, target, false);
				}
			});
		}
		if (block && block.parentElement !== state.panel) {
			state.panel.insertBefore(block, state.panel.querySelector('.articlenotes-panel-list'));
		}
		renderPanel(state);
	}

	function renderPanel(state) {
		if (!state.panel) {
			return;
		}
		const t = settings().i18n;
		const list = state.panel.querySelector('.articlenotes-panel-list');
		list.textContent = '';
		list.appendChild(element('h5', 'articlenotes-list-title')).textContent = t.listTitle;
		const ol = element('ol', 'articlenotes-hl-list');
		sortedItems(state).forEach(function (item) {
			const li = element('li', 'articlenotes-hl-item', { 'data-uuid': item.data.uuid, 'data-color': item.data.color });
			const head = element('div', 'articlenotes-hl-head');
			head.appendChild(element('span', 'articlenotes-swatch articlenotes-swatch-static', { 'data-color': item.data.color, 'aria-hidden': 'true' }));
			head.appendChild(element('span', 'articlenotes-hl-color')).textContent = t['color' + item.data.color.charAt(0).toUpperCase() + item.data.color.slice(1)] || item.data.color;
			if (item.orphan) {
				head.appendChild(element('span', 'articlenotes-orphan')).textContent = t.orphan;
			}
			li.appendChild(head);
			li.appendChild(element('blockquote', 'articlenotes-hl-quote')).textContent = excerpt(item.data.selector.quote.exact, EXCERPT_LENGTH);
			if (item.data.note) {
				li.appendChild(element('p', 'articlenotes-hl-note')).textContent = item.data.note;
			}
			const actions = element('div', 'articlenotes-actions');
			if (!item.orphan) {
				actions.appendChild(button(t.jumpTo, 'btn articlenotes-jump', {
					'data-action': 'jump', 'aria-label': format(t.jumpToLabel, excerpt(item.data.selector.quote.exact, LABEL_EXCERPT_LENGTH)),
				}));
			}
			actions.appendChild(button(t.edit, 'btn articlenotes-edit', { 'data-action': 'edit' }));
			li.appendChild(actions);
			ol.appendChild(li);
		});
		list.appendChild(ol);
	}

	// --- Opening an article at a highlight (links of the Notes page: ?articlenotes_hl=<uuid>) --------------------------

	let requestedHighlight = new URLSearchParams(window.location.search).get('articlenotes_hl');

	/** Scrolls to the requested highlight once its article is drawn; done once. */
	function jumpToRequested(state) {
		const item = requestedHighlight && state.items.get(requestedHighlight);
		if (!item) {
			return;
		}
		requestedHighlight = null;
		if (item.marks.length > 0) {
			item.marks[0].scrollIntoView({ block: 'center' });
			item.marks[0].focus({ preventScroll: true });
		} else if (state.panel) {
			state.panel.scrollIntoView({ block: 'center' });
		}
	}

	/**
	 * The Notes page links to a list showing just that article: open it (an article's content is only drawn when open).
	 * Retried a few times, as FreshRSS binds its click handlers after the page has loaded.
	 */
	function openRequestedArticle(attempt) {
		if (!requestedHighlight || attempt > 20) {
			return;
		}
		const flux = document.querySelector('.flux[data-entry]');
		const title = flux && flux.querySelector('.flux_header .item a.title');	// FreshRSS toggles on a click on the link's parent
		if (document.readyState === 'complete' && title && title.parentElement && !flux.classList.contains('current')) {
			title.parentElement.click();
		}
		if (!flux || !flux.classList.contains('current')) {
			window.setTimeout(openRequestedArticle, 500, attempt + 1);
		}
	}

	// --- Wiring -----------------------------------------------------------------------------------------------------

	function candidates(node, found) {
		if (!(node instanceof Element)) {
			return;
		}
		if (node.matches('.flux[data-entry]')) {
			found.add(node);
		}
		node.querySelectorAll('.flux[data-entry]').forEach(function (flux) {
			found.add(flux);
		});
		const parent = fluxOf(node);
		if (parent) {
			found.add(parent);
		}
	}

	let sweepScheduled = false;
	const pending = new Set();

	function scheduleSweep(found) {
		found.forEach(function (flux) {
			pending.add(flux);
		});
		if (sweepScheduled || pending.size === 0) {
			return;
		}
		sweepScheduled = true;
		window.requestAnimationFrame(function () {
			sweepScheduled = false;
			const batch = Array.from(pending);
			pending.clear();
			batch.forEach(process);
		});
	}

	function start() {
		const config = settings();
		if (!config || !config.enabled || !config.anchoringUrl) {
			return;
		}
		loadLibrary();
		openRequestedArticle(0);
		const initial = new Set();
		candidates(document.body, initial);
		scheduleSweep(initial);

		// Articles are shown when opened (class changes) and arrive after page load (infinite scroll, global view panels).
		new MutationObserver(function (mutations) {
			const found = new Set();
			mutations.forEach(function (mutation) {
				if (mutation.type === 'attributes') {
					candidates(mutation.target, found);
				} else {
					mutation.addedNodes.forEach(function (node) {
						candidates(node, found);
					});
				}
			});
			scheduleSweep(found);
		}).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['class', 'style', 'hidden'] });

		document.addEventListener('click', function (event) {
			const mark = event.target instanceof Element ? event.target.closest(MARK_SELECTOR) : null;
			if (!mark) {
				if (popover && event.target instanceof Element && event.target.isConnected
					&& !event.target.closest('.articlenotes-popover, .articlenotes-edit, .articlenotes-toolbar')) {
					closePopover(false);
				}
				return;
			}
			const selection = window.getSelection();
			const found = itemOfMark(mark);
			if (!found || (selection && !selection.isCollapsed)) {
				return;
			}
			event.preventDefault();
			openPopover(found.state, found.item, found.item.marks[0] || mark, false);
		});
		document.addEventListener('keydown', function (event) {
			const mark = event.target instanceof Element ? event.target.closest(MARK_SELECTOR) : null;
			if (mark && (event.key === 'Enter' || event.key === ' ')) {
				const found = itemOfMark(mark);
				if (found) {
					event.preventDefault();
					event.stopPropagation();
					openPopover(found.state, found.item, found.item.marks[0] || mark, false);
				}
			} else if (event.key === 'Escape' && toolbar) {
				hideToolbar();
			}
		}, true);

		document.addEventListener('pointerdown', function () {
			pointerDown = true;
		}, true);
		document.addEventListener('pointerup', function () {
			pointerDown = false;
			window.clearTimeout(selectionTimer);
			selectionTimer = window.setTimeout(evaluateSelection, 50);
		}, true);
		document.addEventListener('selectionchange', function () {
			window.clearTimeout(selectionTimer);
			if (!pointerDown) {
				selectionTimer = window.setTimeout(evaluateSelection, 250);
			}
		});
		// The note editor changed the article note: move it into (or out of) the panel.
		document.addEventListener('articlenotes:note', function (event) {
			const flux = fluxOf(event.target);
			const state = flux && stateOf(flux);
			if (state) {
				syncPanel(state);
			}
		});
	}

	if (document.body) {
		start();
	} else {
		document.addEventListener('DOMContentLoaded', start);
	}
}());
