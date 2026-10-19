import { readFileSync } from 'node:fs';
import { expect, test, type Page } from '@playwright/test';
import { ANCHORING_URL, coverageReport } from '../support/coverage';

// Unit tests of the anchoring library (xExtension-ArticleNotes/static/anchoring.js, mounted at /extension) in a real
// browser. The module is served, unmodified, from a routed origin; its V8 coverage is written to JS_COVERAGE_DIR
// (coverage/js/ via bin/e2e, see support/coverage.ts).

const LIBRARY = '/extension/static/anchoring.js';
const ORIGIN = new URL(ANCHORING_URL).origin;
const PAGE = `<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>anchoring</title></head>
<body><div id="root"></div><div id="other"></div>
<script type="module">
import * as anchoring from './anchoring.js';
window.anchoring = anchoring;
// Test helpers: ranges by text, and the DOM structure with text node boundaries.
window.find = (root, needle, occurrence = 0) => {
	const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
	let seen = 0;
	for (let node = walker.nextNode(); node; node = walker.nextNode()) {
		for (let at = node.data.indexOf(needle); at !== -1; at = node.data.indexOf(needle, at + 1)) {
			if (seen++ === occurrence) {
				const range = document.createRange();
				range.setStart(node, at);
				range.setEnd(node, at + needle.length);
				return range;
			}
		}
	}
	throw new Error('not found: ' + needle);
};
window.span = (root, from, to) => {
	const range = document.createRange();
	const start = window.find(root, from);
	const end = window.find(root, to);
	range.setStart(start.startContainer, start.startOffset);
	range.setEnd(end.endContainer, end.endOffset);
	return range;
};
window.structure = (node) => node.nodeType === Node.TEXT_NODE ? JSON.stringify(node.data)
	: '<' + node.nodeName.toLowerCase() + Array.from(node.attributes || []).map((a) => ' ' + a.name + '=' + a.value).join('')
		+ '>[' + Array.from(node.childNodes).map(window.structure).join(',') + ']';
window.collapse = (text) => text.replace(/\\s+/g, ' ').trim();
window.root = document.getElementById('root');
window.other = document.getElementById('other');
window.ready = true;
</script></body></html>`;

const coverage = coverageReport();

declare global {
	interface Window {
		anchoring: any;
		find: (root: Element, needle: string, occurrence?: number) => Range;
		span: (root: Element, from: string, to: string) => Range;
		structure: (node: Node) => string;
		collapse: (text: string) => string;
		root: HTMLElement;
		other: HTMLElement;
	}
}

test.beforeEach(async ({ page }) => {
	await page.coverage.startJSCoverage({ resetOnNavigation: false });
	await page.route(`${ORIGIN}/**`, (route) => {
		const url = new URL(route.request().url());
		if (url.href === ANCHORING_URL) {
			return route.fulfill({ contentType: 'text/javascript', body: readFileSync(LIBRARY, 'utf8') });
		}
		return route.fulfill({ contentType: 'text/html', body: PAGE });
	});
	await page.goto(`${ORIGIN}/`);
	await page.waitForFunction(() => (window as any).ready === true);
});

test.afterEach(async ({ page }) => {
	await coverage.add(await page.coverage.stopJSCoverage());
});

async function setHtml(page: Page, html: string, target: 'root' | 'other' = 'root'): Promise<void> {
	await page.evaluate(([markup, id]) => { document.getElementById(id)!.innerHTML = markup; }, [html, target]);
}

test('describe() gives the collapsed quote, its context and position, and anchor() finds it again', async ({ page }) => {
	await setHtml(page, '<p>The   quick brown\n fox jumps over the lazy dog, and the dog sleeps.</p>');
	const result = await page.evaluate(() => {
		const { describe, anchor, textOf } = window.anchoring;
		const range = window.span(window.root, 'quick', 'fox');
		const selector = describe(range, window.root);
		const anchored = anchor(selector, window.root);
		return { selector, slice: textOf(window.root).slice(selector.position.start, selector.position.end), anchored: anchored.toString() };
	});
	expect(result.selector).toEqual({
		quote: { exact: 'quick brown fox', prefix: 'The ', suffix: ' jumps over the lazy dog, and the dog sleeps.' },
		position: { start: 6, end: 22 },
	});
	expect(result.slice).toBe('quick brown\n fox');
	expect(result.anchored).toBe('quick brown\n fox');
});

test('prefix and suffix are at most 64 characters of the surrounding text', async ({ page }) => {
	const before = 'a'.repeat(100) + ' 😀 lead-in';
	const after = 'tail ' + 'é'.repeat(100);
	await setHtml(page, `<p>${before} QUOTE ${after}</p>`);
	const quote = await page.evaluate(() => window.anchoring.describe(window.find(window.root, 'QUOTE'), window.root).quote);
	expect(Array.from(quote.prefix)).toHaveLength(64);
	expect(quote.prefix.endsWith('😀 lead-in ')).toBe(true);
	expect(quote.suffix).toBe(' ' + after.slice(0, 63));
});

test('a repeated phrase is resolved by its context', async ({ page }) => {
	await setHtml(page, '<p>Cats like fish. The red door opens.</p><p>Dogs like bones. The red door closes.</p>');
	const result = await page.evaluate(() => {
		const { describe, anchor } = window.anchoring;
		const selector = describe(window.find(window.root, 'The red door', 1), window.root);
		delete selector.position;
		const range = anchor(selector, window.root);
		return { selector, after: range.endContainer.data.slice(range.endOffset) };
	});
	expect(result.selector.quote).toMatchObject({ exact: 'The red door', suffix: ' closes.' });
	expect(result.after).toBe(' closes.');
});

test('the context outweighs a stale position hint, which only breaks ties', async ({ page }) => {
	await setHtml(page, '<p>one two one two one</p>');
	const result = await page.evaluate(() => {
		const { anchor } = window.anchoring;
		const at = (selector: object) => {
			const range = anchor(selector, window.root);
			return range.startOffset;
		};
		return {
			byHint: at({ quote: { exact: 'one' }, position: { start: 9, end: 12 } }),	// stale: "wo " is there
			byContext: at({ quote: { exact: 'one', prefix: 'two ', suffix: '' }, position: { start: 0, end: 2 } }),
			first: at({ quote: { exact: 'one' } }),
		};
	});
	expect(result).toEqual({ byHint: 8, byContext: 8, first: 0 });
});

test('selections across inline and block elements, links, emphasis and list items', async ({ page }) => {
	await setHtml(page, `<div>
		<p>Read <a href="https://example.org/">the <em>linked</em> page</a> first.</p>
		<ul>
			<li>Item <strong>one</strong></li>
			<li>Item two</li>
		</ul>
		<p>After<br>the list.</p>
	</div>`);
	const result = await page.evaluate(() => {
		const { describe, anchor, highlight } = window.anchoring;
		const original = window.structure(window.root);
		const selector = describe(window.span(window.root, 'linked', 'After'), window.root);
		const range = anchor(selector, window.root);
		const handle = highlight(range, { id: 7 });
		const marks = Array.from(window.root.querySelectorAll('mark'));
		const result = {
			exact: selector.quote.exact,
			anchored: window.collapse(range.toString()),
			marked: window.collapse(marks.map((mark) => mark.textContent).join(' ')),
			parents: [...new Set(marks.map((mark) => mark.parentElement!.localName))].sort(),
			restored: false,
		};
		handle.remove();
		result.restored = window.structure(window.root) === original;
		return result;
	});
	expect(result.exact).toBe('linked page first. Item one Item two After');
	expect(result.anchored).toBe(result.exact);
	expect(result.marked).toBe(result.exact);
	// Never directly inside <ul> (the whitespace between the list items is skipped).
	expect(result.parents).toEqual(['a', 'em', 'li', 'p', 'strong']);
	expect(result.restored).toBe(true);
});

test('block boundaries and <br> separate words in the text model', async ({ page }) => {
	await setHtml(page, '<h2>Title</h2><p>First<br>line</p><ul><li>a</li><li>b</li></ul><table><tr><td>x</td><td>y</td></tr></table>');
	const text = await page.evaluate(() => window.anchoring.textOf(window.root));
	expect(text).toBe('Title\nFirst\nline\na\nb\nx\ny');
});

test('selections starting or ending at element boundaries', async ({ page }) => {
	await setHtml(page, '<p id="a">Alpha <em>beta</em></p><p id="b">Gamma</p><ul id="c"><li>Delta</li><li>Epsilon</li></ul><p id="d"><img alt=""></p>');
	const result = await page.evaluate(() => {
		const { describe } = window.anchoring;
		const range = (startNode: Node, startOffset: number, endNode: Node, endOffset: number) => {
			const r = document.createRange();
			r.setStart(startNode, startOffset);
			r.setEnd(endNode, endOffset);
			return describe(r, window.root).quote.exact;
		};
		const byId = (id: string) => document.getElementById(id)!;
		return [
			range(byId('a'), 1, byId('b'), 1),	// from before <em> to the end of "Gamma"
			range(byId('b'), 0, byId('c'), 1),	// from the start of <p id=b> to after the first <li>
			range(byId('c'), 1, byId('d'), 1),	// from between the items to after the image
			range(window.root, 0, byId('a').firstChild!, 3),
		];
	});
	expect(result).toEqual(['beta Gamma', 'Gamma Delta', 'Epsilon', 'Alp']);
});

test('whitespace and line-break differences still anchor', async ({ page }) => {
	await setHtml(page, '<p>Line one ends here.</p><p>Line two starts here with    some text.</p>');
	await setHtml(page, '<div>Line one ends\nhere.<br>Line two starts here with some\n\ttext.</div>', 'other');
	const result = await page.evaluate(() => {
		const { describe, anchor } = window.anchoring;
		const selector = describe(window.span(window.root, 'ends', 'some'), window.root);
		const there = anchor(selector, window.other).toString();
		// Only whitespace-insensitive matching finds it when the other side has no whitespace at a boundary.
		window.other.innerHTML = '<span>Line one ends</span><span>here.</span><span>Line two starts here with some text.</span>';
		const stripped = anchor(selector, window.other);
		return { exact: selector.quote.exact, there: window.collapse(there), stripped: stripped.toString() };
	});
	expect(result.exact).toBe('ends here. Line two starts here with some');
	expect(result.there).toBe('ends here.Line two starts here with some');	// Range.toString() has no <br>
	expect(result.stripped).toBe('endshere.Line two starts here with some');
});

test('edited content still anchors by quote', async ({ page }) => {
	await setHtml(page, '<p>Intro.</p><p>The answer is 42, said the computer. The answer is 42; nobody asked.</p>');
	const result = await page.evaluate(() => {
		const { describe, anchor } = window.anchoring;
		const selector = describe(window.find(window.root, 'The answer is 42', 1), window.root);
		// An inserted paragraph moves the text, and the punctuation around both quotes changed.
		window.root.innerHTML = '<p>Intro.</p><p>A new paragraph appears.</p>'
			+ '<p>The answer is 42 - said the computer! The answer is 42: nobody asked?</p>';
		const range = anchor(selector, window.root);
		return { text: range.toString(), after: range.endContainer.data.slice(range.endOffset) };
	});
	expect(result.text).toBe('The answer is 42');
	expect(result.after).toBe(': nobody asked?');
});

test('a removed quote is orphaned: anchor() returns null', async ({ page }) => {
	await setHtml(page, '<p>Something entirely different now.</p>');
	const result = await page.evaluate(() => window.anchoring.anchor({
		quote: { exact: 'The passage that was here', prefix: 'Something ', suffix: '' },
		position: { start: 0, end: 25 },
	}, window.root));
	expect(result).toBeNull();
});

test('invalid selectors are rejected with a typed error', async ({ page }) => {
	await setHtml(page, '<p>text</p>');
	const codes = await page.evaluate(() => {
		const { anchor, AnchoringError } = window.anchoring;
		return [null, {}, { quote: {} }, { quote: { exact: '   ' } }, { quote: { exact: 'x', prefix: 5 } }].map((selector) => {
			try {
				anchor(selector, window.root);
				return 'none';
			} catch (error) {
				return error instanceof AnchoringError ? error.code : String(error);
			}
		});
	});
	expect(codes).toEqual(['invalid-selector', 'invalid-selector', 'invalid-selector', 'invalid-selector', 'invalid-selector']);
});

test('an invalid or out-of-range position falls back to the quote', async ({ page }) => {
	await setHtml(page, '<p>short text</p>');
	const texts = await page.evaluate(() => [{ start: 5, end: 100 }, { start: 3, end: 3 }, { start: '0', end: 4 }, null].map(
		(position) => window.anchoring.anchor({ quote: { exact: 'text' }, position }, window.root).toString(),
	));
	expect(texts).toEqual(['text', 'text', 'text', 'text']);
});

test('overlapping and adjacent highlights render and remove cleanly in any order', async ({ page }) => {
	await setHtml(page, '<p>Alpha beta <b>gamma</b> delta epsilon.</p><p>Zeta eta.</p>');
	const result = await page.evaluate(() => {
		const { describe, anchor, highlight } = window.anchoring;
		const original = window.structure(window.root);
		const selectors = [
			describe(window.span(window.root, 'beta', 'delta'), window.root),	// A
			describe(window.span(window.root, 'gamma', 'Zeta'), window.root),	// B overlaps A
			describe(window.find(window.root, 'Alph'), window.root),	// C ends where D starts
			describe(window.find(window.root, 'a beta'), window.root),	// D overlaps A
		];
		const orders = [[0, 1, 2, 3], [3, 2, 1, 0], [1, 3, 0, 2], [2, 0, 3, 1]];
		const outcomes = [];
		for (const order of orders) {
			const handles = selectors.map((selector, i) => highlight(anchor(selector, window.root), { n: i }));
			const texts = selectors.map((_, i) => window.collapse(Array.from(window.root.querySelectorAll(`mark[data-n="${i}"]`))
				.map((mark) => mark.textContent).join('')));
			for (const i of order) {
				handles[i].remove();
				handles[i].remove();	// idempotent
			}
			outcomes.push({ texts, restored: window.structure(window.root) === original });
		}
		return outcomes;
	});
	for (const outcome of result) {
		expect(outcome.texts).toEqual(['beta gamma delta', 'gamma delta epsilon.Zeta', 'Alph', 'a beta']);
		expect(outcome.restored).toBe(true);
	}
});

test('highlight() sets data attributes and never interprets text as HTML', async ({ page }) => {
	await setHtml(page, '<p>Use &lt;img src=x onerror=alert(1)&gt; carefully.</p>');
	const result = await page.evaluate(() => {
		const { describe, anchor, highlight, HIGHLIGHT_CLASS } = window.anchoring;
		const selector = describe(window.find(window.root, '<img src=x onerror=alert(1)>'), window.root);
		const handle = highlight(anchor(selector, window.root), { id: 12, color: 'pink', highlightUuid: 'abc', skipped: null });
		const mark = handle.marks[0];
		return {
			exact: selector.quote.exact,
			className: mark.className,
			expected: HIGHLIGHT_CLASS,
			attributes: Array.from(mark.attributes as NamedNodeMap).map((a) => `${a.name}=${a.value}`),
			images: window.root.querySelectorAll('img').length,
			text: mark.textContent,
		};
	});
	expect(result.exact).toBe('<img src=x onerror=alert(1)>');
	expect(result.className).toBe(result.expected);
	expect(result.attributes).toEqual(['class=articlenotes-hl', 'data-id=12', 'data-color=pink', 'data-highlight-uuid=abc']);
	expect(result.images).toBe(0);
	expect(result.text).toBe('<img src=x onerror=alert(1)>');
});

test('Unicode: emoji, combining characters and right-to-left text', async ({ page }) => {
	await setHtml(page, '<p>Party 🎉🎉 time with café and <span dir="rtl">שלום עולם</span> today.</p>'
		+ '<p dir="rtl">مرحبا بالعالم 👋 مرحبا</p>');
	const result = await page.evaluate(() => {
		const { describe, anchor, highlight } = window.anchoring;
		const original = window.structure(window.root);
		const out = [];
		for (const [from, to] of [['🎉🎉', 'café'], ['שלום', 'עולם'], ['بالعالم', '👋']]) {
			const selector = describe(window.span(window.root, from, to), window.root);
			delete selector.position;
			const handle = highlight(anchor(selector, window.root));
			out.push({ exact: selector.quote.exact, marked: handle.marks.map((mark: Element) => mark.textContent).join('') });
			handle.remove();
		}
		return { out, restored: window.structure(window.root) === original };
	});
	expect(result.out).toEqual([
		{ exact: '🎉🎉 time with café', marked: '🎉🎉 time with café' },
		{ exact: 'שלום עולם', marked: 'שלום עולם' },
		{ exact: 'بالعالم 👋', marked: 'بالعالم 👋' },
	]);
	expect(result.restored).toBe(true);
});

test('quotes are 1 to 2,000 characters, counted in Unicode code points', async ({ page }) => {
	await setHtml(page, `<p>${'x'.repeat(2000)}</p><p>${'😀'.repeat(2000)}</p><p>${'y'.repeat(2001)}</p><p id="blank"> \n </p>`);
	await setHtml(page, '<p>elsewhere</p>', 'other');
	const result = await page.evaluate(() => {
		const { describe, AnchoringError } = window.anchoring;
		const attempt = (range: Range) => {
			try {
				return Array.from(describe(range, window.root).quote.exact as string).length;
			} catch (error) {
				return error instanceof AnchoringError ? error.code : String(error);
			}
		};
		const blank = document.createRange();
		blank.selectNodeContents(document.getElementById('blank')!);
		return [
			attempt(window.find(window.root, 'x'.repeat(2000))),
			attempt(window.find(window.root, '😀'.repeat(2000))),
			attempt(window.find(window.root, 'y'.repeat(2001))),
			attempt(blank),
			attempt(window.find(window.other, 'elsewhere')),
		];
	});
	expect(result).toEqual([2000, 2000, 'too-long', 'empty', 'outside-root']);
});

test('textOf() ignores scripts, styles, hidden elements and the extension UI', async ({ page }) => {
	await setHtml(page, '<p>Visible <script>var secret = 1;</script><style>p{}</style><span hidden>hidden</span>'
		+ '<span style="display:none">none</span><span data-articlenotes-ui>ui</span>text.</p>'
		+ '<section class="articlenotes-note"><h4>Your note</h4></section>');
	const result = await page.evaluate(() => {
		const { textOf, describe, anchor, highlight } = window.anchoring;
		const text = textOf(window.root);
		const selector = describe(window.span(window.root, 'Visible', 'text.'), window.root);
		const handle = highlight(anchor(selector, window.root));
		const marked = handle.marks.map((mark: Element) => mark.textContent);
		const scriptIntact = window.root.querySelector('script')!.textContent;
		handle.remove();
		return { text, exact: selector.quote.exact, marked, scriptIntact };
	});
	expect(result.text).toBe('Visible text.');
	expect(result.exact).toBe('Visible text.');
	expect(result.marked).toEqual(['Visible ', 'text.']);
	expect(result.scriptIntact).toBe('var secret = 1;');
});

test('the text model follows changes to the document', async ({ page }) => {
	await setHtml(page, '<p id="p">before</p>');
	const result = await page.evaluate(() => {
		const { textOf, anchor } = window.anchoring;
		const first = textOf(window.root);
		document.getElementById('p')!.firstChild!.textContent = 'after';
		const second = textOf(window.root);
		document.getElementById('p')!.hidden = true;
		const third = textOf(window.root);
		document.getElementById('p')!.hidden = false;
		const range = anchor({ quote: { exact: 'after' } }, window.root);
		return { first, second, third, anchored: range.toString() };
	});
	expect(result).toEqual({ first: 'before', second: 'after', third: '', anchored: 'after' });
});

test('anchoring 200 highlights in a 50 KB article takes under 250 ms', async ({ page }) => {
	const result = await page.evaluate(() => {
		const { describe, anchor, highlight } = window.anchoring;
		const words = ['lorem', 'ipsum', 'dolor', 'sit', 'amet', 'consectetur', 'adipiscing', 'elit', 'sed', 'do', 'eiusmod',
			'tempor', 'incididunt', 'ut', 'labore', 'et', 'dolore', 'magna', 'aliqua'];
		let seed = 42;
		const random = (n: number) => {
			seed = (seed * 1103515245 + 12345) % 2147483648;
			return seed % n;
		};
		const paragraphs = [];
		let size = 0;
		while (size < 50 * 1024) {
			const sentence = [];
			for (let i = 0; i < 60; i++) {
				const word = words[random(words.length)];
				sentence.push(random(10) === 0 ? `<em>${word}</em>` : random(15) === 0 ? `<a href="#">${word}</a>` : word);
			}
			const html = `<p>${sentence.join(' ')}.</p>\n`;
			paragraphs.push(html);
			size += html.length;
		}
		window.root.innerHTML = paragraphs.join('');
		const nodes: Text[] = [];
		const walker = document.createTreeWalker(window.root, NodeFilter.SHOW_TEXT);
		for (let node = walker.nextNode(); node; node = walker.nextNode()) {
			if ((node as Text).data.trim() !== '') {
				nodes.push(node as Text);
			}
		}
		const selectors = [];
		for (let i = 0; i < 200; i++) {
			const first = random(nodes.length - 10);
			const last = first + random(6);
			const range = document.createRange();
			range.setStart(nodes[first], random(nodes[first].data.length - 1));
			range.setEnd(nodes[last], nodes[last].data.length);
			const selector = describe(range, window.root);
			if (i % 2 === 0) {	// half of them need the quote search
				delete selector.position;
			}
			selectors.push(selector);
		}
		// A fresh copy of the article: nothing cached.
		const copy = window.root.cloneNode(true) as HTMLElement;
		window.other.appendChild(copy);
		const started = performance.now();
		const ranges = selectors.map((selector) => anchor(selector, copy));
		const elapsed = performance.now() - started;
		const anchored = ranges.filter((range, i) => range !== null && window.collapse(range.toString()) === selectors[i].quote.exact).length;
		const renderStarted = performance.now();
		const handles = selectors.map((selector) => highlight(anchor(selector, copy)));
		const rendered = performance.now() - renderStarted;
		handles.forEach((handle) => handle.remove());
		return { size: copy.innerHTML.length, elapsed, anchored, rendered, restored: copy.innerHTML === window.root.innerHTML };
	});
	console.log(`anchoring: 200 highlights in ${result.size} bytes in ${result.elapsed.toFixed(1)} ms, rendered in ${result.rendered.toFixed(1)} ms`);
	expect(result.size).toBeGreaterThanOrEqual(50 * 1024);
	expect(result.anchored).toBe(200);
	expect(result.elapsed).toBeLessThan(250);
	expect(result.restored).toBe(true);
});

test('the library is served unmodified from the extension\'s static URL', async ({ request }) => {
	const response = await request.get('/ext.php?f=xExtension-ArticleNotes/static/anchoring.js&t=js');
	expect(response.status()).toBe(200);
	expect(response.headers()['content-type']).toContain('javascript');
	expect(await response.text()).toBe(readFileSync(LIBRARY, 'utf8'));
});
