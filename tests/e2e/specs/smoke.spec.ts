import { expect, test } from '@playwright/test';
import { admin, signIn, throwawayUser } from '../support/freshrss';

test('the dev admin can sign in and ArticleNotes is enabled', async ({ page }) => {
	await signIn(page, admin);
	await page.goto('/i/?c=extension');
	const row = page.locator('li', { has: page.locator('.ext_name', { hasText: /^ArticleNotes$/ }) });
	await expect(row).toHaveCount(1);
	// Enabled extensions render a plain name and offer a "disable" switch; disabled ones the opposite.
	await expect(row.locator('.ext_name')).not.toHaveClass(/disabled/);
	await expect(row.locator('button.switch')).toHaveAttribute('formaction', /a=disable/);
});

test('the throwaway user sees the local fixture feed articles', async ({ page }) => {
	await signIn(page, throwawayUser());
	await page.goto('/i/?state=3');	// all articles: other specs mark some as read
	await expect(page.locator('.flux').first()).toBeVisible();
	await expect(page.locator('#stream')).toContainText('Rust 1.90 stabilizes async closures');
});
