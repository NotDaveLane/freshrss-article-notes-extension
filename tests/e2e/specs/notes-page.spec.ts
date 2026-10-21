import { expect, test, type APIRequestContext, type Locator, type Page } from '../support/test';
import { basicAuth, signIn, throwawayApiPassword, throwawayUser } from '../support/freshrss';

const API = '/api/misc.php/ArticleNotes/v1';
const MARK = 'npgmark';

const viewports = [
	{ name: 'mobile 390x844', width: 390, height: 844 },
	{ name: 'desktop 1440x900', width: 1440, height: 900 },
];

const RUST = 'Rust 1.90 stabilizes async closures in more places';
const POSTGRES = 'PostgreSQL 18 brings asynchronous I/O to sequential scans';
const WASM = 'A gentle introduction to WebAssembly components';
const PAGE_TITLE = 'title-token-gamma npgmark page';
const FEED = 'Fixture Tech Feed';

let api: APIRequestContext;
const created = { notes: [] as string[], pageNotes: [] as string[], highlights: [] as string[] };

async function entryId(n: number): Promise<string> {
	const response = await api.get(`${API}/lookup`, { params: { url: `http://fixtures/articles/${n}` } });
	expect(response.status()).toBe(200);
	return ((await response.json()) as { matches: { entry_id: string }[] }).matches[0].entry_id;
}

async function putNote(n: number, note: string): Promise<string> {
	const id = await entryId(n);
	expect((await api.put(`${API}/notes/${id}`, { data: { note } })).ok()).toBe(true);
	created.notes.push(id);
	return id;
}

async function putPageNote(url: string, note: string, title: string): Promise<void> {
	expect((await api.put(`${API}/page-notes`, { params: { url }, data: { note, title } })).ok()).toBe(true);
	created.pageNotes.push(url);
}

async function highlight(body: Record<string, unknown>): Promise<string> {
	const response = await api.post(`${API}/highlights`, { data: body });
	expect(response.status()).toBe(201);
	const uuid = ((await response.json()) as { highlight: { uuid: string } }).highlight.uuid;
	created.highlights.push(uuid);
	return uuid;
}

async function freshHighlight(n: number, exact: string, extra: Record<string, unknown> = {}): Promise<string> {
	return highlight({ entry_id: await entryId(n), source: 'freshrss', selector: { quote: { exact } }, ...extra });
}

test.describe.configure({ mode: 'serial' });

test.beforeAll(async ({ playwright }) => {
	api = await playwright.request.newContext({
		baseURL: process.env.BASE_URL ?? 'http://freshrss',
		extraHTTPHeaders: basicAuth(throwawayUser().user, throwawayApiPassword()),
	});
	// Older annotations first: the pager notes fill the second page.
	for (let i = 1; i <= 26; i++) {
		await putPageNote(`https://pager.example.org/${MARK}/${i}`, `${MARK} pager ${i}`, `Pager ${i}`);
	}
	await new Promise((resolve) => setTimeout(resolve, 1500));
	await putNote(1, `${MARK} note-token-alpha: remember 100% of the sure_thing`);
	await freshHighlight(1, 'async closure ergonomics', { color: 'yellow', note: `${MARK} hl-note-delta` });
	await putNote(2, `${MARK} <img src=x onerror=alert(1)> html-token-eps <script>alert(2)</script>`);
	await freshHighlight(3, 'component model', { color: 'green' });
	await freshHighlight(3, `ghostquote ${MARK} vanished`, { color: 'blue', note: `${MARK} ghost-note` });
	await putPageNote(`https://example.org/${MARK}-page`, `${MARK} page-note-body`, PAGE_TITLE);
	await highlight({ url: `https://example.org/${MARK}-page`, title: PAGE_TITLE, source: 'page', selector: { quote: { exact: `${MARK} page quote` } }, color: 'pink' });
	await highlight({ url: `https://example.net/${MARK}-other`, title: `Other ${MARK}`, source: 'page', selector: { quote: { exact: `${MARK} other quote` } }, color: 'purple' });
});

test.afterAll(async () => {
	for (const uuid of created.highlights) {
		await api.delete(`${API}/highlights/${uuid}`);
	}
	for (const id of created.notes) {
		await api.delete(`${API}/notes/${id}`);
	}
	for (const url of created.pageNotes) {
		await api.delete(`${API}/page-notes`, { params: { url } });
	}
	await api.dispose();
});

test.beforeEach(async ({ page }) => {
	page.on('dialog', (dialog) => {
		throw new Error('Unexpected dialog: ' + dialog.message());
	});
	await signIn(page, throwawayUser());
});

function groups(page: Page): Locator {
	return page.locator('.articlenotes-notes-group');
}

function group(page: Page, title: string): Locator {
	return groups(page).filter({ has: page.getByRole('heading', { level: 2, name: title }) });
}

async function search(page: Page, params: string): Promise<void> {
	await page.goto(`/i/?c=ArticleNotes&a=notes&${params}`);
	await expect(page.getByRole('heading', { level: 1, name: 'Notes' })).toBeVisible();
}

async function applyFilters(page: Page, fields: { q?: string; kind?: string; color?: string; feed?: string; hasNote?: boolean }): Promise<void> {
	const form = page.getByRole('search');
	const more = form.locator('details.articlenotes-notes-more');
	if ((fields.kind ?? fields.color ?? fields.feed ?? fields.hasNote) !== undefined && !(await more.evaluate((details: HTMLDetailsElement) => details.open))) {
		await more.getByText('Filters', { exact: true }).click();
	}
	if (fields.q !== undefined) {
		await form.getByLabel('Search').fill(fields.q);
	}
	if (fields.kind !== undefined) {
		await form.getByLabel('Kind').selectOption(fields.kind);
	}
	if (fields.color !== undefined) {
		await form.getByLabel('Color').selectOption(fields.color);
	}
	if (fields.feed !== undefined) {
		await form.getByLabel('Feed').selectOption({ label: fields.feed });
	}
	if (fields.hasNote !== undefined) {
		await form.getByLabel('Only with a note').setChecked(fields.hasNote);
	}
	await form.getByRole('button', { name: 'Apply filters' }).click();
	await expect(page.getByRole('heading', { level: 1, name: 'Notes' })).toBeVisible();
}

async function titles(page: Page): Promise<string[]> {
	return (await groups(page).locator('h2').allInnerTexts()).map((title) => title.trim());
}

test('the Configuration menu and the settings page link to the Notes page', async ({ page }) => {
	await page.goto('/i/?c=configure&a=display');
	await page.locator('#aside_feed a[href*="a=notes"]').click();
	await expect(page.getByRole('heading', { level: 1, name: 'Notes' })).toBeVisible();
	await page.goto('/i/?c=ArticleNotes&a=settings');
	await page.getByRole('link', { name: 'All my notes' }).click();
	await expect(page.getByRole('heading', { level: 1, name: 'Notes' })).toBeVisible();
});

for (const viewport of viewports) {
	test.describe(viewport.name, () => {
		test.use({ viewport: { width: viewport.width, height: viewport.height } });

		test('lists article notes, page notes and highlights grouped by article or page', async ({ page }) => {
			await search(page, `q=${MARK}`);
			await expect(groups(page)).toHaveCount(25);
			await expect(page.locator('.articlenotes-notes-summary')).toContainText('31 annotated articles and pages');

			const rust = group(page, RUST);
			await expect(rust.locator('.articlenotes-notes-note')).toContainText('note-token-alpha');
			await expect(rust.locator('.articlenotes-hl-item')).toHaveCount(1);
			await expect(rust.locator('.articlenotes-hl-item')).toHaveAttribute('data-color', 'yellow');
			await expect(rust.locator('.articlenotes-hl-quote')).toHaveText('async closure ergonomics');
			await expect(rust.locator('.articlenotes-hl-note')).toHaveText(`${MARK} hl-note-delta`);
			await expect(rust.locator('.articlenotes-notes-meta')).toContainText(FEED);
			await expect(rust.getByRole('heading', { level: 2 }).getByRole('link')).toHaveAttribute('href', /search=e%3A\d+/);

			const wasm = group(page, WASM);
			await expect(wasm.locator('.articlenotes-notes-note')).toHaveCount(0);
			await expect(wasm.locator('.articlenotes-hl-item')).toHaveCount(1);
			await expect(wasm.locator('.articlenotes-hl-item')).toHaveAttribute('data-color', 'blue');
			await expect(wasm.locator('.articlenotes-orphan')).toHaveText('Passage not found');

			const pageGroup = group(page, PAGE_TITLE);
			await expect(pageGroup.locator('.articlenotes-notes-note')).toContainText('page-note-body');
			await expect(pageGroup.locator('.articlenotes-hl-item')).toHaveAttribute('data-color', 'pink');
			await expect(pageGroup.locator('.articlenotes-notes-meta')).toContainText('example.org');
			const link = pageGroup.getByRole('heading', { level: 2 }).getByRole('link');
			await expect(link).toHaveAttribute('href', `https://example.org/${MARK}-page`);
			await expect(link).toHaveAttribute('target', '_blank');
			await expect(link).toHaveAttribute('rel', 'noopener noreferrer');
			await expect(pageGroup.locator('.articlenotes-notes-jump')).toHaveCount(0);

			const bare = group(page, `Other ${MARK}`);
			await expect(bare.locator('.articlenotes-notes-note')).toHaveCount(0);
			await expect(bare.locator('.articlenotes-hl-item')).toHaveAttribute('data-color', 'purple');
			expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true);
		});

		test('shows notes containing HTML as text', async ({ page }) => {
			await search(page, 'q=html-token-eps');
			const postgres = group(page, POSTGRES);
			await expect(postgres.locator('.articlenotes-notes-note')).toContainText('<img src=x onerror=alert(1)> html-token-eps <script>alert(2)</script>');
			await expect(page.locator('main img, main script')).toHaveCount(0);
		});

		test('searches notes, quotes and titles', async ({ page }) => {
			await search(page, '');
			await applyFilters(page, { q: 'note-token-alpha' });
			expect(await titles(page)).toEqual([RUST]);
			await expect(page.getByLabel('Search')).toHaveValue('note-token-alpha');

			await applyFilters(page, { q: 'hl-note-delta' });
			expect(await titles(page)).toEqual([RUST]);
			await applyFilters(page, { q: 'GHOSTQUOTE' });
			expect(await titles(page)).toEqual([WASM]);
			await applyFilters(page, { q: 'title-token-gamma' });
			expect(await titles(page)).toEqual([PAGE_TITLE]);
			await applyFilters(page, { q: 'Rust 1.90 stabilizes' });
			expect(await titles(page)).toContain(RUST);
			await applyFilters(page, { q: 'no-such-text-' + MARK + '-anywhere' });
			await expect(page.getByText('Nothing matches these filters.')).toBeVisible();
		});

		test('counts the listed groups in a grammatical summary and offers exports only when something is listed', async ({ page }) => {
			const summary = page.locator('.articlenotes-notes-summary');
			const exports = page.getByRole('link', { name: /^(Markdown|JSON \(W3C Web Annotation\))$/ });
			await search(page, `q=${MARK}`);
			await expect(summary).toContainText('31 annotated articles and pages');
			await expect(exports).toHaveCount(2);

			await search(page, 'q=note-token-alpha');
			await expect(summary).toContainText('1 annotated article or page');
			await expect(summary).not.toContainText('articles');
			await expect(exports).toHaveCount(2);

			await search(page, `q=no-such-text-${MARK}-anywhere`);
			await expect(page.getByText('Nothing matches these filters.')).toBeVisible();
			await expect(summary).toHaveCount(0);
			await expect(page.getByText(/annotated/)).toHaveCount(0);
			await expect(exports).toHaveCount(0);
			await expect(page.getByText('Export:')).toHaveCount(0);
		});

		test('treats % and _ in the search as plain characters', async ({ page }) => {
			await search(page, '');
			await applyFilters(page, { q: '100% of the sure_thing' });
			expect(await titles(page)).toEqual([RUST]);
			await applyFilters(page, { q: 'sure%thing' });
			expect(await titles(page)).toEqual([]);
			await applyFilters(page, { q: 'sure_thin_' });
			expect(await titles(page)).toEqual([]);
		});

		test('filters by kind, color, feed and note, alone and combined', async ({ page }) => {
			await search(page, '');
			await applyFilters(page, { q: MARK, kind: 'article' });
			expect((await titles(page)).sort()).toEqual([POSTGRES, RUST].sort());

			await applyFilters(page, { kind: 'highlight' });
			expect((await titles(page)).sort()).toEqual([RUST, WASM, PAGE_TITLE, `Other ${MARK}`].sort());
			await expect(group(page, RUST).locator('.articlenotes-notes-note')).toHaveCount(0);

			await applyFilters(page, { kind: '', color: 'pink' });
			expect(await titles(page)).toEqual([PAGE_TITLE]);
			await expect(group(page, PAGE_TITLE).locator('.articlenotes-notes-note')).toHaveCount(0);

			await applyFilters(page, { color: '', hasNote: true, kind: 'highlight' });
			expect((await titles(page)).sort()).toEqual([RUST, WASM].sort());

			await applyFilters(page, { hasNote: false, kind: '', feed: FEED });
			expect((await titles(page)).sort()).toEqual([POSTGRES, RUST, WASM].sort());

			await applyFilters(page, { kind: 'highlight', color: 'blue', hasNote: true });
			expect(await titles(page)).toEqual([WASM]);

			await applyFilters(page, { kind: 'page', color: '' });
			expect(await titles(page)).toEqual([]);
		});

		test('keeps the filters when paging', async ({ page }) => {
			await search(page, `q=${MARK}`);
			await expect(groups(page)).toHaveCount(25);
			await expect(page.getByText('Page 1 of 2')).toBeVisible();
			await page.getByRole('link', { name: 'Next' }).click();
			await expect(page).toHaveURL(/page=2/);
			await expect(page).toHaveURL(new RegExp(`q=${MARK}`));
			await expect(groups(page)).toHaveCount(6);
			await expect(page.getByText('Page 2 of 2')).toBeVisible();

			await applyFilters(page, { kind: 'page' });
			await expect(page.getByText('Page 1 of 2')).toBeVisible();
			await page.getByRole('link', { name: 'Next' }).click();
			await expect(page).toHaveURL(/kind=page/);
			await expect(page).toHaveURL(/page=2/);
			await expect(groups(page)).toHaveCount(2);
			await page.getByRole('link', { name: 'Previous' }).click();
			await expect(page).toHaveURL(/kind=page/);
			await expect(groups(page)).toHaveCount(25);
		});

		test('exports what the filters match', async ({ page }) => {
			await search(page, `q=${MARK}&kind=page`);
			const markdown = page.getByRole('link', { name: 'Markdown' });
			await expect(markdown).toHaveAttribute('href', /format=markdown/);
			await expect(markdown).toHaveAttribute('href', /kind=page/);
			const md = await page.request.get(new URL((await markdown.getAttribute('href'))!, page.url()).href);
			expect(md.status()).toBe(200);
			expect(md.headers()['content-disposition']).toMatch(/^attachment; filename="articlenotes-\d{4}-\d\d-\d\d\.md"$/);
			const text = await md.text();
			expect(text).toContain('# ArticleNotes');
			expect(text).toContain(`## [${PAGE_TITLE}](https://example.org/${MARK}-page)`);
			expect(text).toContain(`${MARK} page-note-body`);
			expect(text).not.toContain('note-token-alpha');

			await search(page, `q=${MARK}&kind=highlight`);
			const json = await page.request.get(new URL((await page.getByRole('link', { name: 'JSON (W3C Web Annotation)' }).getAttribute('href'))!, page.url()).href);
			expect(json.status()).toBe(200);
			expect(json.headers()['content-type']).toContain('application/ld+json');
			const collection = (await json.json()) as { '@context': string; type: string; first: { items: Record<string, any>[] } };
			expect(collection['@context']).toBe('http://www.w3.org/ns/anno.jsonld');
			expect(collection.type).toBe('AnnotationCollection');
			const quotes = collection.first.items.map((item) => item.target.selector[0].exact);
			expect(quotes.sort()).toEqual(['async closure ergonomics', `${MARK} other quote`, `${MARK} page quote`, `ghostquote ${MARK} vanished`].sort());
			for (const item of collection.first.items) {
				expect(item.type).toBe('Annotation');
				expect(item.target.source).toMatch(/^https?:\/\//);
			}
		});

		test('opens the article at the highlight', async ({ page }) => {
			await search(page, 'q=component+model');
			const item = group(page, WASM).locator('.articlenotes-hl-item');
			await expect(item.locator('.articlenotes-hl-quote')).toHaveText('component model');
			await expect(item.locator('.articlenotes-orphan')).toHaveCount(0);
			await item.getByRole('link', { name: 'Open the article at the highlight: component model' }).click();
			await expect(page).toHaveURL(/articlenotes_hl=/);
			const article = page.locator('.flux', { hasText: WASM }).first();
			await expect(article).toHaveClass(/current/);
			const mark = article.locator('mark.articlenotes-hl', { hasText: 'component model' });
			await expect(mark).toHaveText('component model');
			await expect(mark).toBeInViewport();
			await expect(article.locator('.articlenotes-panel .articlenotes-notes-link')).toHaveText('All my notes');
			await article.locator('.articlenotes-panel .articlenotes-notes-link').click();
			await expect(page.getByRole('heading', { level: 1, name: 'Notes' })).toBeVisible();
		});
	});
}

for (const viewport of [{ width: 1440, height: 900 }, { width: 390, height: 844 }]) {
	test.describe(`link buttons at ${viewport.width}px`, () => {
		test.use({ viewport, hasTouch: viewport.width < 600, isMobile: viewport.width < 600 });

		test('centres the label of Open article at this highlight', async ({ page }) => {
			await search(page, `q=${MARK}`);
			const link = group(page, RUST).locator('.articlenotes-notes-jump').first();
			const box = (await link.boundingBox())!;
			const textBox = await link.evaluate((el) => {
				const range = document.createRange();
				range.selectNodeContents(el);
				const r = range.getBoundingClientRect();
				return { y: r.y, height: r.height };
			});
			expect(Math.abs(textBox.y + textBox.height / 2 - (box.y + box.height / 2))).toBeLessThanOrEqual(2);
		});
	});
}

test.describe('filters on a phone', () => {
	test.use({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true });

	test('keeps the search and collapses the other filters so the first note shows', async ({ page }) => {
		await search(page, `q=${MARK}`);
		const form = page.getByRole('search');
		await expect(form.getByLabel('Search')).toBeVisible();
		await expect(form.getByRole('button', { name: 'Apply filters' })).toBeVisible();
		await expect(form.getByLabel('Kind')).toBeHidden();
		await expect(groups(page).first().getByRole('heading', { level: 2 })).toBeInViewport();

		await form.getByText('Filters', { exact: true }).click();
		await expect(form.getByLabel('Kind')).toBeVisible();
		await expect(form.getByLabel('Only with a note')).toBeVisible();
	});

	test('shows the other filters when one of them is active', async ({ page }) => {
		await search(page, `q=${MARK}&kind=page`);
		await expect(page.getByRole('search').getByLabel('Kind')).toHaveValue('page');
		await expect(page.getByRole('search').getByLabel('Kind')).toBeVisible();
	});

	test('gives the small controls a 44px tap height', async ({ page }) => {
		await search(page, `q=${MARK}`);
		const form = page.getByRole('search');
		await form.getByText('Filters', { exact: true }).click();
		const targets = [
			form.getByText('Filters', { exact: true }),
			form.locator('.articlenotes-notes-check'),
			page.getByRole('link', { name: 'Markdown' }),
			page.getByRole('link', { name: 'JSON (W3C Web Annotation)' }),
			group(page, RUST).locator('.articlenotes-notes-note').getByText('Edit', { exact: true }),
			group(page, RUST).locator('.articlenotes-notes-jump'),
		];
		for (const target of targets) {
			const box = (await target.boundingBox())!;
			expect(box.height, await target.innerText()).toBeGreaterThanOrEqual(44);
		}
	});
});

test.describe('filters on a desktop', () => {
	test.use({ viewport: { width: 1440, height: 900 } });

	test('shows every filter without a toggle', async ({ page }) => {
		await search(page, '');
		const form = page.getByRole('search');
		await expect(form.getByLabel('Kind')).toBeVisible();
		await expect(form.getByLabel('Only with a note')).toBeVisible();
		await expect(form.getByText('Filters', { exact: true })).toBeHidden();
	});
});

for (const viewport of viewports) {
	test.describe(`edits at ${viewport.name}`, () => {
		test.use({ viewport: { width: viewport.width, height: viewport.height } });

		test('edits, recolors and deletes annotations in place', async ({ page }) => {
			const tag = `npgedit${viewport.width}`;
			await putNote(10, `${tag} original note`);
			const uuid = await freshHighlight(10, 'compile-time checks', { color: 'yellow', note: `${tag} hl note` });
			await putPageNote(`https://example.org/${tag}`, `${tag} page original`, `Edit ${tag}`);

			await search(page, `q=${tag}`);
			const article = group(page, 'Type-safe SQL without an ORM');
			await expect(article.locator('.articlenotes-notes-note')).toContainText('original note');

			// The article note.
			await article.locator('.articlenotes-notes-note').getByText('Edit', { exact: true }).click();
			await article.locator('.articlenotes-notes-note textarea').fill(`${tag} changed <b>note</b>`);
			await article.locator('.articlenotes-notes-note').getByRole('button', { name: 'Save' }).click();
			await expect(page.getByText('Saved.')).toBeVisible();
			await expect(page).toHaveURL(new RegExp(`q=${tag}`));
			await page.reload();
			await expect(group(page, 'Type-safe SQL without an ORM').locator('.articlenotes-notes-note')).toContainText(`${tag} changed <b>note</b>`);

			// The highlight: color and note.
			const item = group(page, 'Type-safe SQL without an ORM').locator(`.articlenotes-hl-item[data-uuid="${uuid}"]`);
			await item.getByText('Edit', { exact: true }).click();
			await item.getByLabel('Color').selectOption('pink');
			await item.locator('textarea').fill(`${tag} new hl note`);
			await item.getByRole('button', { name: 'Save' }).click();
			await expect(page.getByText('Saved.')).toBeVisible();
			const changed = group(page, 'Type-safe SQL without an ORM').locator(`.articlenotes-hl-item[data-uuid="${uuid}"]`);
			await expect(changed).toHaveAttribute('data-color', 'pink');
			await expect(changed.locator('.articlenotes-hl-note')).toHaveText(`${tag} new hl note`);

			// The page note.
			const pageGroup = group(page, `Edit ${tag}`);
			await pageGroup.getByText('Edit', { exact: true }).click();
			await pageGroup.locator('textarea').fill(`${tag} page changed`);
			await pageGroup.getByRole('button', { name: 'Save' }).click();
			await expect(group(page, `Edit ${tag}`).locator('.articlenotes-notes-note')).toContainText('page changed');
			await page.reload();
			await expect(group(page, `Edit ${tag}`).locator('.articlenotes-notes-note')).toContainText('page changed');

			// Labels per kind, and Cancel.
			const noteBox = group(page, 'Type-safe SQL without an ORM').locator('.articlenotes-notes-note');
			await noteBox.getByText('Edit', { exact: true }).click();
			await expect(noteBox.getByRole('textbox', { name: 'Note', exact: true })).toBeVisible();
			await expect(noteBox.locator('.articlenotes-note-text')).toBeHidden();
			// Only the form shows: no stale Edit toggle, and "Note" once (the label, not also the heading).
			await expect(noteBox.locator('summary')).toBeHidden();
			await expect(noteBox.locator('h3')).toBeHidden();
			await expect(noteBox.getByText('Note', { exact: true }).filter({ visible: true })).toHaveCount(1);
			await noteBox.getByRole('textbox').fill('discarded');
			await noteBox.getByRole('button', { name: 'Cancel' }).click();
			await expect(noteBox.locator('details')).not.toHaveAttribute('open', '');
			await expect(noteBox.locator('summary')).toBeVisible();
			await expect(noteBox.locator('summary')).toBeFocused();
			await expect(noteBox.locator('h3')).toBeVisible();
			await expect(noteBox.locator('.articlenotes-note-text')).toContainText('changed');
			await expect(noteBox.locator('.articlenotes-note-text')).not.toContainText('discarded');
			await group(page, `Edit ${tag}`).getByText('Edit', { exact: true }).click();
			await expect(group(page, `Edit ${tag}`).getByRole('textbox', { name: 'Page note', exact: true })).toBeVisible();
			await group(page, `Edit ${tag}`).getByRole('button', { name: 'Cancel' }).click();
			await changed.getByText('Edit', { exact: true }).click();
			await expect(changed.getByRole('textbox', { name: 'Note on this highlight' })).toBeVisible();
			await expect(changed.locator('summary')).toBeHidden();
			await expect(changed.locator('.articlenotes-notes-jump')).toBeVisible();
			await changed.getByRole('button', { name: 'Cancel' }).click();
			await expect(changed.locator('summary')).toBeVisible();
			await expect(changed.locator('summary')).toBeFocused();
			await changed.getByText('Edit', { exact: true }).click();

			// Deleting: dismissing keeps the item, accepting deletes it.
			page.removeAllListeners('dialog');
			const messages: string[] = [];
			const answer = (accept: boolean) => page.once('dialog', (dialog) => {
				messages.push(dialog.message());
				return accept ? dialog.accept() : dialog.dismiss();
			});
			await expect(changed.getByRole('button', { name: 'Delete' })).toHaveClass(/btn-attention/);
			answer(false);
			await changed.getByRole('button', { name: 'Delete' }).click();
			await expect(page.locator('.articlenotes-hl-item')).toHaveCount(1);
			answer(true);
			await changed.getByRole('button', { name: 'Delete' }).click();
			await expect(page.getByText('Deleted.')).toBeVisible();
			await expect(page.locator('.articlenotes-hl-item')).toHaveCount(0);
			const note = group(page, 'Type-safe SQL without an ORM').locator('.articlenotes-notes-note');
			await note.getByText('Edit', { exact: true }).click();
			answer(false);
			await note.getByRole('button', { name: 'Delete' }).click();
			await expect(note).toBeVisible();
			answer(true);
			await note.getByRole('button', { name: 'Delete' }).click();
			await expect(group(page, 'Type-safe SQL without an ORM')).toHaveCount(0);
			const pageNote = group(page, `Edit ${tag}`).locator('.articlenotes-notes-note');
			await pageNote.getByText('Edit', { exact: true }).click();
			answer(false);
			await pageNote.getByRole('button', { name: 'Delete' }).click();
			await expect(pageNote).toBeVisible();
			answer(true);
			await pageNote.getByRole('button', { name: 'Delete' }).click();
			await expect(page.getByText('Nothing matches these filters.')).toBeVisible();
			expect(messages).toEqual([
				'Delete this highlight and its note?', 'Delete this highlight and its note?',
				'Delete your note on this article?', 'Delete your note on this article?',
				'Delete your note on this page?', 'Delete your note on this page?',
			]);
		});
	});
}
