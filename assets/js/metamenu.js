/**
 * Lean Admin — MetaMenu runtime.
 *
 * Renders the configured groups as cascading nested flyouts in the native
 * admin sidebar. It builds BRAND-NEW DOM appended to each group's own
 * top-level <li> (id `toplevel_page_la-mm-{id}`, which PHP created). Every href
 * comes from PHP (sourced from WordPress's cap-filtered menu), so the JS only
 * draws authorized links.
 *
 * The grouped originals are HIDDEN here (display:none on their native <li>),
 * never removed server-side: PHP deliberately leaves `$menu`/`$submenu` intact
 * so WordPress's own page authorization keeps working — a hidden page still
 * loads. This is the one place we touch native `.wp-submenu`/`li.menu-top`
 * nodes, and only to set display.
 *
 * On the folded rail or below 782px (no hover), flyouts are not bound — the
 * group's top-level link goes to its server-rendered hub page instead.
 *
 * Interaction polish (safe-zone triangle, arrow keys, immediate close) follows
 * docs/solutions/design-patterns/nested-admin-menu-beyond-two-level-limit.md.
 */
(function () {
	'use strict';

	var CLOSE_DELAY = 80;
	var cfg = window.leanAdminMetaMenu || {};
	var groups = Array.isArray(cfg.groups) ? cfg.groups : [];
	var hideHrefs = Array.isArray(cfg.hide) ? cfg.hide : [];

	if (!groups.length && !hideHrefs.length) {
		return;
	}

	/** Normalize hrefs so PHP absolute admin URLs match native sidebar anchors. */
	function hrefKey(href) {
		try {
			var url = new URL(href || '', window.location.href);
			url.hash = '';
			return url.pathname + url.search;
		} catch (e) {
			return '';
		}
	}

	/** Hide grouped originals without removing WP's server-side menu globals. */
	function hideNativeOriginals() {
		var hidden = {};
		hideHrefs.forEach(function (href) {
			var key = hrefKey(href);
			if (key) {
				hidden[key] = true;
			}
		});

		if (!Object.keys(hidden).length) {
			return;
		}

		document.querySelectorAll('#adminmenu a[href]').forEach(function (a) {
			if (a.closest('.la-mm-root, .la-mm-flyout') || !hidden[hrefKey(a.href)]) {
				return;
			}
			var li = a.closest('.wp-submenu') ? a.closest('li') : (a.closest('li.menu-top') || a.closest('li'));
			if (li) {
				li.style.display = 'none';
			}
		});
	}

	function hasActiveChild(node) {
		var children = Array.isArray(node.children) ? node.children : [];
		return !!node.active || children.some(hasActiveChild);
	}

	function markActiveGroups() {
		groups.forEach(function (group) {
			if (!hasActiveChild(group)) {
				return;
			}
			var topLi = document.getElementById('toplevel_page_la-mm-' + group.id);
			if (topLi) {
				topLi.classList.add('la-mm-active', 'current', 'wp-has-current-submenu', 'wp-menu-open');
			}
		});
	}

	/** Hover flyouts need a real pointer + an expanded sidebar. */
	function flyoutsEnabled() {
		if (window.innerWidth <= 782) {
			return false;
		}
		return !document.body.classList.contains('folded');
	}

	/** Barycentric point-in-triangle — keeps the panel open while en route. */
	function inTriangle(p, a, b, c) {
		var d = (b.y - c.y) * (a.x - c.x) + (c.x - b.x) * (a.y - c.y);
		if (d === 0) {
			return false;
		}
		var s = ((b.y - c.y) * (p.x - c.x) + (c.x - b.x) * (p.y - c.y)) / d;
		var t = ((c.y - a.y) * (p.x - c.x) + (a.x - c.x) * (p.y - c.y)) / d;
		return s >= 0 && t >= 0 && s + t <= 1;
	}

	/** Build a nested <ul> from an array of resolved nodes. */
	function buildList(items, level) {
		var ul = document.createElement('ul');
		ul.className = 'la-mm-flyout la-mm-level-' + level;

		items.forEach(function (item) {
			var li = document.createElement('li');
			li.className = 'la-mm-item';

			var a = document.createElement('a');
			a.textContent = item.label || '';
			if (item.type === 'group') {
				// A subgroup is organizational; clicking the first leaf is
				// harmless. We point it at the group hub via '#' fallback only
				// when it has no resolvable destination.
				a.href = item.href || '#';
				li.classList.add('la-mm-group');
			} else {
				a.href = item.href || '#';
				if (item.active) {
					a.classList.add('current');
					a.setAttribute('aria-current', 'page');
				}
			}
			li.appendChild(a);

			var children = Array.isArray(item.children) ? item.children : [];
			if (children.length) {
				li.classList.add('la-mm-has-flyout');
				li.appendChild(buildList(children, level + 1));
				bindFlyout(li);
			}

			ul.appendChild(li);
		});

		return ul;
	}

	// Single shared close-intent tracker. At most one panel is "closing" at a
	// time (the one the cursor just left), so one document mousemove listener
	// serves every flyout — instead of one permanent listener per item, which
	// accumulated O(items) handlers firing on every pointer move.
	var pendingClose = null; // { panel, li, apex, timer }
	var outsideCloseTimer = null;
	var lastCursor = { x: 0, y: 0 };

	function pointInsideMenu(x, y) {
		var target = document.elementFromPoint(x, y);
		return !! (target && target.closest('.la-mm-root, .la-mm-flyout, [id^="toplevel_page_la-mm-"]'));
	}

	function closeVisibleFlyouts() {
		document.querySelectorAll('.la-mm-flyout.is-visible').forEach(function (panel) {
			panel.classList.remove('is-visible');
		});
		pendingClose = null;
		outsideCloseTimer = null;
	}

	function scheduleOutsideClose(cursor) {
		// Cursor wandered off an open panel into empty chrome — close at once.
		if (!pointInsideMenu(cursor.x, cursor.y)) {
			closeVisibleFlyouts();
		}
	}

	function closeRemainingIfOutside() {
		if (!pointInsideMenu(lastCursor.x, lastCursor.y)) {
			closeVisibleFlyouts();
		}
	}

	document.addEventListener('mousemove', function (e) {
		lastCursor = { x: e.clientX, y: e.clientY };
		clearTimeout(outsideCloseTimer);
		outsideCloseTimer = null;

		if (!pendingClose) {
			scheduleOutsideClose({ x: e.clientX, y: e.clientY });
			return;
		}
		var r = pendingClose.panel.getBoundingClientRect();
		// The cursor-facing edge is the right edge for a leftward (flipped) panel.
		var edgeX = pendingClose.li.classList.contains('la-mm-flip') ? r.right : r.left;
		var cursor = { x: e.clientX, y: e.clientY };
		var target = document.elementFromPoint(cursor.x, cursor.y);
		if (target && pendingClose.panel.contains(target)) {
			clearTimeout(pendingClose.timer);
			pendingClose = null;
			return;
		}
		// Still travelling toward the panel through the safe-zone triangle: hold.
		var nearPanelEdge = Math.abs(cursor.x - edgeX) <= 36;
		var inPanelBand = cursor.y >= r.top - 16 && cursor.y <= r.bottom + 16;
		if (nearPanelEdge && inPanelBand && inTriangle(cursor, pendingClose.apex, { x: edgeX, y: r.top }, { x: edgeX, y: r.bottom })) {
			clearTimeout(pendingClose.timer);
			return;
		}
		// Cursor moved away from the panel — close immediately instead of lingering.
		clearTimeout(pendingClose.timer);
		pendingClose.panel.classList.remove('is-visible');
		pendingClose = null;
		closeRemainingIfOutside();
	});

	/** Wire hover/keyboard open-close with the safe-zone triangle. */
	function bindFlyout(li) {
		var panel = li.querySelector(':scope > .la-mm-flyout, :scope > .la-mm-root > .la-mm-flyout');
		if (!panel) {
			return;
		}

		function open() {
			// Cancel a pending close targeting this panel.
			if (pendingClose && pendingClose.panel === panel) {
				clearTimeout(pendingClose.timer);
				pendingClose = null;
			}
			// Mutually exclusive siblings: opening this panel closes any other
			// open panel at the same level (sibling <li>s in the parent list).
			var parentList = li.parentElement;
			if (parentList) {
				Array.prototype.forEach.call(parentList.children, function (sibling) {
					if (sibling === li) {
						return;
					}
					sibling.querySelectorAll(':scope > .la-mm-flyout.is-visible').forEach(function (p) {
						p.classList.remove('is-visible');
					});
				});
			}
			panel.classList.add('is-visible');
			edgeFlip(li, panel);
		}

		// apex = the point where the cursor left the parent; the safe-zone
		// triangle from there to the panel's near edge keeps it open en route.
		function scheduleClose(apex) {
			if (pendingClose) {
				clearTimeout(pendingClose.timer);
			}
			var timer = setTimeout(function () {
				panel.classList.remove('is-visible');
				if (pendingClose && pendingClose.panel === panel) {
					pendingClose = null;
				}
				closeRemainingIfOutside();
			}, CLOSE_DELAY);
			pendingClose = { panel: panel, li: li, apex: apex, timer: timer };
		}

		li.addEventListener('mouseenter', function () {
			open();
		});

		li.addEventListener('mouseleave', function (e) {
			scheduleClose({ x: e.clientX, y: e.clientY });
		});

		panel.addEventListener('mouseleave', function (e) {
			scheduleClose({ x: e.clientX, y: e.clientY });
		});

		// Keyboard: ArrowRight opens, ArrowLeft closes.
		li.addEventListener('keydown', function (e) {
			if (e.key === 'ArrowRight') {
				open();
				var first = panel.querySelector('a');
				if (first) {
					first.focus();
				}
			} else if (e.key === 'ArrowLeft') {
				panel.classList.remove('is-visible');
			}
		});
	}

	/** Flip a deep panel to open leftward if it would overflow the viewport. */
	function edgeFlip(li, panel) {
		li.classList.remove('la-mm-flip'); // reset so a re-measure can un-flip
		var r = panel.getBoundingClientRect();
		if (r.right > window.innerWidth) {
			li.classList.add('la-mm-flip');
		}
	}

	function build() {
		hideNativeOriginals();
		markActiveGroups();

		if (!flyoutsEnabled()) {
			return; // hub-page fallback owns this case
		}

		groups.forEach(function (group) {
			var topLi = document.getElementById('toplevel_page_la-mm-' + group.id);
			if (!topLi) {
				return; // group not rendered (shouldn't happen) — skip silently
			}

			var children = Array.isArray(group.children) ? group.children : [];
			if (!children.length) {
				return;
			}

			topLi.classList.add('la-mm-has-flyout', 'la-mm-top');
			var root = document.createElement('div');
			root.className = 'la-mm-root';
			var panel = buildList(children, 2);
			root.appendChild(panel);
			topLi.appendChild(root);
			bindFlyout(topLi);

			// Mark the active group with WordPress's own current-menu classes so
			// the top-level item highlights exactly like a native section.
			if (topLi.querySelector('a.current')) {
				topLi.classList.add('la-mm-active', 'current', 'wp-has-current-submenu', 'wp-menu-open');
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', build);
	} else {
		build();
	}
})();
