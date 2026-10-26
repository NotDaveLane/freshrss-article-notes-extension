import { expect, test, type Locator } from '../support/test';
import { openArticle, setTheme, signIn, throwawayUser } from '../support/freshrss';

const title = 'The hidden costs of microservices';

/**
 * The contrast of each part of the note block (title, text, "Last updated") against the block's rendered background:
 * the semi-transparent backgrounds of the block and its ancestors are composited down to the first opaque one (white
 * when none is), and each text colour is composited over that with its alpha and opacity.
 */
async function noteContrasts(block: Locator): Promise<Record<string, number>> {
	return block.evaluate((el) => {
		type Rgba = [number, number, number, number];
		const parse = (color: string): Rgba => {
			const [r, g, b, a] = (color.match(/[\d.]+/g) ?? []).map(Number);
			return [r, g, b, a ?? 1];
		};
		const over = (top: Rgba, bottom: Rgba): Rgba => {
			const alpha = top[3];
			return [0, 1, 2].map((i) => top[i] * alpha + bottom[i] * (1 - alpha)).concat(1) as Rgba;
		};
		const layers: Rgba[] = [];
		for (let node: Element | null = el; node; node = node.parentElement) {
			const layer = parse(getComputedStyle(node).backgroundColor);
			if (layer[3] > 0) {
				layers.push(layer);
			}
			if (layer[3] >= 1) {
				break;
			}
		}
		const background = layers.reduceRight<Rgba>((below, layer) => over(layer, below), [255, 255, 255, 1]);
		const luminance = (color: Rgba): number => {
			const [r, g, b] = color.slice(0, 3).map((v) => {
				const c = v / 255;
				return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
			});
			return 0.2126 * r + 0.7152 * g + 0.0722 * b;
		};
		const contrast = (selector: string): number => {
			const part = el.querySelector(selector)!;
			const color = parse(getComputedStyle(part).color);
			let opacity = color[3];
			for (let node: Element | null = part; node && node !== el.parentElement; node = node.parentElement) {
				opacity *= Number(getComputedStyle(node).opacity);
			}
			const [hi, lo] = [luminance(over([color[0], color[1], color[2], opacity], background)), luminance(background)]
				.sort((x, y) => y - x);
			return (hi + 0.05) / (lo + 0.05);
		};
		return {
			title: contrast('.articlenotes-note-title'),
			text: contrast('.articlenotes-note-text'),
			meta: contrast('.articlenotes-note-meta'),
		};
	});
}

const themes = [
	{ theme: 'Origine', scheme: 'light' },
	{ theme: 'Origine', scheme: 'dark' },
	{ theme: 'Alternative-Dark', scheme: 'light' },
	{ theme: 'Dark', scheme: 'light' },
	{ theme: 'Nord', scheme: 'light' },
	{ theme: 'Ansum', scheme: 'light' },
] as const;

for (const { theme, scheme } of themes) {
	test(`the saved note is readable in the ${theme} theme (${scheme} colour scheme)`, async ({ page }) => {
		await page.setViewportSize({ width: 1440, height: 900 });
		await page.emulateMedia({ colorScheme: scheme });
		await signIn(page, throwawayUser());
		await setTheme(page, theme);
		try {
			await page.goto('/i/?state=3');
			const article = await openArticle(page, title);
			await article.locator('.articlenotes-trigger').click();
			const editor = article.locator('.articlenotes-editor');
			await editor.getByLabel('Your note on this article').fill(`a note in ${theme}`);
			await editor.getByRole('button', { name: 'Save' }).click();
			const block = article.locator('.articlenotes-note');
			await expect(block).toContainText(`a note in ${theme}`);

			await page.mouse.move(0, 0);
			for (const [part, ratio] of Object.entries(await noteContrasts(block))) {
				expect(ratio, `${part} at rest`).toBeGreaterThanOrEqual(4.5);
			}
			await block.locator('.articlenotes-note-meta').hover();
			for (const [part, ratio] of Object.entries(await noteContrasts(block))) {
				expect(ratio, `${part} on hover`).toBeGreaterThanOrEqual(4.5);
			}

			await article.locator('.articlenotes-trigger').click();
			page.once('dialog', (dialog) => dialog.accept());
			await article.getByRole('button', { name: 'Delete' }).click();
			await expect(block).toHaveCount(0);
		} finally {
			await setTheme(page, 'Origine');
		}
	});
}
