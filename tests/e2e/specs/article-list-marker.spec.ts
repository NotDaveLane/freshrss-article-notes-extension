import { expect, test, type APIRequestContext } from '../support/test';
import { basicAuth, openArticle, signIn, throwawayApiPassword, throwawayUser } from '../support/freshrss';

const API = '/api/misc.php/ArticleNotes/v1';
const TITLE = 'The hidden costs of microservices';
const URL = 'http://fixtures/articles/9';

function auth(): Record<string, string> {
	return basicAuth(throwawayUser().user, throwawayApiPassword());
}

async function entryId(request: APIRequestContext): Promise<string> {
	const response = await request.get(`${API}/lookup`, { headers: auth(), params: { url: URL } });
	return ((await response.json()) as { matches: { entry_id: string }[] }).matches[0].entry_id;
}

async function clear(request: APIRequestContext, id: string): Promise<void> {
	await request.delete(`${API}/notes/${id}`, { headers: auth() });
	const list = await request.get(`${API}/highlights`, { headers: auth(), params: { entry_id: id } });
	for (const highlight of ((await list.json()) as { highlights: { uuid: string }[] }).highlights) {
		await request.delete(`${API}/highlights/${highlight.uuid}`, { headers: auth() });
	}
}

/** The list URL with a throwaway parameter: FreshRSS answers a repeated request with 304 though a note changed. */
function listUrl(): string {
	return `/i/?state=3&r=${Date.now()}`;
}

test.beforeEach(async ({ page, request }) => {
	await clear(request, await entryId(request));
	await signIn(page, throwawayUser());
});

test.afterAll(async ({ playwright }) => {
	const request = await playwright.request.newContext({ baseURL: process.env.BASE_URL ?? 'http://freshrss' });
	await clear(request, await entryId(request));
	await request.dispose();
});

test('rows of annotated articles carry a marker that follows saves and deletes', async ({ page, request }) => {
	const id = await entryId(request);
	await page.goto(listUrl());
	const row = page.locator('.flux', { hasText: TITLE }).first();
	await expect(row.locator('.flux_header .articlenotes-header-mark')).toHaveCount(0);

	const response = await request.put(`${API}/notes/${id}`, { headers: auth(), data: { note: 'marker note' } });
	expect(response.ok()).toBe(true);
	await request.post(`${API}/highlights`, {
		headers: auth(), data: { entry_id: id, source: 'freshrss', selector: { quote: { exact: 'costs' } } },
	});
	await page.goto(listUrl());
	const mark = page.locator('.flux', { hasText: TITLE }).first().locator('.flux_header .articlenotes-header-mark');
	await expect(mark).toHaveCount(1);
	await expect(mark).toContainText('Has a note');
	await expect(mark).toContainText('1 highlight');
	await expect(mark).toBeVisible();
	await expect(page.locator('.flux:not(:has(.articlenotes-marker)) .articlenotes-header-mark')).toHaveCount(0);

	// Deleting the note in the editor updates the marker without a reload.
	const article = await openArticle(page, TITLE);
	await article.locator('.articlenotes-trigger').click();
	page.once('dialog', (dialog) => void dialog.accept());
	await article.locator('.articlenotes-editor').getByRole('button', { name: 'Delete' }).click();
	await expect(article.locator('.articlenotes-editor')).toHaveCount(0);
	await expect(mark).not.toContainText('Has a note');
	await expect(mark).toContainText('1 highlight');

	await clear(request, id);
	await page.goto(listUrl());
	await expect(page.locator('.flux', { hasText: TITLE }).first().locator('.flux_header .articlenotes-header-mark')).toHaveCount(0);
});

test('saving a note adds the marker without a reload', async ({ page }) => {
	await page.goto(listUrl());
	const article = await openArticle(page, TITLE);
	await expect(article.locator('.flux_header .articlenotes-header-mark')).toHaveCount(0);
	await article.locator('.articlenotes-trigger').click();
	await article.locator('.articlenotes-editor').getByLabel('Your note on this article').fill('fresh');
	await article.locator('.articlenotes-editor').getByRole('button', { name: 'Save' }).click();
	await expect(article.locator('.flux_header .articlenotes-header-mark')).toContainText('Has a note');
});

test('the marker fits on a phone without horizontal overflow', async ({ page, request }) => {
	const id = await entryId(request);
	await request.put(`${API}/notes/${id}`, { headers: auth(), data: { note: 'x' } });
	await page.setViewportSize({ width: 390, height: 844 });
	await page.goto(listUrl());
	expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
});
