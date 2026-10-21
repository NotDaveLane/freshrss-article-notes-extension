import { expect, type Locator, type Page } from '@playwright/test';

export interface Credentials {
	user: string;
	password: string;
}

/** The development admin (see AGENTS.md). */
export const admin: Credentials = {
	user: process.env.FRESHRSS_DEV_USER ?? 'admin',
	password: process.env.FRESHRSS_DEV_PASSWORD ?? 'freshrss-dev-password',
};

/**
 * The throwaway user created for this run by bin/e2e (subscribed to the local fixture feed,
 * never to the live internet feeds).
 */
export function throwawayUser(): Credentials {
	const user = process.env.E2E_USER;
	const password = process.env.E2E_PASSWORD;
	if (!user || !password) {
		throw new Error('E2E_USER/E2E_PASSWORD are not set; run the suite through bin/e2e or bin/ci.');
	}
	return { user, password };
}

/** The API password of the throwaway user (FreshRSS's API password, not the web password). */
export function throwawayApiPassword(): string {
	const apiPassword = process.env.E2E_API_PASSWORD;
	if (!apiPassword) {
		throw new Error('E2E_API_PASSWORD is not set; run the suite through bin/e2e or bin/ci.');
	}
	return apiPassword;
}

export async function signIn(page: Page, credentials: Credentials): Promise<void> {
	await page.goto('/i/?c=auth&a=login');
	await page.locator('#username').fill(credentials.user);
	await page.locator('#passwordPlain').fill(credentials.password);
	await page.locator('#loginButton').click();
	await page.waitForURL((url) => !url.search.includes('c=auth'));
	await expect(page.locator('button.signout').first()).toBeAttached();
}

/** Opens an article by title in the normal view and returns its element. */
export async function openArticle(page: Page, title: string): Promise<Locator> {
	const article = page.locator('.flux', { hasText: title }).first();
	await expect(async () => {
		if (!/current/.test((await article.getAttribute('class')) ?? '')) {
			await article.locator('.flux_header .title').click();
		}
		await expect(article).toHaveClass(/current/, { timeout: 1000 });
	}).toPass();
	return article;
}

/**
 * A second throwaway user created by bin/e2e for the full-journey spec: subscribed to the local fixture feed,
 * with no API password (the spec sets one through the Profile page) and no notes from other specs.
 */
export function journeyUser(): Credentials {
	const user = process.env.E2E_JOURNEY_USER;
	const password = process.env.E2E_JOURNEY_PASSWORD;
	if (!user || !password) {
		throw new Error('E2E_JOURNEY_USER/E2E_JOURNEY_PASSWORD are not set; run the suite through bin/e2e or bin/ci.');
	}
	return { user, password };
}

/** A third throwaway user created by bin/e2e that never gets an API password (unlike the journey user). */
export function noApiPasswordUser(): Credentials {
	const user = process.env.E2E_NO_API_USER;
	const password = process.env.E2E_NO_API_PASSWORD;
	if (!user || !password) {
		throw new Error('E2E_NO_API_USER/E2E_NO_API_PASSWORD are not set; run the suite through bin/e2e or bin/ci.');
	}
	return { user, password };
}

/** HTTP Basic authorization header. */
export function basicAuth(user: string, password: string): Record<string, string> {
	return { Authorization: 'Basic ' + Buffer.from(`${user}:${password}`).toString('base64') };
}
