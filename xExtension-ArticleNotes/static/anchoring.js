/*
 * ArticleNotes anchoring library: turns a text selection into a highlight selector, finds a stored selector again in a
 * document, and draws highlights. Dependency-free ES module, shared by the FreshRSS UI and third-party clients.
 *
 * Selector (modeled on the W3C Web Annotation TextQuoteSelector and TextPositionSelector):
 *   {quote: {exact, prefix, suffix}, position: {start, end}}
 * Positions are offsets, in UTF-16 code units, into textOf(root).
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** Maximum length of `quote.exact`, in characters (Unicode code points). */
export const MAX_EXACT_LENGTH = 2000;
/** Maximum length of `quote.prefix` and `quote.suffix`, in characters (Unicode code points). */
export const MAX_CONTEXT_LENGTH = 64;
/** The class of the `<mark>` elements drawn by highlight(). */
export const HIGHLIGHT_CLASS = 'articlenotes-hl';
/** Elements of the extension's own UI, left out of the text model (add `data-articlenotes-ui` to yours). */
export const UI_SELECTOR = '[data-articlenotes-ui], .articlenotes-note, .articlenotes-editor';

/** Thrown by describe() and anchor() for unusable selections or selectors; `code` tells why. */
export class AnchoringError extends Error {
	/**
	 * @param {'empty'|'too-long'|'outside-root'|'invalid-selector'} code
	 * @param {string} message
	 */
	constructor(code, message) {
		super(message);
		this.name = 'AnchoringError';
		this.code = code;
	}
}

const IGNORED_TAGS = new Set(['script', 'style', 'noscript', 'template', 'textarea', 'select', 'iframe', 'object']);
const BLOCK_TAGS = new Set([
	'address', 'article', 'aside', 'blockquote', 'body', 'caption', 'dd', 'details', 'dialog', 'div', 'dl', 'dt',
	'fieldset', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hgroup', 'hr',
	'li', 'main', 'menu', 'nav', 'ol', 'p', 'pre', 'section', 'summary', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead',
	'tr', 'ul',
]);
const WHITESPACE = /\s/;
const WHITESPACE_ONLY = /^\s*$/;

/** Collapses whitespace runs to one space and trims. */
function collapse(text) {
	return text.replace(/\s+/g, ' ').trim();
}

function codePointLength(text) {
	return Array.from(text).length;
}

function isBlock(node) {
	return node !== null && node.nodeType === Node.ELEMENT_NODE && BLOCK_TAGS.has(node.localName);
}

function isIgnored(element) {
	if (IGNORED_TAGS.has(element.localName) || element.hidden || element.matches(UI_SELECTOR)) {
		return true;
	}
	const view = element.ownerDocument.defaultView;
	return element.isConnected && view !== null && view.getComputedStyle(element).display === 'none';
}

// --- Text model ---------------------------------------------------------------------------------------------------

/** Per root: the cached text model, invalidated by any DOM mutation highlight() did not account for. */
const models = new WeakMap();
/** Per text node: the models listing it, so that highlight() can keep them up to date when splitting it. */
const nodeModels = new WeakMap();

/**
 * The text model of root: the text of its text nodes in document order (ignored subtrees left out), with a line break
 * added at block boundaries and `<br>` where the text has no whitespace there, and the position of each text node.
 */
function buildModel(root) {
	const parts = [];
	const segments = [];
	let length = 0;
	let lastChar = '';
	let pendingBreak = false;
	const append = (text) => {
		parts.push(text);
		length += text.length;
		lastChar = text[text.length - 1];
	};
	const visit = (node) => {
		if (node.nodeType === Node.TEXT_NODE || node.nodeType === Node.CDATA_SECTION_NODE) {
			const data = node.data;
			if (data === '') {
				return;
			}
			if (pendingBreak && length > 0 && !WHITESPACE.test(lastChar) && !WHITESPACE.test(data[0])) {
				append('\n');
			}
			pendingBreak = false;
			segments.push({ node, start: length, end: length + data.length });
			append(data);
			return;
		}
		if (node.nodeType !== Node.ELEMENT_NODE && node.nodeType !== Node.DOCUMENT_FRAGMENT_NODE) {
			return;
		}
		if (node.nodeType === Node.ELEMENT_NODE) {
			if (node !== root && isIgnored(node)) {
				return;
			}
			if (node.localName === 'br') {
				append('\n');
				pendingBreak = false;
				return;
			}
		}
		const block = isBlock(node);
		pendingBreak = pendingBreak || block;
		for (let child = node.firstChild; child !== null; child = child.nextSibling) {
			visit(child);
		}
		pendingBreak = pendingBreak || block;
	};
	visit(root);
	// Changes are seen synchronously through takeRecords() (modelOf(), highlight()) and, once the records have been
	// delivered after an asynchronous gap, by this callback.
	const observer = new MutationObserver(() => invalidate(model));
	const model = { root, text: parts.join(''), segments, byNode: new Map(), observer, views: {}, stale: false };
	observer.observe(root, { subtree: true, childList: true, characterData: true, attributes: true });
	for (const segment of segments) {
		model.byNode.set(segment.node, segment);
		addNodeModel(segment.node, model);
	}
	return model;
}

function addNodeModel(node, model) {
	let set = nodeModels.get(node);
	if (set === undefined) {
		set = new Set();
		nodeModels.set(node, set);
	}
	set.add(model);
}

function invalidate(model) {
	model.stale = true;
	model.observer.disconnect();
	if (models.get(model.root) === model) {
		models.delete(model.root);
	}
}

function modelOf(root) {
	if (!root || (root.nodeType !== Node.ELEMENT_NODE && root.nodeType !== Node.DOCUMENT_FRAGMENT_NODE)) {
		throw new TypeError('root must be an element');
	}
	const cached = models.get(root);
	if (cached !== undefined) {
		if (cached.observer.takeRecords().length === 0) {
			return cached;
		}
		invalidate(cached);
	}
	const model = buildModel(root);
	models.set(root, model);
	return model;
}

/**
 * A view of the model text with whitespace collapsed (`collapsed`: runs become one space) or removed (`stripped`),
 * with `map[i]` = the offset in the model text of the view's character i.
 */
function viewOf(model, kind) {
	if (model.views[kind] === undefined) {
		const raw = model.text;
		const chars = [];
		const map = new Int32Array(raw.length + 1);
		let size = 0;
		let previousSpace = false;
		for (let i = 0; i < raw.length; i++) {
			const char = raw[i];
			if (WHITESPACE.test(char)) {
				if (kind === 'stripped' || previousSpace) {
					continue;
				}
				chars.push(' ');
				previousSpace = true;
			} else {
				chars.push(char);
				previousSpace = false;
			}
			map[size++] = i;
		}
		map[size] = raw.length;
		model.views[kind] = { text: chars.join(''), map: map.subarray(0, size + 1) };
	}
	return model.views[kind];
}

/** The index, in a view, of the first view character at or after offset `rawOffset` of the model text. */
function viewIndex(view, rawOffset) {
	let low = 0;
	let high = view.text.length;
	while (low < high) {
		const middle = (low + high) >> 1;
		if (view.map[middle] < rawOffset) {
			low = middle + 1;
		} else {
			high = middle;
		}
	}
	return low;
}

/** The model offset of a DOM boundary point: of the first text node at or after it when it is not in a text node. */
function offsetOf(model, container, offset) {
	const segment = model.byNode.get(container);
	if (segment !== undefined) {
		return segment.start + Math.min(offset, segment.end - segment.start);
	}
	const point = container.ownerDocument.createRange();
	point.setStart(container, offset);
	const segments = model.segments;
	let low = 0;
	let high = segments.length;
	while (low < high) {
		const middle = (low + high) >> 1;
		if (point.comparePoint(segments[middle].node, 0) < 0) {
			low = middle + 1;
		} else {
			high = middle;
		}
	}
	return low < segments.length ? segments[low].start : model.text.length;
}

/** A DOM Range for model offsets [start, end), or null when there is no text in it. */
function rangeOf(model, start, end) {
	const segments = model.segments;
	let low = 0;
	let high = segments.length;
	while (low < high) {	// first segment ending after start
		const middle = (low + high) >> 1;
		if (segments[middle].end <= start) {
			low = middle + 1;
		} else {
			high = middle;
		}
	}
	const first = low;
	high = segments.length;
	while (low < high) {	// first segment starting at or after end
		const middle = (low + high) >> 1;
		if (segments[middle].start < end) {
			low = middle + 1;
		} else {
			high = middle;
		}
	}
	const last = low - 1;
	if (first >= segments.length || last < first) {
		return null;
	}
	const range = model.root.ownerDocument.createRange();
	const from = segments[first];
	range.setStart(from.node, Math.max(0, start - from.start));
	const to = segments[last];
	range.setEnd(to.node, Math.min(end, to.end) - to.start);
	return range;
}

/** The text-content model used for positions: root's text, without scripts, styles, hidden elements and our UI. */
export function textOf(root) {
	return modelOf(root).text;
}

// --- describe() ---------------------------------------------------------------------------------------------------

function lastCodePoints(text, count) {
	const chars = Array.from(text);
	return chars.slice(Math.max(0, chars.length - count)).join('');
}

function firstCodePoints(text, count) {
	return Array.from(text).slice(0, count).join('');
}

/**
 * The selector of `range` (a DOM Range) inside `root`: the selected text with whitespace collapsed, up to 64 characters
 * of context on each side, and its position in textOf(root). Throws an AnchoringError when the range is outside root
 * or its text is empty or longer than 2,000 characters.
 */
export function describe(range, root) {
	const model = modelOf(root);
	if (!range.intersectsNode(root)) {
		throw new AnchoringError('outside-root', 'The selection is outside the article.');
	}
	const text = model.text;
	let start = root.contains(range.startContainer) ? offsetOf(model, range.startContainer, range.startOffset) : 0;
	let end = root.contains(range.endContainer) ? offsetOf(model, range.endContainer, range.endOffset) : text.length;
	while (start < end && WHITESPACE.test(text[start])) {
		start++;
	}
	while (end > start && WHITESPACE.test(text[end - 1])) {
		end--;
	}
	const exact = collapse(text.slice(start, end));
	if (exact === '') {
		throw new AnchoringError('empty', 'The selection contains no text.');
	}
	if (codePointLength(exact) > MAX_EXACT_LENGTH) {
		throw new AnchoringError('too-long', 'The selection is longer than ' + MAX_EXACT_LENGTH + ' characters.');
	}
	const view = viewOf(model, 'collapsed');
	const from = viewIndex(view, start);
	const to = viewIndex(view, end);
	const before = view.text.slice(Math.max(0, from - 4 * MAX_CONTEXT_LENGTH), from);
	const after = view.text.slice(to, to + 4 * MAX_CONTEXT_LENGTH);
	return {
		quote: {
			exact,
			prefix: lastCodePoints(before, MAX_CONTEXT_LENGTH).replace(/^[\udc00-\udfff]/, '').trimStart(),
			suffix: firstCodePoints(after, MAX_CONTEXT_LENGTH).replace(/[\ud800-\udbff]$/, '').trimEnd(),
		},
		position: { start, end },
	};
}

// --- anchor() -----------------------------------------------------------------------------------------------------

/**
 * How well `actual` matches `expected`, comparing characters outward from the quote (`fromEnd`: from the end of both):
 * the unbroken run of equal characters counts double, later equal characters once, so small edits cost little.
 */
function contextScore(expected, actual, fromEnd) {
	const count = Math.min(expected.length, actual.length);
	let score = 0;
	let unbroken = true;
	for (let i = 0; i < count; i++) {
		const a = fromEnd ? expected[expected.length - 1 - i] : expected[i];
		const b = fromEnd ? actual[actual.length - 1 - i] : actual[i];
		if (a === b) {
			score += unbroken ? 2 : 1;
		} else {
			unbroken = false;
		}
	}
	return score;
}

/** The best occurrence of needle in a view as model offsets [start, end), or null. */
function search(model, view, needle, prefix, suffix, hint) {
	if (needle === '') {
		return null;
	}
	const text = view.text;
	let best = null;
	let bestScore = -Infinity;
	for (let at = text.indexOf(needle); at !== -1; at = text.indexOf(needle, at + 1)) {
		const end = at + needle.length;
		let score = 0;
		if (prefix !== '') {
			score += contextScore(prefix, text.slice(Math.max(0, at - prefix.length), at), true);
		}
		if (suffix !== '') {
			score += contextScore(suffix, text.slice(end, end + suffix.length), false);
		}
		const rawStart = view.map[at];
		if (hint !== null) {	// tie-breaker only: always less than one point
			score -= Math.abs(rawStart - hint) / (model.text.length + 1);
		}
		if (score > bestScore) {
			bestScore = score;
			best = { start: rawStart, end: view.map[end - 1] + 1 };
		}
	}
	return best;
}

function contextOf(quote, key) {
	const value = quote[key];
	if (value === undefined || value === null) {
		return '';
	}
	if (typeof value !== 'string') {
		throw new AnchoringError('invalid-selector', 'quote.' + key + ' must be a string.');
	}
	return value;
}

function validPosition(position, length) {
	return position !== null && typeof position === 'object'
		&& Number.isInteger(position.start) && Number.isInteger(position.end)
		&& position.start >= 0 && position.start < position.end && position.end <= length;
}

/**
 * Finds a selector in root and returns its DOM Range, or null when the passage is not found (the highlight is
 * orphaned): the stored position when the text there still is the quote, otherwise the occurrence of the quote whose
 * surroundings best match prefix and suffix, otherwise the same search ignoring whitespace.
 */
export function anchor(selector, root) {
	const quote = selector !== null && typeof selector === 'object' ? selector.quote : undefined;
	if (quote === null || typeof quote !== 'object' || typeof quote.exact !== 'string') {
		throw new AnchoringError('invalid-selector', 'The selector has no quote.exact.');
	}
	const exact = collapse(quote.exact);
	if (exact === '') {
		throw new AnchoringError('invalid-selector', 'quote.exact is empty.');
	}
	const prefix = contextOf(quote, 'prefix').replace(/\s+/g, ' ');
	const suffix = contextOf(quote, 'suffix').replace(/\s+/g, ' ');
	const model = modelOf(root);
	const position = selector.position === undefined ? null : selector.position;
	let hint = null;
	if (validPosition(position, model.text.length)) {
		if (collapse(model.text.slice(position.start, position.end)) === exact) {
			return rangeOf(model, position.start, position.end);
		}
		hint = position.start;
	}
	const found = search(model, viewOf(model, 'collapsed'), exact, prefix, suffix, hint)
		|| search(model, viewOf(model, 'stripped'), exact.replace(/\s/g, ''),
			prefix.replace(/\s/g, ''), suffix.replace(/\s/g, ''), hint);
	return found === null ? null : rangeOf(model, found.start, found.end);
}

// --- highlight() --------------------------------------------------------------------------------------------------

/** Whitespace-only text where a `<mark>` would be invalid or invisible: between, or at the edge of, blocks. */
function isInterBlockWhitespace(node) {
	if (!WHITESPACE_ONLY.test(node.data)) {
		return false;
	}
	const parent = node.parentNode;
	const previous = node.previousSibling;
	const next = node.nextSibling;
	return isBlock(previous) || isBlock(next)
		|| ((previous === null || next === null) && (parent === null || isBlock(parent)));
}

/** The parts of text nodes that `range` covers, in document order, before anything is changed. */
function textPieces(range) {
	const common = range.commonAncestorContainer;
	const pieces = [];
	const add = (node) => {
		const start = node === range.startContainer ? range.startOffset : 0;
		const end = node === range.endContainer ? range.endOffset : node.data.length;
		if (start < end && !isInterBlockWhitespace(node)) {
			pieces.push({ node, start, end });
		}
	};
	if (common.nodeType === Node.TEXT_NODE) {
		add(common);
		return pieces;
	}
	const document = common.ownerDocument || common;
	const walker = document.createTreeWalker(common, NodeFilter.SHOW_ELEMENT | NodeFilter.SHOW_TEXT, {
		acceptNode(node) {
			if (node.nodeType === Node.ELEMENT_NODE) {
				return isIgnored(node) ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_SKIP;
			}
			return range.intersectsNode(node) ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_SKIP;
		},
	});
	for (let node = walker.nextNode(); node !== null; node = walker.nextNode()) {
		add(node);
	}
	return pieces;
}

/** Text.splitText(), keeping the up-to-date models of the node in sync. Returns the new node. */
function split(node, offset) {
	const next = node.splitText(offset);
	const owners = nodeModels.get(node);
	if (owners !== undefined) {
		for (const model of owners) {
			const segment = model.byNode.get(node);
			if (model.stale || segment === undefined) {
				continue;
			}
			const added = { node: next, start: segment.start + offset, end: segment.end };
			segment.end = added.start;
			model.segments.splice(model.segments.indexOf(segment, 0) + 1, 0, added);
			model.byNode.set(next, added);
			addNodeModel(next, model);
		}
	}
	return next;
}

/** Merges text node `node` with its adjacent text siblings. */
function mergeText(node) {
	if (node === null || node.nodeType !== Node.TEXT_NODE || node.parentNode === null) {
		return;
	}
	const previous = node.previousSibling;
	if (previous !== null && previous.nodeType === Node.TEXT_NODE) {
		previous.appendData(node.data);
		node.remove();
		node = previous;
	}
	const next = node.nextSibling;
	if (next !== null && next.nodeType === Node.TEXT_NODE) {
		node.appendData(next.data);
		next.remove();
	}
}

/**
 * Draws a highlight: wraps the text that `range` covers in `<mark class="articlenotes-hl">` elements, with `attrs` as
 * `data-*` attributes (`{id: 5, color: 'yellow'}` gives `data-id="5" data-color="yellow"`). Never parses HTML. Returns
 * `{marks, remove()}`; remove() unwraps the marks and merges the text nodes split for them. `range` is updated to
 * cover the marked text; other ranges with boundaries in that text are not, so anchor each highlight right before
 * drawing it.
 */
export function highlight(range, attrs = {}) {
	const pieces = textPieces(range);
	const owners = new Set();
	for (const piece of pieces) {
		for (const model of nodeModels.get(piece.node) || []) {
			owners.add(model);
		}
	}
	for (const model of owners) {	// earlier, unrelated changes make a model unusable
		if (!model.stale && model.observer.takeRecords().length > 0) {
			invalidate(model);
		}
	}
	const marks = [];
	const wrapped = [];
	for (const piece of pieces) {
		let node = piece.node;
		if (piece.end < node.data.length) {
			split(node, piece.end);
		}
		if (piece.start > 0) {
			node = split(node, piece.start);
		}
		const mark = node.ownerDocument.createElement('mark');
		mark.className = HIGHLIGHT_CLASS;
		for (const [key, value] of Object.entries(attrs)) {
			if (value !== null && value !== undefined) {
				mark.dataset[key] = String(value);
			}
		}
		node.parentNode.insertBefore(mark, node);
		mark.appendChild(node);
		marks.push(mark);
		wrapped.push(node);
	}
	if (wrapped.length > 0) {	// moving the text nodes into the marks collapsed the boundaries in them
		range.setStart(wrapped[0], 0);
		range.setEnd(wrapped[wrapped.length - 1], wrapped[wrapped.length - 1].data.length);
	}
	for (const model of owners) {	// the text is unchanged and the models are already up to date
		if (!model.stale) {
			model.observer.takeRecords();
		}
	}
	let removed = false;
	return {
		marks,
		remove() {
			if (removed) {
				return;
			}
			removed = true;
			for (const mark of marks) {
				const parent = mark.parentNode;
				if (parent === null) {
					continue;
				}
				const first = mark.firstChild;
				const last = mark.lastChild;
				while (mark.firstChild !== null) {
					parent.insertBefore(mark.firstChild, mark);
				}
				mark.remove();
				mergeText(first);
				if (last !== first) {
					mergeText(last);
				}
			}
		},
	};
}
