import { defineConfig } from '@playwright/test';
import base from './playwright.config';

/** Release check (bin/verify-release): runs tests/e2e/release/ against a clean FreshRSS with the release zip. */
export default defineConfig({
	...base,
	testDir: './release',
});
