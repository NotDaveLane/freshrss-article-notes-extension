import { expect, test } from '../support/test';
import { throwawayApiPassword, throwawayUser } from '../support/freshrss';

// The reference client (examples/notes-client.mjs, mounted at /examples) against the dev instance, from Node.
interface Client {
	status(): Promise<{ user: string; version: string }>;
	lookup(url: string): Promise<{ matches: { entry_id: string; note: unknown; highlights: unknown[] }[]; page_note: { note: string } | null; page_highlights: { uuid: string }[] }>;
	getNote(entryId: string): Promise<{ note: { note: string }; etag: string } | null>;
	saveNote(entryId: string, text: string, etag?: string | null): Promise<{ note: { note: string }; etag: string } | null>;
	deleteNote(entryId: string, etag?: string | null): Promise<void>;
	getPageNote(url: string): Promise<{ note: { note: string; title: string | null }; etag: string } | null>;
	savePageNote(url: string, text: string, options?: { title?: string; etag?: string | null }): Promise<{ note: { note: string }; etag: string } | null>;
	deletePageNote(url: string, etag?: string | null): Promise<void>;
	listHighlights(target: { entryId?: string; url?: string }): Promise<{ uuid: string; note: string | null; color: string }[]>;
	getHighlight(uuid: string): Promise<{ highlight: { uuid: string; note: string | null; color: string }; etag: string } | null>;
	createHighlight(highlight: Record<string, unknown>): Promise<{ highlight: { id: number; uuid: string; color: string }; etag: string; created: boolean }>;
	updateHighlight(uuid: string, changes: { note?: string; color?: string }, etag?: string | null): Promise<{ highlight: { note: string | null; color: string }; etag: string }>;
	deleteHighlight(uuid: string, etag?: string | null): Promise<void>;
	listChangedSince(kind: 'notes' | 'highlights', since?: number, options?: { limit?: number }): Promise<{ items: { uuid?: string; entry_id: string | null; deleted_at: number | null }[]; serverTime: number }>;
	listAnnotated(options?: { since?: number; kind?: string; limit?: number }): Promise<{ targets: { kind: string; entry_id: string | null; normalized_url: string; highlight_count: number; highlight_colors: string[]; note: { note: string } | null }[]; removed: { kind: string; entry_id: string | null; normalized_url: string }[]; serverTime: number }>;
}
interface ClientModule {
	NotesClient: new (options: { baseUrl: string; user: string; apiPassword: string }) => Client;
	ApiError: new (...args: never[]) => Error & { status: number; code: string };
	PreconditionFailedError: new (...args: never[]) => Error & { status: number };
}

const ARTICLE_URL = 'http://fixtures/articles/4';
const PAGE_URL = 'https://example.org/client-test-page';

async function load(): Promise<{ client: Client; module: ClientModule }> {
	const module = (await import('/examples/notes-client.mjs')) as ClientModule;
	const user = throwawayUser();
	const client = new module.NotesClient({
		baseUrl: process.env.BASE_URL ?? 'http://freshrss', user: user.user, apiPassword: throwawayApiPassword(),
	});
	return { client, module };
}

const selector = (exact: string): Record<string, unknown> => ({ quote: { exact, prefix: 'before ', suffix: ' after' }, position: { start: 7, end: 7 + exact.length } });

test('lookup, save a note, hit a 412 with a stale ETag, refetch, delete', async () => {
	const { client, module } = await load();
	expect((await client.status()).user).toBe(throwawayUser().user);

	const found = await client.lookup(ARTICLE_URL);
	expect(found.matches).toHaveLength(1);
	const entryId = found.matches[0].entry_id;
	if ((await client.getNote(entryId)) !== null) {
		await client.deleteNote(entryId);
	}
	expect(await client.getNote(entryId)).toBeNull();

	const first = await client.saveNote(entryId, 'first version');
	expect(first?.etag).toMatch(/^"\d+-\d+"$/);
	const second = await client.saveNote(entryId, 'second version', first?.etag);
	expect(second?.note.note).toBe('second version');
	expect(second?.etag).not.toBe(first?.etag);

	// The first ETag is stale now.
	await expect(client.saveNote(entryId, 'lost update', first?.etag)).rejects.toBeInstanceOf(module.PreconditionFailedError);
	await expect(client.deleteNote(entryId, first?.etag)).rejects.toMatchObject({ status: 412 });

	const fresh = await client.getNote(entryId);
	expect(fresh?.note.note).toBe('second version');
	const third = await client.saveNote(entryId, 'third version', fresh?.etag);
	expect(third?.note.note).toBe('third version');

	expect((await client.lookup(ARTICLE_URL)).matches[0].note).toMatchObject({ note: 'third version' });
	await client.deleteNote(entryId, third?.etag);
	expect(await client.getNote(entryId)).toBeNull();
	await expect(client.deleteNote(entryId)).rejects.toMatchObject({ status: 404, code: 'not_found' });
});

test('a page note round trip', async () => {
	const { client, module } = await load();
	if ((await client.getPageNote(PAGE_URL)) !== null) {
		await client.deletePageNote(PAGE_URL);
	}
	const created = await client.savePageNote(PAGE_URL, 'about this page', { title: 'Client test page' });
	expect(created?.note.note).toBe('about this page');

	// A variant of the URL finds the same note (normalized on the server).
	const read = await client.getPageNote('http://www.example.org/client-test-page/?utm_source=x#top');
	expect(read?.note).toMatchObject({ note: 'about this page', title: 'Client test page' });
	expect((await client.lookup(PAGE_URL)).page_note?.note).toBe('about this page');

	const updated = await client.savePageNote(PAGE_URL, 'updated', { etag: read?.etag });
	await expect(client.savePageNote(PAGE_URL, 'stale', { etag: read?.etag })).rejects.toBeInstanceOf(module.PreconditionFailedError);
	await client.deletePageNote(PAGE_URL, updated?.etag);
	expect(await client.getPageNote(PAGE_URL)).toBeNull();

	// An article's URL is no page.
	await expect(client.savePageNote(ARTICLE_URL, 'nope')).rejects.toMatchObject({ status: 409, code: 'url_is_article' });
});

test('highlights: idempotent create, update, delete', async () => {
	const { client, module } = await load();
	const url = 'https://example.org/client-test-highlights';
	const uuid = crypto.randomUUID();
	const options = { url, uuid, source: 'page', title: 'Highlights page', selector: selector('a highlighted passage'), color: 'green', note: 'why' };

	const created = await client.createHighlight(options);
	expect(created.created).toBe(true);
	expect(created.highlight.uuid).toBe(uuid);
	// A retry after a lost response sends the same uuid: same highlight, no duplicate.
	const retried = await client.createHighlight(options);
	expect(retried.created).toBe(false);
	expect(retried.highlight.id).toBe(created.highlight.id);
	expect(await client.listHighlights({ url })).toHaveLength(1);

	// A highlight made without a uuid gets a generated one.
	const generated = await client.createHighlight({ url, selector: selector('another passage') });
	expect(generated.highlight.uuid).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
	expect(generated.highlight.color).toBe('yellow');

	const updated = await client.updateHighlight(uuid, { note: 'changed', color: 'purple' }, created.etag);
	expect(updated.highlight).toMatchObject({ note: 'changed', color: 'purple' });
	await expect(client.updateHighlight(uuid, { note: 'stale' }, created.etag)).rejects.toBeInstanceOf(module.PreconditionFailedError);
	expect((await client.getHighlight(uuid))?.highlight.note).toBe('changed');

	await client.deleteHighlight(uuid, updated.etag);
	expect(await client.getHighlight(uuid)).toBeNull();
	await expect(client.updateHighlight(uuid, { note: 'gone' })).rejects.toMatchObject({ status: 404 });
	await client.deleteHighlight(generated.highlight.uuid);
	expect(await client.listHighlights({ url })).toEqual([]);
});

test('changed-since follows the continuation and reports tombstones; annotated targets follow it too', async () => {
	const { client } = await load();
	const url = 'https://example.org/client-test-sync';
	const before = (await client.listChangedSince('highlights', 0, { limit: 500 })).serverTime;
	const uuids: string[] = [];
	for (const text of ['one', 'two', 'three', 'four', 'five']) {
		uuids.push((await client.createHighlight({ url, selector: selector(`sync passage ${text}`), color: text === 'one' ? 'blue' : 'pink' })).highlight.uuid);
	}

	// Two per page: the client walks three pages to get all five.
	const changed = await client.listChangedSince('highlights', before, { limit: 2 });
	expect(changed.items.map((item) => item.uuid).filter((uuid) => uuids.includes(uuid ?? ''))).toEqual(uuids);
	expect(changed.serverTime).toBeGreaterThanOrEqual(before);

	const annotated = await client.listAnnotated({ since: before, limit: 1 });
	const target = annotated.targets.find((candidate) => candidate.normalized_url === 'https://example.org/client-test-sync');
	expect(target).toMatchObject({ kind: 'page', entry_id: null, highlight_count: 5, note: null });
	expect([...(target?.highlight_colors ?? [])].sort()).toEqual(['blue', 'pink']);
	expect((await client.listAnnotated({ kind: 'page' })).targets.every((candidate) => candidate.kind === 'page')).toBe(true);

	for (const uuid of uuids) {
		await client.deleteHighlight(uuid);
	}
	const after = await client.listChangedSince('highlights', changed.serverTime);
	expect(after.items.filter((item) => uuids.includes(item.uuid ?? '')).every((item) => item.deleted_at !== null)).toBe(true);
	const gone = await client.listAnnotated({ since: changed.serverTime });
	expect(gone.targets.find((candidate) => candidate.normalized_url === 'https://example.org/client-test-sync')).toBeUndefined();
	expect(gone.removed.map((removed) => removed.normalized_url)).toContain('https://example.org/client-test-sync');
});
