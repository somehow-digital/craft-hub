(() => {
	const valueOf = (control) => {
		if (control.type === 'checkbox') {
			return control.checked ? '1' : '';
		}

		return control.value.trim();
	};

	// Filters of a list's form as they appear in its URL, leaving out empty ones.
	const formParams = (form) => {
		const query = new URLSearchParams();

		[...form.elements].forEach((control) => {
			if (!control.name || control.disabled) {
				return;
			}

			const empty = control.type === 'checkbox' ? !control.checked : !control.value.trim();

			if (!empty) {
				query.append(control.name, control.value.trim());
			}
		});

		return query;
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

		// Scrolling fires faster than frames are drawn, and placing it reads the layout.
		let frame = null;
		const follow = () => {
			frame ??= requestAnimationFrame(() => {
				frame = null;
				position();
			});
		};

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
				window.addEventListener('resize', follow);
				window.addEventListener('scroll', follow, true);
				opened?.();
				return;
			}

			window.removeEventListener('resize', follow);
			window.removeEventListener('scroll', follow, true);
			cancelAnimationFrame(frame);
			frame = null;
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
				const values = part.querySelector('input[type="hidden"]').value.split(',').filter(Boolean);
				const boxes = [...part.querySelectorAll('input[type="checkbox"]')];

				boxes.forEach((box) => {
					box.checked = values.includes(box.value);
				});

				// Filters applied here, like saved ones, can pick options not loaded yet, which name the filter on its button.
				if (values.some((value) => !boxes.some((box) => box.value === value))) {
					loadOptions(part);
				}
			});

			render();
		};

		const changed = (input) => {
			render();
			input.dispatchEvent(new Event('input', { bubbles: true }));
		};

		// Options read from the database are only loaded as they're needed; those picked are there from the start.
		const loadOptions = async (part) => {
			if (!part.dataset.hubOptions || part.hasAttribute('aria-busy')) {
				return;
			}

			const status = part.querySelector('[data-hub-options-status]');
			part.setAttribute('aria-busy', 'true');
			status.replaceChildren(Object.assign(document.createElement('div'), { className: 'spinner' }));

			try {
				const response = await fetch(part.dataset.hubOptions, { headers: { Accept: 'text/html' } });

				if (!response.ok) {
					throw new Error(response.statusText);
				}

				const loaded = new DOMParser().parseFromString(await response.text(), 'text/html');
				const options = loaded.querySelector('.hub-dropdown__options');

				if (!options) {
					throw new Error('Missing options');
				}

				// Picked options can be ones no longer offered, like users without entries, which stay to be unpicked.
				const current = part.querySelector('.hub-dropdown__options');
				const values = [...options.querySelectorAll('input')].map((box) => box.value);
				const kept = [...current.querySelectorAll('input:checked')].filter((box) => !values.includes(box.value)).map((box) => box.closest('label'));

				if (kept.length > 0) {
					options.querySelector('p')?.remove();
					options.prepend(...kept);
				}

				current.replaceWith(options);

				const search = loaded.querySelector('.hub-dropdown__search');

				if (search) {
					options.before(search);
				}

				status.replaceChildren();
				delete part.dataset.hubOptions;
				part.dispatchEvent(new Event('hub:options'));
				sync();

				if (popover.matches(':popover-open')) {
					place(popover, toggle);

					if (!popover.contains(document.activeElement)) {
						popover.querySelector('input:not([type="hidden"])')?.focus();
					}
				}
			} catch {
				status.replaceChildren(Craft.t('hub', 'The content could not be loaded.'));
			} finally {
				part.removeAttribute('aria-busy');
			}
		};

		popover.addEventListener('beforetoggle', (event) => {
			if (event.newState === 'open') {
				choices.forEach(loadOptions);
			}
		});

		choices.forEach((part) => {
			const hidden = part.querySelector('input[type="hidden"]');
			let boxes = [];

			// There can be hundreds of choices, like users, so they share a listener and their text is read once.
			part.addEventListener('change', (event) => {
				const box = event.target;

				if (!boxes.includes(box)) {
					return;
				}

				// Single choices act like radios that can be unchecked.
				if (box.checked && 'hubSingle' in part.dataset) {
					boxes.forEach((other) => {
						other.checked = other === box;
					});
				}

				hidden.value = boxes.filter((other) => other.checked).map((other) => other.value).join(',');
				changed(hidden);
			});

			const bind = () => {
				const search = part.querySelector('[data-hub-dropdown-search]');
				boxes = [...part.querySelectorAll('input[type="checkbox"]')];

				// Enter would submit the form of lists filtered on the server.
				search?.addEventListener('keydown', (event) => {
					if (event.key === 'Enter') {
						event.preventDefault();
					}
				});

				const options = search ? boxes.map((box) => {
					const label = box.closest('label');

					return { label, text: label.textContent.toLowerCase() };
				}) : [];

				search?.addEventListener('input', () => {
					const term = search.value.trim().toLowerCase();
					options.forEach(({ label, text }) => {
						label.hidden = term !== '' && !text.includes(term);
					});
				});
			};

			bind();
			part.addEventListener('hub:options', bind);
		});

		ranges.forEach((part) => {
			part.querySelectorAll('input').forEach((input) => input.addEventListener('input', render));
		});

		followAnchor(popover, toggle, () => popover.querySelector('input:not([type="hidden"])')?.focus());

		const clear = () => {
			named.forEach((input) => {
				input.value = '';
				input.dispatchEvent(new Event('input', { bubbles: true }));
			});

			sync();
		};

		popover.querySelector('[data-hub-dropdown-clear]').addEventListener('click', () => {
			clear();

			// The button hides once nothing is set, so its focus moves into the popover.
			popover.querySelector('input:not([type="hidden"])')?.focus();
		});

		// The bar hides the dropdown once removed, moving its focus.
		dropdown.querySelector('[data-hub-dropdown-remove]')?.addEventListener('click', clear);

		dropdown.addEventListener('hub:sync', sync);
		render();
	};

	// Bars offering to add filters show only the dropdowns and checkbox filters set, along with the saved filters and the search;
	// the others are offered by the menu, which shows the dropdown picked and opens it, or checks the checkbox filter.
	// A dropdown stays once shown, even while not set, until removed by its button; the focus it had moves to the menu's button.
	const initAdd = (bar, add) => {
		const toggle = add.querySelector('.hub-add__toggle');
		const popover = add.querySelector('[popover]');
		const menu = add.querySelector('[data-hub-add-menu]');
		const filters = [...bar.querySelectorAll(':scope > [data-hub-dropdown], :scope > .hub-toggle')];
		const isDropdown = (filter) => filter.matches('[data-hub-dropdown]');
		const isSet = (filter) => (isDropdown(filter) ? filter.classList.contains('is-active') : filter.querySelector('input').checked);
		const isOpen = (filter) => isDropdown(filter) && filter.querySelector('[popover]').matches(':popover-open');
		const shown = new Set();

		const update = () => {
			let lost = false;

			filters.forEach((filter) => {
				if (isSet(filter) || isOpen(filter)) {
					shown.add(filter);
				}

				const hidden = isDropdown(filter) ? !shown.has(filter) : !isSet(filter);
				lost ||= hidden && !filter.hidden && (filter.contains(document.activeElement) || document.activeElement === document.body);
				filter.hidden = hidden;
			});

			add.hidden = filters.every((filter) => !filter.hidden);

			if (lost && !add.hidden) {
				toggle.focus();
			}
		};

		const item = (filter) => {
			const button = Object.assign(document.createElement('button'), { type: 'button', className: 'hub-add__item' });
			const label = filter.querySelector(isDropdown(filter) ? '.hub-dropdown__label' : '.hub-toggle__label').textContent.trim();
			const icon = isDropdown(filter)
				? filter.querySelector('.hub-dropdown__toggle .hub-btn-icon')?.cloneNode(true)
				: Object.assign(document.createElement('span'), { className: 'hub-toggle__box' });

			icon?.setAttribute('aria-hidden', 'true');
			button.append(...(icon ? [icon] : []), Object.assign(document.createElement('span'), { className: 'hub-add__label', textContent: label }));
			button.addEventListener('click', () => {
				popover.hidePopover();

				if (isDropdown(filter)) {
					filter.hidden = false;
					filter.querySelector('[popover]').showPopover();
					return;
				}

				const box = filter.querySelector('input');
				box.checked = true;
				box.dispatchEvent(new Event('change', { bubbles: true }));
				box.focus();
			});

			return button;
		};

		// The menu lists the filters hidden, the dropdowns first, then the checkbox filters.
		const render = () => {
			const hidden = filters.filter((filter) => filter.hidden);
			const groups = [hidden.filter(isDropdown), hidden.filter((filter) => !isDropdown(filter))].filter((group) => group.length);

			menu.replaceChildren(...groups.map((group) => {
				const element = Object.assign(document.createElement('div'), { className: 'hub-menu__group' });
				element.append(...group.map(item));

				return element;
			}));
		};

		popover.addEventListener('beforetoggle', (event) => {
			if (event.newState === 'open') {
				render();
			}
		});

		followAnchor(popover, toggle, () => menu.querySelector('button')?.focus());

		popover.addEventListener('keydown', (event) => {
			if (!['ArrowDown', 'ArrowUp'].includes(event.key)) {
				return;
			}

			const buttons = [...menu.querySelectorAll('button')];
			const index = buttons.indexOf(document.activeElement);

			event.preventDefault();
			buttons[(index + (event.key === 'ArrowDown' ? 1 : -1) + buttons.length) % buttons.length]?.focus();
		});

		// The dropdown clears itself first.
		filters.filter(isDropdown).forEach((filter) => {
			filter.querySelector('[data-hub-dropdown-remove]').addEventListener('click', () => {
				shown.delete(filter);
				update();
			});
		});

		// Dropdowns are set by their checkboxes and fields, by clearing them or the bar, and by saved filters; their state shows in their class.
		const observer = new MutationObserver(update);
		filters.filter(isDropdown).forEach((filter) => observer.observe(filter, { attributes: true, attributeFilter: ['class'] }));

		// Popovers don't bubble their toggle event.
		bar.addEventListener('toggle', update, true);
		bar.addEventListener('change', update);
		bar.addEventListener('hub:sync', update);

		update();
		bar.classList.add('is-ready');
	};

	// Filter bars offer to reset all their filters while any is set, then load the unfiltered list.
	const initBar = (bar) => {
		const add = bar.querySelector(':scope > [data-hub-add]');

		if (add) {
			initAdd(bar, add);
		}

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

	// Lazy panels load their header and body once opened, from the panel rendered by their URL; those opened from the start,
	// as their state was kept, load right away. A failed load says so in place of the spinner, and is tried again as the
	// panel opens next.
	const initLazy = (panel) => {
		let loading = false;

		const load = async () => {
			if (!panel.open || loading || !panel.dataset.hubLazy) {
				return;
			}

			const status = panel.querySelector('[data-hub-lazy-status]');
			loading = true;
			panel.setAttribute('aria-busy', 'true');
			status?.replaceChildren(Object.assign(document.createElement('div'), { className: 'spinner' }));

			try {
				const response = await fetch(panel.dataset.hubLazy, { headers: { Accept: 'text/html' } });

				if (!response.ok) {
					throw new Error(response.statusText);
				}

				const loaded = new DOMParser().parseFromString(await response.text(), 'text/html').querySelector('details[data-hub-panel]');

				if (!loaded) {
					throw new Error('Missing panel');
				}

				// The details element stays, keeping its state and handlers.
				const children = [...loaded.children];
				panel.replaceChildren(...children);
				delete panel.dataset.hubLazy;
				children.forEach(init);
			} catch {
				status?.replaceChildren(Craft.t('hub', 'The content could not be loaded.'));
			} finally {
				loading = false;
				panel.removeAttribute('aria-busy');
			}
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
			const query = formParams(form);

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
				const response = await fetch(url, { signal: request.signal, headers: { Accept: 'text/html', 'X-Hub-Filters': '1', 'X-Hub-Results': '1' } });

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

				// The fields of the saved filters name them rather than filter the list.
				if (!event.target.matches('[data-hub-dropdown-search], [data-hub-saved] input')) {
					load();
				}
			}
		});

		form.addEventListener('submit', (event) => {
			event.preventDefault();
			load();
		});
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

	// Preview target menus of entries with several targets: a popover below the link button, aligned to its right edge as it is in the last column.
	const initVisit = (popover) => {
		followAnchor(popover, document.querySelector(`[popovertarget="${popover.id}"]`), undefined, 'right');
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

	// Craft styles buttons as disabled by their class only, not by the attribute.
	const disable = (button, disabled) => {
		button.disabled = disabled;
		button.classList.toggle('disabled', disabled);
	};

	// Asks to confirm an action in a modal dialog rather than the browser's own, resolving to whether it was confirmed: titled
	// and confirmed by the action (`label`), its button colored as destructive unless it isn't (`danger`), asking the
	// `message`, followed by an optional `text` naming what it applies to. The dialog is made once, and a dialog opened
	// while another one asks resolves that one as not confirmed.
	let confirmation = null;
	let settle = null;

	const confirmDialog = ({ message, text = '', label = Craft.t('app', 'Continue'), danger = true }) => {
		if (!confirmation) {
			confirmation = Object.assign(document.createElement('dialog'), { className: 'hub-preview-dialog hub-confirm-dialog' });
			confirmation.setAttribute('aria-labelledby', 'hub-confirm-title');
			confirmation.setAttribute('aria-describedby', 'hub-confirm-message');
			confirmation.innerHTML = `
				<h2 class="hub-popover__title hub-preview-dialog__title" id="hub-confirm-title"></h2>
				<div class="hub-preview-dialog__body hub-confirm-dialog__body">
					<p id="hub-confirm-message"></p>
					<p class="hub-muted" id="hub-confirm-text"></p>
				</div>
				<div class="hub-preview-dialog__footer">
					<button type="button" class="btn" value="cancel"></button>
					<button type="button" class="btn" value="confirm"></button>
				</div>`;

			// A click on the backdrop lands on the dialog itself; Escape closes it without a value.
			confirmation.addEventListener('click', (event) => {
				const button = event.target === confirmation ? null : event.target.closest('button[value]');

				if (event.target === confirmation || button) {
					confirmation.close(button?.value ?? 'cancel');
				}
			});

			confirmation.addEventListener('close', () => {
				const resolve = settle;
				settle = null;
				resolve?.(confirmation.returnValue === 'confirm');
			});

			document.body.append(confirmation);
		}

		if (confirmation.open) {
			confirmation.close();
		}

		const [cancel, confirm] = confirmation.querySelectorAll('button');
		confirmation.querySelector('#hub-confirm-title').textContent = label;
		confirmation.querySelector('#hub-confirm-message').textContent = message;
		confirmation.querySelector('#hub-confirm-text').textContent = text;
		confirmation.querySelector('#hub-confirm-text').hidden = !text;
		cancel.textContent = Craft.t('app', 'Cancel');
		confirm.textContent = label;
		confirm.className = `btn ${danger ? 'hub-btn--danger' : 'submit'}`;
		confirmation.returnValue = '';

		return new Promise((resolve) => {
			settle = resolve;
			confirmation.showModal();
			cancel.focus();
		});
	};

	// Craft asks for confirmation in the browser's own dialog, as for the actions of the save button's menu and deleting
	// the rows of admin tables, so on the Hub's pages it asks in the Hub's instead, labeled after the action picked.
	const submitForm = Craft.submitForm.bind(Craft);

	Craft.submitForm = (form, options = {}) => {
		if (!options.confirm) {
			submitForm(form, options);
			return;
		}

		const trigger = options.data?.customTrigger;

		confirmDialog({ message: options.confirm, label: trigger?.text().trim() || undefined, danger: trigger?.hasClass('error') ?? true })
			.then((confirmed) => confirmed && submitForm(form, { ...options, confirm: false }));
	};

	Craft.AdminTable.prototype.confirmDeleteItem = function ($row) {
		if (!this.settings.confirmDeleteMessage) {
			return true;
		}

		confirmDialog({ message: Craft.t('app', this.settings.confirmDeleteMessage, { name: $row.attr(this.settings.nameAttribute) }), label: Craft.t('app', 'Delete') })
			.then((confirmed) => confirmed && this.deleteItem($row));

		return false;
	};

	// For the scripts of the Hub's templates.
	window.Hub = { ...window.Hub, confirm: confirmDialog };

	// Deleting and downloading preview what they take along in a modal dialog of their own first (see the `previewDialog`
	// macro), loaded by pages from its preview action. It returns the function opening it, for the item it applies to
	// (`name`) or the number of items (`count`), with the `data` the preview is loaded with, what confirming it does
	// (`apply`), and what is done in its place if the preview can't be loaded (`fallback`).
	const initPreview = (preview) => {
		const title = preview.querySelector('[data-hub-preview-title]');
		const content = preview.querySelector('[data-hub-preview-body]');
		const cancel = preview.querySelector('[data-hub-preview-cancel]');
		const confirm = preview.querySelector('[data-hub-preview-confirm]');
		const action = preview.dataset.hubPreviewAction ?? 'hub/bulk/preview';
		let pending = null;
		let request = 0;

		cancel.addEventListener('click', () => preview.close());

		// A click on the backdrop lands on the dialog itself.
		preview.addEventListener('click', (event) => {
			if (event.target === preview) {
				preview.close();
			}
		});

		// Closing it drops the preview still loading.
		preview.addEventListener('close', () => {
			request++;
		});

		confirm.addEventListener('click', () => {
			preview.close();
			pending.apply();
		});

		// Loads a page of the preview of the action pending. The first one shows a spinner in place of the preview; later
		// ones keep the page shown until the next is there, focusing the link to the page it was loaded by again.
		const load = async (page, first = false, link = null) => {
			const current = ++request;

			if (first) {
				content.replaceChildren(
					Object.assign(document.createElement('div'), { className: 'spinner' }),
					Object.assign(document.createElement('span'), { className: 'hub-muted', textContent: content.dataset.loading }),
				);
				content.classList.add('is-loading');
			} else {
				content.setAttribute('aria-busy', 'true');
			}

			try {
				const { data } = await Craft.sendActionRequest('POST', action, { data: { ...pending.data, page } });

				if (current !== request) {
					return;
				}

				content.classList.remove('is-loading');
				content.removeAttribute('aria-busy');
				content.innerHTML = data.html;
				initDeletion(content);
				initExport(content);
				disable(confirm, data.total === 0);

				if (link) {
					(content.querySelector(`a.page-link.${link}`) ?? content.querySelector('a.page-link'))?.focus();
				}
			} catch {
				if (current !== request) {
					return;
				}

				// The page shown stays, to try again.
				if (!first) {
					content.removeAttribute('aria-busy');
					Craft.cp.displayError(Craft.t('hub', 'The page could not be loaded.'));

					return;
				}

				preview.close();
				pending.fallback();
			}
		};

		// The pages of its items load in place, rather than leaving the page.
		content.addEventListener('click', (event) => {
			const link = event.target.closest('a.page-link');
			const pages = link?.closest('[data-hub-preview-pages]');

			if (!pages) {
				return;
			}

			event.preventDefault();
			const prev = link.classList.contains('prev-page');
			load(Number(pages.dataset.hubPreviewPages) + (prev ? -1 : 1), false, prev ? 'prev-page' : 'next-page');
		});

		return (options) => {
			pending = options;
			title.textContent = options.name !== undefined
				? title.dataset.one.replace('{name}', options.name)
				: title.dataset.some.replace('{count}', options.count.toLocaleString());
			disable(confirm, true);
			load(1, true);
			preview.showModal();
			cancel.focus();
		};
	};

	// Links applying a previewed action to a single item, like downloading a file from its page, open its preview first
	// (`data-hub-preview-open`, naming the dialog), loaded with their params, and follow the link once it is confirmed, or
	// right away if it can't be loaded. Clicks opening the link elsewhere are left to the browser.
	const initPreviewLink = (link) => {
		const preview = document.getElementById(link.dataset.hubPreviewOpen);

		if (!preview) {
			return;
		}

		const open = initPreview(preview);
		const follow = () => window.location.assign(link.href);

		link.addEventListener('click', (event) => {
			if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
				return;
			}

			event.preventDefault();
			open({ name: link.dataset.hubPreviewName, data: JSON.parse(link.dataset.hubPreviewParams), apply: follow, fallback: follow });
		});
	};

	// Bulk actions: rows are selected by their checkbox, or all at once by that of the header.
	// Clicking a row toggles it like its checkbox, keeping the others selected; shift-clicking a row or checkbox sets the rows
	// between it and the one clicked before to its new state.
	// Lists keeping their selection on the server (see the `bulk` component) send each change to it, select the page or all
	// items matching the filters from a menu, and offer the actions in a menu while reviewing the selection, applying them to all of it.
	// Other lists submit their checked rows with the action picked from the menu, which is only offered while any is
	// selected, and select all rows, none or the others with the buttons of the bar.
	// Labeling opens the labels of the selection in a modal dialog, adding and removing those picked there once applied.
	// Right-clicking a row opens its context menu, applying the actions to its item only, in place where they can be.
	// Lists the user may not bulk-edit (`single`) have no checkboxes or bar, so their rows aren't selected, only their
	// context menus applying the actions.
	const initBulk = (form) => {
		const all = form.querySelector('[data-hub-bulk-all]');
		const items = [...form.querySelectorAll('[data-hub-bulk-item]')];
		const count = form.querySelector('[data-hub-bulk-count]');
		const toggle = form.querySelector('[data-hub-bulk-toggle]');
		const selects = [...form.querySelectorAll('[data-hub-bulk-select]')];
		const applies = [...form.querySelectorAll('[data-hub-bulk-apply]')];
		const review = form.querySelector('[data-hub-bulk-review-link]');
		const outsideCount = form.querySelector('[data-hub-bulk-outside-count]');
		const menuCounts = [...form.querySelectorAll('[data-hub-bulk-menu-count]')];
		const menu = form.querySelector('.hub-bulk__menu:not([data-hub-context], [data-hub-bulk-actions])');
		const actions = form.querySelector('[data-hub-bulk-actions]');
		const actionsToggle = form.querySelector('[data-hub-bulk-actions-toggle]');
		const context = form.querySelector('[data-hub-context]');
		const dialog = form.querySelector('[data-hub-bulk-labels-dialog]');
		const body = form.querySelector('tbody');
		const kept = 'hubBulkPath' in form.dataset;
		const single = 'hubBulkSingle' in form.dataset;
		const selection = kept ? selectionOf(form) : null;
		const limit = Number(form.dataset.hubBulkLimit);
		// More items match the filters than can be selected at once.
		const tooMany = form.querySelector('[data-hub-bulk-over]') !== null;
		let outside = Number(form.dataset.hubBulkOutside ?? 0);
		let anchor = null;

		if (!body || (!toggle && !kept)) {
			return;
		}

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

			if (single) {
				return;
			}

			all.checked = selected > 0 && selected === items.length;
			all.indeterminate = partial;
			count.textContent = size ? label(count, size) : count.dataset.none;

			menuCounts.forEach((menuCount) => {
				menuCount.textContent = label(menuCount, size);
			});

			selects.forEach((button) => disable(button, !enabled[button.dataset.hubBulkSelect]));
			applies.forEach((button) => disable(button, size === 0));
			items.forEach((item) => item.closest('tr').classList.toggle('is-selected', item.checked));

			if (outsideCount) {
				outsideCount.hidden = outside === 0;
				outsideCount.textContent = label(outsideCount, outside);
			}

			if (!size && actions?.matches(':popover-open')) {
				actions.hidePopover();
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

		all?.addEventListener('change', () => selectEach(() => all.checked));

		const states = { all: () => true, none: () => false, invert: (checked) => !checked };
		const values = items.map((item) => item.value);

		const selectAll = {
			matching: () => changeSelection(selection, 'matching', values),
			unmatching: () => changeSelection(selection, 'unmatching', [], values),
			clear: (button) => {
				// Clearing a selection reaching beyond the page asks first, as it can't be undone.
				const message = button.dataset.hubConfirm;
				const clear = () => {
					outside = 0;
					changeSelection(selection, 'clear');
					render();
				};

				if (message && selection.ids.size > items.filter((item) => item.checked).length) {
					confirmDialog({ message: message.replace('{count}', selection.ids.size.toLocaleString()), label: button.textContent.trim() })
						.then((confirmed) => confirmed && clear());
					return;
				}

				clear();
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
			if (!single && (event.shiftKey || event.ctrlKey || event.metaKey) && rowItem(event)) {
				event.preventDefault();
			}
		});

		body.addEventListener('click', (event) => {
			const item = single ? null : rowItem(event);

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
		// while the menu is open. The menu offers what changes the item as it is, and stays open as the item is selected and
		// actions are applied to it in place.
		let target = null;
		let applyInPlace = null;

		if (context) {
			const name = context.querySelector('[data-hub-context-name]');
			const open = context.querySelector('[data-hub-context-open]');
			const select = context.querySelector('[data-hub-context-select]');
			const selectText = select?.querySelector('[data-select]');
			const actions = [...context.querySelectorAll('[data-hub-context-action]')];
			const actionGroup = context.querySelector('[data-hub-context-actions]');
			const labeling = context.querySelector('[data-hub-context-labels]');
			const editLabels = labeling ? initLabels(labeling) : null;
			const entries = () => [...context.querySelectorAll('[role="menuitem"], [data-hub-context-labels] input')]
				.filter((entry) => !entry.closest('[hidden]') && !entry.disabled);
			const close = () => context.hidePopover();

			// Leaves out the actions the item is already in the state of, keeping the focus in the menu.
			const contextual = () => {
				const states = (target.dataset.hubBulkStates ?? '').split(' ');

				if (selectText) {
					selectText.textContent = target.checked ? selectText.dataset.unselect : selectText.dataset.select;
				}

				actions.forEach((action) => {
					action.hidden = states.includes(action.dataset.hubContextUnless);
				});

				if (actionGroup) {
					actionGroup.hidden = actions.every((action) => action.hidden);
				}

				if (context.matches(':popover-open') && !entries().includes(document.activeElement)) {
					entries()[0]?.focus();
				}
			};

			// Shows the row as it is now, from the page loaded again, keeping its checkbox.
			const refresh = async (row, item) => {
				const response = await fetch(window.location.href, { headers: { Accept: 'text/html', 'X-Hub-Results': '1' } });

				if (!response.ok) {
					throw new Error(response.statusText);
				}

				const page = new DOMParser().parseFromString(await response.text(), 'text/html');
				const fresh = [...page.querySelectorAll('[data-hub-bulk-item]')].find((other) => other.value === item.value);
				const freshRow = fresh?.closest('tr');

				if (!freshRow) {
					return;
				}

				[...row.attributes].filter(({ name: attribute }) => attribute !== 'class' && !freshRow.hasAttribute(attribute))
					.forEach(({ name: attribute }) => row.removeAttribute(attribute));
				[...freshRow.attributes].filter(({ name: attribute }) => attribute !== 'class')
					.forEach(({ name: attribute, value }) => row.setAttribute(attribute, value));

				const cells = [...freshRow.children];
				[...row.children].forEach((cell, index) => {
					if (!cell.contains(item) && cells[index]) {
						cell.replaceWith(cells[index]);
						init(cells[index]);
					}
				});

				if (fresh.dataset.hubBulkStates) {
					item.dataset.hubBulkStates = fresh.dataset.hubBulkStates;
				} else {
					delete item.dataset.hubBulkStates;
				}
			};

			// The item applied to is unselected, as from the bar.
			if (kept) {
				applyInPlace = async (submitter, item) => {
					const row = item.closest('tr');
					disable(submitter, true);

					try {
						await selection.working;
						const { data } = await Craft.sendActionRequest('POST', 'hub/bulk/apply', {
							data: { list: selection.list, path: selection.path, bulkAction: submitter.value, item: item.value },
						});

						selection.ids.delete(item.value);
						render();
						Craft.cp.displaySuccess(data.message);
						await refresh(row, item).catch(() => {});
					} catch (error) {
						Craft.cp.displayError(error?.response?.data?.message ?? Craft.t('hub', 'The action could not be applied.'));
					}

					disable(submitter, false);

					if (target === item) {
						contextual();

						if (labeling) {
							editLabels(row.querySelector('[data-hub-labels-editable]'));
						}
					}
				};
			}

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
				contextual();

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

			select?.addEventListener('click', () => {
				target.checked = !target.checked;
				anchor = target;
				changed();
				contextual();
			});
		}

		// The labels of the selection are read once they're opened, as it is kept on the server. Each label is checked (`all`),
		// unchecked (`none`) or partly checked (`some`), which only labels partly checked when opened return to, as they're
		// checked, unchecked and then left as they were. The labels changed are sent with the action once applied.
		const labelStates = new Map();
		const initialStates = new Map();
		const labelBoxes = dialog ? [...dialog.querySelectorAll('input[type="checkbox"]')] : [];

		if (dialog && kept) {
			const title = dialog.querySelector('[data-hub-bulk-labels-name]');
			const applyLabels = dialog.querySelector('[data-hub-bulk-labels-apply]');
			const cancel = dialog.querySelector('[data-hub-bulk-labels-cancel]');
			let request = 0;

			const show = (box) => {
				box.checked = labelStates.get(box) === 'all';
				box.indeterminate = labelStates.get(box) === 'some';
			};

			const update = () => applyLabels && disable(applyLabels, labelBoxes.every((box) => labelStates.get(box) === initialStates.get(box)));

			const load = async () => {
				const current = ++request;

				labelBoxes.forEach((box) => {
					box.disabled = true;
				});
				title.textContent = label(title, total());
				labelStates.clear();
				initialStates.clear();
				update();

				try {
					await selection.working;
					const { data } = await Craft.sendActionRequest('POST', 'hub/labels/counts', { data: { list: selection.list, path: selection.path } });

					if (current !== request) {
						return;
					}

					title.textContent = label(title, data.total);
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
					if (current !== request) {
						return;
					}

					Craft.cp.displayError(Craft.t('hub', 'The labels could not be loaded.'));
					dialog.close();
				}
			};

			labelBoxes.forEach((box) => box.addEventListener('change', () => {
				const next = { some: 'all', all: 'none', none: initialStates.get(box) === 'some' ? 'some' : 'all' };
				labelStates.set(box, next[labelStates.get(box)]);
				show(box);
				update();
			}));

			cancel.addEventListener('click', () => dialog.close());

			// A click on the backdrop lands on the dialog itself.
			dialog.addEventListener('click', (event) => {
				if (event.target === dialog) {
					dialog.close();
				}
			});

			// Closing it drops the labels still loading.
			dialog.addEventListener('close', () => {
				request++;
			});

			form.querySelectorAll('[data-hub-bulk-labels]').forEach((opener) => opener.addEventListener('click', () => {
				menu?.matches(':popover-open') && menu.hidePopover();
				actions?.matches(':popover-open') && actions.hidePopover();
				dialog.showModal();
				cancel.focus();
				load();
			}));
		}

		// Actions previewed open their preview first (see initPreview()), for the selection as sent, the rows checked in
		// lists not keeping it, or the item of the context menu; confirming it submits the form with the same button, as does
		// confirming other actions asking for it. Without a preview, as it couldn't be loaded, they ask for confirmation like
		// other actions do, if they ask for any, unless they pick what they change the items to in it (`required`).
		const previews = new Map([...form.querySelectorAll('[data-hub-preview-dialog]')]
			.map((preview) => [preview.dataset.hubPreviewDialog, initPreview(preview)]));
		let confirmedBy = null;

		const submitConfirmed = (submitter) => {
			confirmedBy = submitter;
			form.requestSubmit(submitter);
		};

		// Asks to confirm the action of the button, if it asks for it, before submitting it.
		const confirmSubmit = (submitter, one) => {
			const message = submitter.dataset.hubConfirm;

			if (!message) {
				submitConfirmed(submitter);
				return;
			}

			confirmDialog({ message, text: one ? target.dataset.hubBulkName : label(count, total()), label: submitter.textContent.trim() })
				.then((confirmed) => confirmed && submitConfirmed(submitter));
		};

		// The fields sent with the action, which its preview is loaded with: the selection kept, or the fields of the form,
		// like the rows checked, and the item alone in their place for one picked from its context menu.
		const previewData = (submitter, one) => {
			if (kept) {
				return { list: selection.list, path: selection.path, bulkAction: submitter.value, ...(one ? { item: target.value } : {}) };
			}

			const data = { bulkAction: submitter.value };
			const add = (key, value) => {
				if (key.endsWith('[]')) {
					(data[key.slice(0, -2)] ??= []).push(value);
				} else {
					data[key] = value;
				}
			};

			new FormData(form).forEach((value, key) => {
				if (!['action', 'redirect', Craft.csrfTokenName].includes(key) && !(one && key === target.name)) {
					add(key, value);
				}
			});

			if (one) {
				add(target.name, target.value);
			}

			return data;
		};

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

			const confirmed = submitter !== null && confirmedBy === submitter;
			confirmedBy = null;

			const openPreview = submitter?.hasAttribute('data-hub-preview') && previews.get(submitter.value);

			if ((openPreview || message) && !confirmed) {
				event.preventDefault();
				menu?.hidePopover();
				actions?.hidePopover();
				context?.hidePopover();

				if (!openPreview) {
					confirmSubmit(submitter, single);
					return;
				}

				openPreview({
					...(single ? { name: target.dataset.hubBulkName } : { count: total() }),
					data: previewData(submitter, single),
					apply: () => submitConfirmed(submitter),
					fallback: () => submitter.hasAttribute('data-hub-preview-required')
						? Craft.cp.displayError(Craft.t('hub', 'The preview could not be loaded.'))
						: confirmSubmit(submitter, single),
				});
				return;
			}

			if (single && applyInPlace && submitter.hasAttribute('data-hub-context-inline')) {
				event.preventDefault();
				applyInPlace(submitter, target);
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
				dialog.close();
				const changedBoxes = labelBoxes.filter((box) => labelStates.get(box) !== initialStates.get(box));
				submitOnce([
					...changedBoxes.filter((box) => labelStates.get(box) === 'all').map((box) => hidden('labelsAdd[]', box.value)),
					...changedBoxes.filter((box) => labelStates.get(box) === 'none').map((box) => hidden('labelsRemove[]', box.value)),
				]);
			}

			menu?.hidePopover();
			actions?.hidePopover();
			context?.hidePopover();
		});

		if (kept) {
			selection.render = render;
		}

		// The bar is in the footer, sticking to the bottom of the viewport, so the menu opens above it.
		if (menu) {
			followAnchor(menu, toggle, undefined, 'right', 'above');
		}

		// While reviewing, the actions are in a menu in place of the filters, at the top, so it opens below its button.
		if (actions) {
			followAnchor(actions, actionsToggle, () => actions.querySelector('button')?.focus(), 'right');
		}

		render();
	};

	// The items of a deletion preview expand to what deleting each takes along; its button expands or collapses all of them.
	const initDeletion = (root) => {
		const toggle = root.querySelector('[data-hub-deletion-toggle]');
		const items = [...root.querySelectorAll('details.hub-deletion__item')];

		if (!toggle) {
			return;
		}

		const update = () => {
			toggle.textContent = items.every((item) => item.open) ? toggle.dataset.collapse : toggle.dataset.expand;
		};

		toggle.addEventListener('click', () => {
			const open = !items.every((item) => item.open);
			items.forEach((item) => {
				item.open = open;
			});
			update();
		});

		items.forEach((item) => item.addEventListener('toggle', update));
	};

	// The format of an export preview is only picked for exporters whose data is formatted, as Craft's export does; the
	// others send a file of their own, so it is disabled for them, and left out when the form is sent.
	const initExport = (root) => {
		const exporter = root.querySelector('[data-hub-export-exporter]');
		const format = root.querySelector('[data-hub-export-format]');

		if (!exporter || !format) {
			return;
		}

		const update = () => {
			const formattable = exporter.selectedOptions[0]?.dataset.formattable === '1';
			format.classList.toggle('hidden', !formattable);
			format.querySelector('select').disabled = !formattable;
		};

		exporter.addEventListener('change', update);
		update();
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

	// Batches of the activity log show and hide the rows of their activities, which follow them. Their chevron, their
	// number of changes and clicks anywhere else on their row, past its links and buttons or selecting text, toggle them.
	const initBatchRow = (row) => {
		row.addEventListener('click', (event) => {
			if (event.target.closest('a, button, input, label') || String(window.getSelection())) {
				return;
			}

			row.querySelector('button.hub-activity-chevron')?.click();
		});
	};

	const initBatch = (toggle) => {
		toggle.addEventListener('click', () => {
			const expanded = toggle.getAttribute('aria-expanded') !== 'true';
			const tbody = toggle.closest('tbody');

			tbody.querySelectorAll(`button[data-hub-batch="${toggle.dataset.hubBatch}"]`).forEach((button) => {
				button.setAttribute('aria-expanded', String(expanded));
			});
			tbody.querySelectorAll(`tr[data-hub-batch-of="${toggle.dataset.hubBatch}"]`).forEach((row) => {
				row.hidden = !expanded;
			});
		});
	};

	// Saved filters of a list: picking one sets the list's filters to those it saved, keeping the sorting, and loads the
	// results like any filter. Those the list shows are checked, the first named on the button, or else the custom filter applied,
	// which is a filter of its own. The user's own filters are saved from those the list shows, under a new name or the
	// name of one to replace, renamed in place and deleted; the menu is rendered again by the server after each change.
	const initSaved = (saved) => {
		const form = saved.closest('form[data-hub-submit]');
		const toggle = saved.querySelector('.hub-dropdown__toggle');
		const popover = saved.querySelector('[popover]');
		const output = saved.querySelector('[data-hub-saved-value]');
		const current = saved.querySelector('[data-hub-saved-current]');
		const input = saved.querySelector('[data-hub-saved-input]');
		const save = saved.querySelector('[data-hub-saved-save]');
		const hint = saved.querySelector('[data-hub-saved-hint]');

		if (!form) {
			return;
		}

		// Listening to the form stops once the menu is swapped.
		const listening = new AbortController();
		const items = () => [...saved.querySelectorAll('[data-hub-saved-item]')];
		const nameOf = (item) => item.querySelector('[data-hub-saved-name]').textContent.trim();
		const paramsOf = (item) => Object.entries(JSON.parse(item.dataset.params || '{}')).map(([name, value]) => [name, String(value)]);

		// Filters as they're saved: the sorting and the review of the selection left out, the values of each in any order.
		const normalize = (entries) => JSON.stringify(entries
			.filter(([name, value]) => value !== '' && !['sort', 'dir', 'selection'].includes(name))
			.map(([name, value]) => [name, value.split(',').sort().join(',')])
			.sort(([a], [b]) => (a < b ? -1 : a > b ? 1 : 0)));

		const shown = () => normalize([...formParams(form)]);

		const updateSave = () => {
			const name = input.value.trim().toLowerCase();
			const empty = shown() === '[]';
			const existing = [...saved.querySelectorAll('[data-hub-saved-own] [data-hub-saved-item]')].map(nameOf).find((other) => other.toLowerCase() === name);

			save.disabled = empty || !name;
			save.textContent = existing ? save.dataset.update : save.dataset.save;
			hint.textContent = empty ? hint.dataset.empty : existing ? hint.dataset.update.replace('{name}', existing) : '';
			hint.hidden = !hint.textContent;
		};

		const render = () => {
			const state = shown();
			const matching = state !== '[]' ? items().filter((item) => normalize(paramsOf(item)) === state) : [];
			const active = matching[0];
			const custom = current.value ? items().find((item) => item.dataset.custom === current.value) : null;

			// A user's own filter may save the same filters as one offered to everyone, which are all checked.
			items().forEach((item) => {
				matching.includes(item) ? item.setAttribute('aria-current', 'true') : item.removeAttribute('aria-current');
			});

			output.textContent = active || custom ? nameOf(active ?? custom) : '';
			saved.classList.toggle('is-active', Boolean(active || custom));
			updateSave();
		};

		const apply = (item) => {
			const params = new URLSearchParams(paramsOf(item));

			[...form.elements].forEach((control) => {
				if (!control.name || ['sort', 'dir'].includes(control.name)) {
					return;
				}

				control.type === 'checkbox' ? (control.checked = params.get(control.name) === control.value) : (control.value = params.get(control.name) ?? '');
				control.dispatchEvent(new Event('hub:sync', { bubbles: true }));
			});

			popover.hidePopover();
			form.requestSubmit();
		};

		// The menu is swapped with the one the server rendered again, opened again if it was.
		const replace = (html) => {
			const next = document.createRange().createContextualFragment(html).querySelector('[data-hub-saved]');
			const open = popover.matches(':popover-open');

			listening.abort();
			saved.replaceWith(next);
			initSaved(next);

			if (open) {
				next.querySelector('[popover]').showPopover();
				next.querySelector('[data-hub-saved-input]').focus();
			}
		};

		const send = async (action, data, fallback) => {
			try {
				const { data: response } = await Craft.sendActionRequest('POST', action, {
					data: { kind: saved.dataset.kind, path: saved.dataset.path, current: current.value, ...data },
				});

				replace(response.html);
				Craft.cp.displaySuccess(response.message);

				return true;
			} catch (error) {
				Craft.cp.displayError(error?.response?.data?.message ?? fallback);

				return false;
			}
		};

		const submit = () => {
			if (save.disabled) {
				return;
			}

			disable(save, true);
			send('hub/filters/save-own', { name: input.value.trim(), params: Object.fromEntries(formParams(form)) }, Craft.t('hub', 'Couldn’t save the filter.'))
				.then((sent) => !sent && disable(save, false));
		};

		// The name turns into a field in place; Enter or leaving it renames the filter, Escape keeps its name.
		const rename = (row) => {
			const link = row.querySelector('[data-hub-saved-item]');
			const name = nameOf(link);
			const field = Object.assign(document.createElement('input'), { type: 'text', className: 'text hub-saved__rename', value: name, maxLength: 255 });
			let done = false;

			const finish = async (commit) => {
				if (done) {
					return;
				}

				done = true;
				const value = field.value.trim();

				if (commit && value && value !== name && await send('hub/filters/rename-own', { id: row.dataset.hubSavedOwn, name: value }, Craft.t('hub', 'Couldn’t save the filter.'))) {
					return;
				}

				field.remove();
				link.hidden = false;
				link.focus();
			};

			field.setAttribute('aria-label', Craft.t('app', 'Rename'));
			field.addEventListener('keydown', (event) => {
				if (event.key === 'Enter' || event.key === 'Escape') {
					event.preventDefault();
					event.stopPropagation();
					finish(event.key === 'Enter');
				}
			});
			field.addEventListener('blur', () => finish(true));

			link.hidden = true;
			row.prepend(field);
			field.focus();
			field.select();
		};

		const remove = async (row) => {
			const name = nameOf(row.querySelector('[data-hub-saved-item]'));
			const confirmed = await confirmDialog({
				label: Craft.t('app', 'Delete'),
				message: Craft.t('hub', 'Are you sure you want to delete “{name}”?', { name }),
			});

			if (confirmed) {
				send('hub/filters/delete-own', { id: row.dataset.hubSavedOwn }, Craft.t('hub', 'Couldn’t delete the filter.'));
			}
		};

		// Links open the list on its own when opened in a new tab or window.
		saved.addEventListener('click', (event) => {
			const item = event.target.closest('[data-hub-saved-item]');
			const row = event.target.closest('[data-hub-saved-own]');

			if (item && !event.metaKey && !event.ctrlKey && !event.shiftKey && !event.altKey && event.button === 0) {
				event.preventDefault();
				apply(item);
			} else if (event.target.closest('[data-hub-saved-rename]')) {
				rename(row);
			} else if (event.target.closest('[data-hub-saved-delete]')) {
				remove(row);
			} else if (event.target.closest('[data-hub-saved-save]')) {
				submit();
			}
		});

		input.addEventListener('input', updateSave);
		input.addEventListener('keydown', (event) => {
			if (event.key === 'Enter') {
				event.preventDefault();
				submit();
			}
		});

		followAnchor(popover, toggle);

		// Clearing the filters of the bar clears the custom filter too.
		saved.addEventListener('hub:sync', render);
		form.addEventListener('input', render, { signal: listening.signal });
		form.addEventListener('change', render, { signal: listening.signal });
		form.addEventListener('hub:results', render, { signal: listening.signal });
		render();
	};

	// Previews of the settings forms follow their fields as they're changed: `data-hub-mirror` shows the value of the field with
	// that ID, or the text of its selected option, `data-hub-mirror-color` sets the color to it, and `data-hub-mirror-icon` the icon
	// of the icon picker with that ID. Values are mapped by the JSON of `data-hub-mirror-labels` and `data-hub-mirror-colors`, if
	// any; blank ones show `data-hub-mirror-fallback`, text or the markup of an icon, and gray.
	const initMirror = (element) => {
		const { hubMirror: text, hubMirrorColor: color, hubMirrorIcon: icon, hubMirrorFallback: fallback = '' } = element.dataset;
		const labels = JSON.parse(element.dataset.hubMirrorLabels ?? 'null');
		const colors = JSON.parse(element.dataset.hubMirrorColors ?? 'null');

		const follow = (id, update) => {
			const field = document.getElementById(id);

			if (!field) {
				return;
			}

			// Craft's selectized selects, like the color select, trigger their changes through jQuery only, and drop the handlers
			// bound to them as they're set up. A select may start on another option than the one shown, like the first severity
			// of a new check.
			field.addEventListener('input', () => update(field));
			$(document).on('change', `#${CSS.escape(id)}`, () => update(field));
			update(field);
		};

		follow(text, (field) => {
			const value = field.value.trim();
			const label = labels ? labels[value] : field.selectedOptions?.[0]?.textContent.trim() ?? value;
			element.textContent = label || fallback;
		});

		follow(color, (field) => {
			const value = field.value === '__blank__' ? '' : field.value;
			element.dataset.color = (colors ? colors[value] : value) || 'gray';
		});

		// The icon picker shows the picked icon itself, which is copied as it's swapped.
		const picker = icon && document.querySelector(`#${CSS.escape(icon)} .icon-picker--icon`);

		if (picker) {
			new MutationObserver(() => {
				element.innerHTML = picker.innerHTML.trim() || fallback;
			}).observe(picker, { childList: true });
		}
	};

	// Charts of the statistics cards draw a line of each series, sized to their width, on an axis of time; hovering or moving
	// along them with the arrow keys shows the values of every line shown on a day. Lines are shown or hidden by their
	// buttons, remembered per card, and plotted as their counts or their change since the first day, so small ones aren't
	// flattened by large ones, remembered for all charts. A range reloads the chart and the change in the panel's header,
	// keeping the frame meanwhile.
	const initChart = (chart) => {
		const { dates, series } = JSON.parse(chart.dataset.hubChart);
		const plot = chart.querySelector('[data-hub-chart-plot]');
		const storageKey = `hub:chart:${chart.dataset.hubChartKey}`;
		const svgNs = 'http://www.w3.org/2000/svg';
		const lang = document.documentElement.lang || undefined;
		const numberFormat = new Intl.NumberFormat(lang);
		const compactFormat = new Intl.NumberFormat(lang, { notation: 'compact', maximumFractionDigits: 1 });
		const day = 86400000;
		const times = dates.map((date) => Date.UTC(...date.split('-').map((part, i) => Number(part) - (i === 1 ? 1 : 0))));
		const height = 220;
		const margin = { top: 12, right: 16, bottom: 28, left: 8 };
		const modeKey = 'hub:chart:mode';
		let hidden;
		let mode = 'count';
		let active = null;

		try {
			mode = window.localStorage.getItem(modeKey) === 'change' ? 'change' : 'count';
		} catch {
			// Storage can be unavailable, e.g. in private windows.
		}

		try {
			hidden = new Set(JSON.parse(window.localStorage.getItem(storageKey) ?? '[]'));
		} catch {
			hidden = new Set();
		}

		// Unknown ones are dropped, and one is always shown.
		hidden = new Set([...hidden].filter((key) => series.some((line) => line.key === key)));

		if (hidden.size >= series.length) {
			hidden.clear();
		}

		const svg = (name, attributes = {}) => {
			const element = document.createElementNS(svgNs, name);
			Object.entries(attributes).forEach(([attribute, value]) => element.setAttribute(attribute, value));

			return element;
		};

		const colored = (element, line) => {
			if (line.color) {
				element.dataset.color = line.color;
			}

			return element;
		};

		// Round steps of 1, 2 or 5 of a power of ten, spanning the values.
		const ticks = (min, max, count = 4) => {
			const raw = (max - min) / count;
			const power = 10 ** Math.floor(Math.log10(raw));
			const step = Math.max(1, [1, 2, 5, 10].map((factor) => factor * power).find((candidate) => candidate >= raw));
			const values = [];

			for (let value = Math.floor(min / step) * step; value < max + step; value += step) {
				values.push(value);

				if (value >= max) {
					break;
				}
			}

			return values;
		};

		const draw = () => {
			const width = plot?.clientWidth ?? 0;

			// Collapsed panels are drawn once opened.
			if (width === 0) {
				return;
			}

			// Changes are since the first value of a line.
			const shown = series.filter((line) => !hidden.has(line.key)).map((line) => {
				const first = line.values.find((value) => value !== null);

				return mode === 'change' ? { ...line, values: line.values.map((value) => (value === null ? null : value - first)) } : line;
			});
			const signed = (value) => (mode === 'change' && value > 0 ? '+' : '') + numberFormat.format(value);
			const values = shown.flatMap((line) => line.values).filter((value) => value !== null);
			let min = Math.min(...values);
			let max = Math.max(...values);

			// Counts start at zero, unless they change little next to their size, which a zero would flatten; changes span it.
			if (mode === 'change') {
				min = Math.min(min, 0);
				max = Math.max(max, 0);
			} else if (min >= 0 && min <= max - min) {
				min = 0;
			}

			if (min === max) {
				max = min + 1;
			}

			const yTicks = ticks(min, max);
			const yMin = yTicks[0];
			const yMax = yTicks.at(-1);
			const labelWidth = Math.max(...yTicks.map((value) => compactFormat.format(value).length)) * 7 + 8;
			const left = margin.left + labelWidth;
			const inner = width - left - margin.right;
			const bottom = height - margin.bottom;
			const span = Math.max(times.at(-1) - times[0], day);
			const x = (i) => left + ((times[i] - times[0]) / span) * inner;
			const y = (value) => bottom - ((value - yMin) / (yMax - yMin)) * (bottom - margin.top);
			const years = new Date(times[0]).getUTCFullYear() !== new Date(times.at(-1)).getUTCFullYear();
			const axisFormat = new Intl.DateTimeFormat(lang, { month: 'short', day: 'numeric', ...(years ? { year: '2-digit' } : {}), timeZone: 'UTC' });

			const root = svg('svg', { class: 'hub-chart__svg', width, height, viewBox: `0 0 ${width} ${height}`, role: 'img' });
			root.setAttribute('aria-label', shown.map((line) => `${line.label}: ${signed(line.values.at(-1))}`).join(', '));

			yTicks.forEach((value) => {
				root.append(svg('line', { class: `hub-chart__grid${value === 0 && mode === 'change' ? ' hub-chart__grid--zero' : ''}`, x1: left, x2: width - margin.right, y1: y(value), y2: y(value) }));
				const label = svg('text', { class: 'hub-chart__tick', x: left - 8, y: y(value), 'text-anchor': 'end', 'dominant-baseline': 'middle' });
				label.textContent = compactFormat.format(value);
				root.append(label);
			});

			// Dates far enough apart to be labeled, always the first and the last.
			const labeled = [];
			// Labels are centered, but the first one starts at its date and the last one ends at it.
			const gap = axisFormat.format(times.at(-1)).length * 6 * 1.5 + 12;
			times.forEach((time, i) => {
				const last = i === times.length - 1;

				if (labeled.length === 0 || last || (x(i) - x(labeled.at(-1)) >= gap && x(times.length - 1) - x(i) >= gap)) {
					if (last && labeled.length > 1 && x(i) - x(labeled.at(-1)) < gap) {
						labeled.pop();
					}

					labeled.push(i);
				}
			});

			labeled.forEach((i, n) => {
				const anchor = n === 0 ? 'start' : (i === times.length - 1 ? 'end' : 'middle');
				const label = svg('text', { class: 'hub-chart__tick', x: x(i), y: height - 8, 'text-anchor': labeled.length > 1 ? anchor : 'start' });
				label.textContent = axisFormat.format(times[i]);
				root.append(label);
			});

			shown.forEach((line) => {
				// Days a line has no value for break it.
				let path = '';
				line.values.forEach((value, i) => {
					if (value !== null) {
						path += `${path === '' || line.values[i - 1] === null ? 'M' : 'L'}${x(i).toFixed(1)},${y(value).toFixed(1)}`;
					}
				});

				const element = colored(svg('path', { class: 'hub-chart__line', d: path }), line);

				if (line.dashed) {
					element.dataset.dashed = '';
				}

				root.append(element);
				root.append(colored(svg('circle', { class: 'hub-chart__dot', cx: x(times.length - 1), cy: y(line.values.at(-1)), r: 4 }), line));
			});

			const crosshair = svg('g', { class: 'hub-chart__crosshair' });
			root.append(crosshair);

			const tooltip = document.createElement('div');
			tooltip.className = 'hub-chart__tooltip';
			tooltip.hidden = true;

			const show = (i) => {
				active = i;
				crosshair.replaceChildren(svg('line', { x1: x(i), x2: x(i), y1: margin.top, y2: bottom }));

				shown.forEach((line) => {
					if (line.values[i] !== null) {
						crosshair.append(colored(svg('circle', { class: 'hub-chart__dot', cx: x(i), cy: y(line.values[i]), r: 4 }), line));
					}
				});

				const heading = document.createElement('p');
				heading.className = 'hub-chart__date';
				heading.textContent = formatDate(dates[i]);

				const rows = shown.map((line) => {
					const row = document.createElement('p');
					const key = Object.assign(document.createElement('span'), { className: 'hub-chart__key' });
					const value = Object.assign(document.createElement('strong'), { textContent: line.values[i] === null ? '–' : signed(line.values[i]) });
					const label = Object.assign(document.createElement('span'), { textContent: line.label });
					key.setAttribute('aria-hidden', 'true');
					colored(key, line);

					if (line.dashed) {
						key.dataset.dashed = '';
					}

					row.append(key, value, label);

					return row;
				});

				tooltip.replaceChildren(heading, ...rows);
				tooltip.hidden = false;

				// Beside the crosshair, on the side with room for it.
				const right = x(i) + 12 + tooltip.offsetWidth <= width;
				tooltip.style.left = `${right ? x(i) + 12 : x(i) - 12 - tooltip.offsetWidth}px`;
			};

			const hide = () => {
				active = null;
				crosshair.replaceChildren();
				tooltip.hidden = true;
			};

			const nearest = (clientX) => {
				const offset = clientX - plot.getBoundingClientRect().left;

				return times.reduce((best, _, i) => (Math.abs(x(i) - offset) < Math.abs(x(best) - offset) ? i : best), 0);
			};

			// The point only changes every so many pixels, and showing it reads the layout.
			root.addEventListener('pointermove', (event) => {
				const i = nearest(event.clientX);

				if (i !== active) {
					show(i);
				}
			});
			root.addEventListener('pointerleave', hide);

			plot.onkeydown = (event) => {
				const moves = { ArrowLeft: -1, ArrowRight: 1 };
				let i = active ?? times.length - 1;

				if (event.key in moves) {
					i = Math.min(times.length - 1, Math.max(0, (active === null ? i : i + moves[event.key])));
				} else if (event.key === 'Home' || event.key === 'End') {
					i = event.key === 'Home' ? 0 : times.length - 1;
				} else if (event.key === 'Escape') {
					hide();

					return;
				} else {
					return;
				}

				event.preventDefault();
				show(i);
			};

			plot.onblur = hide;
			plot.replaceChildren(root, tooltip);

			if (active !== null) {
				show(Math.min(active, times.length - 1));
			}
		};

		const observer = new ResizeObserver(() => draw());

		if (plot) {
			plot.tabIndex = 0;
			observer.observe(plot);
		}

		chart.querySelectorAll('[data-hub-chart-mode]').forEach((button) => {
			button.setAttribute('aria-pressed', button.dataset.hubChartMode === mode ? 'true' : 'false');

			button.addEventListener('click', () => {
				mode = button.dataset.hubChartMode;
				chart.querySelectorAll('[data-hub-chart-mode]').forEach((other) => other.setAttribute('aria-pressed', other === button ? 'true' : 'false'));

				try {
					mode === 'change' ? window.localStorage.setItem(modeKey, mode) : window.localStorage.removeItem(modeKey);
				} catch {
					// Storage can be unavailable, e.g. in private windows.
				}

				draw();
			});
		});

		chart.querySelectorAll('[data-hub-chart-series]').forEach((button) => {
			const key = button.dataset.hubChartSeries;
			button.setAttribute('aria-pressed', hidden.has(key) ? 'false' : 'true');

			button.addEventListener('click', () => {
				if (hidden.has(key)) {
					hidden.delete(key);
				} else if (hidden.size < series.length - 1) {
					hidden.add(key);
				} else {
					return;
				}

				button.setAttribute('aria-pressed', hidden.has(key) ? 'false' : 'true');

				try {
					hidden.size
						? window.localStorage.setItem(storageKey, JSON.stringify([...hidden]))
						: window.localStorage.removeItem(storageKey);
				} catch {
					// Storage can be unavailable, e.g. in private windows.
				}

				draw();
			});
		});

		chart.querySelectorAll('[data-hub-chart-range]').forEach((button) => button.addEventListener('click', async () => {
			if (button.getAttribute('aria-pressed') === 'true' || chart.hasAttribute('aria-busy')) {
				return;
			}

			chart.setAttribute('aria-busy', 'true');

			try {
				const response = await fetch(button.dataset.hubChartRange, { headers: { Accept: 'text/html' } });

				if (!response.ok) {
					throw new Error(response.statusText);
				}

				const loaded = new DOMParser().parseFromString(await response.text(), 'text/html');
				const replacement = loaded.querySelector('[data-hub-chart]');
				const trend = chart.closest('details')?.querySelector('[data-hub-trend]');

				if (!replacement) {
					throw new Error('Missing chart');
				}

				trend?.replaceChildren(...(loaded.querySelector('[data-hub-trend]')?.childNodes ?? []));
				observer.disconnect();
				chart.replaceWith(replacement);
				init(replacement);
			} catch {
				Craft.cp.displayError(Craft.t('hub', 'The content could not be loaded.'));
				chart.removeAttribute('aria-busy');
			}
		}));
	};

	// Elements matching a selector, including the root itself, so swapped content can be set up like the page.
	const within = (root, selector) => [...(root.matches?.(selector) ? [root] : []), ...root.querySelectorAll(selector)];

	const init = (root) => {
		within(root, '[data-hub-dropdown]').forEach(initDropdown);
		within(root, '.hub-filters').forEach(initBar);
		within(root, 'details[data-hub-panel]').forEach(initPanel);
		within(root, 'details[data-hub-lazy]').forEach(initLazy);
		within(root, '[data-hub-chart]').forEach(initChart);
		within(root, '[data-hub-info]').forEach(initInfo);
		within(root, '[data-hub-visit]').forEach(initVisit);
		within(root, '[data-hub-columns]').forEach(initColumns);
		within(root, 'form[data-hub-submit]').forEach(initSubmitForm);
		within(root, '[data-hub-saved]').forEach(initSaved);
		within(root, 'input[data-hub-site]').forEach(initSite);
		within(root, 'form[data-hub-bulk]').forEach(initBulk);
		within(root, 'button[data-hub-batch]').forEach(initBatch);
		within(root, 'tr.hub-activity-batch').forEach(initBatchRow);
		within(root, '[data-hub-mirror], [data-hub-mirror-color], [data-hub-mirror-icon]').forEach(initMirror);
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
