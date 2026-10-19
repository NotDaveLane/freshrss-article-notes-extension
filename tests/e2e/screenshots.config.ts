import { defineConfig } from '@playwright/test';
import base from './playwright.config';

/** README screenshots (bin/screenshots): runs tests/e2e/screenshots/ against the dev instance. */
export default defineConfig({
	...base,
	testDir: './screenshots',
	use: { ...base.use, viewport: { width: 1280, height: 800 } },
});
