import { expect, test, type Locator, type Page } from '../support/test';
import { basicAuth, openArticle, signIn, throwawayApiPassword, throwawayUser } from '../support/freshrss';

const API = '/api/misc.php/ArticleNotes/v1';

const viewports = [
	{ name: 'mobile 390x844', width: 390, height: 844 },
	{ name: 'desktop 1440x900', width: 1440, height: 900 },
];

function trigger(article: Locator): Locator {
	return article.locator('.articlenotes-trigger');
}

async function saveNote(page: Page, article: Locator, text: string): Promise<void> {
	await trigger(article).click();
	const editor = article.locator('.articlenotes-editor');
	await expect(editor.getByLabel('Your note on this article')).toBeFocused();
	await editor.getByLabel('Your note on this article').fill(text);
	await editor.getByRole('button', { name: 'Save' }).click();
	await expect(editor).toHaveCount(0);
}

/** Deletes the article's note so the user shared by the specs is left as it was. */
async function deleteNote(page: Page, article: Locator): Promise<void> {
	if (await article.locator('.articlenotes-editor').count() === 0) {
		await trigger(article).click();
	}
	page.once('dialog', (dialog) => dialog.accept());
	await article.getByRole('button', { name: 'Delete' }).click();
	await expect(article.locator('.articlenotes-note')).toHaveCount(0);
}

test.beforeEach(async ({ page }) => {
	await signIn(page, throwawayUser());
});

for (const viewport of viewports) {
	test.describe(viewport.name, () => {
		test.use({ viewport: { width: viewport.width, height: viewport.height } });

		test('add, reload, edit and delete a note', async ({ page }) => {
			const title = `Rust 1.90 stabilizes async closures (${viewport.width})`.replace(/ \(\d+\)$/, '');
			await page.goto('/i/?state=3');
			let article = await openArticle(page, title);
			await expect(article.locator('.articlenotes-note')).toHaveCount(0);

			await saveNote(page, article, `first note ${viewport.width}\nsecond line`);
			const block = article.locator('.articlenotes-note');
			await expect(block).toContainText('Your note');
			await expect(block).toContainText(`first note ${viewport.width}`);
			await expect(block.locator('.articlenotes-note-text br')).toHaveCount(1);
			await expect(block).toContainText('Last updated');
			await expect(trigger(article)).toBeFocused();

			await page.reload();
			article = await openArticle(page, title);
			await expect(article.locator('.articlenotes-note')).toContainText(`first note ${viewport.width}`);

			await trigger(article).click();
			const editor = article.locator('.articlenotes-editor');
			const textarea = editor.getByLabel('Your note on this article');
			await expect(textarea).toHaveValue(`first note ${viewport.width}\nsecond line`);
			await expect(editor.locator('.articlenotes-counter')).toContainText('/ 20000');
			await textarea.fill('edited note');
			await editor.getByRole('button', { name: 'Save' }).click();
			await expect(article.locator('.articlenotes-note')).toContainText('edited note');
			await expect(article.locator('.articlenotes-note')).not.toContainText('first note');

			await trigger(article).click();
			page.once('dialog', (dialog) => dialog.accept());
			await article.getByRole('button', { name: 'Delete' }).click();
			await expect(article.locator('.articlenotes-note')).toHaveCount(0);
			await expect(article.locator('.articlenotes-editor')).toHaveCount(0);

			await page.reload();
			article = await openArticle(page, title);
			await expect(article.locator('.articlenotes-note')).toHaveCount(0);
			await trigger(article).click();
			await expect(article.getByRole('button', { name: 'Delete' })).toHaveCount(0);
		});
		test('tapping the note opens its editor', async ({ page }) => {
			await page.goto('/i/?state=3');
			const article = await openArticle(page, 'The hidden costs of microservices');
			await saveNote(page, article, `tap me ${viewport.width}`);
			const block = article.locator('.articlenotes-note');
			await expect(block).toHaveAttribute('aria-label', 'Tap to edit note');
			await block.locator('.articlenotes-note-text').click();
			const textarea = article.locator('.articlenotes-editor').getByLabel('Your note on this article');
			await expect(textarea).toBeFocused();
			await expect(textarea).toHaveValue(`tap me ${viewport.width}`);
			await expect(trigger(article)).toHaveAttribute('aria-expanded', 'true');
			await textarea.fill(`tapped ${viewport.width}`);
			await article.getByRole('button', { name: 'Save' }).click();
			await expect(article.locator('.articlenotes-editor')).toHaveCount(0);
			await expect(block).toContainText(`tapped ${viewport.width}`);
			await expect(trigger(article)).toBeFocused();

			// The footer link still toggles the editor.
			await trigger(article).click();
			await expect(article.locator('.articlenotes-editor')).toHaveCount(1);
			await trigger(article).click();
			await expect(article.locator('.articlenotes-editor')).toHaveCount(0);
			await deleteNote(page, article);
		});

		test('tapping the note in the Annotations panel opens its editor', async ({ page, request }) => {
			const auth = basicAuth(throwawayUser().user, throwawayApiPassword());
			const lookup = await request.get(`${API}/lookup`, { headers: auth, params: { url: 'http://fixtures/articles/1' } });
			expect(lookup.status()).toBe(200);
			const id = ((await lookup.json()) as { matches: { entry_id: string }[] }).matches[0].entry_id;
			const list = await request.get(`${API}/highlights`, { headers: auth, params: { entry_id: id } });
			for (const highlight of ((await list.json()) as { highlights: { uuid: string }[] }).highlights) {
				await request.delete(`${API}/highlights/${highlight.uuid}`, { headers: auth });
			}
			const created = await request.post(`${API}/highlights`, {
				headers: { ...auth, 'Content-Type': 'application/json' },
				data: { entry_id: id, source: 'freshrss', selector: { quote: { exact: 'compile times' } } },
			});
			expect(created.status()).toBe(201);

			await page.goto('/i/?state=3');
			const article = await openArticle(page, 'Rust 1.90 stabilizes async closures');
			await expect(article.locator('mark.articlenotes-hl')).not.toHaveCount(0);
			await saveNote(page, article, `panel note ${viewport.width}`);
			const block = article.locator('.articlenotes-panel .articlenotes-note');
			await expect(block).toContainText(`panel note ${viewport.width}`);
			await block.locator('.articlenotes-note-title').click();
			const textarea = article.locator('.articlenotes-editor').getByLabel('Your note on this article');
			await expect(textarea).toBeFocused();
			await expect(textarea).toHaveValue(`panel note ${viewport.width}`);
			await textarea.fill(`panel edited ${viewport.width}`);
			await article.getByRole('button', { name: 'Save' }).click();
			await expect(article.locator('.articlenotes-panel .articlenotes-note')).toContainText(`panel edited ${viewport.width}`);
			await deleteNote(page, article);
			const response = await request.delete(`${API}/highlights/${((await created.json()) as { highlight: { uuid: string } }).highlight.uuid}`, { headers: auth });
			expect(response.ok()).toBe(true);
		});

		test('the footer Note action is spaced from the date, has an icon and shows when a note exists', async ({ page }) => {
			await page.goto('/i/?state=3');
			const article = await openArticle(page, 'SQLite as an application file format, revisited');
			const action = trigger(article);
			await expect(action).toBeVisible();
			await expect(action.locator('svg.articlenotes-icon')).toHaveAttribute('aria-hidden', 'true');
			await expect(action).toHaveAccessibleName('Note');
			await expect(action).toHaveAttribute('data-has-note', 'false');

			const actionBox = await action.boundingBox();
			const dateBox = await article.locator('ul.bottom li.date .item-element').boundingBox();
			expect(actionBox).not.toBeNull();
			expect(dateBox).not.toBeNull();
			if (actionBox === null || dateBox === null) {
				return;
			}
			expect(dateBox.x - (actionBox.x + actionBox.width)).toBeGreaterThanOrEqual(8);
			if (viewport.width < 600) {
				expect(actionBox.width).toBeGreaterThanOrEqual(44);
				expect(actionBox.height).toBeGreaterThanOrEqual(44);
			}

			await saveNote(page, article, `state ${viewport.width}`);
			await expect(action).toHaveAttribute('data-has-note', 'true');
			await expect(action).toHaveAttribute('title', 'Edit your note on this article');
			await expect(action).toHaveCSS('font-weight', '700');
			await page.reload();
			const reloaded = await openArticle(page, 'SQLite as an application file format, revisited');
			await expect(trigger(reloaded)).toHaveAttribute('data-has-note', 'true');
			await deleteNote(page, reloaded);
			await expect(trigger(reloaded)).toHaveAttribute('data-has-note', 'false');
			await expect(trigger(reloaded)).toHaveAttribute('title', 'Add a note to this article');
		});

		test('the editor opens in place of the note, in view and aligned with the article text', async ({ page, request }) => {
			const auth = basicAuth(throwawayUser().user, throwawayApiPassword());
			const lookup = await request.get(`${API}/lookup`, { headers: auth, params: { url: 'http://fixtures/articles/5' } });
			expect(lookup.status()).toBe(200);
			const id = ((await lookup.json()) as { matches: { entry_id: string }[] }).matches[0].entry_id;
			const list = await request.get(`${API}/highlights`, { headers: auth, params: { entry_id: id } });
			for (const highlight of ((await list.json()) as { highlights: { uuid: string }[] }).highlights) {
				await request.delete(`${API}/highlights/${highlight.uuid}`, { headers: auth });
			}
			const uuids: string[] = [];
			for (const exact of ['single-file databases', 'stronger than ever']) {
				const created = await request.post(`${API}/highlights`, {
					headers: { ...auth, 'Content-Type': 'application/json' },
					data: { entry_id: id, source: 'freshrss', selector: { quote: { exact } }, note: `highlight note on ${exact}` },
				});
				expect(created.status()).toBe(201);
				uuids.push(((await created.json()) as { highlight: { uuid: string } }).highlight.uuid);
			}

			await page.goto('/i/?a=normal&state=3');
			const article = await openArticle(page, 'SQLite as an application file format');
			await expect(article.locator('.articlenotes-panel .articlenotes-hl-item')).toHaveCount(2);
			const text = article.locator('.content .text');
			// The article text column: the content box of .text, where the article's own text lines start and end.
			const column = async (): Promise<{ left: number; right: number }> => text.evaluate((el) => {
				const rect = el.getBoundingClientRect();
				const style = getComputedStyle(el);
				return {
					left: rect.left + parseFloat(style.borderLeftWidth) + parseFloat(style.paddingLeft),
					right: rect.right - parseFloat(style.borderRightWidth) - parseFloat(style.paddingRight),
				};
			});
			const expectAligned = async (editor: Locator): Promise<void> => {
				const box = (await editor.boundingBox())!;
				const col = await column();
				expect(Math.abs(box.x - col.left)).toBeLessThanOrEqual(1);
				expect(Math.abs(box.x + box.width - col.right)).toBeLessThanOrEqual(1);
			};

			// Without a note: the editor opens above the Annotations panel.
			await trigger(article).click();
			const editor = article.locator('.articlenotes-editor');
			await expect(editor).toBeInViewport({ ratio: 0.99 });
			expect(await editor.evaluate((el) => el.nextElementSibling?.matches('.articlenotes-panel'))).toBe(true);
			await expectAligned(editor);
			const noteText = `in place ${viewport.width}`;
			await editor.getByLabel('Your note on this article').fill(noteText);
			await editor.getByRole('button', { name: 'Save' }).click();
			await expect(editor).toHaveCount(0);
			const block = article.locator('.articlenotes-panel .articlenotes-note');
			await expect(block).toContainText(noteText);

			// With a note: the editor replaces the note block, so the note shows once (in the textarea).
			for (const open of ['action', 'note']) {
				await page.evaluate(() => window.scrollTo(0, 0));
				if (open === 'action') {
					await trigger(article).click();
				} else {
					await block.locator('.articlenotes-note-title').click();
				}
				const textarea = editor.getByLabel('Your note on this article');
				await expect(textarea).toBeFocused();
				await expect(textarea).toHaveValue(noteText);
				await expect(editor).toBeInViewport({ ratio: 0.99 });
				await expect(block).toBeHidden();
				await expect(article.getByText(noteText).filter({ visible: true })).toHaveCount(0);
				expect(await editor.evaluate((el) => el.nextElementSibling?.matches('.articlenotes-note'))).toBe(true);
				await expectAligned(editor);
				await page.keyboard.press('Escape');
				await expect(editor).toHaveCount(0);
				await expect(block).toBeVisible();
				await expect(block).toContainText(noteText);
			}

			await trigger(article).click();
			await article.getByRole('button', { name: 'Cancel' }).click();
			await expect(block).toBeVisible();

			await trigger(article).click();
			await editor.getByLabel('Your note on this article').fill(`${noteText} saved`);
			await editor.getByRole('button', { name: 'Save' }).click();
			await expect(editor).toHaveCount(0);
			await expect(article.locator('.articlenotes-note')).toHaveCount(1);
			await expect(block).toBeVisible();
			await expect(block).toContainText(`${noteText} saved`);

			await deleteNote(page, article);
			for (const uuid of uuids) {
				expect((await request.delete(`${API}/highlights/${uuid}`, { headers: auth })).ok()).toBe(true);
			}
		});

	});
}

test('the note opens its editor from the keyboard', async ({ page }) => {
	await page.goto('/i/?state=3');
	const article = await openArticle(page, 'Understanding Linux io_uring security trade-offs');
	await saveNote(page, article, 'keyboard note');
	const block = article.locator('.articlenotes-note');
	await expect(block).toHaveAttribute('tabindex', '0');
	await expect(block).toHaveAttribute('title', 'Tap to edit note');
	await block.focus();
	await page.keyboard.press('Enter');
	const textarea = article.getByLabel('Your note on this article');
	await expect(textarea).toBeFocused();
	await expect(textarea).toHaveValue('keyboard note');
	await page.keyboard.press('Escape');
	await expect(article.locator('.articlenotes-editor')).toHaveCount(0);
	await expect(trigger(article)).toBeFocused();

	await block.focus();
	await page.keyboard.press(' ');
	await expect(textarea).toBeFocused();
	await deleteNote(page, article);
});

test('selecting text in the note does not open the editor', async ({ page }) => {
	await page.goto('/i/?state=3');
	const article = await openArticle(page, 'PHP 8.5 pipe operator in practice');
	await saveNote(page, article, 'select these words');
	const text = article.locator('.articlenotes-note-text');
	// Drag across the words themselves, centred on screen (the block may sit in the Annotations panel if other specs
	// highlighted the article).
	const box = await text.evaluate((el) => {
		el.scrollIntoView({ block: 'center' });
		const range = document.createRange();
		range.selectNodeContents(el);
		const rect = range.getBoundingClientRect();
		return { left: rect.left, right: rect.right, middle: rect.top + rect.height / 2 };
	});
	await page.mouse.move(box.left + 1, box.middle);
	await page.mouse.down();
	await page.mouse.move(box.right - 1, box.middle, { steps: 5 });
	await page.mouse.up();
	expect(await page.evaluate(() => window.getSelection()!.toString())).toContain('select');
	await expect(article.locator('.articlenotes-editor')).toHaveCount(0);
	await deleteNote(page, article);
});

test('cancel and Escape close the editor and return focus to the trigger', async ({ page }) => {
	await page.goto('/i/?state=3');
	const article = await openArticle(page, 'PostgreSQL 18 brings asynchronous I/O');
	await trigger(article).click();
	await expect(article.locator('.articlenotes-editor')).toHaveCount(1);
	await article.getByRole('button', { name: 'Cancel' }).click();
	await expect(article.locator('.articlenotes-editor')).toHaveCount(0);
	await expect(trigger(article)).toBeFocused();

	await trigger(article).focus();
	await page.keyboard.press('Enter');
	await expect(article.getByLabel('Your note on this article')).toBeFocused();
	await page.keyboard.type('jkrs');	// FreshRSS shortcut keys must not fire while typing
	await expect(article.getByLabel('Your note on this article')).toHaveValue('jkrs');
	await page.keyboard.press('Escape');
	await expect(article.locator('.articlenotes-editor')).toHaveCount(0);
	await expect(trigger(article)).toBeFocused();
	await expect(article.locator('.articlenotes-note')).toHaveCount(0);
});

test('note text is rendered as text, never as HTML', async ({ page }) => {
	const xss = '<script>window.__notePwned = true</script><img src=x onerror="window.__notePwned = true">';
	await page.goto('/i/?state=3');
	let article = await openArticle(page, 'A gentle introduction to WebAssembly components');
	await saveNote(page, article, xss);
	await expect(article.locator('.articlenotes-note-text')).toHaveText(xss);
	await expect(article.locator('.articlenotes-note-text script, .articlenotes-note-text img')).toHaveCount(0);

	await page.reload();
	article = await openArticle(page, 'A gentle introduction to WebAssembly components');
	await expect(article.locator('.articlenotes-note-text')).toHaveText(xss);
	expect(await page.evaluate(() => (window as unknown as { __notePwned?: boolean }).__notePwned)).toBeUndefined();

	await trigger(article).click();
	await expect(article.getByLabel('Your note on this article')).toHaveValue(xss);
});

test('a note over 20,000 characters shows an inline error and is not saved', async ({ page }) => {
	await page.goto('/i/?state=3');
	const article = await openArticle(page, 'Why we moved our CI to ephemeral containers');
	await trigger(article).click();
	const editor = article.locator('.articlenotes-editor');
	await editor.getByLabel('Your note on this article').fill('a'.repeat(20001));
	await expect(editor.locator('.articlenotes-counter')).toContainText('20001 / 20000');
	await editor.getByRole('button', { name: 'Save' }).click();
	await expect(editor.getByRole('alert')).toHaveText('The note is too long (20,000 characters at most).');
	await expect(article.locator('.articlenotes-note')).toHaveCount(0);

	await editor.getByLabel('Your note on this article').fill('a'.repeat(20000));
	await editor.getByRole('button', { name: 'Save' }).click();
	await expect(article.locator('.articlenotes-note')).toHaveCount(1);
});

test('works in the reading view', async ({ page }) => {
	await page.goto('/i/?a=reader&state=3');
	const article = page.locator('.flux', { hasText: 'SQLite as an application file format' }).first();
	await article.scrollIntoViewIfNeeded();
	await saveNote(page, article, 'reader note');
	await expect(article.locator('.articlenotes-note')).toContainText('reader note');
	await page.reload();
	await expect(page.locator('.flux', { hasText: 'SQLite as an application file format' }).locator('.articlenotes-note')).toContainText('reader note');
});

test('works in the global view', async ({ page }) => {
	await page.goto('/i/?a=global&state=3');
	await page.locator('#stream .box-content a.item-title').first().click();
	const article = await openArticle(page, 'Type-safe SQL without an ORM');
	await saveNote(page, article, 'global note');
	await expect(article.locator('.articlenotes-note')).toContainText('global note');
});

test('works for an article loaded by scrolling', async ({ page }) => {
	await page.goto('/i/?state=3&nb=3');
	await expect(page.locator('.flux').first()).toBeVisible();
	const initial = await page.locator('.flux').count();
	expect(initial).toBeLessThan(10);
	// FreshRSS loads the next batch when the end of the stream is reached; the button is the same code path.
	await page.locator('#load_more').scrollIntoViewIfNeeded();
	await expect.poll(async () => {
		if (await page.locator('#load_more').isVisible()) {
			await page.locator('#load_more').click();
		}
		return page.locator('.flux').count();
	}, { timeout: 15000 }).toBeGreaterThan(initial);
	const article = page.locator('.flux').nth(initial);
	await article.scrollIntoViewIfNeeded();
	await article.locator('.flux_header .title').click();
	await expect(article).toHaveClass(/current/);
	await saveNote(page, article, 'note on a scrolled-in article');
	await expect(article.locator('.articlenotes-note')).toContainText('note on a scrolled-in article');
});
