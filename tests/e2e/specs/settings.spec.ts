import { expect, test, type Locator, type Page } from '../support/test';
import { noApiPasswordUser, signIn, throwawayUser } from '../support/freshrss';
import { phpDiagnostics } from '../support/test';

/** Opens an article by title in the normal view and returns its element. */
async function openArticle(page: Page, title: string): Promise<Locator> {
	const article = page.locator('.flux', { hasText: title }).first();
	await expect(async () => {
		if (!/current/.test((await article.getAttribute('class')) ?? '')) {
			await article.locator('.flux_header .title').click();
		}
		await expect(article).toHaveClass(/current/, { timeout: 1000 });
	}).toPass();
	return article;
}

async function saveNote(article: Locator, text: string): Promise<void> {
	await article.locator('.articlenotes-trigger').click();
	const editor = article.locator('.articlenotes-editor');
	await editor.getByLabel('Your note on this article').fill(text);
	await editor.getByRole('button', { name: 'Save' }).click();
	await expect(editor).toHaveCount(0);
}

/** Opens the settings page through the Configuration menu. */
async function openSettings(page: Page): Promise<void> {
	await page.goto('/i/?c=configure&a=display');
	await page.locator('#aside_feed a[href*="a=settings"]').click();
	await expect(page.getByRole('heading', { name: 'Article notes' })).toBeVisible();
}

test.beforeEach(async ({ page }) => {
	await signIn(page, throwawayUser());
});

test('a non-admin user sees the settings with their defaults and the API URL', async ({ page }) => {
	await openSettings(page);
	await expect(page.getByLabel('Add a label to articles I annotate')).toBeChecked();
	await expect(page.getByLabel('Label name')).toHaveValue('Annotated');
	await expect(page.getByLabel('Your API endpoint')).toHaveValue(/\/api\/misc\.php\/ArticleNotes\/$/);
	await expect(page.locator('main')).toContainText('not touch articles that are already labelled');
	await expect(page.locator('main')).toContainText('select text in an article to highlight it');
	await expect(page.locator('main')).toContainText('Note action');
});

test('the API section explains the credentials and links to the Profile page and the API documentation', async ({ page }) => {
	await openSettings(page);
	const section = page.locator('#articlenotes_api_url').locator('xpath=ancestor::div[contains(@class, "group-controls")]');
	await expect(section).toContainText('Sign in with your user name and your API password, not your login password.');
	const profile = section.getByRole('link', { name: 'Profile → External access via API' });
	await expect(profile).toHaveAttribute('href', /c=user&a=profile/);
	const docs = section.getByRole('link', { name: 'API documentation (OpenAPI)' });
	await expect(docs).toHaveAttribute('href', /\/api\/misc\.php\/ArticleNotes\/v1\/openapi\.json$/);
	// The throwaway user has an API password and the dev instance allows API access: no warning.
	await expect(page.locator('main .alert-warn')).toHaveCount(0);

	await profile.click();
	await expect(page.locator('#apiPasswordPlain')).toBeVisible();
});

test('a user without an API password is warned', async ({ page, context }) => {
	await context.clearCookies();
	await signIn(page, noApiPasswordUser());
	await openSettings(page);
	await expect(page.locator('main .alert-warn')).toHaveText('You have no API password yet: set one under Profile → External access via API.');
});

test('Copy puts the endpoint on the clipboard and says so', async ({ page }) => {
	await page.addInitScript(() => {
		const copied: string[] = [];
		(window as unknown as { copied: string[] }).copied = copied;
		Object.defineProperty(navigator, 'clipboard', {
			configurable: true,
			value: { writeText: (text: string) => { copied.push(text); return Promise.resolve(); } },
		});
	});
	await openSettings(page);
	const status = page.getByRole('status').filter({ hasText: /Copied|Could not copy/ });
	await expect(page.locator('.articlenotes-copy-status')).toHaveAttribute('aria-live', 'polite');
	await page.getByRole('button', { name: 'Copy the API URL' }).click();
	await expect(status).toHaveText('Copied');
	const url = await page.getByLabel('Your API endpoint').inputValue();
	expect(await page.evaluate(() => (window as unknown as { copied: string[] }).copied)).toEqual([url]);
});

test('Copy reports a failure and leaves the URL selected', async ({ page }) => {
	await page.addInitScript(() => {
		Object.defineProperty(navigator, 'clipboard', {
			configurable: true,
			value: { writeText: () => Promise.reject(new Error('denied')) },
		});
	});
	await openSettings(page);
	await page.getByRole('button', { name: 'Copy the API URL' }).click();
	await expect(page.locator('.articlenotes-copy-status')).toHaveText('Could not copy: select the URL and copy it.');
	const selected = await page.getByLabel('Your API endpoint').evaluate((input: HTMLInputElement) =>
		input.value.substring(input.selectionStart ?? 0, input.selectionEnd ?? 0));
	expect(selected).toMatch(/\/api\/misc\.php\/ArticleNotes\/$/);
});

test('without the Clipboard API (plain HTTP), Copy copies the selection', async ({ page }) => {
	await openSettings(page);
	expect(await page.evaluate(() => window.isSecureContext && 'clipboard' in navigator)).toBe(false);
	await page.getByRole('button', { name: 'Copy the API URL' }).click();
	await expect(page.locator('.articlenotes-copy-status')).toHaveText('Copied');
});

for (const viewport of [{ width: 390, height: 844 }, { width: 1440, height: 900 }]) {
	test.describe(`${viewport.width}x${viewport.height}`, () => {
		test.use({ viewport });

		test('the settings page renders inside FreshRSS\'s layout without PHP diagnostics', async ({ page }) => {
			// The dev instance displays PHP diagnostics, e.g. the layout's `$this->rss_title` warning when the
			// controller renders a plain Minz_View instead of a FreshRSS_View.
			const response = await page.goto('/i/?c=ArticleNotes&a=settings');
			expect(response?.status()).toBe(200);
			const html = (await response?.text()) ?? '';
			expect(phpDiagnostics(html)).toEqual([]);
			expect(html.trimStart()).toMatch(/^<!DOCTYPE html>/i);
			await expect(page.locator('body')).not.toContainText(/(Warning|Notice|Deprecated|Fatal error):/);
			await expect(page).toHaveTitle(/Article notes/);
			await expect(page.locator('header.header')).toBeVisible();
			await expect(page.locator('header.header button.signout, header.header a.signout').first()).toBeAttached();
			await expect(page.locator('#aside_feed a[href*="a=settings"]')).toBeAttached();
			await expect(page.locator('main.post').getByRole('heading', { name: 'Article notes' })).toBeVisible();
		});

		test('the API endpoint and its Copy button fit without horizontal overflow', async ({ page }) => {
			await page.goto('/i/?c=ArticleNotes&a=settings');
			const input = page.getByLabel('Your API endpoint');
			const copy = page.getByRole('button', { name: 'Copy the API URL' });
			await expect(copy).toBeVisible();
			const fits = await input.evaluate((el: HTMLInputElement) => el.scrollWidth <= el.clientWidth);
			if (viewport.width >= 1440) {
				expect(fits).toBe(true);
			}
			const inputBox = await input.boundingBox();
			const copyBox = await copy.boundingBox();
			expect(inputBox && copyBox).toBeTruthy();
			expect(copyBox!.x + copyBox!.width).toBeLessThanOrEqual(viewport.width);
			expect(inputBox!.x).toBeGreaterThanOrEqual(0);
			expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(viewport.width);
			// The URL can be selected (to copy it by hand) even where it does not fit.
			await input.selectText();
			expect(await input.evaluate((el: HTMLInputElement) => el.selectionEnd! - el.selectionStart!))
				.toBe((await input.inputValue()).length);
		});
	});
}

test('invalid names are refused and valid settings are confirmed', async ({ page }) => {
	await openSettings(page);
	await page.getByLabel('Label name').evaluate((input: HTMLInputElement) => input.removeAttribute('maxlength'));
	await page.getByLabel('Label name').fill('x'.repeat(64));
	await page.getByRole('button', { name: 'Submit' }).click();
	await expect(page.locator('.notification.bad')).toContainText('1 to 63 characters');
	await expect(page.getByLabel('Label name')).toHaveValue('Annotated');
});

test('turning the label off keeps new annotated articles unlabelled; the default labels them', async ({ page }) => {
	await openSettings(page);
	await page.getByLabel('Add a label to articles I annotate').uncheck();
	await page.getByRole('button', { name: 'Submit' }).click();
	await expect(page.locator('.notification.good')).toBeVisible();
	await expect(page.getByLabel('Add a label to articles I annotate')).not.toBeChecked();

	await page.goto('/i/?state=3');
	const unlabelled = await openArticle(page, 'The hidden costs of microservices');
	await saveNote(unlabelled, 'unlabelled note');

	await openSettings(page);
	await page.getByLabel('Add a label to articles I annotate').check();
	await page.getByRole('button', { name: 'Submit' }).click();
	await expect(page.locator('.notification.good')).toBeVisible();

	await page.goto('/i/?state=3');
	const labelled = await openArticle(page, 'PHP 8.5 pipe operator in practice');
	await saveNote(labelled, 'labelled note');
	await page.goto('/i/?state=3');
	const sidebar = page.locator('#aside_feed');
	await expect(sidebar.getByRole('link', { name: /Annotated/ })).toBeVisible();
	const href = await sidebar.getByRole('link', { name: /Annotated/ }).getAttribute('href');
	expect(href).toContain('get=t_');
	await page.goto(`/i/${href}`);
	await expect(page.locator('.flux', { hasText: 'PHP 8.5 pipe operator in practice' })).toHaveCount(1);
	await expect(page.locator('.flux', { hasText: 'The hidden costs of microservices' })).toHaveCount(0);
});
