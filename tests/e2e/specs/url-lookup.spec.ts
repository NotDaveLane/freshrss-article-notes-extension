import { expect, test, type APIResponse } from '@playwright/test';
import { basicAuth as basic, openArticle, signIn, throwawayApiPassword, throwawayUser } from '../support/freshrss';

const API = '/api/misc.php/ArticleNotes/v1';
const TITLE = 'Rust 1.90 stabilizes async closures in more places';
const PAGE_URL = 'http://fixtures/articles/1';
// The same page as a browser extension would report it: other scheme, `www.`, tracking parameters, trailing slash, fragment.
const VISITED_URL = 'https://www.fixtures/articles/1/?utm_source=newsletter&fbclid=abc#comments';

interface Match {
	entry_id: string;
	title: string;
	url: string;
	feed: { url: string };
	note: { note: string; label_applied: boolean; url: string; normalized_url: string } | null;
}

async function json(response: APIResponse, status = 200): Promise<Record<string, unknown>> {
	expect(response.status()).toBe(status);
	expect(response.headers()['content-type']).toBe('application/json; charset=UTF-8');
	return (await response.json()) as Record<string, unknown>;
}

test('a browser extension looks a page up, writes a note, and deletes it again', async ({ page, request }) => {
	const user = throwawayUser();
	const auth = basic(user.user, throwawayApiPassword());
	const headers = { ...auth, 'Content-Type': 'application/json' };

	// 1. The visited page is a FreshRSS article, and has no note yet.
	const lookup = await json(await request.get(`${API}/lookup`, { headers: auth, params: { url: VISITED_URL } }));
	expect(lookup.url).toBe(VISITED_URL);
	expect(lookup.normalized_url).toBe('https://fixtures/articles/1');
	expect(lookup.index_complete).toBe(true);
	const matches = lookup.matches as Match[];
	expect(matches).toHaveLength(1);
	expect(matches[0].title).toBe(TITLE);
	expect(matches[0].url).toBe(PAGE_URL);
	expect(matches[0].feed.url).toBe('http://fixtures/feed.xml');
	expect(matches[0].note).toBeNull();
	const entryId = matches[0].entry_id;

	// 2. PUT creates the note (201 + ETag); no CSRF token, no cookie.
	const created = await request.put(`${API}/notes/${entryId}`, { headers, data: { note: 'Written from the page\nsecond line' } });
	expect(created.status()).toBe(201);
	const etag = created.headers()['etag'];
	expect(etag).toMatch(/^"\d+-\d+"$/);
	expect(((await created.json()) as { note: { note: string; label_applied: boolean } }).note).toMatchObject({
		note: 'Written from the page\nsecond line',
		label_applied: true,
	});

	// A stale If-Match is refused; the current one updates the note.
	expect((await request.put(`${API}/notes/${entryId}`, { headers: { ...headers, 'If-Match': '"0-0"' }, data: { note: 'nope' } })).status()).toBe(412);
	const updated = await request.put(`${API}/notes/${entryId}`, {
		headers: { ...headers, 'If-Match': etag },
		data: { note: 'Edited from the page' },
	});
	expect(updated.status()).toBe(200);

	// 3. The lookup now carries the note.
	const again = await json(await request.get(`${API}/lookup`, { headers: auth, params: { url: PAGE_URL } }));
	expect((again.matches as Match[])[0].note).toMatchObject({ note: 'Edited from the page', normalized_url: 'https://fixtures/articles/1' });

	// 4. The FreshRSS UI shows the note under the article, and the article carries the "Annotated" label.
	await signIn(page, user);
	await page.goto('/i/?state=3');
	const article = await openArticle(page, TITLE);
	await expect(article.locator('.articlenotes-note')).toContainText('Edited from the page');
	const label = page.locator('#aside_feed').getByRole('link', { name: /Annotated/ });
	await expect(label).toBeVisible();
	await page.goto(`/i/${await label.getAttribute('href')}&state=3`);
	await expect(page.locator('.flux', { hasText: TITLE })).toHaveCount(1);

	// 5. DELETE removes the note (204), once.
	const current = (await request.get(`${API}/notes/${entryId}`, { headers: auth })).headers()['etag'];
	expect((await request.delete(`${API}/notes/${entryId}`, { headers: { ...auth, 'If-Match': current } })).status()).toBe(204);
	expect((await request.delete(`${API}/notes/${entryId}`, { headers: auth })).status()).toBe(404);
	const gone = await json(await request.get(`${API}/lookup`, { headers: auth, params: { url: PAGE_URL } }));
	expect((gone.matches as Match[])[0].note).toBeNull();

	// A URL the browser has not cached: FreshRSS's Last-Modified has a one-second resolution and API requests have no
	// session (part of its ETag), so the same URL fetched in the same second as the write could revalidate as 304.
	await page.goto(`/i/${await label.getAttribute('href')}&state=3&after=delete`);
	await expect(page.locator('#stream')).toBeVisible();
	await expect(page.locator('.flux', { hasText: TITLE })).toHaveCount(0);
});

test('write requests are validated and need API credentials, never a session', async ({ page, request }) => {
	const user = throwawayUser();
	const auth = basic(user.user, throwawayApiPassword());
	const lookup = await json(await request.get(`${API}/lookup`, { headers: auth, params: { url: PAGE_URL } }));
	const entryId = (lookup.matches as Match[])[0].entry_id;
	const code = async (response: APIResponse, status: number): Promise<unknown> =>
		((await json(response, status)).error as { code: string }).code;

	expect(await code(await request.put(`${API}/notes/${entryId}`, { headers: { ...auth, 'Content-Type': 'text/plain' }, data: 'x' }), 415)).toBe('unsupported_media_type');
	expect(await code(await request.put(`${API}/notes/${entryId}`, { headers: { ...auth, 'Content-Type': 'application/json' }, data: Buffer.from('{nope') }), 400)).toBe('invalid_json');
	expect(await code(await request.put(`${API}/notes/${entryId}`, { headers: { ...auth, 'Content-Type': 'application/json' }, data: { note: 'x'.repeat(70000) } }), 413)).toBe('payload_too_large');
	const post = await request.post(`${API}/notes/${entryId}`, { headers: auth });
	expect(await code(post, 405)).toBe('method_not_allowed');
	expect(post.headers()['allow']).toBe('GET, HEAD, PUT, DELETE');
	expect(await code(await request.get(`${API}/lookup`, { headers: auth, params: { url: 'ftp://fixtures/a' } }), 400)).toBe('invalid_parameter');

	// A signed-in browser session does not authorize API writes.
	await signIn(page, user);
	const viaSession = await page.request.put(`${API}/notes/${entryId}`, { headers: { 'Content-Type': 'application/json' }, data: { note: 'csrf' } });
	expect(await code(viaSession, 401)).toBe('unauthorized');
	const check = await request.get(`${API}/notes/${entryId}`, { headers: auth });
	expect(check.status() === 404 || ((await check.json()) as { note: { note: string } }).note.note !== 'csrf').toBe(true);
});
