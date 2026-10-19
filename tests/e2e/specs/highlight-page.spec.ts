import { expect, test, type Page } from '../support/test';
import { basicAuth, throwawayApiPassword, throwawayUser } from '../support/freshrss';
import { serveExamples, setAllowedOrigins, type ExampleServer } from '../support/clients';

// examples/highlight-page.html, served from an allowed origin: highlights its own text, stores them through the API, and
// redraws them after a reload (the shape of a browser-extension content script).
const API = '/api/misc.php/ArticleNotes/v1';

let server: ExampleServer;

test.describe.configure({ mode: 'serial' });

test.beforeAll(async ({ browser }) => {
	server = await serveExamples();
	const page = await browser.newPage();
	await setAllowedOrigins(page, server.origin);
	await page.close();
});

test.afterAll(async ({ browser }) => {
	const page = await browser.newPage();
	await setAllowedOrigins(page, '');
	await page.close();
	await server.close();
});

async function selectText(page: Page, text: string): Promise<void> {
	await page.evaluate((wanted) => {
		const walker = document.createTreeWalker(document.querySelector('#content') as Node, NodeFilter.SHOW_TEXT);
		for (let node = walker.nextNode(); node !== null; node = walker.nextNode()) {
			const start = (node.textContent ?? '').indexOf(wanted);
			if (start >= 0) {
				const range = document.createRange();
				range.setStart(node, start);
				range.setEnd(node, start + wanted.length);
				const selection = window.getSelection() as Selection;
				selection.removeAllRanges();
				selection.addRange(range);
				return;
			}
		}
		throw new Error(`"${wanted}" is not in the page`);
	}, text);
}

test('the page highlights its text through the API, and redraws it after a reload', async ({ page, request }) => {
	const user = throwawayUser();
	const auth = basicAuth(user.user, throwawayApiPassword());
	const pageUrl = `${server.origin}/highlight-page.html`;

	await page.goto(pageUrl);
	await page.locator('#base-url').fill(process.env.BASE_URL ?? 'http://freshrss');
	await page.locator('#user').fill(user.user);
	await page.locator('#api-password').fill(throwawayApiPassword());
	await page.getByRole('button', { name: 'Connect' }).click();
	await expect(page.locator('#status')).toContainText('Connected');
	await expect(page.locator('mark.articlenotes-hl')).toHaveCount(0);

	await page.locator('#color').selectOption('green');
	await selectText(page, 'brown fox jumps over');
	await page.getByRole('button', { name: 'Highlight the selected text' }).click();
	const mark = page.locator('mark.articlenotes-hl');
	await expect(mark).toHaveText('brown fox jumps over');
	await expect(mark).toHaveAttribute('data-color', 'green');

	// Stored on the server, as a page highlight with the selector the anchoring library described.
	const stored = await request.get(`${API}/highlights`, { headers: auth, params: { url: pageUrl } });
	expect(stored.status()).toBe(200);
	const highlights = ((await stored.json()) as { highlights: { uuid: string; source: string; color: string; selector: { quote: { exact: string } } }[] }).highlights;
	expect(highlights).toHaveLength(1);
	expect(highlights[0]).toMatchObject({ source: 'page', color: 'green' });
	expect(highlights[0].selector.quote.exact).toBe('brown fox jumps over');

	// A highlight whose passage is not on the page is listed, not dropped.
	const orphan = await request.post(`${API}/highlights`, {
		headers: auth,
		data: { url: pageUrl, source: 'page', selector: { quote: { exact: 'a sentence that was removed' } } },
	});
	expect(orphan.status()).toBe(201);

	await page.reload();
	await expect(page.locator('#status')).toContainText('2 highlight(s)');
	await expect(page.locator('mark.articlenotes-hl')).toHaveText('brown fox jumps over');
	await expect(page.locator('#orphans li')).toHaveText('Passage not found: a sentence that was removed');

	for (const highlight of [...highlights, { uuid: ((await orphan.json()) as { highlight: { uuid: string } }).highlight.uuid }]) {
		expect((await request.delete(`${API}/highlights/${highlight.uuid}`, { headers: auth })).status()).toBe(204);
	}
});
