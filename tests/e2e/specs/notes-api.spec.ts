import { expect, test, type APIResponse } from '../support/test';
import { basicAuth as basic, openArticle, signIn, throwawayApiPassword, throwawayUser } from '../support/freshrss';

const API = '/api/misc.php/ArticleNotes/v1';
const TITLE = 'The hidden costs of microservices';

interface ApiNote {
	entry_id: string;
	greader_item_id: string;
	note: string | null;
	deleted_at: number | null;
	label_applied: boolean;
	article: { title: string; url: string; exists: boolean; feed: { url: string } };
}

async function json(response: APIResponse, status = 200): Promise<Record<string, unknown>> {
	expect(response.status()).toBe(status);
	expect(response.headers()['content-type']).toBe('application/json; charset=UTF-8');
	expect(response.headers()['cache-control']).toBe('no-store');
	return (await response.json()) as Record<string, unknown>;
}

test('a note saved in the UI is served by the API, then synced as a tombstone', async ({ page, request }) => {
	const user = throwawayUser();
	const auth = basic(user.user, throwawayApiPassword());

	await signIn(page, user);
	await page.goto('/i/?state=3');
	const article = await openArticle(page, TITLE);
	await article.locator('.articlenotes-trigger').click();
	await article.getByLabel('Your note on this article').fill('API note\nfrom the UI');
	await article.getByRole('button', { name: 'Save' }).click();
	await expect(article.locator('.articlenotes-note')).toContainText('API note');

	// Basic auth with the API password, no session cookie (the `request` fixture is independent of the page).
	const list = await json(await request.get(`${API}/notes`, { headers: auth }));
	const note = (list.notes as ApiNote[]).find((candidate) => candidate.article.title === TITLE);
	expect(note).toBeDefined();
	expect(note?.note).toBe('API note\nfrom the UI');
	expect(note?.article.exists).toBe(true);
	expect(note?.label_applied).toBe(true);	// the "Annotated" label is on by default
	expect(note?.article.feed.url).toBe('http://fixtures/feed.xml');
	expect(note?.greader_item_id).toMatch(/^tag:google\.com,2005:reader\/item\/[0-9a-f]{16}$/);
	expect(typeof list.server_time).toBe('number');

	const one = await json(await request.get(`${API}/notes/${note?.entry_id}`, { headers: auth }));
	expect((one.note as ApiNote).note).toBe('API note\nfrom the UI');

	// The token returned by FreshRSS's Google Reader ClientLogin works too.
	const login = await request.post('/api/greader.php/accounts/ClientLogin', {
		form: { Email: user.user, Passwd: throwawayApiPassword() },
	});
	expect(login.status()).toBe(200);
	const token = /Auth=(\S+)/.exec(await login.text())?.[1];
	expect(token).toBeDefined();
	const status = await json(await request.get(`${API}/status`, { headers: { Authorization: `GoogleLogin auth=${token}` } }));
	expect(status.user).toBe(user.user);
	expect(status.note_count).toBeGreaterThanOrEqual(1);

	// Deleting in the UI shows up as a tombstone for a client syncing since its last server_time.
	await article.locator('.articlenotes-trigger').click();
	page.once('dialog', (dialog) => dialog.accept());
	await article.getByRole('button', { name: 'Delete' }).click();
	await expect(article.locator('.articlenotes-note')).toHaveCount(0);
	const changes = await json(await request.get(`${API}/notes?since=${list.server_time}`, { headers: auth }));
	const tombstone = (changes.notes as ApiNote[]).find((candidate) => candidate.entry_id === note?.entry_id);
	expect(tombstone?.note).toBeNull();
	expect(typeof tombstone?.deleted_at).toBe('number');
	await json(await request.get(`${API}/notes/${note?.entry_id}`, { headers: auth }), 404);
});

test('errors are JSON too', async ({ request }) => {
	const user = throwawayUser();
	const auth = basic(user.user, throwawayApiPassword());

	const webPassword = await request.get(`${API}/notes`, { headers: basic(user.user, user.password) });
	expect(await json(webPassword, 401)).toEqual({ error: { code: 'unauthorized', message: expect.any(String) } });
	expect(webPassword.headers()['www-authenticate']).toBe('Basic realm="ArticleNotes"');

	expect(((await json(await request.get(`${API}/nope`, { headers: auth }), 404)).error as { code: string }).code).toBe('not_found');

	const post = await request.post(`${API}/notes`, { headers: auth });
	expect(((await json(post, 405)).error as { code: string }).code).toBe('method_not_allowed');
	expect(post.headers()['allow']).toBe('GET, HEAD');

	const invalid = await request.get(`${API}/notes?limit=0`, { headers: auth });
	expect(((await json(invalid, 400)).error as { code: string }).code).toBe('invalid_parameter');

	const viaQuery = await json(await request.get(`/api/misc.php/v1/status?ext=ArticleNotes`, { headers: auth }));
	expect(viaQuery.user).toBe(user.user);

	const head = await request.head(`${API}/status`, { headers: auth });
	expect(head.status()).toBe(200);
	expect(head.headers()['content-type']).toBe('application/json; charset=UTF-8');
});
