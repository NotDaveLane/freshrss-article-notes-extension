import { expect, test } from '../support/test';
import { openArticle, signIn, throwawayUser } from '../support/freshrss';

for (const width of [390, 360]) {
	test.describe(`article action bar at ${width}px`, () => {
		test.use({ viewport: { width, height: 844 } });

		test('fits the viewport with an article open and with a saved note', async ({ page }) => {
			await signIn(page, throwawayUser());
			await page.goto('/i/?a=normal');
			const article = await openArticle(page, '');
			const trigger = article.locator('.articlenotes-trigger');
			await expect(trigger).toBeVisible();

			async function expectFits(): Promise<void> {
				const overflow = await page.evaluate(() => ({
					scroll: document.documentElement.scrollWidth,
					inner: window.innerWidth,
					items: Array.from(document.querySelectorAll('.horizontal-list > .item, .horizontal-list > li'))
						.map((el) => el.getBoundingClientRect())
						.filter((r) => r.width > 0 && r.right > window.innerWidth + 0.5)
						.length,
				}));
				expect(overflow.scroll).toBeLessThanOrEqual(overflow.inner);
				expect(overflow.items).toBe(0);
				const box = await trigger.boundingBox();
				expect(box!.width).toBeGreaterThanOrEqual(44);
				expect(box!.height).toBeGreaterThanOrEqual(44);
				await expect(article.getByRole('button', { name: 'Note' })).toBeVisible();
			}

			await expectFits();

			await trigger.click();
			const editor = article.locator('.articlenotes-editor');
			await editor.getByLabel('Your note on this article').fill('Mobile bar note');
			await editor.getByRole('button', { name: 'Save' }).click();
			await expect(editor).toHaveCount(0);
			await expect(trigger).toHaveAttribute('data-has-note', 'true');
			await expectFits();

			await trigger.click();
			page.once('dialog', (dialog) => dialog.accept());
			await article.getByRole('button', { name: 'Delete' }).click();
			await expect(trigger).not.toHaveAttribute('data-has-note', 'true');
		});
	});
}
