import { expect, type Page } from '@playwright/test';

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

export async function signIn(page: Page, credentials: Credentials): Promise<void> {
	await page.goto('/i/?c=auth&a=login');
	await page.locator('#username').fill(credentials.user);
	await page.locator('#passwordPlain').fill(credentials.password);
	await page.locator('#loginButton').click();
	await page.waitForURL((url) => !url.search.includes('c=auth'));
	await expect(page.locator('button.signout').first()).toBeAttached();
}
