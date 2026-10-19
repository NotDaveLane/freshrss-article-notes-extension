import { Validator } from '@seriousme/openapi-schema-validator';
import { expect, test } from '../support/test';
import { basicAuth, throwawayApiPassword, throwawayUser } from '../support/freshrss';

const API = '/api/misc.php/ArticleNotes/v1';

test('the OpenAPI description is served without authentication and is a valid OpenAPI 3.1 document', async ({ request }) => {
	const response = await request.get(`${API}/openapi.json`);	// no credentials
	expect(response.status()).toBe(200);
	expect(response.headers()['content-type']).toBe('application/json; charset=UTF-8');
	const document = (await response.json()) as { openapi: string; info: { version: string }; servers: { url: string }[]; paths: Record<string, unknown> };

	const result = await new Validator().validate(document);
	expect(result.valid, JSON.stringify(result)).toBe(true);
	expect((await new Validator().validate({ openapi: '3.1.0', info: { title: 'x' }, paths: {} })).valid).toBe(false);	// the validator does reject
	expect(document.openapi).toMatch(/^3\.1\./);
	expect(document.servers[0].url).toMatch(/\/api\/misc\.php\/ArticleNotes$/);
	expect(Object.keys(document.paths)).toContain('/v1/annotated');

	// The version it announces is the running extension's.
	const status = await request.get(`${API}/status`, { headers: basicAuth(throwawayUser().user, throwawayApiPassword()) });
	expect(((await status.json()) as { version: string }).version).toBe(document.info.version);
});
