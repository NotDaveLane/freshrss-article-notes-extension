import { expect, test, type Locator, type Page } from '../support/test';
import { openArticle, signIn, throwawayUser } from '../support/freshrss';

const viewports = [
	{ name: 'mobile 390x844', width: 390, height: 844 },
	{ name: 'desktop 1440x900', width: 1440, height: 900 },
];

function trigger(article: Locator): Locator {
	return article.locator('.articlenotes-trigger');
}

async function saveNote(page: Page, article: Locator, text: string): Promise<void> {
	await trigger(article).click();
	const editor = article.locator('.articlenotes-editor');
	await expect(editor.getByLabel('Your note on this article')).toBeFocused();
	await editor.getByLabel('Your note on this article').fill(text);
	await editor.getByRole('button', { name: 'Save' }).click();
	await expect(editor).toHaveCount(0);
}

test.beforeEach(async ({ page }) => {
	await signIn(page, throwawayUser());
});

for (const viewport of viewports) {
	test.describe(viewport.name, () => {
		test.use({ viewport: { width: viewport.width, height: viewport.height } });

		test('add, reload, edit and delete a note', async ({ page }) => {
			const title = `Rust 1.90 stabilizes async closures (${viewport.width})`.replace(/ \(\d+\)$/, '');
			await page.goto('/i/?state=3');
			let article = await openArticle(page, title);
			await expect(article.locator('.articlenotes-note')).toHaveCount(0);

			await saveNote(page, article, `first note ${viewport.width}\nsecond line`);
			const block = article.locator('.articlenotes-note');
			await expect(block).toContainText('Your note');
			await expect(block).toContainText(`first note ${viewport.width}`);
			await expect(block.locator('.articlenotes-note-text br')).toHaveCount(1);
			await expect(block).toContainText('Last updated');
			await expect(trigger(article)).toBeFocused();

			await page.reload();
			article = await openArticle(page, title);
			await expect(article.locator('.articlenotes-note')).toContainText(`first note ${viewport.width}`);

			await trigger(article).click();
			const editor = article.locator('.articlenotes-editor');
			const textarea = editor.getByLabel('Your note on this article');
			await expect(textarea).toHaveValue(`first note ${viewport.width}\nsecond line`);
			await expect(editor.locator('.articlenotes-counter')).toContainText('/ 20000');
			await textarea.fill('edited note');
			await editor.getByRole('button', { name: 'Save' }).click();
			await expect(article.locator('.articlenotes-note')).toContainText('edited note');
			await expect(article.locator('.articlenotes-note')).not.toContainText('first note');

			await trigger(article).click();
			page.once('dialog', (dialog) => dialog.accept());
			await article.getByRole('button', { name: 'Delete' }).click();
			await expect(article.locator('.articlenotes-note')).toHaveCount(0);
			await expect(article.locator('.articlenotes-editor')).toHaveCount(0);

			await page.reload();
			article = await openArticle(page, title);
			await expect(article.locator('.articlenotes-note')).toHaveCount(0);
			await trigger(article).click();
			await expect(article.getByRole('button', { name: 'Delete' })).toHaveCount(0);
		});
	});
}

test('cancel and Escape close the editor and return focus to the trigger', async ({ page }) => {
	await page.goto('/i/?state=3');
	const article = await openArticle(page, 'PostgreSQL 18 brings asynchronous I/O');
	await trigger(article).click();
	await expect(article.locator('.articlenotes-editor')).toHaveCount(1);
	await article.getByRole('button', { name: 'Cancel' }).click();
	await expect(article.locator('.articlenotes-editor')).toHaveCount(0);
	await expect(trigger(article)).toBeFocused();

	await trigger(article).focus();
	await page.keyboard.press('Enter');
	await expect(article.getByLabel('Your note on this article')).toBeFocused();
	await page.keyboard.type('jkrs');	// FreshRSS shortcut keys must not fire while typing
	await expect(article.getByLabel('Your note on this article')).toHaveValue('jkrs');
	await page.keyboard.press('Escape');
	await expect(article.locator('.articlenotes-editor')).toHaveCount(0);
	await expect(trigger(article)).toBeFocused();
	await expect(article.locator('.articlenotes-note')).toHaveCount(0);
});

test('note text is rendered as text, never as HTML', async ({ page }) => {
	const xss = '<script>window.__notePwned = true</script><img src=x onerror="window.__notePwned = true">';
	await page.goto('/i/?state=3');
	let article = await openArticle(page, 'A gentle introduction to WebAssembly components');
	await saveNote(page, article, xss);
	await expect(article.locator('.articlenotes-note-text')).toHaveText(xss);
	await expect(article.locator('.articlenotes-note-text script, .articlenotes-note-text img')).toHaveCount(0);

	await page.reload();
	article = await openArticle(page, 'A gentle introduction to WebAssembly components');
	await expect(article.locator('.articlenotes-note-text')).toHaveText(xss);
	expect(await page.evaluate(() => (window as unknown as { __notePwned?: boolean }).__notePwned)).toBeUndefined();

	await trigger(article).click();
	await expect(article.getByLabel('Your note on this article')).toHaveValue(xss);
});

test('a note over 20,000 characters shows an inline error and is not saved', async ({ page }) => {
	await page.goto('/i/?state=3');
	const article = await openArticle(page, 'Why we moved our CI to ephemeral containers');
	await trigger(article).click();
	const editor = article.locator('.articlenotes-editor');
	await editor.getByLabel('Your note on this article').fill('a'.repeat(20001));
	await expect(editor.locator('.articlenotes-counter')).toContainText('20001 / 20000');
	await editor.getByRole('button', { name: 'Save' }).click();
	await expect(editor.getByRole('alert')).toHaveText('The note is too long (20,000 characters at most).');
	await expect(article.locator('.articlenotes-note')).toHaveCount(0);

	await editor.getByLabel('Your note on this article').fill('a'.repeat(20000));
	await editor.getByRole('button', { name: 'Save' }).click();
	await expect(article.locator('.articlenotes-note')).toHaveCount(1);
});

test('works in the reading view', async ({ page }) => {
	await page.goto('/i/?a=reader&state=3');
	const article = page.locator('.flux', { hasText: 'SQLite as an application file format' }).first();
	await article.scrollIntoViewIfNeeded();
	await saveNote(page, article, 'reader note');
	await expect(article.locator('.articlenotes-note')).toContainText('reader note');
	await page.reload();
	await expect(page.locator('.flux', { hasText: 'SQLite as an application file format' }).locator('.articlenotes-note')).toContainText('reader note');
});

test('works in the global view', async ({ page }) => {
	await page.goto('/i/?a=global&state=3');
	await page.locator('#stream .box-content a.item-title').first().click();
	const article = await openArticle(page, 'Type-safe SQL without an ORM');
	await saveNote(page, article, 'global note');
	await expect(article.locator('.articlenotes-note')).toContainText('global note');
});

test('works for an article loaded by scrolling', async ({ page }) => {
	await page.goto('/i/?state=3&nb=3');
	await expect(page.locator('.flux').first()).toBeVisible();
	const initial = await page.locator('.flux').count();
	expect(initial).toBeLessThan(10);
	// FreshRSS loads the next batch when the end of the stream is reached; the button is the same code path.
	await page.locator('#load_more').scrollIntoViewIfNeeded();
	await expect.poll(async () => {
		if (await page.locator('#load_more').isVisible()) {
			await page.locator('#load_more').click();
		}
		return page.locator('.flux').count();
	}, { timeout: 15000 }).toBeGreaterThan(initial);
	const article = page.locator('.flux').nth(initial);
	await article.scrollIntoViewIfNeeded();
	await article.locator('.flux_header .title').click();
	await expect(article).toHaveClass(/current/);
	await saveNote(page, article, 'note on a scrolled-in article');
	await expect(article.locator('.articlenotes-note')).toContainText('note on a scrolled-in article');
});
