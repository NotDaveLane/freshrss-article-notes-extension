import { expect, test } from '../support/test';
import { basicAuth, openArticle, signIn } from '../support/freshrss';

const API = '/api/misc.php/ArticleNotes/v1';

test('a clean FreshRSS installs the release zip: enable, set an API password, add a note, fetch it', async ({ page, request }) => {
	const password = process.env.RELEASE_ADMIN_PASSWORD;
	const version = process.env.RELEASE_VERSION;
	if (!password || !version) {
		throw new Error('RELEASE_ADMIN_PASSWORD/RELEASE_VERSION are not set; run this through bin/verify-release.');
	}
	const apiPassword = `release-api-${Date.now()}`;
	const auth = basicAuth('admin', apiPassword);

	// Before the administrator enables it, the extension's API endpoint does not exist (FreshRSS 1.27 answers 503).
	expect([404, 503]).toContain((await request.get(`${API}/status`, { headers: auth })).status());

	// Configuration → Extensions → ArticleNotes (a system extension, enabled once for the instance).
	await signIn(page, { user: 'admin', password });
	await page.goto('/i/?c=extension');
	const row = page.locator('li', { has: page.locator('.ext_name', { hasText: /^ArticleNotes$/ }) });
	await expect(row).toHaveCount(1);
	await expect(row.locator('button.switch')).toHaveAttribute('formaction', /a=enable/);
	await row.locator('button.switch').click();
	await expect(row.locator('button.switch')).toHaveAttribute('formaction', /a=disable/);

	// Profile → External access via API → API password.
	await page.goto('/i/?c=user&a=profile');
	await page.locator('#apiPasswordPlain').fill(apiPassword);
	await page.locator('form[action*="updatePassword"]').getByRole('button', { name: 'Submit' }).click();
	await expect(page.locator('.notification.good')).toBeVisible();

	await page.goto('/i/?state=3');
	const article = await openArticle(page, 'Rust 1.90 stabilizes async closures');
	await article.locator('.articlenotes-trigger').click();
	await article.getByLabel('Your note on this article').fill('Installed from the release zip');
	await article.getByRole('button', { name: 'Save' }).click();
	await expect(article.locator('.articlenotes-note')).toContainText('Installed from the release zip');

	const status = await request.get(`${API}/status`, { headers: auth });
	expect(status.status()).toBe(200);
	expect(await status.json()).toMatchObject({ extension: 'ArticleNotes', version, user: 'admin', note_count: 1 });
	const notes = await request.get(`${API}/notes`, { headers: auth });
	expect(notes.status()).toBe(200);
	const body = (await notes.json()) as { notes: { note: string; label_applied: boolean }[] };
	expect(body.notes).toEqual([expect.objectContaining({ note: 'Installed from the release zip', label_applied: true })]);
});
