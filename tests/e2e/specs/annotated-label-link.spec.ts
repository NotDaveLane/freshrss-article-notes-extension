import { expect, test, type Locator, type Page } from '../support/test';
import { basicAuth, openArticle, signIn, throwawayApiPassword, throwawayUser } from '../support/freshrss';

const ARTICLE = 'PostgreSQL 18 brings asynchronous I/O to sequential scans';
const LINK = /^Show annotated articles in FreshRSS/;

async function saveSettings(page: Page, enabled: boolean, name: string): Promise<void> {
	await page.goto('/i/?c=ArticleNotes&a=settings');
	await page.getByLabel('Add a label to articles I annotate').setChecked(enabled);
	await page.getByLabel('Label name').fill(name);
	await page.getByRole('button', { name: 'Submit' }).click();
	await expect(page.locator('.notification.good')).toBeVisible();
}

async function saveNote(article: Locator, text: string): Promise<void> {
	await article.locator('.articlenotes-trigger').click();
	const editor = article.locator('.articlenotes-editor');
	await editor.getByLabel('Your note on this article').fill(text);
	await editor.getByRole('button', { name: 'Save' }).click();
	await expect(editor).toHaveCount(0);
}

/** The label link on the settings page and on the Notes page. */
function settingsLink(page: Page): Locator {
	return page.locator('main').getByRole('link', { name: LINK });
}

test.describe.configure({ mode: 'serial' });

// A label name of its own, so that "not created yet" holds whatever other specs did with "Annotated" (and without
// "Annotated" in it, which other specs look for in the sidebar).
const name = `Label link ${Date.now()}`;
let entryId = '';

test.beforeEach(async ({ page }) => {
	await signIn(page, throwawayUser());
});

// Leaves the shared throwaway user as other specs expect it: no note on the article, default label settings.
test.afterAll(async ({ browser, playwright }) => {
	if (entryId !== '') {
		const api = await playwright.request.newContext({
			baseURL: process.env.BASE_URL ?? 'http://freshrss',
			extraHTTPHeaders: basicAuth(throwawayUser().user, throwawayApiPassword()),
		});
		expect((await api.delete(`/api/misc.php/ArticleNotes/v1/notes/${entryId}`)).ok()).toBe(true);
		await api.dispose();
	}
	const context = await browser.newContext({ baseURL: process.env.BASE_URL ?? 'http://freshrss' });
	const page = await context.newPage();
	await signIn(page, throwawayUser());
	await saveSettings(page, true, 'Annotated');
	await context.close();
});

test('no link while the label has not been created', async ({ page }) => {
	await saveSettings(page, true, name);
	await expect(settingsLink(page)).toHaveCount(0);
	await expect(page.locator('main')).toContainText('the sidebar shows labels only while they have unread articles');
	await expect(page.locator('main')).not.toContainText('listed under this label in the sidebar');
	await page.goto('/i/?c=ArticleNotes&a=notes');
	await expect(settingsLink(page)).toHaveCount(0);
});

test('the Notes page and the settings page link to the label view, which lists the read annotated article', async ({ page }) => {
	await page.goto('/i/?state=3');
	const article = await openArticle(page, ARTICLE);
	await saveNote(article, 'note for the label link');
	entryId = ((await article.getAttribute('id')) ?? '').replace(/^flux_/, '');
	// Annotated articles are nearly always read, and FreshRSS's sidebar then hides their label.
	if (/not_read/.test((await article.getAttribute('class')) ?? '')) {
		await article.locator('.flux_header a.read').first().click();
	}
	await expect(article).not.toHaveClass(/not_read/);
	await page.goto('/i/?state=3');
	await expect(page.locator('.flux', { hasText: ARTICLE }).first()).not.toHaveClass(/not_read/);

	await page.goto('/i/?c=ArticleNotes&a=settings');
	await expect(settingsLink(page)).toHaveText(`Show annotated articles in FreshRSS (label “${name}”)`);
	await expect(settingsLink(page)).toHaveAttribute('href', /get=t_\d+&state=3/);

	await page.goto('/i/?c=ArticleNotes&a=notes');
	await settingsLink(page).click();
	await expect(page).toHaveURL(/get=t_\d+/);
	await expect(page.locator('.flux', { hasText: ARTICLE })).toHaveCount(1);
});

test('no link while the label is off', async ({ page }) => {
	await saveSettings(page, false, name);
	await expect(settingsLink(page)).toHaveCount(0);
	await page.goto('/i/?c=ArticleNotes&a=notes');
	await expect(settingsLink(page)).toHaveCount(0);
});
