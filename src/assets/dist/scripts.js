(() => {
	const valueOf = (control) => {
		if (control.type === 'checkbox') {
			return control.checked ? '1' : '';
		}

		return control.value.trim();
	};

	// Dates compare as Unix timestamps, like the rows hold them; the end of a range includes its whole day.
	const boundOf = (control, value) => {
		if (control.type === 'date') {
			const [year, month, day] = value.split('-').map(Number);
			const date = new Date(year, month - 1, day + (control.dataset.hubMatch === 'max' ? 1 : 0));

			return date.getTime() / 1000 - (control.dataset.hubMatch === 'max' ? 1 : 0);
		}

		return Number(value) * Number(control.dataset.hubScale ?? 1);
	};

	const matches = (row, control, raw = valueOf(control)) => {
		const value = raw.toLowerCase();

		if (!value) {
			return true;
		}

		const data = (row.dataset[control.dataset.hubFilter] ?? '').toLowerCase();
		// Dropdown choices hold several values, any of which matches.
		const values = value.split(',');

		switch (control.dataset.hubMatch) {
			case 'list':
				return data.split('|').some((item) => values.includes(item));
			case 'equals':
				return values.includes(data);
			case 'min':
				return data !== '' && Number(data) >= boundOf(control, value);
			case 'max':
				return data !== '' && Number(data) <= boundOf(control, value);
			default:
				return data.includes(value);
		}
	};

	const formatDate = (value) => {
		const [year, month, day] = value.split('-').map(Number);

		return new Date(year, month - 1, day).toLocaleDateString(document.documentElement.lang || undefined);
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

		const position = () => {
			const rect = toggle.getBoundingClientRect();
			const width = popover.offsetWidth;
			popover.style.top = `${rect.bottom + 4}px`;
			popover.style.left = `${Math.max(8, Math.min(rect.left, window.innerWidth - width - 8))}px`;
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
				window.addEventListener('resize', position);
				window.addEventListener('scroll', position, true);
				popover.querySelector('input:not([type="hidden"])')?.focus();
				return;
			}

			window.removeEventListener('resize', position);
			window.removeEventListener('scroll', position, true);
		});

		popover.querySelector('[data-hub-dropdown-clear]').addEventListener('click', () => {
			named.forEach((input) => {
				input.value = '';
				input.dispatchEvent(new Event('input', { bubbles: true }));
			});

			sync();
		});

		dropdown.addEventListener('hub:sync', sync);
		toggle.setAttribute('aria-expanded', 'false');
		render();
	};

	// Filter bars offer to reset all their filters while any is set.
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

			const form = bar.closest('form[data-hub-submit]');

			if (form) {
				form.requestSubmit();
				return;
			}

			controls.forEach((control) => control.dispatchEvent(new Event(control.type === 'checkbox' ? 'change' : 'input')));
			update();
		});

		bar.addEventListener('input', update);
		bar.addEventListener('change', update);
		bar.addEventListener('hub:sync', update);
		update();
	};

	const initList = (root) => {
		const table = root.querySelector('[data-hub-table]');

		if (!table || !table.tBodies[0]) {
			return;
		}

		const body = table.tBodies[0];
		const rows = [...body.rows];
		const controls = [...root.querySelectorAll('[data-hub-filter]')];
		const counter = root.querySelector('[data-hub-count]');
		const empty = root.querySelector('[data-hub-empty]');
		const presets = [...root.querySelectorAll('a[data-hub-preset]')];

		const load = (params, reset) => {
			controls.forEach((control) => {
				const value = params.get(control.name);

				if (value === null && !reset) {
					return;
				}

				if (control.type === 'checkbox') {
					control.checked = value === '1';
				} else {
					control.value = value ?? '';
				}

				control.dispatchEvent(new Event('hub:sync', { bubbles: true }));
			});
		};

		load(new URLSearchParams(window.location.search), false);

		// Choices show how many rows each option matches, like the counts of lists filtered on the server.
		root.querySelectorAll('[data-hub-choices]').forEach((part) => {
			const control = part.querySelector('input[type="hidden"][data-hub-filter]');

			// The site scope doesn't filter rows.
			if (!control) {
				return;
			}

			part.querySelectorAll('input[type="checkbox"]').forEach((box) => {
				const label = box.closest('label');

				if (label.querySelector('.hub-dropdown__count')) {
					return;
				}

				const count = document.createElement('span');
				count.className = 'hub-dropdown__count';
				count.textContent = rows.filter((row) => matches(row, control, box.value)).length.toLocaleString(document.documentElement.lang || undefined);
				label.append(count);
			});
		});

		const apply = () => {
			const query = new URLSearchParams(window.location.search);
			let visible = 0;

			controls.forEach((control) => {
				const value = valueOf(control);
				value ? query.set(control.name, value) : query.delete(control.name);
			});

			rows.forEach((row) => {
				const show = controls.every((control) => matches(row, control));
				row.hidden = !show;
				visible += show ? 1 : 0;
			});

			if (counter) {
				counter.textContent = (counter.dataset.template ?? '{visible} / {total}')
					.replace('{visible}', String(visible))
					.replace('{total}', String(rows.length));
			}

			if (empty) {
				empty.hidden = visible > 0;
			}

			presets.forEach((preset) => {
				const params = new URLSearchParams(preset.search);
				const active = controls.every((control) => valueOf(control) === (params.get(control.name) ?? ''));
				active ? preset.setAttribute('aria-current', 'true') : preset.removeAttribute('aria-current');
			});

			const search = query.toString();
			window.history.replaceState(null, '', window.location.pathname + (search ? `?${search}` : ''));
		};

		// Legend entries in summary cards act as filter presets; clicking an active one clears it.
		presets.forEach((preset) => {
			preset.addEventListener('click', (event) => {
				event.preventDefault();
				const active = preset.getAttribute('aria-current') === 'true';
				load(active ? new URLSearchParams() : new URLSearchParams(preset.search), true);
				apply();
			});
		});

		controls.forEach((control) => {
			const event = control.tagName === 'SELECT' || control.type === 'checkbox' ? 'change' : 'input';
			control.addEventListener(event, apply);
		});

		const headers = [...table.querySelectorAll('th[data-hub-sort]')];

		const sort = (header) => {
			const key = header.dataset.hubSort;
			const numeric = header.dataset.hubSortType === 'number';
			const direction = header.dataset.hubDirection === 'asc' ? 'desc' : 'asc';

			headers.forEach((other) => {
				delete other.dataset.hubDirection;
				other.removeAttribute('aria-sort');
			});

			header.dataset.hubDirection = direction;
			header.setAttribute('aria-sort', direction === 'asc' ? 'ascending' : 'descending');

			rows.sort((a, b) => {
				const x = a.dataset[key] ?? '';
				const y = b.dataset[key] ?? '';
				const result = numeric
					? Number(x) - Number(y)
					: x.localeCompare(y, undefined, { numeric: true, sensitivity: 'base' });

				return direction === 'asc' ? result : -result;
			});

			rows.forEach((row) => body.appendChild(row));
		};

		headers.forEach((header) => {
			header.tabIndex = 0;
			header.addEventListener('click', () => sort(header));
			header.addEventListener('keydown', (event) => {
				if (event.key === 'Enter' || event.key === ' ') {
					event.preventDefault();
					sort(header);
				}
			});
		});

		apply();
	};

	// Collapsible panels remember a toggled state per page; states matching the default are not kept.
	const initPanel = (panel) => {
		const key = `hub:panel:${window.location.pathname}#${panel.dataset.hubPanel}`;
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

	// Lists filtered on the server load their results in place as filters change, leaving empty and default ones out of the URL.
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
				const response = await fetch(url, { signal: request.signal, headers: { Accept: 'text/html' } });

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

				if (count) {
					count.textContent = next.querySelector('.hub-count')?.textContent ?? '';
				}

				window.history.replaceState(null, '', url + window.location.hash);
				form.parentElement.removeAttribute('aria-busy');
			} catch (error) {
				if (error.name !== 'AbortError') {
					window.location.assign(url);
				}
			}
		};

		const schedule = (event) => {
			const control = event.target;

			// The site filter reloads the page on its own; the checkboxes and searches of dropdowns update their named inputs.
			if (!control.name || control.matches('[data-hub-site]')) {
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
			const response = await fetch(url, { signal: rescoping.signal, headers: { Accept: 'text/html' } });

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

			// Panes of in-page tabs are rendered hidden but the first; the selected tab's pane is shown again.
			document.querySelectorAll('#tabs a[href^="#"]').forEach((tab) => {
				document.getElementById(tab.getAttribute('href').slice(1))?.classList.toggle('hidden', !tab.classList.contains('sel'));
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
		within(root, '[data-hub-list]').forEach(initList);
		within(root, 'details[data-hub-panel]').forEach(initPanel);
		within(root, 'form[data-hub-submit]').forEach(initSubmitForm);
		within(root, 'form[data-hub-autosubmit]').forEach(initAutosubmit);
		within(root, 'input[data-hub-site]').forEach(initSite);
	};

	init(document);
})();
