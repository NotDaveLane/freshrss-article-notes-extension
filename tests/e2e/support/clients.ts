import type { Page } from '@playwright/test';
import { createServer, type Server } from 'node:http';
import { readFile } from 'node:fs/promises';
import { expect } from './test';
import { admin, signIn } from './freshrss';

/** A static server for the example page and its two imports, on a random port of the test container. */
export interface ExampleServer {
	origin: string;
	close(): Promise<void>;
}

const FILES: Record<string, { path: string; type: string }> = {
	'/highlight-page.html': { path: '/examples/highlight-page.html', type: 'text/html; charset=utf-8' },
	'/notes-client.mjs': { path: '/examples/notes-client.mjs', type: 'text/javascript; charset=utf-8' },
	'/anchoring.js': { path: '/extension/static/anchoring.js', type: 'text/javascript; charset=utf-8' },
};

export async function serveExamples(): Promise<ExampleServer> {
	const server: Server = createServer((request, response) => {
		const file = FILES[new URL(request.url ?? '/', 'http://localhost').pathname];
		if (!file) {
			response.writeHead(404).end();
			return;
		}
		readFile(file.path).then(
			(content) => response.writeHead(200, { 'Content-Type': file.type }).end(content),
			() => response.writeHead(500).end(),
		);
	});
	await new Promise<void>((resolve) => server.listen(0, '127.0.0.1', resolve));
	const address = server.address();
	if (address === null || typeof address === 'string') {
		throw new Error('The example server has no port');
	}
	return {
		origin: `http://127.0.0.1:${address.port}`,
		close: () => new Promise<void>((resolve) => server.close(() => resolve())),
	};
}

/** Sets "Allowed client origins" on the extension's administrator page (one origin per line; '' removes them all). */
export async function setAllowedOrigins(page: Page, origins: string): Promise<void> {
	await signIn(page, admin);
	await page.goto('/i/?c=extension&a=configure&e=ArticleNotes');
	await page.getByLabel('Allowed client origins').fill(origins);
	await page.getByRole('button', { name: 'Submit' }).click();
	await expect(page.locator('.notification.good')).toBeVisible();
}
