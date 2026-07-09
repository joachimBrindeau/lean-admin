<?php

declare(strict_types=1);

/**
 * MetaMenu reorganizer.
 *
 * The PHP runtime core. At `admin_menu` (late priority, after CPTs register)
 * it:
 *   1. registers one real native top-level entry per top-level group
 *      (`add_menu_page`), whose callback renders a server-side hub page —
 *      the no-JS / folded-rail fallback;
 *   2. resolves the saved tree against the cap-filtered menu into a structural
 *      tree the browser renders as flyouts, plus the hide-list of grouped
 *      originals for the runtime to hide.
 *
 * It is strictly ADDITIVE to the native sidebar: it never removes a menu entry
 * and never registers a parent_file/submenu_file filter. Leaving `$menu`,
 * `$submenu`, `$_registered_pages` and `$_parent_pages` untouched keeps
 * WordPress's own user_can_access_admin_page() authorization fully native, so a
 * grouped page can never become inaccessible. The grouped originals are hidden
 * in the browser (metamenu.js), not deleted server-side.
 *
 * It NEVER asks WordPress to render depth and NEVER relocates live DOM nodes —
 * WordPress renders the (native) top level; the runtime JS draws brand-new
 * flyout markup off our own group `<li>`s. Routing + capabilities ride along
 * because every href/cap is sourced from WordPress's own cap-filtered arrays.
 *
 * The pure tree transforms (buildTree / dedupe / markActive / hideHrefs) live
 * in {@see MenuTreeBuilder} and are unit-tested in isolation; this class owns
 * only the WordPress registration of group hub pages.
 *
 */

namespace LeanAdmin\MetaMenu;

use LeanAdmin\PluginPaths;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MetaMenuReorganizer {

    /** Resolved structural tree (top-level groups), built once in apply(). */
    private array $resolvedGroups = [];

    /** Absolute hrefs of grouped/hidden originals the runtime hides. */
    private array $hideHrefs = [];

    public function register(): void {
        // Priority 10000 mirrors the origin pattern doc — late enough that CPT
        // and plugin menus have already registered.
        add_action( 'admin_menu', [ $this, 'apply' ], 10000 );
    }

    /**
     * admin_menu callback: register each group as a native top-level hub page
     * and cache the resolved tree + hide-list for the localizer.
     *
     * Deliberately NON-destructive: it never calls remove_menu_page() or
     * remove_submenu_page(). The grouped originals stay in `$menu`/`$submenu`
     * exactly as WordPress built them, so core's user_can_access_admin_page()
     * still authorizes every moved page — eliminating the 403-on-grouped-page
     * class of bug entirely. The originals are hidden visually in the browser
     * by the runtime (metamenu.js), keyed on the hrefs computed here.
     */
    public function apply(): void {
        $config = MetaMenuConfig::load();

        if ( $config === [] ) {
            return;
        }

        // Resolve the structural flyout tree and the active-state from the live,
        // cap-filtered menu. No snapshot needed: nothing is removed, so the
        // editor palette reads the same live `$menu` later.
        $this->resolvedGroups = MenuTreeBuilder::buildTree( $config );
        $this->resolvedGroups = MenuTreeBuilder::dedupeGroupedHrefs( $this->resolvedGroups );
        $this->resolvedGroups = MenuTreeBuilder::markActive( $this->resolvedGroups, MenuTreeBuilder::currentMenuSlug() );
        $this->hideHrefs      = MenuTreeBuilder::hideHrefs( $config );

        // Register each group as a real native top-level entry (the hub page is
        // the no-JS / folded-rail fallback; the flyout JS draws the rest).
        foreach ( $this->resolvedGroups as $group ) {
            if ( ( $group['type'] ?? null ) !== 'group' || $group['children'] === [] ) {
                continue; // empty / non-group resolved nodes render nothing
            }

            $groupId = $group['id'];
            add_menu_page(
                $group['label'],
                $group['label'],
                'read',
                MenuTreeBuilder::MENU_SLUG_PREFIX . $groupId,
                function () use ( $groupId ): void {
                    $this->renderHub( $groupId );
                },
                // 'none' renders no icon (empty .wp-menu-image) — an unset icon
                // means the group shows label-only rather than a forced default.
                $group['icon'] !== '' ? $group['icon'] : 'none',
                $group['position']
            );
        }
    }

    /**
     * Structural tree for wp_localize_script. Built by apply().
     *
     * @return array<int, array<string, mixed>>
     */
    public function getLocalizedTree(): array {
        return $this->resolvedGroups;
    }

    /**
     * Absolute hrefs of the grouped/hidden originals for the runtime to hide.
     * Built by apply().
     *
     * @return array<int, string>
     */
    public function getHideHrefs(): array {
        return $this->hideHrefs;
    }

    /**
     * Render the hub page for a group (no-JS / folded-rail fallback).
     */
    public function renderHub( string $groupId ): void {
        $group = null;
        foreach ( $this->resolvedGroups as $candidate ) {
            if ( $candidate['id'] === $groupId ) {
                $group = $candidate;
                break;
            }
        }

        if ( $group === null ) {
            return;
        }

        $template = PluginPaths::path() . 'templates/metamenu-hub.php';
        if ( file_exists( $template ) ) {
            include $template;
        }
    }
}
