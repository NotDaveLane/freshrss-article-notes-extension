import { expect, test, type APIRequestContext, type Locator, type Page } from '../support/test';
import { basicAuth, journeyUser, openArticle, signIn } from '../support/freshrss';

const API = '/api/misc.php/ArticleNotes/v1';
const TITLE = 'Understanding Linux io_uring security trade-offs';

interface ApiNote {
	entry_id: string;
	note: string | null;
	updated_at: number;
	deleted_at: number | null;
	label_applied: boolean;
	article: { title: string; exists: boolean };
}

interface NotesPage {
	notes: ApiNote[];
	continuation: string | null;
	server_time: number;
}

async function getNotes(request: APIRequestContext, auth: Record<string, string>, query = ''): Promise<NotesPage> {
	const response = await request.get(`${API}/notes${query}`, { headers: auth });
	expect(response.status()).toBe(200);
	return (await response.json()) as NotesPage;
}

async function saveNote(article: Locator, text: string): Promise<void> {
	await article.locator('.articlenotes-trigger').click();
	const editor = article.locator('.articlenotes-editor');
	await editor.getByLabel('Your note on this article').fill(text);
	await editor.getByRole('button', { name: 'Save' }).click();
	await expect(editor).toHaveCount(0);
	await expect(article.locator('.articlenotes-note')).toContainText(text);
}

/** The articles listed under the "Annotated" label (the label link in the sidebar). */
async function openAnnotatedLabel(page: Page): Promise<void> {
	await page.goto('/i/?state=3');
	const link = page.locator('#aside_feed').getByRole('link', { name: /Annotated/ });
	await expect(link).toBeVisible();
	const href = await link.getAttribute('href');
	expect(href).toContain('get=t_');
	await page.goto(`/i/${href}&state=3`);
}

test('full journey: API password, note in the UI, label, API fetch, edit via since, delete and tombstone', async ({ page, request }) => {
	const user = journeyUser();
	const apiPassword = `journey-${Date.now()}`;
	const auth = basicAuth(user.user, apiPassword);

	// 1. Without an API password the API refuses the user.
	expect((await request.get(`${API}/status`, { headers: auth })).status()).toBe(401);

	// 2. The user sets an API password in Profile → External access via API.
	await signIn(page, user);
	await page.goto('/i/?c=user&a=profile');
	await page.locator('#apiPasswordPlain').fill(apiPassword);
	await page.locator('form[action*="updatePassword"]').getByRole('button', { name: 'Submit' }).click();
	await expect(page.locator('.notification.good')).toBeVisible();
	const status = await request.get(`${API}/status`, { headers: auth });
	expect(status.status()).toBe(200);
	expect(await status.json()).toMatchObject({ extension: 'ArticleNotes', user: user.user, note_count: 0 });

	// 3. A note added in the UI applies the "Annotated" label.
	await page.goto('/i/?state=3');
	let article = await openArticle(page, TITLE);
	await saveNote(article, 'First thoughts on io_uring');
	await openAnnotatedLabel(page);
	await expect(page.locator('.flux', { hasText: TITLE })).toHaveCount(1);
	await expect(page.locator('.flux')).toHaveCount(1);

	// 4. The API serves the note (HTTP Basic, no session cookie).
	const first = await getNotes(request, auth);
	expect(first.notes).toHaveLength(1);
	const note = first.notes[0];
	expect(note).toMatchObject({ note: 'First thoughts on io_uring', deleted_at: null, label_applied: true });
	expect(note.article).toMatchObject({ title: TITLE, exists: true });
	expect(first.continuation).toBeNull();

	// 5. Editing advances updated_at, and a client syncing `since` its last server_time gets the new text.
	await expect.poll(() => Math.floor(Date.now() / 1000)).toBeGreaterThan(first.server_time);
	await page.goto('/i/?state=3');
	article = await openArticle(page, TITLE);
	await saveNote(article, 'Second thoughts on io_uring');
	const edited = await getNotes(request, auth, `?since=${first.server_time}`);
	expect(edited.notes).toHaveLength(1);
	expect(edited.notes[0].entry_id).toBe(note.entry_id);
	expect(edited.notes[0].note).toBe('Second thoughts on io_uring');
	expect(edited.notes[0].updated_at).toBeGreaterThan(note.updated_at);
	expect(edited.server_time).toBeGreaterThanOrEqual(first.server_time);

	// 6. Deleting the note syncs as a tombstone and removes the label the extension applied.
	await expect.poll(() => Math.floor(Date.now() / 1000)).toBeGreaterThan(edited.server_time);
	await article.locator('.articlenotes-trigger').click();
	page.once('dialog', (dialog) => dialog.accept());
	await article.getByRole('button', { name: 'Delete' }).click();
	await expect(article.locator('.articlenotes-note')).toHaveCount(0);

	const deleted = await getNotes(request, auth, `?since=${edited.server_time}`);
	expect(deleted.notes).toHaveLength(1);
	expect(deleted.notes[0]).toMatchObject({ entry_id: note.entry_id, note: null });
	expect(deleted.notes[0].deleted_at).toEqual(expect.any(Number));
	expect((await request.get(`${API}/notes/${note.entry_id}`, { headers: auth })).status()).toBe(404);
	expect((await getNotes(request, auth)).notes).toHaveLength(0);	// live notes only without `since`

	await openAnnotatedLabel(page);
	await expect(page.locator('.flux', { hasText: TITLE })).toHaveCount(0);
});
