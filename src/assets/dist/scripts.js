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

	// Popovers in the top layer are placed below their button, kept within the viewport.
	const placeBelow = (popover, anchor) => {
		const rect = anchor.getBoundingClientRect();
		const width = popover.offsetWidth;
		popover.style.top = `${rect.bottom + 4}px`;
		popover.style.left = `${Math.max(8, Math.min(rect.left, window.innerWidth - width - 8))}px`;
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

		const position = () => placeBelow(popover, toggle);

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

	// Collapsible panels remember a toggled state per kind of page, shared by all elements of that kind; states matching the default are not kept.
	const initPanel = (panel) => {
		const page = window.location.pathname.replace(/\/\d+\/?$/, '');
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
		query.delete('listPage');

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

	// Panel descriptions: a popover below the info button next to the heading, following it while open.
	const initInfo = (popover) => {
		const toggle = document.querySelector(`[popovertarget="${popover.id}"]`);
		const position = () => placeBelow(popover, toggle);

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
				return;
			}

			window.removeEventListener('resize', position);
			window.removeEventListener('scroll', position, true);
		});
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
		within(root, 'form[data-hub-submit]').forEach(initSubmitForm);
		within(root, 'form[data-hub-autosubmit]').forEach(initAutosubmit);
		within(root, 'input[data-hub-site]').forEach(initSite);
	};

	// Panes of in-page tabs naming a crumb add it to the crumbs while their tab is selected, taking over as the current one.
	const initTabCrumbs = () => {
		const manager = Craft.cp?.tabManager;
		const list = document.getElementById('crumb-list');
		const last = list?.lastElementChild;

		if (!manager || !last) {
			return;
		}

		const crumb = document.createElement('li');
		const link = document.createElement('a');
		const label = document.createElement('span');

		crumb.className = 'crumb current';
		link.className = 'crumb-link';
		link.append(label);
		crumb.append(link);

		const update = ($tab) => {
			const href = $tab?.attr('href') ?? '';
			const name = href.startsWith('#') ? document.getElementById(href.slice(1))?.dataset.hubCrumb : null;

			last.classList.toggle('current', !name);
			name ? list.append(crumb) : crumb.remove();

			if (name) {
				link.href = href;
				label.textContent = name;
			}
		};

		manager.on('selectTab', (event) => update(event.$tab));
		update(manager.$selectedTab);
	};

	// Links reloading the page from the pane of an in-page tab, like those sorting or paging its list, keep that tab selected.
	const initTabLinks = () => {
		const page = (pathname) => pathname.replace(/\/p\d+\/?$/, '');

		document.addEventListener('click', (event) => {
			const link = event.target.closest?.('a[href]');

			if (!link || link.hash || link.origin !== window.location.origin || page(link.pathname) !== page(window.location.pathname)) {
				return;
			}

			const pane = [...document.querySelectorAll('#tabs a[href^="#"]')]
				.map((tab) => document.getElementById(tab.getAttribute('href').slice(1)))
				.find((element) => element?.contains(link));

			if (pane) {
				link.hash = pane.id;
			}
		});
	};

	init(document);
	initTabLinks();
	$(initTabCrumbs);
})();
