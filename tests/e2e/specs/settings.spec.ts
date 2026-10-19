import { expect, test, type Locator, type Page } from '@playwright/test';
import { signIn, throwawayUser } from '../support/freshrss';

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
	await page.locator('#aside_feed a[href*="c=ArticleNotes"]').click();
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
	// The dev instance shows PHP warnings: the page must render without any (e.g. a missing FreshRSS_View property).
	await expect(page.locator('body')).not.toContainText(/(Warning|Notice|Deprecated): /);
});

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
