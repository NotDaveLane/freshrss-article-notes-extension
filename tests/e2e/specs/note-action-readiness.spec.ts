import { expect, test } from '../support/test';
import { signIn, throwawayUser } from '../support/freshrss';

const scripts = ['main.js', 'article-notes.js'];

test.beforeEach(async ({ page }) => {
	await signIn(page, throwawayUser());
});

for (const delayed of scripts) {
	for (const view of ['/i/?a=normal', '/i/?a=reader&state=3', '/i/?a=normal&state=3']) {
		test(`every article has a Note action when ${delayed} is delayed (${view})`, async ({ page }) => {
			await page.route(new RegExp(`/${delayed.replace('.', '\\.')}(\\?|$)`), async (route) => {
				await new Promise((resolve) => setTimeout(resolve, 1500));
				await route.continue();
			});
			await page.goto(view);
			const articles = page.locator('.flux');
			await expect(articles.first()).toBeVisible();
			const count = await articles.count();
			await expect(page.locator('.articlenotes-trigger')).toHaveCount(count);
		});
	}
}
