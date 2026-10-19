import { defineConfig } from '@playwright/test';

export default defineConfig({
	testDir: './specs',
	globalSetup: './support/coverage-setup.ts',
	globalTeardown: './support/coverage-teardown.ts',
	fullyParallel: false,
	workers: 1,
	retries: 0,
	reporter: [['list']],
	outputDir: '/tmp/playwright-results',
	use: {
		baseURL: process.env.BASE_URL ?? 'http://freshrss',
		trace: 'off',
	},
});
