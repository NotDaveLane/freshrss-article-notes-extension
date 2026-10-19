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

test('highlight screenshot', async ({ page }) => {
	await signIn(page, admin);
	await page.goto('/i/?state=3');
	// The first article whose content has a sentence to highlight (the dev feeds' articles are mostly links).
	const article = page.locator('.flux', { has: page.locator('.content .text') }).first();
	await open(article);
	const quote = await article.locator('.content .text').evaluate((root) => {
		const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
		for (let node = walker.nextNode(); node; node = walker.nextNode()) {
			const text = (node as Text).data;
			const start = text.search(/\S/);
			if (text.trim().length >= 12 && start !== -1) {
				const end = Math.min(text.length, start + 40);
				window.getSelection()!.setBaseAndExtent(node, start, node, end);
				return text.slice(start, end).trim();
			}
		}
		return null;
	});
	test.skip(quote === null, 'no article with text in the dev feeds');
	await page.getByRole('button', { name: 'Add note' }).click();
	await page.getByLabel('Note on this highlight').fill('Check this claim against the release notes.');
	await page.getByRole('button', { name: 'Save' }).click();
	await expect(article.locator('mark.articlenotes-hl')).not.toHaveCount(0);
	await article.screenshot({ path: `${dir}/highlights.png` });

	// Leave the dev data as it was.
	await article.locator('mark.articlenotes-hl').first().click();
	await page.getByRole('dialog').getByRole('button', { name: 'Delete' }).click();
	await expect(article.locator('mark.articlenotes-hl')).toHaveCount(0);
});

test('notes page screenshot', async ({ page }) => {
	await signIn(page, admin);
	const api = '/api/misc.php/ArticleNotes/v1';
	const headers = {
		Authorization: 'Basic ' + Buffer.from(`${admin.user}:${process.env.FRESHRSS_DEV_API_PASSWORD ?? 'freshrss-dev-api'}`).toString('base64'),
	};
	await page.goto('/i/?state=3');
	const ids = await page.locator('.flux[data-entry]').evaluateAll((items) => items.slice(0, 2).map((item) => item.getAttribute('data-entry')));
	const created = { notes: [] as string[], highlights: [] as string[], pageNotes: [] as string[] };
	const notes = ['Worth re-reading before the architecture review.', 'Compare with the follow-up discussion on Lobsters.'];
	for (const [index, id] of ids.entries()) {
		await page.request.put(`${api}/notes/${id}`, { headers, data: { note: notes[index] } });
		created.notes.push(id!);
		const response = await page.request.post(`${api}/highlights`, {
			headers,
			data: { entry_id: id, source: 'freshrss', color: ['yellow', 'green'][index], note: index === 0 ? 'Check this claim.' : '',
				selector: { quote: { exact: ['the passage worth keeping', 'another passage that moved'][index] } } },
		});
		created.highlights.push(((await response.json()) as { highlight: { uuid: string } }).highlight.uuid);
	}
	const pageUrl = 'https://example.org/docs/screenshot-page';
	await page.request.put(`${api}/page-notes`, { headers, params: { url: pageUrl }, data: { note: 'Pricing changes in March.', title: 'Example pricing page' } });
	created.pageNotes.push(pageUrl);
	const pageHighlight = await page.request.post(`${api}/highlights`, {
		headers,
		data: { url: pageUrl, title: 'Example pricing page', source: 'page', color: 'pink', selector: { quote: { exact: 'Free for personal use' } } },
	});
	created.highlights.push(((await pageHighlight.json()) as { highlight: { uuid: string } }).highlight.uuid);

	await page.goto('/i/?c=ArticleNotes&a=notes');
	await expect(page.locator('.articlenotes-notes-group').first()).toBeVisible();
	await page.screenshot({ path: `${dir}/notes-page.png` });

	// Leave the dev data as it was.
	for (const uuid of created.highlights) {
		await page.request.delete(`${api}/highlights/${uuid}`, { headers });
	}
	for (const id of created.notes) {
		await page.request.delete(`${api}/notes/${id}`, { headers });
	}
	for (const url of created.pageNotes) {
		await page.request.delete(`${api}/page-notes`, { headers, params: { url } });
	}
});
