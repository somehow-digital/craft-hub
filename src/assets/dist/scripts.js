(() => {
	const valueOf = (control) => {
		if (control.type === 'checkbox') {
			return control.checked ? '1' : '';
		}

		return control.value.trim();
	};

	const formatDate = (value) => {
		const [year, month, day] = value.split('-').map(Number);

		return new Date(year, month - 1, day).toLocaleDateString(document.documentElement.lang || undefined);
	};

	// Popovers in the top layer are placed below their button, aligned to its left or right edge, kept within the viewport.
	// Right-aligned ones are anchored by their right edge, as their width is unknown before they first show.
	const placeBelow = (popover, anchor, align = 'left') => {
		const rect = anchor.getBoundingClientRect();
		const width = popover.offsetWidth;
		const viewport = document.documentElement.clientWidth;
		popover.style.top = `${rect.bottom + 4}px`;

		if (align === 'right') {
			popover.style.right = `${Math.max(8, Math.min(viewport - rect.right, viewport - width - 8))}px`;
			return;
		}

		popover.style.left = `${Math.max(8, Math.min(rect.left, window.innerWidth - width - 8))}px`;
	};

	// Keeps an open popover below its button, following it as the page scrolls or resizes.
	const followAnchor = (popover, toggle, opened, align) => {
		const position = () => placeBelow(popover, toggle, align);

		// Placed before it shows, to not flash elsewhere, and again once its width is known.
		popover.addEventListener('beforetoggle', (event) => {
			if (event.newState === 'open') {
				position();
			}
		});

		popover.addEventListener('toggle', (event) => {
			toggle.setAttribute('aria-expanded', String(event.newState === 'open'));

			if (event.newState === 'open') {
				position();
				window.addEventListener('resize', position);
				window.addEventListener('scroll', position, true);
				opened?.();
				return;
			}

			window.removeEventListener('resize', position);
			window.removeEventListener('scroll', position, true);
		});

		toggle.setAttribute('aria-expanded', 'false');
	};

	// Filter dropdowns: a button summarizing the selection, opening a popover with choices and ranges.
	const initDropdown = (dropdown) => {
		const toggle = dropdown.querySelector('.hub-dropdown__toggle');
		const popover = dropdown.querySelector('[popover]');
		const output = dropdown.querySelector('[data-hub-dropdown-value]');
		const choices = [...popover.querySelectorAll('[data-hub-choices]')];
		const ranges = [...popover.querySelectorAll('[data-hub-range]')];
		const named = [...popover.querySelectorAll('input[name]')];

		const describeChoices = (part) => {
			const labels = [...part.querySelectorAll('input[type="checkbox"]:checked')]
				.map((box) => box.closest('label').querySelector('.hub-dropdown__text').textContent.trim());

			return labels.length > 2 ? `${labels[0]}, +${labels.length - 1}` : labels.join(', ');
		};

		const describeRange = (part) => {
			const [min, max] = [...part.querySelectorAll('input')].map((input) => {
				const value = input.value.trim();
				return value && input.type === 'date' ? formatDate(value) : value;
			});
			const unit = part.dataset.unit ? ` ${part.dataset.unit}` : '';
			const range = min && max ? `${min}–${max}` : min ? `≥ ${min}` : max ? `≤ ${max}` : '';

			// Dropdowns with several ranges name them.
			return range && (ranges.length > 1 ? `${part.dataset.label} ${range}${unit}` : `${range}${unit}`);
		};

		const render = () => {
			const parts = [...choices.map(describeChoices), ...ranges.map(describeRange)].filter(Boolean);
			output.textContent = parts.join(' · ');
			dropdown.classList.toggle('is-active', parts.length > 0);
		};

		// Reads the hidden inputs into the checkboxes, e.g. after the filters are loaded from the URL.
		const sync = () => {
			choices.forEach((part) => {
				const values = part.querySelector('input[type="hidden"]').value.split(',');
				part.querySelectorAll('input[type="checkbox"]').forEach((box) => {
					box.checked = values.includes(box.value);
				});
			});

			render();
		};

		const changed = (input) => {
			render();
			input.dispatchEvent(new Event('input', { bubbles: true }));
		};

		choices.forEach((part) => {
			const hidden = part.querySelector('input[type="hidden"]');
			const boxes = [...part.querySelectorAll('input[type="checkbox"]')];
			const search = part.querySelector('[data-hub-dropdown-search]');

			boxes.forEach((box) => {
				box.addEventListener('change', () => {
					// Single choices act like radios that can be unchecked.
					if (box.checked && 'hubSingle' in part.dataset) {
						boxes.forEach((other) => {
							other.checked = other === box;
						});
					}

					hidden.value = boxes.filter((other) => other.checked).map((other) => other.value).join(',');
					changed(hidden);
				});
			});

			// Enter would submit the form of lists filtered on the server.
			search?.addEventListener('keydown', (event) => {
				if (event.key === 'Enter') {
					event.preventDefault();
				}
			});

			search?.addEventListener('input', () => {
				const term = search.value.trim().toLowerCase();
				boxes.forEach((box) => {
					box.closest('label').hidden = term !== '' && !box.closest('label').textContent.toLowerCase().includes(term);
				});
			});
		});

		ranges.forEach((part) => {
			part.querySelectorAll('input').forEach((input) => input.addEventListener('input', render));
		});

		followAnchor(popover, toggle, () => popover.querySelector('input:not([type="hidden"])')?.focus());

		popover.querySelector('[data-hub-dropdown-clear]').addEventListener('click', () => {
			named.forEach((input) => {
				input.value = '';
				input.dispatchEvent(new Event('input', { bubbles: true }));
			});

			sync();
		});

		dropdown.addEventListener('hub:sync', sync);
		render();
	};

	// Filter bars offer to reset all their filters while any is set, then load the unfiltered list.
	const initBar = (bar) => {
		const clear = bar.querySelector('[data-hub-clear]');

		if (!clear) {
			return;
		}

		const controls = [...bar.querySelectorAll('[data-hub-filter]')];
		const update = () => {
			clear.hidden = controls.every((control) => !valueOf(control));
		};

		clear.addEventListener('click', () => {
			controls.forEach((control) => {
				control.type === 'checkbox' ? (control.checked = false) : (control.value = '');
				control.dispatchEvent(new Event('hub:sync', { bubbles: true }));
			});

			bar.closest('form[data-hub-submit]')?.requestSubmit();
			update();
		});

		bar.addEventListener('input', update);
		bar.addEventListener('change', update);
		bar.addEventListener('hub:sync', update);
		update();
	};

	// Collapsible panels remember a toggled state per kind of page, shared by all elements of that kind and their tabs; states matching the default are not kept.
	const initPanel = (panel) => {
		const page = window.location.pathname.replace(/(\/\d+(\/[a-z]+)?)?(\/p\d+)?\/?$/, '');
		const key = `hub:panel:${page}#${panel.dataset.hubPanel}`;
		const fallback = panel.open;

		try {
			const stored = window.localStorage.getItem(key);

			if (stored !== null) {
				panel.open = stored === '1';
			}
		} catch {
			// Storage can be unavailable, e.g. in private windows.
		}

		panel.addEventListener('toggle', () => {
			try {
				panel.open === fallback
					? window.localStorage.removeItem(key)
					: window.localStorage.setItem(key, panel.open ? '1' : '0');
			} catch {
				// Storage can be unavailable, e.g. in private windows.
			}
		});
	};

	// Lists load their results in place as filters change, leaving empty and default ones out of the URL.
	// The server keeps the filters of each list for when it is opened again; the header tells it they were changed here.
	const initSubmitForm = (form) => {
		const count = form.querySelector('.hub-count');
		let timer;
		let request;

		const urlOf = () => {
			const query = new URLSearchParams();

			[...form.elements].forEach((control) => {
				if (!control.name || control.disabled) {
					return;
				}

				const empty = control.type === 'checkbox' ? !control.checked : !control.value.trim();

				if (!empty && control.value !== control.dataset.hubDefault) {
					query.append(control.name, control.value.trim());
				}
			});

			// The site filter sits in the page header, outside the form, but scopes its results too.
			const site = document.querySelector('input[data-hub-site]');

			if (site?.value) {
				query.append(site.name, site.value);
			}

			const search = query.toString();

			return form.action.split('?')[0] + (search ? `?${search}` : '');
		};

		// The results are the nodes following the form; they are swapped with those of the fetched page.
		const load = async () => {
			clearTimeout(timer);
			request?.abort();
			request = new AbortController();

			const url = urlOf();
			form.parentElement.setAttribute('aria-busy', 'true');

			try {
				const response = await fetch(url, { signal: request.signal, headers: { Accept: 'text/html', 'X-Hub-Filters': '1' } });

				if (!response.ok) {
					throw new Error(response.statusText);
				}

				const page = new DOMParser().parseFromString(await response.text(), 'text/html');
				const next = page.querySelector('form[data-hub-submit]');

				if (!next) {
					throw new Error('Missing results');
				}

				while (form.nextSibling) {
					form.nextSibling.remove();
				}

				form.after(...[...next.parentElement.childNodes].slice([...next.parentElement.childNodes].indexOf(next) + 1));
				form.dispatchEvent(new Event('hub:results'));

				if (count) {
					count.textContent = next.querySelector('.hub-count')?.textContent ?? '';
				}

				window.history.replaceState(null, '', url + window.location.hash);
				form.parentElement.removeAttribute('aria-busy');
			} catch (error) {
				if (error.name !== 'AbortError') {
					window.location.assign(url + window.location.hash);
				}
			}
		};

		const schedule = (event) => {
			const control = event.target;

			// The checkboxes and searches of dropdowns update their named inputs.
			if (!control.name) {
				return;
			}

			clearTimeout(timer);
			timer = setTimeout(load, control.type === 'checkbox' || control.type === 'hidden' ? 150 : 400);
		};

		form.addEventListener('input', schedule);
		form.addEventListener('change', schedule);

		form.addEventListener('keydown', (event) => {
			if (event.key === 'Enter' && event.target.matches('input:not([type="checkbox"])')) {
				event.preventDefault();

				if (!event.target.matches('[data-hub-dropdown-search]')) {
					load();
				}
			}
		});

		form.addEventListener('submit', (event) => {
			event.preventDefault();
			load();
		});
	};


	// Other filter forms submit as soon as a value changes.
	const initAutosubmit = (form) => {
		form.addEventListener('change', () => form.requestSubmit());
	};

	let rescoping;

	// Fetches the page for the selected sites and swaps its content in place, keeping an open site filter open.
	const rescope = async (input) => {
		const query = new URLSearchParams(window.location.search);
		input.value ? query.set(input.name, input.value) : query.delete(input.name);
		query.delete('page');

		const search = query.toString();
		const url = window.location.pathname + (search ? `?${search}` : '');
		const content = document.getElementById('content');

		rescoping?.abort();
		rescoping = new AbortController();
		content.setAttribute('aria-busy', 'true');

		try {
			const response = await fetch(url, { signal: rescoping.signal, headers: { Accept: 'text/html', 'X-Hub-Filters': '1' } });

			if (!response.ok) {
				throw new Error(response.statusText);
			}

			const page = new DOMParser().parseFromString(await response.text(), 'text/html');

			if (!page.getElementById('content')) {
				throw new Error('Missing content');
			}

			const dropdown = input.closest('[data-hub-dropdown]');
			const index = [...document.querySelectorAll('input[data-hub-site]')].indexOf(input);
			const open = dropdown.querySelector('[popover]').matches(':popover-open');
			const focused = dropdown.contains(document.activeElement) ? document.activeElement.value : null;
			const scroll = window.scrollY;

			// The content and the details pane hold the counts; tabs, crumbs and the nav only need their links updated.
			['content', 'details'].forEach((id) => {
				const current = document.getElementById(id);
				const next = page.getElementById(id);

				if (!current || !next) {
					return;
				}

				[...current.children].filter((child) => child.id !== 'content-header').forEach((child) => child.remove());

				const children = [...next.children].filter((child) => child.id !== 'content-header');
				current.append(...children);
				children.forEach(init);
			});

			const base = new URL(Craft.getCpUrl('hub')).pathname;

			document.querySelectorAll('a[href]:not([href^="#"])').forEach((link) => {
				const target = new URL(link.href);

				if (target.origin !== window.location.origin || (target.pathname !== base && !target.pathname.startsWith(`${base}/`))) {
					return;
				}

				input.value ? target.searchParams.set(input.name, input.value) : target.searchParams.delete(input.name);
				link.href = target.toString();
			});

			window.history.replaceState(null, '', url + window.location.hash);
			window.scrollTo(0, scroll);
			content.removeAttribute('aria-busy');

			// A swapped site filter is opened again where it was, focusing the same option.
			const swapped = document.querySelectorAll('input[data-hub-site]')[index];

			if (open && swapped && swapped !== input) {
				const popover = swapped.closest('[data-hub-dropdown]').querySelector('[popover]');

				popover.addEventListener('toggle', () => {
					[...popover.querySelectorAll('input[type="checkbox"]')].find((box) => box.value === focused)?.focus();
				}, { once: true });

				popover.showPopover();
			}
		} catch (error) {
			if (error.name !== 'AbortError') {
				window.location.assign(url);
			}
		}
	};

	// Panel descriptions: a popover below the info button next to the heading, following it while open.
	const initInfo = (popover) => {
		followAnchor(popover, document.querySelector(`[popovertarget="${popover.id}"]`));
	};

	// Column pickers show, hide and reorder the columns of the list right away, then keep them in the user's preferences.
	// The cells of all columns are rendered, so they only need to be moved, shown or hidden; the column naming the rows stays first.
	// Cells of hidden columns are rendered empty, as their data isn't loaded, so showing one loads the results again.
	const initColumns = (picker) => {
		const form = picker.closest('form');
		const popover = picker.querySelector('[popover]');
		const list = popover.querySelector('.hub-columns__list');
		const items = () => [...list.children];
		const keyOf = (item) => item.dataset.hubColumn;
		const isShown = (item) => item.querySelector('input[type="checkbox"]').checked;

		// Returns whether any shown cell is empty, as its column was hidden when the results were loaded.
		const apply = () => {
			const columns = items().map((item) => [keyOf(item), isShown(item)]);
			let unloaded = false;

			form.parentElement.querySelectorAll('.hub-table tr').forEach((row) => {
				const cells = new Map([...row.children].filter((cell) => cell.dataset.hubColumn).map((cell) => [cell.dataset.hubColumn, cell]));

				columns.forEach(([key, shown]) => {
					const cell = cells.get(key);

					if (cell) {
						cell.hidden = !shown;
						unloaded ||= shown && cell.hasAttribute('data-hub-unloaded');
						row.append(cell);
					}
				});
			});

			return unloaded;
		};

		// Saves one after the other, each sending the columns as they are by then; a save already waiting sends the latest ones.
		// Each save resolves to whether it succeeded.
		let saving = Promise.resolve(false);
		let waiting = false;

		const send = (data) => Craft.sendActionRequest('POST', 'hub/columns/save', { data: { list: picker.dataset.hubColumns, ...data } });

		const failed = () => {
			Craft.cp.displayError(Craft.t('hub', 'The columns could not be saved.'));

			return false;
		};

		const save = () => {
			if (waiting) {
				return;
			}

			waiting = true;
			saving = saving.then(() => {
				waiting = false;

				return send({ order: items().map(keyOf), hidden: items().filter((item) => !isShown(item)).map(keyOf) })
					.then(() => true, failed);
			});
		};

		// The results are loaded again once the columns are saved, so the server renders the shown ones with their data.
		// Each save reloads them once at most, so results still missing data after it don't load them over and over;
		// a later save reloads them instead.
		let reloaded = null;

		const reload = () => {
			if (reloaded === saving) {
				return;
			}

			const saved = saving;
			reloaded = saved;
			saved.then((success) => success && saved === saving && form.requestSubmit());
		};

		const changed = () => {
			const unloaded = apply();
			save();

			if (unloaded) {
				reload();
			}
		};

		list.addEventListener('change', changed);

		// The neighbor is moved instead of the item, to keep the focus on its button.
		list.addEventListener('click', (event) => {
			const button = event.target.closest('[data-hub-move]');
			const item = button?.closest('li');
			const up = button?.dataset.hubMove === '-1';
			const neighbor = up ? item?.previousElementSibling : item?.nextElementSibling;

			if (!neighbor) {
				return;
			}

			up ? item.after(neighbor) : item.before(neighbor);
			changed();
		});

		let dragged = null;

		list.addEventListener('dragstart', (event) => {
			dragged = event.target.closest('li');
			event.dataTransfer.effectAllowed = 'move';
			event.dataTransfer.setData('text/plain', keyOf(dragged));
			dragged.classList.add('is-dragging');
		});

		// The columns follow the dragged one as it moves.
		list.addEventListener('dragover', (event) => {
			if (!dragged) {
				return;
			}

			event.preventDefault();
			const item = event.target.closest('li');

			if (!item || item === dragged) {
				return;
			}

			const rect = item.getBoundingClientRect();
			const after = event.clientY > rect.top + rect.height / 2;

			if ((after ? item.nextElementSibling : item.previousElementSibling) !== dragged) {
				after ? item.after(dragged) : item.before(dragged);
				apply();
			}
		});

		list.addEventListener('drop', (event) => event.preventDefault());

		list.addEventListener('dragend', () => {
			dragged.classList.remove('is-dragging');
			dragged = null;
			save();
		});

		popover.querySelector('[data-hub-columns-reset]').addEventListener('click', () => {
			saving = saving.then(() => send({ reset: 1 })
				.then(({ data }) => {
					const byKey = new Map(items().map((item) => [keyOf(item), item]));

					data.columns.forEach(({ key, visible }) => {
						const item = byKey.get(key);

						if (item) {
							item.querySelector('input[type="checkbox"]').checked = visible;
							list.append(item);
						}
					});

					if (apply()) {
						reload();
					}

					return true;
				})
				.catch(failed));
		});

		// Results loaded before the columns were saved are rendered with the previous ones.
		form.addEventListener('hub:results', () => {
			if (apply()) {
				reload();
			}
		});
		followAnchor(popover, picker.querySelector('[popovertarget]'), undefined, 'right');
	};

	// Bulk actions: rows are selected by their checkbox, or all at once by that of the header, and the selected ones are
	// submitted with the action picked from the menu, which is only offered while any is selected.
	const initBulk = (form) => {
		const all = form.querySelector('[data-hub-bulk-all]');
		const items = [...form.querySelectorAll('[data-hub-bulk-item]')];
		const count = form.querySelector('[data-hub-bulk-count]');
		const toggle = form.querySelector('[data-hub-bulk-toggle]');
		const menu = form.querySelector('[popover]');

		if (!toggle) {
			return;
		}

		const update = () => {
			const selected = items.filter((item) => item.checked).length;

			all.checked = selected > 0 && selected === items.length;
			all.indeterminate = selected > 0 && selected < items.length;
			count.textContent = selected ? count.dataset.some.replace('{count}', selected.toLocaleString()) : count.dataset.none;
			toggle.disabled = selected === 0;
			items.forEach((item) => item.closest('tr').classList.toggle('is-selected', item.checked));

			if (!selected && menu.matches(':popover-open')) {
				menu.hidePopover();
			}
		};

		all.addEventListener('change', () => {
			items.forEach((item) => {
				item.checked = all.checked;
			});

			update();
		});

		items.forEach((item) => item.addEventListener('change', update));

		form.addEventListener('submit', (event) => {
			const message = event.submitter?.dataset.hubConfirm;

			if (message && !window.confirm(message)) {
				event.preventDefault();
				return;
			}

			menu.hidePopover();
		});

		followAnchor(menu, toggle);
		update();
	};

	// The site filter scopes counts computed on the server, so it fetches the page instead of filtering rows.
	const initSite = (input) => {
		let timer;

		input.addEventListener('input', () => {
			clearTimeout(timer);
			timer = setTimeout(() => rescope(input), 150);
		});
	};

	// Elements matching a selector, including the root itself, so swapped content can be set up like the page.
	const within = (root, selector) => [...(root.matches?.(selector) ? [root] : []), ...root.querySelectorAll(selector)];

	const init = (root) => {
		within(root, '[data-hub-dropdown]').forEach(initDropdown);
		within(root, '.hub-filters').forEach(initBar);
		within(root, 'details[data-hub-panel]').forEach(initPanel);
		within(root, '[data-hub-info]').forEach(initInfo);
		within(root, '[data-hub-columns]').forEach(initColumns);
		within(root, 'form[data-hub-submit]').forEach(initSubmitForm);
		within(root, 'form[data-hub-autosubmit]').forEach(initAutosubmit);
		within(root, 'input[data-hub-site]').forEach(initSite);
		within(root, 'form[data-hub-bulk]').forEach(initBulk);
	};

	init(document);
})();
