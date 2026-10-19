/* ArticleNotes: note editor for FreshRSS articles (normal, reader and global views; works with dynamically loaded articles). */
(function () {
	'use strict';

	function settings() {
		return (typeof context !== 'undefined' && context.extensions && context.extensions.ArticleNotes) || null;
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

	function closeEditor(flux, restoreFocus) {
		const form = flux.querySelector('.articlenotes-editor');
		if (form) {
			form.remove();
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
		textarea.value = hasNote ? block.getAttribute('data-articlenotes-text') || '' : '';
		form.appendChild(textarea);
		const counter = element('div', 'articlenotes-counter', { id: textId + '-counter' });
		form.appendChild(counter);
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
				closeEditor(flux, true);
			}
		});

		const content = flux.querySelector('.content') || flux;
		const text = content.querySelector('.text');
		if (text) {
			text.after(form);
		} else {
			content.appendChild(form);
		}
		trigger.setAttribute('aria-expanded', 'true');
		updateCounter();
		textarea.focus();
		textarea.setSelectionRange(textarea.value.length, textarea.value.length);
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
		trigger.textContent = config.i18n.action;
		item.appendChild(trigger);
		const date = list.querySelector('li.date');
		list.insertBefore(item, date);
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

	function start() {
		scan(document.body);
		// Articles arrive after page load (infinite scroll, global view panels): decorate whatever is added.
		new MutationObserver(function (mutations) {
			mutations.forEach(function (mutation) {
				mutation.addedNodes.forEach(scan);
			});
		}).observe(document.body, { childList: true, subtree: true });
		document.addEventListener('click', function (event) {
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
				closeEditor(flux, true);
			} else {
				openEditor(flux, trigger);
			}
		}, true);
		document.addEventListener('keydown', function (event) {
			if (event.key === ' ' && event.target instanceof Element && event.target.closest('.articlenotes-trigger')) {
				event.preventDefault();
				event.stopPropagation();
				event.target.click();
			}
		}, true);
	}

	if (document.body) {
		start();
	} else {
		document.addEventListener('DOMContentLoaded', start);
	}
}());
