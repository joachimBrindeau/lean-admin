/**
 * Lean Admin — MetaMenu editor.
 *
 * Hydrates from the localized `leanAdminMetaMenuEditor` ({ items, tree, ajax })
 * and lets the admin build a nested menu by drag-and-drop. DRY by design: ONE
 * node factory renders every node — group or ref, at any depth — with the same
 * controls (drag handle, icon picker, editable label, delete, nested child
 * list). SortableJS owns drag/reorder. Serializes back to the recursive tree.
 */
(function () {
	'use strict';

	var cfg = window.leanAdminMetaMenuEditor || {};
	var items = Array.isArray(cfg.items) ? cfg.items : [];
	var tree = Array.isArray(cfg.tree) ? cfg.tree : [];
	var ajax = cfg.ajax || {};
	var i18n = cfg.i18n || {};

	var paletteEl = document.getElementById('la-mm-palette');
	var treeEl = document.getElementById('la-mm-tree');
	if (!paletteEl || !treeEl || typeof window.Sortable === 'undefined') {
		return;
	}

	var SHARED = 'la-mm-shared';
	var itemsBySlug = {};
	items.forEach(function (it) { itemsBySlug[it.slug] = it; });

	function el(tag, cls, text) {
		var node = document.createElement(tag);
		if (cls) { node.className = cls; }
		if (text != null) { node.textContent = text; }
		return node;
	}

	// ---- icon picker (shared by every node) -----------------------------
	var DASHICONS = [
		'dashicons-admin-home', 'dashicons-admin-site-alt3', 'dashicons-dashboard', 'dashicons-admin-post',
		'dashicons-admin-page', 'dashicons-admin-media', 'dashicons-admin-links', 'dashicons-admin-comments',
		'dashicons-admin-appearance', 'dashicons-admin-plugins', 'dashicons-admin-users', 'dashicons-admin-tools',
		'dashicons-admin-settings', 'dashicons-admin-network', 'dashicons-admin-generic', 'dashicons-art',
		'dashicons-layout', 'dashicons-screenoptions', 'dashicons-forms', 'dashicons-feedback',
		'dashicons-cart', 'dashicons-money-alt', 'dashicons-store', 'dashicons-products', 'dashicons-bank',
		'dashicons-chart-bar', 'dashicons-chart-line', 'dashicons-analytics', 'dashicons-performance',
		'dashicons-megaphone', 'dashicons-email-alt', 'dashicons-bell', 'dashicons-share',
		'dashicons-tag', 'dashicons-category', 'dashicons-archive', 'dashicons-portfolio', 'dashicons-list-view',
		'dashicons-groups', 'dashicons-businessperson', 'dashicons-id', 'dashicons-location', 'dashicons-building',
		'dashicons-calendar-alt', 'dashicons-clipboard', 'dashicons-media-document', 'dashicons-images-alt2',
		'dashicons-search', 'dashicons-filter', 'dashicons-shield', 'dashicons-lock', 'dashicons-database',
		'dashicons-networking', 'dashicons-marker', 'dashicons-flag', 'dashicons-star-filled', 'dashicons-heart'
	];

	var openPop = null;

	function closePicker() {
		if (openPop) {
			openPop.remove();
			openPop = null;
			document.removeEventListener('mousedown', onDocMousedown, true);
			document.removeEventListener('keydown', onPickerKey, true);
		}
	}

	function onDocMousedown(e) {
		if (openPop && !openPop.contains(e.target) && !e.target.closest('.la-mm-icon-btn')) {
			closePicker();
		}
	}

	function onPickerKey(e) {
		if (e.key === 'Escape') { closePicker(); }
	}

	function setNodeIcon(li, btn, icon) {
		li.dataset.icon = icon;
		btn.querySelector('.dashicons').className = 'dashicons ' + (icon || 'dashicons-menu-alt');
		btn.classList.toggle('la-mm-icon-empty', !icon);
	}

	function openIconPicker(btn, li) {
		var wasOpen = openPop && openPop.dataset.owner === li.dataset.uid;
		closePicker();
		if (wasOpen) { return; }

		// Simple combobox: one row per option = icon glyph + its text value.
		var pop = el('div', 'la-mm-icon-pop');
		pop.dataset.owner = li.dataset.uid;

		function row(icon, name) {
			var b = el('button', 'la-mm-icon-row');
			b.type = 'button';
			b.appendChild(el('span', 'dashicons ' + (icon || 'dashicons-no-alt')));
			b.appendChild(el('span', 'la-mm-icon-name', name));
			if ((li.dataset.icon || '') === icon) { b.classList.add('is-selected'); }
			b.addEventListener('click', function () { setNodeIcon(li, btn, icon); closePicker(); });
			return b;
		}

		pop.appendChild(row('', i18n.noIcon));
		DASHICONS.forEach(function (d) { pop.appendChild(row(d, d)); });
		document.body.appendChild(pop);

		var r = btn.getBoundingClientRect();
		pop.style.top = (window.scrollY + r.bottom + 4) + 'px';
		pop.style.left = (window.scrollX + r.left) + 'px';

		openPop = pop;
		window.setTimeout(function () {
			document.addEventListener('mousedown', onDocMousedown, true);
			document.addEventListener('keydown', onPickerKey, true);
			var sel = pop.querySelector('.is-selected') || pop.firstChild;
			if (sel && sel.focus) { sel.focus(); }
		}, 0);
	}

	function iconChip(li, icon) {
		var btn = el('button', 'button la-mm-icon-btn');
		btn.type = 'button';
		btn.title = i18n.chooseIcon;
		btn.appendChild(el('span', 'dashicons ' + (icon || 'dashicons-menu-alt')));
		if (!icon) { btn.classList.add('la-mm-icon-empty'); }
		btn.addEventListener('click', function (e) {
			e.preventDefault();
			openIconPicker(btn, li);
		});
		return btn;
	}

	// ---- unified node ---------------------------------------------------

	var uidSeq = 0;
	function genGroupId() {
		return 'g' + Date.now().toString(36) + Math.floor(Math.random() * 1e6).toString(36);
	}

	/**
	 * Build one node — group OR ref — with identical chrome at every level:
	 * handle, icon chip, editable label, delete, and a nested sortable child
	 * list (so any node can contain any other).
	 *
	 * spec: { type:'group'|'ref', id?, slug?, label?, icon?, title?, children? }
	 */
	function nodeEl(spec) {
		var isRef = spec.type === 'ref';
		var li = el('li', 'la-mm-node ' + (isRef ? 'la-mm-ref' : 'la-mm-grp'));
		li.dataset.type = isRef ? 'ref' : 'group';
		li.dataset.uid = 'u' + (++uidSeq);
		li.dataset.icon = spec.icon || '';
		if (isRef) {
			li.dataset.slug = spec.slug;
			li.dataset.title = spec.title || spec.label || spec.slug;
		} else {
			li.dataset.id = spec.id || genGroupId();
		}

		var head = el('div', 'la-mm-node-head');
		head.appendChild(el('span', 'la-mm-handle dashicons dashicons-menu'));
		head.appendChild(iconChip(li, spec.icon || ''));

		var labelInput = el('input', 'la-mm-label-input');
		labelInput.type = 'text';
		labelInput.value = spec.label != null ? spec.label : (spec.title || spec.slug || '');
		labelInput.placeholder = isRef ? i18n.label : i18n.groupName;
		head.appendChild(labelInput);

		var remove = el('button', 'la-mm-remove button-link', '×');
		remove.type = 'button';
		remove.title = isRef ? i18n.removeFromGroup : i18n.deleteGroup;
		remove.addEventListener('click', function () { removeNode(li); });
		head.appendChild(remove);

		li.appendChild(head);

		var ul = el('ul', 'la-mm-sortable la-mm-children');
		li.appendChild(ul);
		makeSortable(ul);
		if (Array.isArray(spec.children)) {
			renderInto(spec.children, ul);
		}

		return li;
	}

	function removeNode(li) {
		if (li.dataset.type === 'group') {
			// Return contained refs to the palette; drop the group + subgroups.
			li.querySelectorAll('li.la-mm-ref').forEach(function (ref) { paletteEl.appendChild(ref); });
			li.remove();
		} else {
			paletteEl.appendChild(li); // ref back to the unplaced palette
		}
		refreshEmpty();
	}

	// ---- render ---------------------------------------------------------

	/**
	 * Turn a stored config node into a nodeEl spec, enriching refs with their
	 * resolved title for the label default.
	 */
	function specFromConfig(node) {
		if (node.type === 'group') {
			return { type: 'group', id: node.id, label: node.label || '', icon: node.icon || '', children: node.children || [] };
		}
		var it = itemsBySlug[node.slug] || {};
		return {
			type: 'ref',
			slug: node.slug,
			title: it.title || node.slug,
			label: node.label != null ? node.label : (it.title || node.slug),
			icon: node.icon || '',
			children: node.children || []
		};
	}

	function renderInto(nodes, container) {
		nodes.forEach(function (node) { container.appendChild(nodeEl(specFromConfig(node))); });
	}

	function usedSlugs(nodes, acc) {
		nodes.forEach(function (n) {
			if (n.type === 'ref') { acc[n.slug] = true; }
			if (n.children) { usedSlugs(n.children, acc); }
		});
		return acc;
	}

	function renderPalette() {
		var used = usedSlugs(tree, {});
		items.forEach(function (it) {
			if (!used[it.slug]) {
				paletteEl.appendChild(nodeEl({ type: 'ref', slug: it.slug, title: it.title }));
			}
		});
	}

	// ---- sortable -------------------------------------------------------

	function makeSortable(ul) {
		window.Sortable.create(ul, {
			group: {
				name: SHARED,
				pull: true,
				put: function (to, from, dragEl) {
					// The palette holds only unplaced refs, never groups.
					if (to.el === paletteEl) {
						return dragEl.dataset.type === 'ref';
					}
					return true;
				}
			},
			handle: '.la-mm-handle',
			animation: 150,
			fallbackOnBody: true,
			invertSwap: true,
			onSort: refreshEmpty
		});
	}

	function refreshEmpty() {
		var empty = document.getElementById('la-mm-empty');
		if (empty) {
			empty.hidden = treeEl.children.length > 0;
		}
	}

	// ---- serialize + save ----------------------------------------------

	function childrenUl(li) {
		return li.querySelector(':scope > ul.la-mm-children');
	}

	function labelOf(li) {
		var input = li.querySelector(':scope > .la-mm-node-head > .la-mm-label-input');
		return input ? input.value.trim() : '';
	}

	function serialize(container) {
		var out = [];
		Array.prototype.forEach.call(container.children, function (li) {
			var kidsUl = childrenUl(li);
			var kids = kidsUl ? serialize(kidsUl) : [];
			var icon = li.dataset.icon || '';
			var label = labelOf(li);

			if (li.dataset.type === 'ref') {
				var node = { type: 'ref', slug: li.dataset.slug };
				// Emit label only when it overrides the resolved title.
				if (label !== '' && label !== (li.dataset.title || '')) { node.label = label; }
				if (icon !== '') { node.icon = icon; }
				if (kids.length) { node.children = kids; }
				out.push(node);
			} else {
				out.push({ type: 'group', id: li.dataset.id, label: label, icon: icon, children: kids });
			}
		});
		return out;
	}

	/** First group node with a blank label (groups require one); focus it. */
	function firstEmptyGroupLabel() {
		var groups = treeEl.querySelectorAll('li.la-mm-grp');
		for (var i = 0; i < groups.length; i++) {
			var input = groups[i].querySelector(':scope > .la-mm-node-head > .la-mm-label-input');
			if (input && input.value.trim() === '') {
				input.focus();
				return true;
			}
		}
		return false;
	}

	function status(msg, ok) {
		var s = document.getElementById('la-mm-status');
		if (!s) { return; }
		s.textContent = msg;
		s.className = 'la-mm-status ' + (ok ? 'is-ok' : 'is-err');
		if (ok) {
			setTimeout(function () { s.textContent = ''; s.className = 'la-mm-status'; }, 3000);
		}
	}

	function save() {
		var btn = document.getElementById('la-mm-save');
		var spinner = document.getElementById('la-mm-spinner');
		if (!btn) { return; }

		if (firstEmptyGroupLabel()) {
			status(i18n.nameRequired, false);
			return;
		}

		btn.disabled = true;
		if (spinner) { spinner.classList.add('is-active'); }

		var body = new URLSearchParams();
		body.set('action', (ajax.module || 'lean_admin_metamenu') + '_save_config');
		body.set('nonce', ajax.nonce || '');
		body.set('tree', JSON.stringify(serialize(treeEl)));

		fetch(ajax.ajax_url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		})
			.then(function (r) { return r.json(); })
			.then(function (json) {
				if (json && json.success) {
					status(i18n.saved, true);
				} else {
					var msg = (json && json.data && json.data.message) || (json && json.message);
					status(msg || i18n.saveFailed, false);
				}
			})
			.catch(function () { status(i18n.networkFailed, false); })
			.finally(function () {
				btn.disabled = false;
				if (spinner) { spinner.classList.remove('is-active'); }
			});
	}

	// ---- boot -----------------------------------------------------------

	makeSortable(paletteEl);
	makeSortable(treeEl);
	renderInto(tree, treeEl);
	renderPalette();
	refreshEmpty();

	var addBtn = document.getElementById('la-mm-add-group');
	if (addBtn) {
		addBtn.addEventListener('click', function () {
			treeEl.appendChild(nodeEl({ type: 'group', label: '' }));
			refreshEmpty();
			var last = treeEl.lastElementChild;
			var input = last && last.querySelector('.la-mm-label-input');
			if (input) { input.focus(); }
		});
	}

	var saveBtn = document.getElementById('la-mm-save');
	if (saveBtn) { saveBtn.addEventListener('click', save); }
})();
