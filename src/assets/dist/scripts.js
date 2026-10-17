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

	// Popovers in the top layer are placed below their button, or above it, aligned to its left or right edge, kept within the viewport.
	// Right-aligned ones are anchored by their right edge, and ones above by their bottom edge, as their size is unknown before they first show.
	const place = (popover, anchor, align = 'left', side = 'below') => {
		const rect = anchor.getBoundingClientRect();
		const width = popover.offsetWidth;
		const viewport = document.documentElement.clientWidth;

		if (side === 'above') {
			popover.style.bottom = `${document.documentElement.clientHeight - rect.top + 4}px`;
		} else {
			popover.style.top = `${rect.bottom + 4}px`;
		}

		if (align === 'right') {
			popover.style.right = `${Math.max(8, Math.min(viewport - rect.right, viewport - width - 8))}px`;
			return;
		}

		popover.style.left = `${Math.max(8, Math.min(rect.left, window.innerWidth - width - 8))}px`;
	};

	// Keeps an open popover at its button, following it as the page scrolls or resizes.
	const followAnchor = (popover, toggle, opened, align, side) => {
		const position = () => place(popover, toggle, align, side);

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

	// Moving an element out of the page closes the popovers within it, so an open one is opened again, keeping the focus where it was.
	const keepOpen = (element, move) => {
		const popover = element?.querySelector('[popover]');
		const open = popover?.matches(':popover-open');
		const focused = element?.contains(document.activeElement) ? document.activeElement : null;

		move();

		if (open && element.isConnected && !popover.matches(':popover-open')) {
			popover.showPopover();
			focused?.focus();
		}
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

	// Panel bodies loaded once their panel is first opened, as they take a while to compute; a failed load is tried again on the next opening.
	const initLazy = (container) => {
		const panel = container.closest('details');
		const status = container.querySelector('[data-hub-lazy-status]');
		const error = container.querySelector('[data-hub-lazy-error]');
		let loading = false;

		const load = async () => {
			if (loading || !panel.open) {
				return;
			}

			loading = true;
			status.hidden = false;
			error.hidden = true;
			container.setAttribute('aria-busy', 'true');

			try {
				const response = await fetch(container.dataset.hubLazy, { headers: { Accept: 'text/html' } });

				if (!response.ok) {
					throw new Error(response.statusText);
				}

				container.replaceChildren(document.createRange().createContextualFragment(await response.text()));
				panel.removeEventListener('toggle', load);
			} catch {
				status.hidden = true;
				error.hidden = false;
				loading = false;
			}

			container.removeAttribute('aria-busy');
		};

		panel.addEventListener('toggle', load);
		load();
	};

	// Lists load their results in place as filters change, leaving empty and default ones out of the URL.
	// The server keeps the filters of each list for when it is opened again; the header tells it they were changed here.
	const initSubmitForm = (form) => {
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

				// The column picker sits in the results; it is kept in place of the fetched one, with its changes not saved yet.
				const picker = form.parentElement.querySelector('[data-hub-columns]');
				const results = [...next.parentElement.childNodes].slice([...next.parentElement.childNodes].indexOf(next) + 1);

				keepOpen(picker, () => {
					while (form.nextSibling) {
						form.nextSibling.remove();
					}

					form.after(...results);

					if (picker) {
						form.parentElement.querySelector('[data-hub-columns]')?.replaceWith(picker);
					}
				});

				results.filter((node) => node instanceof Element).forEach(init);
				form.dispatchEvent(new Event('hub:results'));

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
	// The cells of all columns are rendered, so they only need to be moved, shown or hidden; the column naming the rows stays first
	// and fixed columns, which the picker doesn't offer, stay last.
	// Cells of hidden columns are rendered empty, as their data isn't loaded, so showing one loads the results again.
	// The picker sits in the header of the last column shown, in the results following the filter form, and is kept as they're loaded again.
	const pickers = new WeakSet();

	const initColumns = (picker) => {
		if (pickers.has(picker)) {
			return;
		}

		pickers.add(picker);

		const form = picker.closest(':has(> form[data-hub-submit])').querySelector(':scope > form[data-hub-submit]');
		const popover = picker.querySelector('[popover]');
		const list = popover.querySelector('.hub-columns__list');
		const items = () => [...list.children];
		const keyOf = (item) => item.dataset.hubColumn;
		const isShown = (item) => item.querySelector('input[type="checkbox"]').checked;

		// Moves the picker to the header of the last column shown, which changes without fixed columns.
		const settle = () => {
			const last = [...picker.closest('tr').children].filter((cell) => !cell.hidden).at(-1);

			if (last && last !== picker.parentElement) {
				keepOpen(picker, () => last.append(picker));
			}
		};

		// Returns whether any shown cell is empty, as its column was hidden when the results were loaded.
		// Cells are only moved in rows out of order, and the picker's popover is kept open as its header cell moves.
		const apply = () => {
			const columns = items().map((item) => [keyOf(item), isShown(item)]);
			let unloaded = false;

			keepOpen(picker, () => {
				form.parentElement.querySelectorAll('.hub-table tr').forEach((row) => {
					const cells = new Map([...row.children].filter((cell) => cell.dataset.hubColumn).map((cell) => [cell.dataset.hubColumn, cell]));
					const ordered = [];

					columns.forEach(([key, shown]) => {
						const cell = cells.get(key);

						if (cell) {
							cell.hidden = !shown;
							unloaded ||= shown && cell.hasAttribute('data-hub-unloaded');
							ordered.push(cell);
							cells.delete(key);
						}
					});

					ordered.push(...cells.values());

					if (ordered.some((cell, index) => cell !== row.children[row.children.length - ordered.length + index])) {
						row.append(...ordered);
					}
				});

				settle();
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
		// A picker is dropped with results having no table, and a new one comes with the next ones.
		form.addEventListener('hub:results', () => {
			if (picker.isConnected && apply()) {
				reload();
			}
		});
		followAnchor(popover, picker.querySelector('[popovertarget]'), undefined, 'right');
		settle();
	};

	// Selections kept on the server, by list and page, outliving the forms showing them as the results are loaded again.
	// Each holds the selected IDs as known here, and the changes to send, one after the other: changes of rows are merged
	// into the last one not sent yet, while clearing and (un)selecting all matching items are sent on their own.
	// Every response holds the selection, to which the changes still to send are applied again.
	const selections = new Map();

	const selectionOf = (form) => {
		const { hubBulkList: list, hubBulkPath: path } = form.dataset;
		const key = `${list}:${path}`;

		if (!selections.has(key)) {
			selections.set(key, {
				list,
				path,
				ids: new Set(JSON.parse(form.dataset.hubBulkSelected).map(String)),
				queue: [],
				working: null,
				render: () => {},
			});
		}

		return selections.get(key);
	};

	const replay = (selection, change) => {
		if (change.kind === 'clear') {
			selection.ids.clear();
		}

		change.remove.forEach((id) => selection.ids.delete(id));
		change.add.forEach((id) => selection.ids.add(id));
	};

	const sendSelection = async (selection) => {
		while (selection.queue.length) {
			const change = selection.queue[0];
			change.sent = true;

			const data = {
				items: { add: [...change.add], remove: [...change.remove] },
				clear: { clear: 1 },
				matching: { matching: 'add' },
				unmatching: { matching: 'remove' },
			}[change.kind];

			try {
				const { data: response } = await Craft.sendActionRequest('POST', 'hub/bulk/select', { data: { list: selection.list, path: selection.path, ...data } });

				selection.queue.shift();
				selection.ids = new Set(response.ids.map(String));
				selection.queue.forEach((pending) => replay(selection, pending));

				if (response.error) {
					Craft.cp.displayError(response.error);
				}
			} catch {
				selection.queue.shift();
				Craft.cp.displayError(Craft.t('hub', 'The selection could not be saved.'));
			}

			selection.render();
		}
	};

	// Changes the selection here right away, and on the server once the changes before it are sent.
	// Matching items on other pages are only known once the server responds; those of the page are passed along.
	const changeSelection = (selection, kind, add = [], remove = []) => {
		const last = selection.queue.at(-1);
		let change = kind === 'items' && last?.kind === 'items' && !last.sent ? last : null;

		if (!change) {
			change = { kind, add: new Set(), remove: new Set(), sent: false };
			selection.queue.push(change);
		}

		add.forEach((id) => {
			change.remove.delete(id);
			change.add.add(id);
		});

		remove.forEach((id) => {
			change.add.delete(id);
			change.remove.add(id);
		});

		replay(selection, { kind, add, remove });

		selection.working ??= sendSelection(selection).finally(() => {
			selection.working = null;
		});
	};

	// A page restored from the back-forward cache may show a selection changed on another page since.
	window.addEventListener('pageshow', (event) => {
		if (event.persisted) {
			selections.forEach((selection) => changeSelection(selection, 'items'));
		}
	});

	// Bulk actions: rows are selected by their checkbox, or all at once by that of the header.
	// Clicking a row toggles it like its checkbox, keeping the others selected; shift-clicking a row or checkbox sets the rows
	// between it and the one clicked before to its new state.
	// Lists keeping their selection on the server (see the `bulk` component) send each change to it, select the page or all
	// items matching the filters from a menu, and offer the actions while reviewing the selection, applying them to all of it.
	// Other lists submit their checked rows with the action picked from the menu, which is only offered while any is
	// selected, and select all rows, none or the others with the buttons of the bar.
	// Labeling opens the labels of the selection, adding and removing those picked there once applied.
	// Right-clicking a row opens its context menu, applying the actions to its item only.
	const initBulk = (form) => {
		const all = form.querySelector('[data-hub-bulk-all]');
		const items = [...form.querySelectorAll('[data-hub-bulk-item]')];
		const count = form.querySelector('[data-hub-bulk-count]');
		const toggle = form.querySelector('[data-hub-bulk-toggle]');
		const selects = [...form.querySelectorAll('[data-hub-bulk-select]')];
		const applies = [...form.querySelectorAll('[data-hub-bulk-apply]')];
		const review = form.querySelector('[data-hub-bulk-review-link]');
		const outsideCount = form.querySelector('[data-hub-bulk-outside-count]');
		const menu = form.querySelector('.hub-bulk__menu:not([data-hub-context])');
		const context = form.querySelector('[data-hub-context]');
		const dialog = form.querySelector('[data-hub-bulk-labels-dialog]');
		const body = form.querySelector('tbody');
		const kept = 'hubBulkPath' in form.dataset;
		const selection = kept ? selectionOf(form) : null;
		const limit = Number(form.dataset.hubBulkLimit);
		// More items match the filters than can be selected at once.
		const tooMany = form.querySelector('[data-hub-bulk-over]') !== null;
		let outside = Number(form.dataset.hubBulkOutside ?? 0);
		let anchor = null;

		if (!body || (!toggle && !kept)) {
			return;
		}

		// Craft styles buttons as disabled by their class only, not by the attribute.
		const disable = (button, disabled) => {
			button.disabled = disabled;
			button.classList.toggle('disabled', disabled);
		};

		const total = () => (kept ? selection.ids.size : items.filter((item) => item.checked).length);
		const label = (element, size) => element.dataset.some.replace('{count}', size.toLocaleString());

		const render = () => {
			if (kept) {
				items.forEach((item) => {
					item.checked = selection.ids.has(item.value);
				});
			}

			const size = total();
			const selected = items.filter((item) => item.checked).length;
			const partial = selected > 0 && selected < items.length;
			outside = Math.min(outside, size);
			// Each button is only offered while it changes the selection in its own way, so inverting a selection of none or all
			// is left to the other two. Selecting all matching items stays disabled if there are too many of them.
			const enabled = {
				all: selected < items.length,
				none: selected > 0,
				invert: partial,
				matching: size < limit && !tooMany,
				unmatching: size > outside,
				clear: size > 0,
			};

			all.checked = selected > 0 && selected === items.length;
			all.indeterminate = partial;
			count.textContent = size ? label(count, size) : count.dataset.none;
			selects.forEach((button) => disable(button, !enabled[button.dataset.hubBulkSelect]));
			applies.forEach((button) => disable(button, size === 0));
			items.forEach((item) => item.closest('tr').classList.toggle('is-selected', item.checked));

			if (outsideCount) {
				outsideCount.hidden = outside === 0;
				outsideCount.textContent = label(outsideCount, outside);
			}

			if (review) {
				review.classList.toggle('disabled', size === 0);
				review.setAttribute('aria-disabled', String(size === 0));
			}

			if (!kept) {
				disable(toggle, size === 0);

				if (!size && menu.matches(':popover-open')) {
					menu.hidePopover();
				}
			}
		};

		// Sends the rows whose checkboxes changed to the selection kept on the server.
		const changed = () => {
			if (kept) {
				const add = items.filter((item) => item.checked && !selection.ids.has(item.value)).map((item) => item.value);
				const remove = items.filter((item) => !item.checked && selection.ids.has(item.value)).map((item) => item.value);

				if (add.length || remove.length) {
					changeSelection(selection, 'items', add, remove);
				}
			}

			render();
		};

		// Sets every row to the state returned for it, from its current one.
		const selectEach = (state) => {
			items.forEach((item) => {
				item.checked = state(item.checked);
			});

			anchor = null;
			changed();
		};

		// Sets the rows between the anchor and the item, both included.
		const selectRange = (item, checked) => {
			const [from, to] = [items.indexOf(anchor), items.indexOf(item)].sort((a, b) => a - b);

			items.slice(from, to + 1).forEach((other) => {
				other.checked = checked;
			});
		};

		// Clicks on links, buttons and inputs of a row keep doing what they do, as do clicks ending a text selection.
		const rowItem = (event) => {
			if (event.target.closest('a, button, input, label, select, textarea, [popover]')) {
				return null;
			}

			return event.target.closest('tr')?.querySelector('[data-hub-bulk-item]') ?? null;
		};

		all.addEventListener('change', () => selectEach(() => all.checked));

		const states = { all: () => true, none: () => false, invert: (checked) => !checked };
		const values = items.map((item) => item.value);

		const selectAll = {
			matching: () => changeSelection(selection, 'matching', values),
			unmatching: () => changeSelection(selection, 'unmatching', [], values),
			clear: (button) => {
				// Clearing a selection reaching beyond the page asks first, as it can't be undone.
				const message = button.dataset.hubConfirm;

				if (message && selection.ids.size > items.filter((item) => item.checked).length && !window.confirm(message.replace('{count}', selection.ids.size.toLocaleString()))) {
					return;
				}

				outside = 0;
				changeSelection(selection, 'clear');
			},
		};

		selects.forEach((button) => button.addEventListener('click', () => {
			const kind = button.dataset.hubBulkSelect;
			menu?.matches(':popover-open') && kept && menu.hidePopover();

			if (states[kind]) {
				selectEach(states[kind]);
				return;
			}

			selectAll[kind](button);
			render();
		}));

		// The checkbox is already toggled when its click is handled.
		items.forEach((item) => item.addEventListener('click', (event) => {
			if (event.shiftKey && anchor) {
				selectRange(item, item.checked);
			}

			anchor = item;
			changed();
		}));

		// Shift-clicking a row would select its text otherwise, and ctrl- or cmd-clicking it its cell, as Firefox does,
		// which would leave the click to be taken for the end of a text selection.
		body.addEventListener('mousedown', (event) => {
			if ((event.shiftKey || event.ctrlKey || event.metaKey) && rowItem(event)) {
				event.preventDefault();
			}
		});

		body.addEventListener('click', (event) => {
			const item = rowItem(event);

			if (!item || !window.getSelection().isCollapsed) {
				return;
			}

			const checked = !item.checked;

			if (event.shiftKey && anchor) {
				selectRange(item, checked);
			} else {
				item.checked = checked;
			}

			anchor = item;
			changed();
		});

		if (kept) {
			// Links leaving the list, like those of the pagination, sorting and review, wait for the selection to be sent.
			form.addEventListener('click', (event) => {
				const link = event.target.closest('a[href]');

				if (link === review && review.classList.contains('disabled')) {
					event.preventDefault();
					return;
				}

				if (!link || !selection.working || link.target || event.ctrlKey || event.metaKey || event.shiftKey) {
					return;
				}

				event.preventDefault();
				selection.working.then(() => window.location.assign(link.href));
			});
		} else {
			// Escape clears the selection, unless it closes the menu.
			form.addEventListener('keydown', (event) => {
				if (event.key !== 'Escape' || event.target.closest('[popover]') || !items.some((item) => item.checked)) {
					return;
				}

				selectEach(() => false);
			});
		}

		// Context menus: opened where the row was right-clicked, or below the focused element of the row with the menu key, as
		// those have no position. Shift-right-clicking a row, or right-clicking it with text selected, keeps the browser's menu.
		// The checkbox of the row the menu was last opened for is kept, for the action picked from it, and the row is highlighted
		// while the menu is open.
		let target = null;

		if (context) {
			const name = context.querySelector('[data-hub-context-name]');
			const open = context.querySelector('[data-hub-context-open]');
			const select = context.querySelector('[data-hub-context-select]');
			const selectText = select.querySelector('[data-select]');
			const labeling = context.querySelector('[data-hub-context-labels]');
			const editLabels = labeling ? initLabels(labeling) : null;
			const entries = () => [...context.querySelectorAll('[role="menuitem"], [data-hub-context-labels] input')]
				.filter((entry) => !entry.closest('[hidden]') && !entry.disabled);
			const close = () => context.hidePopover();

			body.addEventListener('contextmenu', (event) => {
				const row = event.target.closest('tr');
				const item = row?.querySelector('[data-hub-bulk-item]');

				if (!item || event.shiftKey || event.target.closest('input:not([type="checkbox"]), textarea, select, [popover]') || !window.getSelection().isCollapsed) {
					return;
				}

				event.preventDefault();
				target?.closest('tr').classList.remove('is-context');
				target = item;
				row.classList.add('is-context');

				const link = row.querySelector('.hub-name__title a[href]') ?? row.querySelector('a[href]');
				name.textContent = item.dataset.hubBulkName;
				open.hidden = !link;
				open.href = link?.href ?? '';
				selectText.textContent = item.checked ? selectText.dataset.unselect : selectText.dataset.select;

				if (labeling) {
					const labels = row.querySelector('[data-hub-labels-editable]');
					labeling.hidden = !labels;
					editLabels(labels);
				}

				const keyboard = event.clientX === 0 && event.clientY === 0;
				const rect = event.target.getBoundingClientRect();
				const [x, y] = keyboard ? [rect.left, rect.bottom + 4] : [event.clientX, event.clientY];

				if (context.matches(':popover-open')) {
					context.hidePopover();
				}

				context.showPopover();
				context.style.left = `${Math.max(8, Math.min(x, document.documentElement.clientWidth - context.offsetWidth - 8))}px`;
				context.style.top = `${Math.max(8, Math.min(y, document.documentElement.clientHeight - context.offsetHeight - 8))}px`;
			});

			// The menu stays where it was opened, so it closes once the page scrolls or resizes.
			context.addEventListener('toggle', (event) => {
				if (event.newState === 'open') {
					window.addEventListener('resize', close);
					window.addEventListener('scroll', close, true);
					entries()[0]?.focus();
					return;
				}

				window.removeEventListener('resize', close);
				window.removeEventListener('scroll', close, true);
				target?.closest('tr').classList.remove('is-context');

				if (document.activeElement === document.body) {
					target?.focus();
				}
			});

			context.addEventListener('keydown', (event) => {
				// Enter checks a label like Space, rather than submitting the form.
				if (event.key === 'Enter' && event.target.matches('[data-hub-context-labels] input')) {
					event.preventDefault();
					event.target.click();
					return;
				}

				const all = entries();
				const index = all.indexOf(document.activeElement);
				const next = { ArrowDown: index + 1, ArrowUp: index - 1, Home: 0, End: all.length - 1 }[event.key];

				if (next === undefined || !all.length) {
					return;
				}

				event.preventDefault();
				all[(next + all.length) % all.length].focus();
			});

			open.addEventListener('click', close);

			select.addEventListener('click', () => {
				close();
				target.checked = !target.checked;
				anchor = target;
				changed();
			});
		}

		// The labels of the selection are read once they're opened, as it is kept on the server. Each label is checked (`all`),
		// unchecked (`none`) or partly checked (`some`), which only labels partly checked when opened return to, as they're
		// checked, unchecked and then left as they were. The labels changed are sent with the action once applied.
		const labelStates = new Map();
		const initialStates = new Map();
		const labelBoxes = dialog ? [...dialog.querySelectorAll('input[type="checkbox"]')] : [];

		if (dialog && kept) {
			const legend = dialog.querySelector('[data-hub-bulk-labels-name]');
			const applyLabels = dialog.querySelector('[data-hub-bulk-labels-apply]');
			const openers = [...form.querySelectorAll('[data-hub-bulk-labels]')];
			let anchorOf = null;
			let openFor = null;

			const show = (box) => {
				box.checked = labelStates.get(box) === 'all';
				box.indeterminate = labelStates.get(box) === 'some';
			};

			const update = () => applyLabels && disable(applyLabels, labelBoxes.every((box) => labelStates.get(box) === initialStates.get(box)));

			// Opened from the menu of the bar, it is placed above its button, as the menu closes.
			const position = () => anchorOf && place(dialog, anchorOf, 'right', anchorOf.closest('.hub-bulk__footer') ? 'above' : 'below');

			const load = async () => {
				labelBoxes.forEach((box) => {
					box.disabled = true;
				});
				legend.textContent = '';
				labelStates.clear();
				initialStates.clear();
				update();

				try {
					await selection.working;
					const { data } = await Craft.sendActionRequest('POST', 'hub/labels/counts', { data: { list: selection.list, path: selection.path } });

					legend.textContent = label(legend, data.total);
					labelBoxes.forEach((box) => {
						const labeled = data.counts[box.value] ?? 0;
						const state = labeled === 0 ? 'none' : labeled >= data.total ? 'all' : 'some';
						initialStates.set(box, state);
						labelStates.set(box, state);
						show(box);
						box.disabled = false;
					});

					update();
					labelBoxes[0]?.focus();
				} catch {
					Craft.cp.displayError(Craft.t('hub', 'The labels could not be loaded.'));
					dialog.hidePopover();
				}
			};

			labelBoxes.forEach((box) => box.addEventListener('change', () => {
				const next = { some: 'all', all: 'none', none: initialStates.get(box) === 'some' ? 'some' : 'all' };
				labelStates.set(box, next[labelStates.get(box)]);
				show(box);
				update();
			}));

			// Clicks outside close the dialog before they are handled, so a click on the button it is open for only closes it.
			openers.forEach((opener) => {
				opener.addEventListener('pointerdown', () => {
					openFor = dialog.matches(':popover-open') ? opener : null;
				});

				opener.addEventListener('click', () => {
					const reopened = openFor === opener;
					openFor = null;

					if (reopened) {
						return;
					}

					menu?.matches(':popover-open') && menu.hidePopover();
					anchorOf = opener.closest('[popover]') ? toggle : opener;
					dialog.showPopover();
				});
			});

			dialog.addEventListener('beforetoggle', (event) => {
				if (event.newState === 'open') {
					position();
				}
			});

			dialog.addEventListener('toggle', (event) => {
				if (event.newState === 'open') {
					position();
					window.addEventListener('resize', position);
					window.addEventListener('scroll', position, true);
					load();
					return;
				}

				window.removeEventListener('resize', position);
				window.removeEventListener('scroll', position, true);
			});
		}

		// Inputs added for a single submission, removed once it is sent, as downloads keep the page open.
		const submitOnce = (inputs, restore = () => {}) => {
			form.append(...inputs);
			setTimeout(() => {
				inputs.forEach((input) => input.remove());
				restore();
			});
		};

		const hidden = (inputName, value) => Object.assign(document.createElement('input'), { type: 'hidden', name: inputName, value });

		// The action applies to the selection as sent, so a submission waits for it; it names the number of items it applies to.
		// Actions picked from a context menu name the item they apply to, and send it in place of the selection: by its ID to
		// lists keeping their selection, or as its checkbox alone to others.
		form.addEventListener('submit', (event) => {
			const submitter = event.submitter;
			const message = submitter?.dataset.hubConfirm;
			const single = submitter?.hasAttribute('data-hub-context-action') && target;

			if (kept && selection.working) {
				event.preventDefault();
				selection.working.then(() => form.requestSubmit(submitter));
				return;
			}

			if (message && !window.confirm(`${message}\n\n${single ? target.dataset.hubBulkName : label(count, total())}`)) {
				event.preventDefault();
				return;
			}

			if (single && kept) {
				submitOnce([hidden('item', target.value)]);
			} else if (single) {
				const enabled = items.filter((item) => !item.disabled);
				enabled.forEach((item) => {
					item.disabled = true;
				});
				submitOnce([hidden(target.name, target.value)], () => enabled.forEach((item) => {
					item.disabled = false;
				}));
			}

			if (submitter?.hasAttribute('data-hub-bulk-labels-apply')) {
				const changedBoxes = labelBoxes.filter((box) => labelStates.get(box) !== initialStates.get(box));
				submitOnce([
					...changedBoxes.filter((box) => labelStates.get(box) === 'all').map((box) => hidden('labelsAdd[]', box.value)),
					...changedBoxes.filter((box) => labelStates.get(box) === 'none').map((box) => hidden('labelsRemove[]', box.value)),
				]);
			}

			menu?.hidePopover();
			context?.hidePopover();
		});

		if (kept) {
			selection.render = render;
		}

		// The bar is in the footer, sticking to the bottom of the viewport, so the menu opens above it.
		if (menu) {
			followAnchor(menu, toggle, undefined, 'right', 'above');
		}

		render();
	};

	// The labels of a context menu label the item it is opened for with the checked labels right away, checking its labels
	// as it opens; it returns the function opening them for the labels of an item.
	// The item's labels are shown as they change; the changes of an item are sent one after the other, each with its labels
	// by then, and a failed one shows the labels it was last saved with again.
	const initLabels = (group) => {
		const boxes = [...group.querySelectorAll('input[type="checkbox"]')];
		const template = group.querySelector('[data-hub-labels-template]');
		const saved = new WeakMap();
		const sending = new WeakMap();
		let current = null;

		const idsOf = (labels) => [...labels.querySelectorAll('[data-hub-label]')].map((label) => label.dataset.hubLabel);

		// Shows the labels in their order, taken from the template.
		const show = (labels, ids) => {
			labels.querySelectorAll('[data-hub-label]').forEach((label) => label.remove());
			labels.append(...[...template.content.querySelectorAll('[data-hub-label]')]
				.filter((label) => ids.includes(label.dataset.hubLabel))
				.map((label) => label.cloneNode(true)));
		};

		const sync = () => {
			const ids = current ? idsOf(current) : [];
			boxes.forEach((box) => {
				box.checked = ids.includes(box.value);
			});
		};

		const save = (labels) => {
			const ids = idsOf(labels);

			if (!saved.has(labels)) {
				saved.set(labels, ids);
			}

			const request = (sending.get(labels) ?? Promise.resolve())
				.then(() => Craft.sendActionRequest('POST', 'hub/labels/apply', { data: { list: group.dataset.hubContextLabels, id: labels.dataset.hubLabels, labels: ids } }))
				.then(({ data }) => {
					saved.set(labels, data.labels.map(String));
				}, () => {
					Craft.cp.displayError(Craft.t('hub', 'The labels could not be saved.'));
				})
				.then(() => {
					// Only the last change of the item shows what was saved, so later changes aren't undone in between.
					if (sending.get(labels) === request) {
						show(labels, saved.get(labels));
						sending.delete(labels);

						if (labels === current) {
							sync();
						}
					}
				});

			sending.set(labels, request);
		};

		boxes.forEach((box) => box.addEventListener('change', () => {
			if (!current) {
				return;
			}

			show(current, boxes.filter((other) => other.checked).map((other) => other.value));
			save(current);
		}));

		return (labels) => {
			current = labels;
			sync();
		};
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
		within(root, '[data-hub-lazy]').forEach(initLazy);
		within(root, '[data-hub-info]').forEach(initInfo);
		within(root, '[data-hub-columns]').forEach(initColumns);
		within(root, 'form[data-hub-submit]').forEach(initSubmitForm);
		within(root, 'form[data-hub-autosubmit]').forEach(initAutosubmit);
		within(root, 'input[data-hub-site]').forEach(initSite);
		within(root, 'form[data-hub-bulk]').forEach(initBulk);
	};

	// Craft fixes its header to the top once scrolled, so the sticky filters keep below it.
	const header = document.getElementById('header');

	if (header) {
		new ResizeObserver(() => {
			document.documentElement.style.setProperty('--hub-header-height', `${header.offsetHeight}px`);
		}).observe(header);
	}

	init(document);
})();
