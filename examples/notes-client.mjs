/*
 * ArticleNotes reference client: a small, dependency-free wrapper around the ArticleNotes v1 API for browsers (pages,
 * WebExtension background scripts) and recent Node (18+: global fetch, crypto and TextEncoder).
 *
 *   import { NotesClient } from './notes-client.mjs';
 *   const client = new NotesClient({ baseUrl: 'https://freshrss.example.net', user: 'alice', apiPassword: '…' });
 *   const found = await client.lookup(location.href);
 *
 * Authentication is HTTP Basic with the FreshRSS API password. Single notes and highlights come back with their `etag`;
 * pass it back to save, update or delete, and a `PreconditionFailedError` (412) tells you to refetch.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** The API refused the request: `status` is the HTTP status, `code` the API's error code (`not_found`, …). */
export class ApiError extends Error {
	/**
	 * @param {number} status
	 * @param {string} code
	 * @param {string} message
	 */
	constructor(status, code, message) {
		super(message);
		this.name = 'ApiError';
		this.status = status;
		this.code = code;
	}
}

/** 412: the note or highlight changed since the `etag` you sent. Refetch it, merge, and try again. */
export class PreconditionFailedError extends ApiError {
	constructor(message) {
		super(412, 'precondition_failed', message);
		this.name = 'PreconditionFailedError';
	}
}

function basic(user, password) {
	const bytes = new TextEncoder().encode(`${user}:${password}`);
	return 'Basic ' + btoa(Array.from(bytes, (byte) => String.fromCharCode(byte)).join(''));
}

/** @param {Record<string, string | number | undefined | null>} params */
function queryString(params) {
	const query = new URLSearchParams();
	for (const [name, value] of Object.entries(params)) {
		if (value !== undefined && value !== null) {
			query.set(name, String(value));
		}
	}
	const text = query.toString();
	return text === '' ? '' : '?' + text;
}

export class NotesClient {
	/**
	 * @param {object} options
	 * @param {string} options.baseUrl The FreshRSS address (`https://freshrss.example.net`), or the full API address
	 *   (`…/api/misc.php/ArticleNotes`).
	 * @param {string} options.user
	 * @param {string} options.apiPassword The FreshRSS API password (Configuration → Profile), not the web password.
	 * @param {typeof fetch} [options.fetch] Another fetch (tests, polyfills); default: the global one.
	 */
	constructor({ baseUrl, user, apiPassword, fetch: fetchImpl }) {
		const base = baseUrl.replace(/\/+$/, '');
		this.apiUrl = /\/api\/misc\.php\/ArticleNotes$/.test(base) ? base : base + '/api/misc.php/ArticleNotes';
		this.authorization = basic(user, apiPassword);
		this.fetch = fetchImpl ?? ((...args) => globalThis.fetch(...args));
	}

	/**
	 * One API call.
	 *
	 * @param {string} method
	 * @param {string} path Below `…/ArticleNotes/`, e.g. `v1/notes`.
	 * @param {{query?: Record<string, string | number | undefined | null>, body?: unknown, etag?: string | null, allow404?: boolean}} [options]
	 * @returns {Promise<{body: any, etag: string | null, status: number} | null>} null for a 404 when `allow404` is set
	 */
	async request(method, path, { query = {}, body, etag, allow404 = false } = {}) {
		const headers = { Authorization: this.authorization, Accept: 'application/json' };
		if (body !== undefined) {
			headers['Content-Type'] = 'application/json';
		}
		if (etag !== undefined && etag !== null) {
			headers['If-Match'] = etag;
		}
		const response = await this.fetch(`${this.apiUrl}/${path}${queryString(query)}`, {
			method,
			headers,
			body: body === undefined ? undefined : JSON.stringify(body),
		});
		if (response.status === 404 && allow404) {
			return null;
		}
		const text = response.status === 204 ? '' : await response.text();
		let parsed = null;
		if (text !== '') {
			try {
				parsed = JSON.parse(text);
			} catch {
				parsed = null;
			}
		}
		if (!response.ok) {
			const error = parsed?.error ?? {};
			const message = error.message ?? `HTTP ${response.status}`;
			throw response.status === 412 ? new PreconditionFailedError(message) : new ApiError(response.status, error.code ?? 'error', message);
		}
		return { body: parsed, etag: response.headers.get('ETag'), status: response.status };
	}

	/** Checks the credentials: `{extension, version, api_version, user, note_count}`. */
	async status() {
		return (await this.request('GET', 'v1/status')).body;
	}

	/**
	 * What does the user have on this page? `{normalized_url, page_note, page_highlights, matches: [{entry_id, note, highlights, …}]}`.
	 *
	 * @param {string} url
	 */
	async lookup(url) {
		return (await this.request('GET', 'v1/lookup', { query: { url } })).body;
	}

	// --- Article notes ---

	/**
	 * @param {string} entryId
	 * @returns {Promise<{note: any, etag: string} | null>} null when the article has no note
	 */
	async getNote(entryId) {
		const result = await this.request('GET', `v1/notes/${encodeURIComponent(entryId)}`, { allow404: true });
		return result === null ? null : { note: result.body.note, etag: result.etag };
	}

	/**
	 * Creates or replaces the note of an article. Pass the `etag` you last saw to avoid overwriting a newer version
	 * (a `PreconditionFailedError` then); without it the write is unconditional. Empty text deletes the note.
	 *
	 * @param {string} entryId
	 * @param {string} text
	 * @param {string | null} [etag]
	 * @returns {Promise<{note: any, etag: string} | null>} null when the text was empty
	 */
	async saveNote(entryId, text, etag) {
		const result = await this.request('PUT', `v1/notes/${encodeURIComponent(entryId)}`, { body: { note: text }, etag });
		return result.status === 204 ? null : { note: result.body.note, etag: result.etag };
	}

	/**
	 * @param {string} entryId
	 * @param {string | null} [etag]
	 */
	async deleteNote(entryId, etag) {
		await this.request('DELETE', `v1/notes/${encodeURIComponent(entryId)}`, { etag });
	}

	// --- Page notes (pages that are no FreshRSS article) ---

	/** @param {string} url */
	async getPageNote(url) {
		const result = await this.request('GET', 'v1/page-notes', { query: { url }, allow404: true });
		return result === null ? null : { note: result.body.note, etag: result.etag };
	}

	/**
	 * @param {string} url
	 * @param {string} text
	 * @param {{title?: string, etag?: string | null}} [options]
	 */
	async savePageNote(url, text, { title, etag } = {}) {
		const body = title === undefined ? { note: text } : { note: text, title };
		const result = await this.request('PUT', 'v1/page-notes', { query: { url }, body, etag });
		return result.status === 204 ? null : { note: result.body.note, etag: result.etag };
	}

	/**
	 * @param {string} url
	 * @param {string | null} [etag]
	 */
	async deletePageNote(url, etag) {
		await this.request('DELETE', 'v1/page-notes', { query: { url }, etag });
	}

	// --- Highlights ---

	/**
	 * The live highlights of an article or a page, in document order.
	 *
	 * @param {{entryId?: string, url?: string}} target
	 */
	async listHighlights({ entryId, url }) {
		return (await this.request('GET', 'v1/highlights', { query: { entry_id: entryId, url } })).body.highlights;
	}

	/** @param {string} uuid */
	async getHighlight(uuid) {
		const result = await this.request('GET', `v1/highlights/${encodeURIComponent(uuid)}`, { allow404: true });
		return result === null ? null : { highlight: result.body.highlight, etag: result.etag };
	}

	/**
	 * Creates a highlight. The uuid is generated here (override it with `uuid`), so retrying the same call after a network
	 * error returns the highlight that was already stored instead of a duplicate.
	 *
	 * @param {object} highlight
	 * @param {string} [highlight.entryId] A FreshRSS article, or
	 * @param {string} [highlight.url] the page (an article with that URL, else a page).
	 * @param {'freshrss'|'page'} [highlight.source] Default `page`.
	 * @param {{quote: {exact: string, prefix?: string, suffix?: string}, position?: {start: number, end: number}}} highlight.selector
	 *   As returned by the anchoring library's `describe()`.
	 * @param {string} [highlight.color] `yellow` (default), `green`, `blue`, `pink` or `purple`.
	 * @param {string} [highlight.note]
	 * @param {string} [highlight.title] The page title.
	 * @param {string} [highlight.uuid]
	 * @returns {Promise<{highlight: any, etag: string, created: boolean}>}
	 */
	async createHighlight({ entryId, url, source = 'page', selector, color, note, title, uuid = globalThis.crypto.randomUUID() }) {
		const body = { uuid, source, selector };
		for (const [name, value] of Object.entries({ entry_id: entryId, url, color, note, title })) {
			if (value !== undefined) {
				body[name] = value;
			}
		}
		const result = await this.request('POST', 'v1/highlights', { body });
		return { highlight: result.body.highlight, etag: result.etag, created: result.status === 201 };
	}

	/**
	 * Changes the note and/or colour of a highlight (the selector cannot change: delete and create again).
	 *
	 * @param {string} uuid
	 * @param {{note?: string, color?: string}} changes
	 * @param {string | null} [etag]
	 */
	async updateHighlight(uuid, changes, etag) {
		const result = await this.request('PATCH', `v1/highlights/${encodeURIComponent(uuid)}`, { body: changes, etag });
		return { highlight: result.body.highlight, etag: result.etag };
	}

	/**
	 * @param {string} uuid
	 * @param {string | null} [etag]
	 */
	async deleteHighlight(uuid, etag) {
		await this.request('DELETE', `v1/highlights/${encodeURIComponent(uuid)}`, { etag });
	}

	// --- Sync ---

	/**
	 * Everything of one kind created, changed or deleted at or after `since` (Unix time), tombstones included
	 * (`deleted_at` set), following `continuation` until the last page. Store `serverTime` and use it as the next `since`.
	 *
	 * @param {'notes'|'highlights'} kind
	 * @param {number} [since]
	 * @param {{limit?: number}} [options] Page size, 1 to 500.
	 * @returns {Promise<{items: any[], serverTime: number}>}
	 */
	async listChangedSince(kind, since = 0, { limit } = {}) {
		if (kind !== 'notes' && kind !== 'highlights') {
			throw new TypeError('kind must be "notes" or "highlights"');
		}
		const items = [];
		let query = { since, limit };
		let serverTime = 0;
		for (;;) {
			const { body } = await this.request('GET', `v1/${kind}`, { query });
			items.push(...body[kind]);
			serverTime = serverTime || body.server_time;
			if (body.continuation === null) {
				return { items, serverTime };
			}
			query = { continuation: body.continuation };
		}
	}

	/**
	 * Every article and page the user annotated (live annotations only), most recently annotated first, following
	 * `continuation`. With `since`, only targets that changed, plus `removed`: those that have no annotation any more.
	 *
	 * @param {{since?: number, kind?: 'article'|'page', feedId?: number, limit?: number}} [options]
	 * @returns {Promise<{targets: any[], removed: any[], serverTime: number}>}
	 */
	async listAnnotated({ since, kind, feedId, limit } = {}) {
		const targets = [];
		let removed = [];
		let query = { since, kind, feed_id: feedId, limit };
		let serverTime = 0;
		for (;;) {
			const { body } = await this.request('GET', 'v1/annotated', { query });
			targets.push(...body.targets);
			removed = body.removed ?? removed;
			serverTime = serverTime || body.server_time;
			if (body.continuation === null) {
				return { targets, removed, serverTime };
			}
			query = { continuation: body.continuation };
		}
	}
}
