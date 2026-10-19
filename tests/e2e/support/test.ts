import { expect, test as base, type APIRequestContext, type APIResponse, type Response } from '@playwright/test';

// Everything else (expect, types) as @playwright/test exports it; `test` below shadows Playwright's.
export * from '@playwright/test';

/**
 * A PHP diagnostic as PHP prints it into a response (display_errors, on in the dev instance): HTML
 * (`<b>Warning</b>:  message in <b>/path/file.php</b> on line <b>53</b>`) or plain text
 * (`Warning: message in /path/file.php on line 53`, `Fatal error: Uncaught Exception: message in /path/file.php:12`).
 */
export const PHP_DIAGNOSTIC =
	/(?:<b>)?(?:PHP )?(?:Fatal error|Parse error|Recoverable fatal error|Warning|Notice|Deprecated)(?:<\/b>)?:[ \t]+[^\n]{0,2000}?[ \t]in[ \t]+(?:<b>)?\/[^\s<]+?(?:<\/b>)?(?:[ \t]+on line[ \t]+(?:<b>)?\d+|:\d+)/;

/** The PHP diagnostics in a response body, as single trimmed lines. */
export function phpDiagnostics(body: string): string[] {
	const global = new RegExp(PHP_DIAGNOSTIC.source, 'g');
	return [...body.matchAll(global)].map((match) => match[0].replace(/<\/?b>/g, '').replace(/\s+/g, ' ').trim());
}

/** Response bodies worth scanning: pages, fragments and API answers (not images, scripts or stylesheets). */
function isScanned(contentType: string): boolean {
	return /text\/html|application\/(?:[\w.+-]*\+)?json|text\/plain|xml/.test(contentType);
}

/** Collects the PHP diagnostics of every scanned response, then fails the test when there are any. */
class DiagnosticCollector {
	private readonly pending: Promise<void>[] = [];
	private readonly found: string[] = [];

	add(url: string, contentType: string, body: () => Promise<string>): void {
		if (!isScanned(contentType)) {
			return;
		}
		this.pending.push(body().then(
			(text) => {
				for (const diagnostic of phpDiagnostics(text)) {
					this.found.push(`${url}: ${diagnostic}`);
				}
			},
			() => undefined,	// redirects and responses discarded by a later navigation have no body
		));
	}

	async assertNone(): Promise<void> {
		await Promise.all(this.pending);
		expect(this.found, 'PHP diagnostics in FreshRSS responses (fix them, do not hide them)').toEqual([]);
	}
}

const REQUEST_METHODS = ['fetch', 'get', 'post', 'put', 'patch', 'delete', 'head'] as const;

/**
 * Playwright's `test` with a guard that fails any test when a response it received (every page load, fragment and
 * fetch of the browser context, and every `request` fixture call) contains a PHP warning, notice, deprecation or
 * error. Every spec imports `test` and `expect` from here instead of `@playwright/test`.
 */
export const test = base.extend<{ phpDiagnosticsGuard: DiagnosticCollector }>({
	phpDiagnosticsGuard: [async ({ context }, use) => {
		const collector = new DiagnosticCollector();
		const onResponse = (response: Response): void => {
			collector.add(response.url(), response.headers()['content-type'] ?? '', () => response.text());
		};
		context.on('response', onResponse);
		await use(collector);
		context.off('response', onResponse);
		await collector.assertNone();
	}, { auto: true }],

	request: async ({ request, phpDiagnosticsGuard }, use) => {
		const guarded = new Proxy(request, {
			get(target, property, receiver): unknown {
				const value: unknown = Reflect.get(target, property, receiver);
				if (typeof value !== 'function' || !(REQUEST_METHODS as readonly (string | symbol)[]).includes(property)) {
					return typeof value === 'function' ? value.bind(target) : value;
				}
				return async (...args: unknown[]): Promise<APIResponse> => {
					const response = await (value as (...a: unknown[]) => Promise<APIResponse>).apply(target, args);
					phpDiagnosticsGuard.add(response.url(), response.headers()['content-type'] ?? '', () => response.text());
					return response;
				};
			},
		});
		await use(guarded as APIRequestContext);
	},
});
