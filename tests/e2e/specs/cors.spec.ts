import { expect, test } from '../support/test';
import { admin, basicAuth, signIn, throwawayApiPassword, throwawayUser } from '../support/freshrss';
import { setAllowedOrigins } from '../support/clients';

const API = '/api/misc.php/ArticleNotes/v1';
const ORIGIN = 'https://notes.example.com';
const EXTENSION_ORIGIN = 'moz-extension://0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0';

test.describe.configure({ mode: 'serial' });

test.afterAll(async ({ browser }) => {
	const page = await browser.newPage();
	await setAllowedOrigins(page, '');
	await page.close();
});

test('there are no CORS headers until an administrator allows an origin', async ({ request }) => {
	const auth = basicAuth(throwawayUser().user, throwawayApiPassword());
	const response = await request.get(`${API}/status`, { headers: { ...auth, Origin: ORIGIN } });
	expect(response.status()).toBe(200);
	expect(Object.keys(response.headers()).filter((name) => name.startsWith('access-control-'))).toEqual([]);
	const preflight = await request.fetch(`${API}/notes/1`, { method: 'OPTIONS', headers: { Origin: ORIGIN } });
	expect(preflight.status()).toBe(405);
	expect(preflight.headers()['access-control-allow-origin']).toBeUndefined();
});

test('the administrator page rejects wildcards and keeps the list; exact origins are saved', async ({ page }) => {
	await signIn(page, admin);
	await page.goto('/i/?c=extension&a=configure&e=ArticleNotes');
	const field = page.getByLabel('Allowed client origins');
	await expect(field).toHaveValue('');

	await field.fill(`${ORIGIN}\n*`);
	await page.getByRole('button', { name: 'Submit' }).click();
	await expect(page.locator('.notification.bad')).toContainText('Not saved');
	await expect(field).toHaveValue(`${ORIGIN}\n*`);
	await expect(field).toHaveAttribute('aria-invalid', 'true');
	const inlineError = page.locator('#articlenotes_allowed_origins_error');
	await expect(inlineError).toContainText('Not saved');
	await expect(inlineError).toContainText('*');
	await expect(inlineError).not.toContainText(ORIGIN);
	await expect(field).toHaveAccessibleDescription(/Not saved/);

	await field.fill(`${ORIGIN}\n${EXTENSION_ORIGIN}`);
	await page.getByRole('button', { name: 'Submit' }).click();
	await expect(page.locator('.notification.good')).toBeVisible();
	await expect(page.locator('#articlenotes_allowed_origins_error')).toHaveCount(0);
	await expect(page.getByLabel('Allowed client origins')).not.toHaveAttribute('aria-invalid', 'true');
	await expect(page.getByLabel('Allowed client origins')).toHaveValue(`${ORIGIN}\n${EXTENSION_ORIGIN}`);
});

test('refused lines are shown as text in the notification, never as HTML', async ({ page }) => {
	await signIn(page, admin);
	await page.goto('/i/?c=extension&a=configure&e=ArticleNotes');
	await page.getByLabel('Allowed client origins').fill('<img src=x onerror="window.__xss=1">');
	await page.getByRole('button', { name: 'Submit' }).click();
	const notification = page.locator('.notification.bad');
	await expect(notification).toContainText('<img src=x onerror="window.__xss=1">');
	await expect(notification.locator('.msg img')).toHaveCount(0);
	await expect(page.locator('#articlenotes_allowed_origins_error img')).toHaveCount(0);
	await expect(page.getByLabel('Allowed client origins')).toHaveValue('<img src=x onerror="window.__xss=1">');
	await page.reload();
	expect(await page.evaluate(() => (window as unknown as { __xss?: number }).__xss)).toBeUndefined();
	await expect(page.getByLabel('Allowed client origins')).toHaveValue(`${ORIGIN}\n${EXTENSION_ORIGIN}`);
});

test('an allowed origin gets preflight and response headers, any other origin none', async ({ request }) => {
	const auth = basicAuth(throwawayUser().user, throwawayApiPassword());
	const preflight = await request.fetch(`${API}/notes/1`, {
		method: 'OPTIONS',
		headers: { Origin: ORIGIN, 'Access-Control-Request-Method': 'PUT', 'Access-Control-Request-Headers': 'authorization,content-type,if-match' },
	});
	expect(preflight.status()).toBe(204);
	const headers = preflight.headers();
	expect(headers['access-control-allow-origin']).toBe(ORIGIN);
	expect(headers['access-control-allow-methods']).toContain('PUT');
	expect(headers['access-control-allow-headers']).toBe('Authorization, Content-Type, If-Match');
	expect(headers['access-control-max-age']).toBe('600');
	expect(headers['vary']).toContain('Origin');
	expect(headers['access-control-allow-credentials']).toBeUndefined();
	expect(await preflight.text()).toBe('');

	const response = await request.get(`${API}/status`, { headers: { ...auth, Origin: EXTENSION_ORIGIN } });
	expect(response.status()).toBe(200);
	expect(response.headers()['access-control-allow-origin']).toBe(EXTENSION_ORIGIN);
	expect(response.headers()['access-control-expose-headers']).toBe('ETag');
	expect(response.headers()['access-control-allow-credentials']).toBeUndefined();

	const other = await request.get(`${API}/status`, { headers: { ...auth, Origin: 'https://evil.example.com' } });
	expect(other.status()).toBe(200);
	expect(Object.keys(other.headers()).filter((name) => name.startsWith('access-control-'))).toEqual([]);
	const otherPreflight = await request.fetch(`${API}/notes/1`, { method: 'OPTIONS', headers: { Origin: 'https://evil.example.com' } });
	expect(otherPreflight.status()).toBe(405);
	expect(otherPreflight.headers()['access-control-allow-origin']).toBeUndefined();
});

test('the settings page is for administrators only', async ({ page }) => {
	await signIn(page, throwawayUser());
	const response = await page.goto('/i/?c=extension&a=configure&e=ArticleNotes');
	expect(response?.status()).toBe(403);
	await expect(page.getByLabel('Allowed client origins')).toHaveCount(0);
});
