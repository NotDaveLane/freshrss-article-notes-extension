import { expect, test } from '../support/test';
import { basicAuth, openArticle, signIn } from '../support/freshrss';

// Upgrading an install: bin/verify-upgrade runs the "before" phase with ArticleNotes 1.0.0 in extensions/, replaces it with
// the release under test, and runs the "after" phase.
const API = '/api/misc.php/ArticleNotes/v1';
const TITLE = 'Rust 1.90 stabilizes async closures in more places';
const NOTE = 'A note written with ArticleNotes 1.0.0';

function setting(name: string): string {
	const value = process.env[name];
	if (!value) {
		throw new Error(`${name} is not set; run this through bin/verify-upgrade.`);
	}
	return value;
}

const phase = setting('UPGRADE_PHASE');
const auth = basicAuth('admin', setting('RELEASE_API_PASSWORD'));

test('before: ArticleNotes 1.0.0 is installed, enabled, and holds a note', async ({ page, request }) => {
	test.skip(phase !== 'before');
	await signIn(page, { user: 'admin', password: setting('RELEASE_ADMIN_PASSWORD') });
	await page.goto('/i/?c=extension');
	const row = page.locator('li', { has: page.locator('.ext_name', { hasText: /^ArticleNotes$/ }) });
	await row.locator('button.switch').click();
	await expect(row.locator('button.switch')).toHaveAttribute('formaction', /a=disable/);

	await page.goto('/i/?c=user&a=profile');
	await page.locator('#apiPasswordPlain').fill(setting('RELEASE_API_PASSWORD'));
	await page.locator('form[action*="updatePassword"]').getByRole('button', { name: 'Submit' }).click();
	await expect(page.locator('.notification.good')).toBeVisible();

	await page.goto('/i/?state=3');
	const article = await openArticle(page, TITLE);
	await article.locator('.articlenotes-trigger').click();
	await article.getByLabel('Your note on this article').fill(NOTE);
	await article.getByRole('button', { name: 'Save' }).click();
	await expect(article.locator('.articlenotes-note')).toContainText(NOTE);

	const status = await request.get(`${API}/status`, { headers: auth });
	expect(await status.json()).toMatchObject({ version: setting('OLD_VERSION'), note_count: 1 });
	expect((await request.get(`${API}/annotated`, { headers: auth })).status()).toBe(404);	// not in 1.0.0
});

test('after: 1.1.0 migrated the user, kept the note, and serves the new API', async ({ page, request }) => {
	test.skip(phase !== 'after');
	const version = setting('RELEASE_VERSION');

	const status = await request.get(`${API}/status`, { headers: auth });
	expect(status.status()).toBe(200);
	expect(await status.json()).toMatchObject({ extension: 'ArticleNotes', version, user: 'admin', note_count: 1 });

	// The 1.0.0 note is intact, with its label and article snapshot.
	const notes = (await (await request.get(`${API}/notes`, { headers: auth })).json()) as {
		notes: { entry_id: string; kind: string; note: string; label_applied: boolean; article: { title: string } }[];
	};
	expect(notes.notes).toHaveLength(1);
	expect(notes.notes[0]).toMatchObject({ kind: 'article', note: NOTE, label_applied: true, article: { title: TITLE } });
	const entryId = notes.notes[0].entry_id;
	const lookup = (await (await request.get(`${API}/lookup`, { headers: auth, params: { url: 'http://fixtures/articles/1' } })).json()) as {
		matches: { entry_id: string; note: { note: string }; highlights: unknown[] }[];
	};
	expect(lookup.matches[0]).toMatchObject({ entry_id: entryId, note: { note: NOTE }, highlights: [] });

	// The note still shows in the article, and can be edited.
	await signIn(page, { user: 'admin', password: setting('RELEASE_ADMIN_PASSWORD') });
	await page.goto('/i/?state=3');
	const article = await openArticle(page, TITLE);
	await expect(article.locator('.articlenotes-note')).toContainText(NOTE);

	// Highlights and page notes work (their tables were created by the migrations).
	const json = { ...auth, 'Content-Type': 'application/json' };
	const created = await request.post(`${API}/highlights`, {
		headers: json,
		data: { entry_id: entryId, source: 'freshrss', color: 'green', note: 'new in 1.1', selector: { quote: { exact: 'compile times for large workspaces' } } },
	});
	expect(created.status()).toBe(201);
	const pageNote = await request.put(`${API}/page-notes`, { headers: json, params: { url: 'https://example.org/upgrade' }, data: { note: 'page note' } });
	expect(pageNote.status()).toBe(201);

	const annotated = (await (await request.get(`${API}/annotated`, { headers: auth })).json()) as {
		targets: { kind: string; entry_id: string | null; highlight_count: number; highlight_colors: string[]; note: { note: string } | null }[];
	};
	expect(annotated.targets).toHaveLength(2);
	expect(annotated.targets.find((target) => target.entry_id === entryId)).toMatchObject({
		kind: 'article', highlight_count: 1, highlight_colors: ['green'], note: { note: NOTE },
	});
	const w3c = await request.get(`${API}/annotations`, { headers: auth, params: { format: 'w3c' } });
	expect(w3c.status()).toBe(200);
	expect(((await w3c.json()) as { total: number }).total).toBe(3);

	const description = await request.get(`${API}/openapi.json`);	// no credentials
	expect(description.status()).toBe(200);
	expect(((await description.json()) as { info: { version: string } }).info.version).toBe(version);

	await page.reload();
	await page.goto('/i/?state=3');
	const again = await openArticle(page, TITLE);
	await expect(again.locator('mark.articlenotes-hl')).toContainText('compile times for large workspaces');
});
