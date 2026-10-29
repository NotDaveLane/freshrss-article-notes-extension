/* ArticleNotes: note editor for FreshRSS articles (normal, reader and global views; works with dynamically loaded articles). */
(function () {
	'use strict';

	function settings() {
		return (typeof context !== 'undefined' && context.extensions && context.extensions.ArticleNotes) || null;
	}

	// The scripts load async, so they may run before FreshRSS's main.js has set up `context`: wait for the config.
	function whenReady(run) {
		let done = false;
		let timer = null;
		function attempt() {
			if (done || !document.body || !settings()) {
				return;
			}
			done = true;
			clearInterval(timer);
			run();
		}
		document.addEventListener('DOMContentLoaded', attempt);
		document.addEventListener('freshrss:globalContextLoaded', attempt);
		window.addEventListener('load', attempt);
		timer = setInterval(attempt, 50);
		setTimeout(function () {
			clearInterval(timer);
		}, 30000);
		attempt();
	}

	function element(tag, className, attributes) {
		const el = document.createElement(tag);
		if (className) {
			el.className = className;
		}
		Object.keys(attributes || {}).forEach(function (name) {
			el.setAttribute(name, attributes[name]);
		});
		return el;
	}

	function length(text) {
		return Array.from(text).length;
	}

	function noteBlock(flux) {
		return flux.querySelector('.articlenotes-note');
	}

	// Unsaved text of editors closed with Escape or the Note action, by entry id, restored when the editor reopens.
	const drafts = new Map();

	function isDirty(textarea) {
		return textarea.value !== textarea.articlenotesInitial;
	}

	function hasUnsavedText() {
		return drafts.size > 0 || Array.from(document.querySelectorAll('.articlenotes-editor textarea')).some(isDirty);
	}

	// Leave-page warning while an editor holds unsaved text (the draft kept in memory is lost on leaving too).
	window.addEventListener('beforeunload', function (event) {
		if (hasUnsavedText()) {
			event.preventDefault();
			event.returnValue = '';
		}
	});

	function closeEditor(flux, restoreFocus, keepDraft) {
		const form = flux.querySelector('.articlenotes-editor');
		if (form) {
			const textarea = form.querySelector('textarea');
			const entryId = form.getAttribute('data-entry');
			if (keepDraft && textarea && isDirty(textarea)) {
				drafts.set(entryId, textarea.value);
			} else {
				drafts.delete(entryId);
			}
			form.remove();
		}
		const block = noteBlock(flux);
		if (block) {
			block.hidden = false;
		}
		const trigger = flux.querySelector('.articlenotes-trigger');
		if (trigger) {
			trigger.setAttribute('aria-expanded', 'false');
			if (restoreFocus) {
				trigger.focus();
			}
		}
	}

	function applyHtml(flux, html) {
		const old = noteBlock(flux);
		if (old) {
			old.remove();
		}
		if (html === '') {
			flux.dispatchEvent(new CustomEvent('articlenotes:note', { bubbles: true }));
			return;
		}
		const template = document.createElement('template');
		template.innerHTML = html;
		const container = flux.querySelector('.content .text') || flux.querySelector('.content') || flux;
		container.appendChild(template.content);
		flux.dispatchEvent(new CustomEvent('articlenotes:note', { bubbles: true }));
	}

	function post(url, entryId, extra) {
		const body = new URLSearchParams(extra || {});
		body.set('id', entryId);
		body.set('_csrf', context.csrf);
		return fetch(url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
			body: body,
		}).then(function (response) {
			return response.json().catch(function () {
				return { ok: false, error: null };
			});
		});
	}

	function openEditor(flux, trigger) {
		const config = settings();
		if (!config || flux.querySelector('.articlenotes-editor')) {
			return;
		}
		const entryId = flux.getAttribute('data-entry');
		const block = noteBlock(flux);
		const hasNote = block !== null;
		const textId = 'articlenotes-text-' + entryId;
		const t = config.i18n;

		const form = element('form', 'articlenotes-editor', { 'data-entry': entryId });
		form.appendChild(element('label', 'articlenotes-label', { 'for': textId })).textContent = t.label;
		const textarea = element('textarea', 'articlenotes-textarea', { id: textId, name: 'note', rows: '6', 'aria-describedby': textId + '-counter' });
		textarea.articlenotesInitial = hasNote ? block.getAttribute('data-articlenotes-text') || '' : '';
		textarea.value = textarea.articlenotesInitial;
		form.appendChild(textarea);
		if (drafts.has(entryId)) {
			textarea.value = drafts.get(entryId);
			drafts.delete(entryId);
			const restored = element('p', 'help articlenotes-draft', { role: 'status' });
			restored.textContent = t.draftRestored;
			form.appendChild(restored);
		}
		const counter = element('div', 'articlenotes-counter', { id: textId + '-counter' });
		form.appendChild(counter);
		if (!flux.querySelector('mark.articlenotes-hl, .articlenotes-hl-item')) {
			const hint = element('p', 'help articlenotes-hint', { id: textId + '-hint' });
			hint.textContent = t.highlightHint;
			form.appendChild(hint);
			textarea.setAttribute('aria-describedby', textId + '-counter ' + hint.id);
		}
		const error = element('p', 'articlenotes-error', { role: 'alert' });
		error.hidden = true;
		form.appendChild(error);

		const actions = element('div', 'articlenotes-actions');
		const save = element('button', 'btn btn-important articlenotes-save', { type: 'submit' });
		save.textContent = t.save;
		const cancel = element('button', 'btn articlenotes-cancel', { type: 'button' });
		cancel.textContent = t.cancel;
		actions.appendChild(save);
		actions.appendChild(cancel);
		let remove = null;
		if (hasNote) {
			remove = element('button', 'btn btn-attention articlenotes-delete', { type: 'button' });
			remove.textContent = t.delete;
			actions.appendChild(remove);
		}
		form.appendChild(actions);

		function updateCounter() {
			const used = length(textarea.value);
			counter.textContent = t.counter.replace('%1$s', String(used)).replace('%2$s', String(config.maxLength));
			counter.classList.toggle('articlenotes-counter-over', used > config.maxLength);
		}

		function showError(message) {
			error.textContent = message;
			error.hidden = false;
		}

		function busy(isBusy) {
			form.querySelectorAll('button, textarea').forEach(function (el) {
				el.disabled = isBusy;
			});
		}

		function submit(url, extra) {
			error.hidden = true;
			busy(true);
			post(url, entryId, extra).then(function (result) {
				if (result.ok) {
					applyHtml(flux, result.html || '');
					closeEditor(flux, true);
					return;
				}
				busy(false);
				showError(result.error || t.network);
				textarea.focus();
			}).catch(function () {
				busy(false);
				showError(t.network);
			});
		}

		form.addEventListener('submit', function (event) {
			event.preventDefault();
			submit(config.saveUrl, { note: textarea.value });
		});
		cancel.addEventListener('click', function () {
			if (isDirty(textarea) && !window.confirm(t.confirmDiscard)) {
				return;
			}
			closeEditor(flux, true);
		});
		if (remove) {
			remove.addEventListener('click', function () {
				if (window.confirm(t.confirmDelete)) {
					submit(config.deleteUrl);
				}
			});
		}
		textarea.addEventListener('input', updateCounter);
		// Keep typing from reaching FreshRSS's keyboard shortcuts.
		form.addEventListener('keydown', function (event) {
			event.stopPropagation();
			if (event.key === 'Escape') {
				event.preventDefault();
				closeEditor(flux, true, true);
			}
		});

		// In place of the note (shown again on close), else above the Annotations panel, else where the note will be.
		const panel = flux.querySelector('.articlenotes-panel');
		if (hasNote) {
			block.before(form);
			block.hidden = true;
		} else if (panel) {
			panel.before(form);
		} else {
			(flux.querySelector('.content .text') || flux.querySelector('.content') || flux).appendChild(form);
		}
		if (trigger) {
			trigger.setAttribute('aria-expanded', 'true');
		}
		updateCounter();
		form.scrollIntoView({ block: 'nearest' });
		textarea.focus({ preventScroll: true });
		textarea.setSelectionRange(textarea.value.length, textarea.value.length);
	}

	/** Note icon drawn in currentColor so it follows the theme: outlined, or filled (through CSS) when the article has a note. */
	function icon() {
		const ns = 'http://www.w3.org/2000/svg';
		const svg = document.createElementNS(ns, 'svg');
		svg.setAttribute('class', 'articlenotes-icon');
		svg.setAttribute('viewBox', '0 0 16 16');
		svg.setAttribute('width', '16');
		svg.setAttribute('height', '16');
		svg.setAttribute('aria-hidden', 'true');
		svg.setAttribute('focusable', 'false');
		const sheet = document.createElementNS(ns, 'path');
		sheet.setAttribute('class', 'articlenotes-icon-sheet');
		sheet.setAttribute('d', 'M2.5 1.5h8l3 3v10h-11z');
		const lines = document.createElementNS(ns, 'path');
		lines.setAttribute('class', 'articlenotes-icon-lines');
		lines.setAttribute('d', 'M5 7h6M5 9.5h6M5 12h4');
		// Filled variant: the lines are holes (even-odd), so they show the page behind whatever the theme's colours are.
		const filled = document.createElementNS(ns, 'path');
		filled.setAttribute('class', 'articlenotes-icon-filled');
		filled.setAttribute('fill-rule', 'evenodd');
		filled.setAttribute('d', 'M1.8 0.8h9l3.4 3.4v11h-12.4z M4.3 6.3h7.4v1.4h-7.4z M4.3 8.8h7.4v1.4h-7.4z M4.3 11.3h5.4v1.4h-5.4z');
		svg.appendChild(sheet);
		svg.appendChild(lines);
		svg.appendChild(filled);
		return svg;
	}

	/** Marks the footer action of an article that has a note (bold, filled icon, "Edit" title). */
	function updateState(flux) {
		const config = settings();
		const hasNote = noteBlock(flux) !== null;
		flux.querySelectorAll('.articlenotes-trigger').forEach(function (trigger) {
			trigger.setAttribute('data-has-note', hasNote ? 'true' : 'false');
			if (config) {
				trigger.setAttribute('title', hasNote ? config.i18n.actionTitleEdit : config.i18n.actionTitle);
			}
		});
	}

	function decorate(list) {
		if (list.querySelector('.articlenotes-item')) {
			return;
		}
		const config = settings();
		const flux = list.closest('.flux');
		if (!config || !config.enabled || !flux || !flux.getAttribute('data-entry')) {
			return;
		}
		const item = element('li', 'item articlenotes-item');
		const trigger = element('a', 'item-element articlenotes-trigger', {
			href: '#', role: 'button', 'aria-expanded': 'false', title: config.i18n.actionTitle,
		});
		trigger.appendChild(icon());
		trigger.appendChild(element('span', 'articlenotes-trigger-label')).textContent = config.i18n.action;
		item.appendChild(trigger);
		const date = list.querySelector('li.date');
		list.insertBefore(item, date);
		updateState(flux);
	}

	function scan(root) {
		if (root.nodeType !== 1) {
			return;
		}
		if (root.matches('ul.bottom')) {
			decorate(root);
		}
		root.querySelectorAll('ul.bottom').forEach(decorate);
	}

	/** The note block opens the same editor as the "Note" action, in place of the block (under the article or in the Annotations panel). */
	function openFromNote(note) {
		const flux = note.closest('.flux');
		if (flux && !flux.querySelector('.articlenotes-editor')) {
			openEditor(flux, flux.querySelector('.articlenotes-trigger'));
		}
	}

	/** The Notes page collapses its filters on narrow screens only: wide enough, they are always shown (the toggle is hidden). */
	function expandWideFilters() {
		const filters = document.querySelector('.articlenotes-notes-more');
		if (!filters || !window.matchMedia) {
			return;
		}
		const wide = window.matchMedia('(min-width: 48rem)');
		const expand = function () {
			if (wide.matches) {
				filters.open = true;
			}
		};
		expand();
		wide.addEventListener('change', expand);
	}

	function start() {
		scan(document.body);
		expandWideFilters();
		// Articles arrive after page load (infinite scroll, global view panels): decorate whatever is added.
		new MutationObserver(function (mutations) {
			mutations.forEach(function (mutation) {
				mutation.addedNodes.forEach(scan);
			});
		}).observe(document.body, { childList: true, subtree: true });
		document.addEventListener('articlenotes:note', function (event) {
			if (event.target instanceof Element) {
				updateState(event.target);
			}
		});
		document.addEventListener('click', function (event) {
			const note = event.target instanceof Element ? event.target.closest('.articlenotes-note') : null;
			if (note) {
				// Selecting text in the note (to copy it) ends with a click: leave it alone.
				const selection = window.getSelection();
				if (!selection || selection.isCollapsed) {
					openFromNote(note);
				}
				return;
			}
			const trigger = event.target instanceof Element ? event.target.closest('.articlenotes-trigger') : null;
			if (!trigger) {
				return;
			}
			event.preventDefault();
			event.stopPropagation();
			const flux = trigger.closest('.flux');
			if (!flux) {
				return;
			}
			if (flux.querySelector('.articlenotes-editor')) {
				closeEditor(flux, true, true);
			} else {
				openEditor(flux, trigger);
			}
		}, true);
		document.addEventListener('keydown', function (event) {
			if ((event.key === 'Enter' || event.key === ' ') && event.target instanceof Element && event.target.matches('.articlenotes-note')) {
				event.preventDefault();
				event.stopPropagation();
				openFromNote(event.target);
				return;
			}
			if (event.key === ' ' && event.target instanceof Element && event.target.closest('.articlenotes-trigger')) {
				event.preventDefault();
				event.stopPropagation();
				event.target.click();
			}
		}, true);
	}

	function closeNotesEdit(details) {
		details.open = false;
		const summary = details.querySelector('summary');
		if (summary instanceof HTMLElement) {
			summary.focus();
		}
	}

	// The Notes page: confirm deletes, and let Cancel close the edit form.
	document.addEventListener('click', function (event) {
		const target = event.target instanceof Element ? event.target : null;
		const del = target && target.closest('.articlenotes-notes-edit button[value="delete"]');
		if (del && !window.confirm(del.getAttribute('data-confirm') || '')) {
			event.preventDefault();
			return;
		}
		const cancel = target && target.closest('.articlenotes-notes-edit .articlenotes-notes-cancel');
		const details = cancel && cancel.closest('details');
		if (details) {
			closeNotesEdit(details);
		}
	});

	// Escape inside an open edit form acts like Cancel.
	document.addEventListener('keydown', function (event) {
		const target = event.target instanceof Element ? event.target : null;
		const details = target && target.closest('details.articlenotes-notes-edit[open]');
		if (details && event.key === 'Escape') {
			event.preventDefault();
			closeNotesEdit(details);
		}
	});

	// The open form hides its summary, so move focus to the textarea (toggle does not bubble: capture).
	document.addEventListener('toggle', function (event) {
		const details = event.target;
		if (!(details instanceof HTMLDetailsElement) || !details.classList.contains('articlenotes-notes-edit') || !details.open) {
			return;
		}
		const textarea = details.querySelector('textarea.articlenotes-textarea');
		if (textarea instanceof HTMLTextAreaElement) {
			textarea.focus({ preventScroll: true });
			textarea.setSelectionRange(textarea.value.length, textarea.value.length);
			textarea.scrollIntoView({ block: 'nearest' });
		}
	}, true);

	// The settings page: copy the API endpoint, and say so in the status line (announced by screen readers).
	document.addEventListener('click', function (event) {
		const button = event.target instanceof Element ? event.target.closest('.articlenotes-copy') : null;
		const input = button && document.getElementById(button.getAttribute('data-copy') || '');
		if (!input) {
			return;
		}
		const controls = button.closest('.group-controls');
		const status = controls && controls.querySelector('.articlenotes-copy-status');
		function report(message) {
			if (status) {
				status.textContent = message;
			}
		}
		report('');
		input.select();
		const clipboard = navigator.clipboard;
		if (!clipboard || !clipboard.writeText) {
			// No Clipboard API outside secure contexts (FreshRSS over plain HTTP): copy the selection instead.
			let copied;
			try {
				copied = document.execCommand('copy');
			} catch {
				copied = false;
			}
			report(button.getAttribute(copied ? 'data-copied' : 'data-failed') || '');
			return;
		}
		clipboard.writeText(input.value).then(function () {
			report(button.getAttribute('data-copied') || '');
		}, function () {
			report(button.getAttribute('data-failed') || '');
		});
	});

	whenReady(start);
}());
