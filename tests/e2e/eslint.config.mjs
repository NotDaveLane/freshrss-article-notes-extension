// ESLint for the extension's browser JavaScript (xExtension-ArticleNotes/static/, mounted at /extension in the e2e
// service); run by bin/lint from /extension.
import js from '@eslint/js';
import globals from 'globals';

export default [
	js.configs.recommended,
	{
		files: ['static/**/*.js'],
		languageOptions: {
			ecmaVersion: 2022,
			sourceType: 'script',
			globals: { ...globals.browser, context: 'readonly' },
		},
		rules: {
			'eqeqeq': 'error',
			'no-var': 'error',
			'prefer-const': 'error',
			'strict': ['error', 'function'],
		},
	},
	{
		// The reference client (examples/, mounted at /examples; run from there by bin/lint): ES modules for browsers and Node.
		files: ['**/*.mjs'],
		languageOptions: { ecmaVersion: 2022, sourceType: 'module', globals: { ...globals.browser, ...globals.node } },
		rules: { 'eqeqeq': 'error', 'no-var': 'error', 'prefer-const': 'error' },
	},
	{
		files: ['static/anchoring.js'],
		languageOptions: { sourceType: 'module', globals: globals.browser },
		rules: { 'strict': 'off' },
	},
];
