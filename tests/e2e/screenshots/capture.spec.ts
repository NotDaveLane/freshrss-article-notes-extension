import { expect, test, type Locator } from '../support/test';
import { admin, signIn } from '../support/freshrss';

const dir = process.env.SCREENSHOT_DIR ?? '/screenshots';

/** Opens an article in the normal view (clicking its title until FreshRSS marks it current). */
async function open(article: Locator): Promise<void> {
	await expect(async () => {
		if (!/current/.test((await article.getAttribute('class')) ?? '')) {
			await article.locator('.flux_header .title').click();
		}
		await expect(article).toHaveClass(/current/, { timeout: 1000 });
	}).toPass();
}

test('README screenshots', async ({ page }) => {
	await signIn(page, admin);
	await page.goto('/i/?state=3');
	const article = page.locator('.flux', { has: page.locator('.flux_header[data-website-name="Hacker News"]') }).first();
	await expect(article).toBeVisible();
	await open(article);

	// The editor, opened from the article's "Note" action.
	await article.locator('.articlenotes-trigger').click();
	await article.getByLabel('Your note on this article').fill(
		'Worth re-reading before the architecture review.\nCompare with the follow-up discussion on Lobsters.');
	await article.screenshot({ path: `${dir}/note-editor.png` });

	// The saved note under the article.
	await article.getByRole('button', { name: 'Save' }).click();
	await expect(article.locator('.articlenotes-note')).toBeVisible();
	await article.screenshot({ path: `${dir}/note.png` });

	// Annotated articles are listed under the "Annotated" label in the sidebar.
	await page.goto('/i/?state=3');
	const href = await page.locator('#aside_feed').getByRole('link', { name: /Annotated/ }).getAttribute('href');
	await page.goto(`/i/${href}`);
	await expect(page.locator('.flux').first()).toBeVisible();
	await page.screenshot({ path: `${dir}/annotated-label.png` });

	// The per-user settings page (Configuration → Article notes).
	await page.goto('/i/?c=ArticleNotes&a=settings');
	await page.screenshot({ path: `${dir}/settings.png` });

	// Leave the dev data as it was.
	await page.goto('/i/?state=3');
	const annotated = page.locator('.flux', { has: page.locator('.articlenotes-note') }).first();
	await open(annotated);
	await annotated.locator('.articlenotes-trigger').click();
	page.once('dialog', (dialog) => dialog.accept());
	await annotated.getByRole('button', { name: 'Delete' }).click();
	await expect(annotated.locator('.articlenotes-note')).toHaveCount(0);
});
