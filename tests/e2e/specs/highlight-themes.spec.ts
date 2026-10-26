import { expect, test, type APIRequestContext, type Locator } from '../support/test';
import { basicAuth, openArticle, setTheme, signIn, throwawayApiPassword, throwawayUser } from '../support/freshrss';

const API = '/api/misc.php/ArticleNotes/v1';
const title = 'PHP 8.5 pipe operator in practice';
const colors = ['Yellow', 'Green', 'Blue', 'Pink', 'Purple'];

// The default theme in both colour schemes and every bundled dark theme.
const themes = [
	{ theme: 'Origine', scheme: 'light' },
	{ theme: 'Origine', scheme: 'dark' },
	{ theme: 'Origine-compact', scheme: 'dark' },
	{ theme: 'Dark', scheme: 'light' },
	{ theme: 'Alternative-Dark', scheme: 'light' },
	{ theme: 'Dark-pink', scheme: 'light' },
	{ theme: 'Nord', scheme: 'light' },
] as const;

async function deleteHighlights(request: APIRequestContext): Promise<void> {
	const auth = basicAuth(throwawayUser().user, throwawayApiPassword());
	const lookup = await request.get(`${API}/lookup`, { headers: auth, params: { url: 'http://fixtures/articles/7' } });
	expect(lookup.status()).toBe(200);
	const id = ((await lookup.json()) as { matches: { entry_id: string }[] }).matches[0].entry_id;
	const list = await request.get(`${API}/highlights`, { headers: auth, params: { entry_id: id } });
	for (const highlight of ((await list.json()) as { highlights: { uuid: string }[] }).highlights) {
		await request.delete(`${API}/highlights/${highlight.uuid}`, { headers: auth });
	}
}

/**
 * Gives the article content links, inline formatting, code, a heading and a list (the fixture feed is plain text), and
 * selects all of it so that one highlight splits across those element boundaries.
 */
async function richContent(article: Locator): Promise<void> {
	await article.locator('.content .text').evaluate((root) => {
		root.innerHTML = '<p>Chain <a href="http://fixtures/articles/7">linked <strong>strong link</strong></a> calls'
			+ ' with <strong>bold</strong>, <em>emphasis</em>, <b>b</b>, <i>i</i>, <code>$x |&gt; f(...)</code>'
			+ ' and <mark>mark</mark>.</p><h3>A heading</h3><ul><li>A list item</li></ul><blockquote>A quote</blockquote>';
		const range = document.createRange();
		range.selectNodeContents(root);
		window.getSelection()!.removeAllRanges();
		window.getSelection()!.addRange(range);
		document.dispatchEvent(new Event('selectionchange'));
	});
}

/**
 * The contrast of each highlighted piece of text against the background it is drawn on: the painted text colour
 * (`-webkit-text-fill-color`, which overrides `color` when set) with its alpha and the opacity of its ancestors,
 * over the backgrounds of the mark and its ancestors composited down to the first opaque one.
 */
async function markContrasts(article: Locator): Promise<{ text: string; ratio: number }[]> {
	return article.locator('mark.articlenotes-hl').evaluateAll((marks) => {
		type Rgba = [number, number, number, number];
		const parse = (color: string): Rgba => {
			const [r, g, b, a] = (color.match(/[\d.]+/g) ?? []).map(Number);
			return [r, g, b, a ?? 1];
		};
		const over = (top: Rgba, bottom: Rgba): Rgba => {
			const alpha = top[3];
			return [0, 1, 2].map((i) => top[i] * alpha + bottom[i] * (1 - alpha)).concat(1) as Rgba;
		};
		const luminance = (color: Rgba): number => {
			const [r, g, b] = color.slice(0, 3).map((v) => {
				const c = v / 255;
				return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
			});
			return 0.2126 * r + 0.7152 * g + 0.0722 * b;
		};
		return marks.filter((mark) => mark.textContent!.trim() !== '').map((mark) => {
			const layers: Rgba[] = [];
			let opacity = 1;
			for (let node: Element | null = mark; node; node = node.parentElement) {
				const style = getComputedStyle(node);
				opacity *= Number(style.opacity);
				const layer = parse(style.backgroundColor);
				if (layer[3] > 0 && layers.every((below) => below[3] < 1)) {
					layers.push(layer);
				}
			}
			const background = layers.reduceRight<Rgba>((below, layer) => over(layer, below), [255, 255, 255, 1]);
			const style = getComputedStyle(mark);
			const fill = parse(style.webkitTextFillColor || style.color);
			const text = over([fill[0], fill[1], fill[2], fill[3] * opacity], background);
			const [hi, lo] = [luminance(text), luminance(background)].sort((x, y) => y - x);
			return { text: `${mark.parentElement!.tagName.toLowerCase()} "${mark.textContent}"`, ratio: (hi + 0.05) / (lo + 0.05) };
		});
	});
}

test.beforeEach(async ({ page, request }) => {
	await deleteHighlights(request);
	await signIn(page, throwawayUser());
});

test.afterEach(async ({ request }) => {
	await deleteHighlights(request);
});

for (const { theme, scheme } of themes) {
	test(`highlighted text is readable in every colour in the ${theme} theme (${scheme} colour scheme)`, async ({ page }) => {
		await page.setViewportSize({ width: 1440, height: 900 });
		await page.emulateMedia({ colorScheme: scheme });
		await setTheme(page, theme);
		try {
			await page.goto('/i/?state=3');
			const article = await openArticle(page, title);
			await richContent(article);
			const toolbar = page.locator('.articlenotes-toolbar[role="toolbar"]');
			await toolbar.getByRole('button', { name: 'Highlight', exact: true }).click();
			const marks = article.locator('mark.articlenotes-hl');
			await expect(marks.filter({ hasText: 'strong link' })).toHaveCount(1);
			await expect(marks.filter({ hasText: '$x |> f(...)' })).toHaveCount(1);
			await expect(marks.filter({ hasText: 'A list item' })).toHaveCount(1);

			const popover = page.getByRole('dialog', { name: 'Highlight' });
			for (const color of colors) {
				if (color !== 'Yellow') {
					await marks.first().click();
					await popover.getByRole('button', { name: color }).click();
					await expect(marks.first()).toHaveAttribute('data-color', color.toLowerCase());
					await page.keyboard.press('Escape');
					await expect(popover).toHaveCount(0);
				}
				await page.mouse.move(0, 0);
				for (const { text, ratio } of await markContrasts(article)) {
					expect(ratio, `${color}: ${text}`).toBeGreaterThanOrEqual(4.5);
				}
			}
		} finally {
			await setTheme(page, 'Origine');
		}
	});
}
