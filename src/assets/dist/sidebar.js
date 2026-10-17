(() => {
	// The labels of the Hub panel on native edit pages (see _sidebar/hub.twig) label the element right away as they're
	// checked. Listening on the document covers the panels of slideouts too, which are added after the page loaded.
	// The changes are sent one after the other, each with the labels by then, and a failed one shows the labels last
	// saved again.
	const saved = new WeakMap();
	const sending = new WeakMap();

	const boxesOf = (section) => [...section.querySelectorAll('[data-hub-sidebar-labels-options] input[type="checkbox"]')];

	// Shows the labels in their order, taken from the template, and checks their boxes.
	const show = (section, ids) => {
		const template = section.querySelector('[data-hub-sidebar-labels-template]');

		section.querySelector('[data-hub-sidebar-labels-list]').replaceChildren(...[...template.content.querySelectorAll('[data-hub-label]')]
			.filter((label) => ids.includes(label.dataset.hubLabel))
			.map((label) => label.cloneNode(true)));
		section.querySelector('[data-hub-sidebar-labels-empty]').hidden = ids.length > 0;

		const count = section.querySelector('[data-hub-sidebar-labels-count]');
		count.textContent = ids.length.toLocaleString();
		count.hidden = ids.length === 0;
		boxesOf(section).forEach((box) => {
			box.checked = ids.includes(box.value);
		});
	};

	const save = (section) => {
		const ids = boxesOf(section).filter((box) => box.checked).map((box) => box.value);

		if (!saved.has(section)) {
			saved.set(section, [...section.querySelectorAll('[data-hub-sidebar-labels-list] [data-hub-label]')].map((label) => label.dataset.hubLabel));
		}

		show(section, ids);

		const request = (sending.get(section) ?? Promise.resolve())
			.then(() => Craft.sendActionRequest('POST', 'hub/labels/apply', { data: { list: section.dataset.hubSidebarLabels, id: section.dataset.hubSidebarId, labels: ids } }))
			.then(({ data }) => {
				saved.set(section, data.labels.map(String));
			}, () => {
				Craft.cp.displayError(Craft.t('hub', 'The labels could not be saved.'));
			})
			.then(() => {
				// Only the last change shows what was saved, so later changes aren't undone in between.
				if (sending.get(section) === request) {
					show(section, saved.get(section));
					sending.delete(section);
				}
			});

		sending.set(section, request);
	};

	document.addEventListener('change', (event) => {
		const section = event.target.closest('[data-hub-sidebar-labels]');

		if (section && event.target.closest('[data-hub-sidebar-labels-options]')) {
			save(section);
		}
	});

	document.addEventListener('click', (event) => {
		const toggle = event.target.closest('[data-hub-sidebar-labels-toggle]');

		if (!toggle) {
			return;
		}

		const options = toggle.closest('[data-hub-sidebar-labels]').querySelector('[data-hub-sidebar-labels-options]');
		options.hidden = !options.hidden;
		toggle.setAttribute('aria-expanded', String(!options.hidden));
	});

	// Sections are rendered collapsed, so nothing is shown only to be hidden again; those the user expanded are expanded
	// again on every edit page, in this browser. Panels are restored as they're added, as slideouts add theirs after the
	// page loaded.
	const storageKey = 'hub:sidebar:expanded';

	const readExpanded = () => {
		try {
			const expanded = JSON.parse(localStorage.getItem(storageKey) ?? '[]');

			return Array.isArray(expanded) ? expanded : [];
		} catch {
			return [];
		}
	};

	const restore = (root) => {
		const sections = root.querySelectorAll('[data-hub-sidebar-section]:not([data-hub-sidebar-restored])');

		if (sections.length === 0) {
			return;
		}

		const expanded = readExpanded();

		sections.forEach((section) => {
			section.dataset.hubSidebarRestored = '';
			section.open = expanded.includes(section.dataset.hubSidebarSection);
		});
	};

	// The toggle event doesn't bubble, so it's caught on its way down.
	document.addEventListener('toggle', (event) => {
		const key = event.target.dataset?.hubSidebarSection;

		if (!key || !event.target.hasAttribute('data-hub-sidebar-restored')) {
			return;
		}

		const expanded = readExpanded().filter((other) => other !== key);

		try {
			localStorage.setItem(storageKey, JSON.stringify(event.target.open ? [...expanded, key] : expanded));
		} catch {
			// Expanded sections are only remembered where storage is available.
		}
	}, true);

	restore(document);

	new MutationObserver((mutations) => {
		if (mutations.some((mutation) => [...mutation.addedNodes].some((node) => node.nodeType === Node.ELEMENT_NODE))) {
			restore(document);
		}
	}).observe(document.body, { childList: true, subtree: true });
})();
