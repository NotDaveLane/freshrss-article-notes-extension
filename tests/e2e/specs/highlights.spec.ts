import { expect, test, type APIRequestContext, type Locator, type Page } from '@playwright/test';
import { basicAuth, openArticle, signIn, throwawayApiPassword, throwawayUser } from '../support/freshrss';

const API = '/api/misc.php/ArticleNotes/v1';

const viewports = [
	{ name: 'mobile 390x844', width: 390, height: 844 },
	{ name: 'desktop 1440x900', width: 1440, height: 900 },
];

interface Article {
	n: number;
	title: string;
}

const articles: Record<string, Article> = {
	create: { n: 2, title: 'PostgreSQL 18 brings asynchronous I/O' },
	note: { n: 3, title: 'A gentle introduction to WebAssembly components' },
	overlap: { n: 4, title: 'Why we moved our CI to ephemeral containers' },
	page: { n: 5, title: 'SQLite as an application file format' },
	keyboard: { n: 6, title: 'Understanding Linux io_uring security trade-offs' },
	scroll: { n: 7, title: 'PHP 8.5 pipe operator in practice' },
	html: { n: 8, title: 'Building a feed reader that respects your attention' },
	toolbar: { n: 9, title: 'The hidden costs of microservices' },
};

function auth(): Record<string, string> {
	return basicAuth(throwawayUser().user, throwawayApiPassword());
}

async function entryId(request: APIRequestContext, article: Article): Promise<string> {
	const response = await request.get(`${API}/lookup`, { headers: auth(), params: { url: `http://fixtures/articles/${article.n}` } });
	expect(response.status()).toBe(200);
	return ((await response.json()) as { matches: { entry_id: string }[] }).matches[0].entry_id;
}

async function apiHighlights(request: APIRequestContext, id: string): Promise<Record<string, any>[]> {
	const response = await request.get(`${API}/highlights`, { headers: auth(), params: { entry_id: id } });
	expect(response.status()).toBe(200);
	return ((await response.json()) as { highlights: Record<string, any>[] }).highlights;
}

/** Starts a test from a clean slate: deletes the article's highlights (the user is shared by the specs). */
async function reset(request: APIRequestContext, article: Article): Promise<string> {
	const id = await entryId(request, article);
	for (const highlight of await apiHighlights(request, id)) {
		await request.delete(`${API}/highlights/${highlight.uuid}`, { headers: auth() });
	}
	return id;
}

async function createViaApi(request: APIRequestContext, id: string, body: Record<string, unknown>): Promise<Record<string, any>> {
	const response = await request.post(`${API}/highlights`, {
		headers: { ...auth(), 'Content-Type': 'application/json' },
		data: { entry_id: id, ...body },
	});
	expect(response.status()).toBe(201);
	return ((await response.json()) as { highlight: Record<string, any> }).highlight;
}

/** Selects the text from the start of `from` to the end of `to` (default: `from`) in the article content. */
async function select(article: Locator, from: string, to = from): Promise<void> {
	await article.locator('.content .text').evaluate((root, [start, end]) => {
		const nodes: { node: Text; from: number }[] = [];
		let text = '';
		const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
		for (let node = walker.nextNode(); node; node = walker.nextNode()) {
			nodes.push({ node: node as Text, from: text.length });
			text += (node as Text).data;
		}
		const locate = (offset: number): { node: Text; at: number } => {
			const found = nodes.filter((n) => n.from <= offset && offset <= n.from + n.node.data.length).pop()!;
			return { node: found.node, at: offset - found.from };
		};
		const first = text.indexOf(start);
		const last = text.indexOf(end, first);
		if (first === -1 || last === -1) {
			throw new Error('text not found: ' + start + ' / ' + end);
		}
		const a = locate(first);
		const b = locate(last + end.length);
		window.getSelection()!.setBaseAndExtent(a.node, a.at, b.node, b.at);
	}, [from, to]);
}

function marks(article: Locator): Locator {
	return article.locator('mark.articlenotes-hl');
}

function toolbar(page: Page): Locator {
	return page.locator('.articlenotes-toolbar[role="toolbar"]');
}

function popover(page: Page): Locator {
	return page.getByRole('dialog', { name: 'Highlight' });
}

async function open(page: Page, article: Article, query = 'state=3'): Promise<Locator> {
	await page.goto(`/i/?${query}`);
	return openArticle(page, article.title);
}

test.beforeEach(async ({ page }) => {
	await signIn(page, throwawayUser());
});

for (const viewport of viewports) {
	test.describe(viewport.name, () => {
		test.use({ viewport: { width: viewport.width, height: viewport.height } });

		test('select, highlight, reload, change color and delete', async ({ page, request }) => {
			const id = await reset(request, articles.create);
			let article = await open(page, articles.create);
			await expect(toolbar(page)).toHaveCount(0);

			await select(article, 'threefold read throughput');
			await expect(toolbar(page)).toBeVisible();
			await toolbar(page).getByRole('button', { name: 'Highlight', exact: true }).click();
			await expect(toolbar(page)).toHaveCount(0);
			await expect(marks(article)).toHaveText('threefold read throughput');
			await expect(marks(article)).toHaveAttribute('data-color', 'yellow');
			await expect(marks(article)).toHaveAttribute('role', 'button');
			await expect(marks(article)).toHaveAttribute('aria-label', /Highlight: threefold read throughput/);

			await expect.poll(async () => (await apiHighlights(request, id)).length).toBe(1);
			const stored = (await apiHighlights(request, id))[0];
			expect(stored.source).toBe('freshrss');
			expect(stored.selector.quote.exact).toBe('threefold read throughput');
			expect(stored.selector.quote.prefix).toContain('up to');
			expect(stored.label_applied).toBe(true);

			// Reload: drawn in the same place; the "Annotated" label is in the sidebar.
			await page.reload();
			article = await openArticle(page, articles.create.title);
			await expect(marks(article)).toHaveText('threefold read throughput');
			await expect(article.locator('.text')).toContainText('Early benchmarks show up to threefold read throughput improvements');
			await expect(page.locator('#aside_feed')).toContainText('Annotated');

			// The popover changes the color.
			await marks(article).click();
			await expect(popover(page)).toBeVisible();
			await popover(page).getByRole('button', { name: 'Blue' }).click();
			await expect(marks(article)).toHaveAttribute('data-color', 'blue');
			await expect.poll(async () => (await apiHighlights(request, id))[0].color).toBe('blue');
			await page.keyboard.press('Escape');
			await expect(popover(page)).toHaveCount(0);
			await expect(marks(article)).toBeFocused();

			// Delete.
			await marks(article).click();
			await popover(page).getByRole('button', { name: 'Delete' }).click();
			await expect(marks(article)).toHaveCount(0);
			await expect(popover(page)).toHaveCount(0);
			await expect(article.locator('.articlenotes-panel')).toHaveCount(0);
			await expect(article.locator('.text')).toContainText('Early benchmarks show up to threefold read throughput improvements');
			expect(await apiHighlights(request, id)).toHaveLength(0);
			await page.reload();
			article = await openArticle(page, articles.create.title);
			await expect(marks(article)).toHaveCount(0);
		});

		test('add, edit and delete a note through the popover', async ({ page, request }) => {
			const id = await reset(request, articles.note);
			let article = await open(page, articles.note);
			await select(article, 'typed interfaces');
			await toolbar(page).getByRole('button', { name: 'Add note' }).click();
			await expect(marks(article)).toHaveText('typed interfaces');
			const dialog = popover(page);
			await expect(dialog.getByLabel('Note on this highlight')).toBeFocused();
			await dialog.getByLabel('Note on this highlight').fill('first <b>note</b>');
			await dialog.getByRole('button', { name: 'Save' }).click();
			await expect(dialog).toHaveCount(0);
			await expect(marks(article)).toHaveAttribute('data-has-note', 'true');
			await expect(marks(article)).toHaveCSS('text-decoration-line', 'underline');
			await expect(marks(article)).toHaveAttribute('aria-label', /has a note/);
			await expect(article.locator('.articlenotes-hl-note')).toHaveText('first <b>note</b>');
			expect((await apiHighlights(request, id))[0].note).toBe('first <b>note</b>');

			await page.reload();
			article = await openArticle(page, articles.note.title);
			await expect(marks(article)).toHaveAttribute('data-has-note', 'true');
			await marks(article).click();
			await expect(dialog.getByLabel('Note on this highlight')).toHaveValue('first <b>note</b>');
			await dialog.getByLabel('Note on this highlight').fill('edited');
			await dialog.getByRole('button', { name: 'Save' }).click();
			await expect(article.locator('.articlenotes-hl-note')).toHaveText('edited');
			await expect.poll(async () => (await apiHighlights(request, id))[0].note).toBe('edited');

			// Clearing the note removes the indicator.
			await marks(article).click();
			await dialog.getByLabel('Note on this highlight').fill('');
			await dialog.getByRole('button', { name: 'Save' }).click();
			await expect(marks(article)).not.toHaveAttribute('data-has-note', 'true');
			await expect(article.locator('.articlenotes-hl-note')).toHaveCount(0);
		});
	});
}

test('color choices in the toolbar create a highlight of that color', async ({ page, request }) => {
	await reset(request, articles.toolbar);
	const article = await open(page, articles.toolbar);
	await select(article, 'partial failures');
	await toolbar(page).getByRole('button', { name: 'Pink' }).click();
	await expect(marks(article)).toHaveAttribute('data-color', 'pink');
	await expect(marks(article)).toHaveText('partial failures');
});

test('selections outside the article content, whitespace-only and over 2,000 characters get no toolbar', async ({ page, request }) => {
	await reset(request, articles.toolbar);
	const article = await open(page, articles.toolbar);
	// In the article header (FreshRSS's own UI).
	await article.locator('.flux_header .title').evaluate((el) => {
		window.getSelection()!.selectAllChildren(el);
	});
	await page.waitForTimeout(500);
	await expect(page.locator('.articlenotes-toolbar')).toHaveCount(0);

	// Whitespace only.
	await article.locator('.content .text').evaluate((root) => {
		const space = document.createTextNode('   ');
		root.appendChild(space);
		window.getSelection()!.setBaseAndExtent(space, 0, space, 3);
	});
	await page.waitForTimeout(500);
	await expect(page.locator('.articlenotes-toolbar')).toHaveCount(0);

	// Too long: a clear message instead of the buttons.
	await article.locator('.content .text').evaluate((root) => {
		const paragraph = document.createElement('p');
		paragraph.id = 'long-text';
		paragraph.textContent = 'word '.repeat(450);
		root.appendChild(paragraph);
		window.getSelection()!.selectAllChildren(paragraph);
	});
	await expect(page.locator('.articlenotes-toolbar')).toContainText('Select at most 2,000 characters');
	await expect(toolbar(page)).toHaveCount(0);

	// Spanning from the article content into FreshRSS's own UI.
	await article.locator('.content .text').evaluate((root) => {
		const first = root.firstChild!;
		window.getSelection()!.setBaseAndExtent(first, 0, document.querySelector('.flux_header')!, 1);
	});
	await page.waitForTimeout(500);
	await expect(page.locator('.articlenotes-toolbar')).toHaveCount(0);
});

test('on touch devices the toolbar is below the selection', async ({ browser }) => {
	const context = await browser.newContext({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true });
	const page = await context.newPage();
	await signIn(page, throwawayUser());
	const article = await open(page, articles.toolbar);
	await select(article, 'partial failures');
	await expect(toolbar(page)).toBeVisible();
	const selection = await page.evaluate(() => window.getSelection()!.getRangeAt(0).getBoundingClientRect().bottom);
	const box = await toolbar(page).boundingBox();
	expect(box!.y).toBeGreaterThan(selection);
	await context.close();
});

test('overlapping highlights are both drawn, after a reload too', async ({ page, request }) => {
	const id = await reset(request, articles.overlap);
	let article = await open(page, articles.overlap);
	await select(article, 'Reproducible builds, no cache');
	await toolbar(page).getByRole('button', { name: 'Highlight', exact: true }).click();
	await expect(marks(article).first()).toBeVisible();
	await select(article, 'no cache poisoning, and a');
	await toolbar(page).getByRole('button', { name: 'Green' }).click();
	await expect.poll(async () => (await apiHighlights(request, id)).length).toBe(2);

	await page.reload();
	article = await openArticle(page, articles.overlap.title);
	await expect(article.locator('mark[data-color="green"]').first()).toBeVisible();
	const texts = await article.locator('mark[data-color="yellow"]').allTextContents();
	expect(texts.join('')).toBe('Reproducible builds, no cache');
	expect((await article.locator('mark[data-color="green"]').allTextContents()).join('')).toBe('no cache poisoning, and a');
	await expect(article.locator('.articlenotes-hl-item')).toHaveCount(2);
});

test('a highlight made on the original page is drawn when it anchors; one that does not is listed as not found', async ({ page, request }) => {
	const id = await reset(request, articles.page);
	await createViaApi(request, id, {
		source: 'page', color: 'green', selector: { quote: { exact: 'single-file databases', prefix: 'the case for' } }, note: 'from the page',
	});
	await createViaApi(request, id, {
		source: 'freshrss', color: 'purple', selector: { quote: { exact: 'a passage that the publisher has since rewritten' }, position: { start: 5, end: 52 } },
		note: 'orphan note',
	});
	const article = await open(page, articles.page);
	await expect(marks(article)).toHaveCount(1);
	await expect(marks(article)).toHaveText('single-file databases');
	await expect(marks(article)).toHaveAttribute('data-color', 'green');
	await expect(marks(article)).toHaveAttribute('data-has-note', 'true');

	const items = article.locator('.articlenotes-hl-item');
	await expect(items).toHaveCount(2);
	const orphan = items.filter({ hasText: 'Passage not found' });
	await expect(orphan).toHaveCount(1);
	await expect(orphan).toContainText('a passage that the publisher has since rewritten');
	await expect(orphan).toContainText('orphan note');
	await expect(orphan.getByRole('button', { name: 'Jump to' })).toHaveCount(0);
	await expect(items.filter({ hasNotText: 'Passage not found' })).toContainText('single-file databases');
	// The orphan is not drawn in the text, but can still be edited and deleted from the panel.
	expect(await article.locator('.text').evaluate((text) => {
		const copy = text.cloneNode(true) as HTMLElement;
		copy.querySelector('.articlenotes-panel')!.remove();
		return copy.textContent;
	})).not.toContain('rewritten');
	await orphan.getByRole('button', { name: 'Edit' }).click();
	await popover(page).getByRole('button', { name: 'Delete' }).click();
	await expect(items).toHaveCount(1);
});

test('keyboard-only flow through the Annotations panel', async ({ page, request }) => {
	const id = await reset(request, articles.keyboard);
	await createViaApi(request, id, { source: 'freshrss', selector: { quote: { exact: 'io_uring' }, position: { start: 40, end: 48 } } });
	const article = await open(page, articles.keyboard);
	const panel = article.locator('.articlenotes-panel');
	await expect(panel.getByRole('heading', { name: 'Annotations' })).toBeVisible();
	await expect(panel.locator('.articlenotes-hl-item')).toHaveCount(1);

	// Jump to the highlight, open it with Enter, close it with Escape: focus returns to the highlight.
	await panel.getByRole('button', { name: 'Jump to' }).focus();
	await page.keyboard.press('Enter');
	await expect(marks(article).first()).toBeFocused();
	await page.keyboard.press('Enter');
	await expect(popover(page)).toBeFocused();
	await page.keyboard.press('Escape');
	await expect(popover(page)).toHaveCount(0);
	await expect(marks(article).first()).toBeFocused();

	// Edit from the panel: type a note, Tab to Save, Enter: focus returns to the Edit button.
	await panel.getByRole('button', { name: 'Edit' }).focus();
	await page.keyboard.press('Enter');
	await expect(popover(page)).toBeFocused();
	await page.keyboard.press('Tab');	// first color
	for (let i = 0; i < 5; i++) {
		await page.keyboard.press('Tab');
	}
	await expect(popover(page).getByLabel('Note on this highlight')).toBeFocused();
	await page.keyboard.type('typed with the keyboard');
	await page.keyboard.press('Tab');
	await expect(popover(page).getByRole('button', { name: 'Save' })).toBeFocused();
	await page.keyboard.press('Enter');
	await expect(popover(page)).toHaveCount(0);
	await expect(panel.locator('.articlenotes-hl-note')).toHaveText('typed with the keyboard');
	await expect(panel.getByRole('button', { name: 'Edit' })).toBeFocused();
});

test('HTML in a note is shown as text', async ({ page, request }) => {
	const id = await reset(request, articles.html);
	await createViaApi(request, id, {
		source: 'freshrss', selector: { quote: { exact: 'filtering, labels' } },
		note: '<img src=x onerror="window.pwned=1"><script>window.pwned=1</script>',
	});
	const article = await open(page, articles.html);
	await expect(article.locator('.articlenotes-hl-note')).toHaveText('<img src=x onerror="window.pwned=1"><script>window.pwned=1</script>');
	await expect(article.locator('.articlenotes-panel img, .articlenotes-panel script')).toHaveCount(0);
	await marks(article).click();
	await expect(popover(page).getByLabel('Note on this highlight')).toHaveValue('<img src=x onerror="window.pwned=1"><script>window.pwned=1</script>');
	await expect(popover(page).locator('img, script')).toHaveCount(0);
	expect(await page.evaluate(() => (window as unknown as { pwned?: number }).pwned)).toBeUndefined();
});

test('a failed save rolls the highlight back with an inline error', async ({ page, request }) => {
	await reset(request, articles.toolbar);
	const article = await open(page, articles.toolbar);
	await page.route('**/*highlightCreate*', (route) => route.fulfill({
		status: 500, contentType: 'application/json', body: JSON.stringify({ ok: false, error: 'Server said no.' }),
	}));
	await select(article, 'organizational overhead');
	await toolbar(page).getByRole('button', { name: 'Highlight', exact: true }).click();
	await expect(page.getByRole('alert').filter({ hasText: 'Server said no.' })).toBeVisible();
	await expect(marks(article)).toHaveCount(0);
	await expect(article.locator('.text')).toContainText('organizational overhead');
});

test('an article loaded by scrolling shows its highlights', async ({ page, request }) => {
	const id = await reset(request, articles.scroll);
	await createViaApi(request, id, { source: 'freshrss', selector: { quote: { exact: 'temporary variables' } } });
	// One article per page; the article is further down and arrives with "load more" (or FreshRSS's automatic loading).
	await page.goto('/i/?state=3&nb=1');
	await expect(page.locator('.flux', { hasText: articles.scroll.title })).toHaveCount(0);
	for (let i = 0; i < 10 && (await page.locator('.flux', { hasText: articles.scroll.title }).count()) === 0; i++) {
		await page.locator('#load_more').scrollIntoViewIfNeeded();
		await page.locator('#load_more').click({ trial: false }).catch(() => undefined);
		await page.waitForTimeout(700);
	}
	const article = await openArticle(page, articles.scroll.title);
	await expect(marks(article)).toHaveText('temporary variables');
});

test('highlights work in the reader view', async ({ page, request }) => {
	const id = await reset(request, articles.html);
	await createViaApi(request, id, { source: 'freshrss', selector: { quote: { exact: 'avoiding infinite scroll' } } });
	await page.goto('/i/?a=reader&state=3');
	const article = page.locator('.flux', { hasText: articles.html.title }).first();
	await expect(marks(article)).toHaveText('avoiding infinite scroll');
});
