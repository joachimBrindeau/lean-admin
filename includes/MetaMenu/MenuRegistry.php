<?php

declare(strict_types=1);

/**
 * MetaMenu menu registry.
 *
 * Snapshots WordPress's already-capability-filtered `$menu` / `$submenu`
 * globals into normalized data the editor palette and the reorganizer consume.
 *
 * Capability safety rides along for free: WordPress only places entries in
 * `$menu`/`$submenu` that the current user can access, so resolving a ref
 * against these arrays can only ever surface links WordPress already
 * authorized. A slug that isn't present resolves to null and is dropped — we
 * never emit a link to a filtered-out destination.
 *
 * Reads live every time: the reorganizer no longer removes grouped originals
 * from `$menu`, so there is nothing to snapshot around — the palette and the
 * flyout resolver see the same intact, cap-filtered globals.
 *
 * Resolves each entry's href and native submenu children from the live menu
 * arrays so the grouped menu mirrors WordPress's own authorization.
 *
 */

namespace LeanAdmin\MetaMenu;

use LeanAdmin\UrlHelper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MenuRegistry {

    /**
     * All top-level admin-menu entries available to the current user.
     *
     * @return array<int, array{slug:string,title:string,icon:string,capability:string,href:string}>
     */
    public static function getAvailableItems(): array {
        return self::readLive();
    }

	/**
	 * Resolve a stored ref slug into a renderable node, or null if the slug is
	 * absent from the (cap-filtered) menu — i.e. gone or not permitted.
	 *
	 * The node carries the entry's real href and its native submenu rows as
	 * `children`, giving the third flyout level (e.g. Posts → All Posts /
	 * Categories / Tags) without any extra configuration.
	 *
	 * @return array{label:string,href:string,icon:string,capability:string,children:array<int,array{label:string,href:string}>}|null
	 */
	public static function resolveRef( string $slug ): ?array {
		global $menu, $submenu;

		if ( ! is_array( $menu ) ) {
			return null;
		}

		$topLevel = self::resolveTopLevelRef( $slug, $menu, is_array( $submenu ) ? $submenu : [] );
		if ( $topLevel !== null ) {
			return $topLevel;
		}

		// Submenu fallback: a ref may point at a submenu item (e.g. a taxonomy
		// screen like "Company types" living under its CPT). Resolving against
		// the cap-filtered $submenu keeps it safe — WordPress only placed it
		// there if the user can reach it. Submenu items are leaves (no deeper
		// native children of their own in the menu structure).
		if ( is_array( $submenu ) ) {
			$submenuRef = self::resolveSubmenuRef( $slug, $submenu );
			if ( $submenuRef !== null ) {
				return $submenuRef;
			}
		}

		$virtual = self::resolveVirtualRef( $slug );
		if ( $virtual !== null ) {
			return $virtual;
		}

		return null;
	}

    /**
     * Clean a raw `$menu`/`$submenu` title for plain-text display (strip tags,
     * decode entities like &mdash; -> "—", drop the trailing count bubble).
     *
     * Public + static so it is the single label normalizer for consumers of the
     * WP admin-menu globals.
     *
     * @param mixed $raw
     */
    public static function cleanLabel( $raw ): string {
        // Native titles carry markup WP outputs as HTML: count bubbles
        // ("Plugins <span class=count>0</span>") and entities (Voxel indents
        // sub-templates with "&mdash; "). Our flyout/hub render text, so strip
        // tags, decode entities (&mdash; -> "—"), and drop the trailing count.
        $text = html_entity_decode( wp_strip_all_tags( (string) $raw ), ENT_QUOTES, 'UTF-8' );
        $text = preg_replace( '/\s+\d+$/', '', $text ) ?? $text;

        return trim( $text );
    }

    /**
     * A renderable menu row has a label and slug and is not a separator.
     *
     * Public so tests and resolvers share the same validity/separator predicate.
     *
     * @param mixed $row
     */
    public static function isRealMenuRow( $row ): bool {
        if ( ! is_array( $row ) || empty( $row[0] ) || empty( $row[2] ) ) {
            return false;
        }

        // Separators carry a `wp-menu-separator` class in index 4 and a
        // `separator*` slug in index 2.
        if ( isset( $row[4] ) && strpos( (string) $row[4], 'wp-menu-separator' ) !== false ) {
            return false;
        }

        return strpos( (string) $row[2], 'separator' ) !== 0;
    }

	/**
	 * Resolve MetaMenu virtual slugs that point at plugin pages not reliably
	 * exposed through WordPress's menu globals.
	 *
	 * Voxel builder screens do not live in `$menu`/`$submenu`; the
	 * `edit-post-type-{key}` slug is a stable local convention. EMCP Tools is
	 * registered by Freemius and may not appear in the live menu array during
	 * MetaMenu resolution, but the admin route is stable.
	 *
	 * Keep capability safety explicit by requiring `manage_options`, which these
	 * destinations use for their builder/settings UI.
	 *
	 * @return array{label:string,href:string,icon:string,capability:string,children:array<int,array{label:string,href:string}>}|null
	 */
	private static function resolveVirtualRef( string $slug ): ?array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return null;
		}

		if ( $slug === 'emcp-tools' ) {
			return [
				'label'      => __( 'EMCP Tools', 'lean-admin' ),
				'href'       => UrlHelper::get_admin_url( 'emcp-tools' ),
				'icon'       => 'dashicons-admin-tools',
				'capability' => 'manage_options',
				'children'   => [],
			];
		}

		if ( ! str_starts_with( $slug, 'edit-post-type-' ) ) {
			return null;
		}

		$postType = sanitize_key( substr( $slug, strlen( 'edit-post-type-' ) ) );
		if ( $postType === '' ) {
			return null;
		}

		return [
			'label'      => __( 'Edit post type', 'lean-admin' ),
			'href'       => UrlHelper::get_voxel_edit_post_type_url( $postType ),
			'icon'       => '',
			'capability' => 'manage_options',
			'children'   => [],
		];
	}

	/**
	 * Resolve a top-level menu row.
	 *
	 * @param array<int, array<int, mixed>>                $menu
	 * @param array<string, array<int, array<int, string>>> $submenu
	 * @return array{label:string,href:string,icon:string,capability:string,children:array<int,array{label:string,href:string}>}|null
	 */
	private static function resolveTopLevelRef( string $slug, array $menu, array $submenu ): ?array {
		foreach ( $menu as $row ) {
			if ( ! self::isRealMenuRow( $row ) || (string) $row[2] !== $slug ) {
				continue;
			}

			return [
				'label'      => self::cleanLabel( $row[0] ),
				'href'       => UrlHelper::get_admin_url( $slug ),
				'icon'       => isset( $row[6] ) ? (string) $row[6] : '',
				'capability' => isset( $row[1] ) ? (string) $row[1] : 'read',
				'children'   => self::resolveSubmenu( $slug, $submenu ),
			];
		}

		return null;
	}

	/**
	 * Resolve a slug from native submenu rows.
	 *
	 * @param array<string, array<int, array<int, string>>> $submenu
	 * @return array{label:string,href:string,icon:string,capability:string,children:array<int,array{label:string,href:string}>}|null
	 */
	private static function resolveSubmenuRef( string $slug, array $submenu ): ?array {
		$match = self::findSubmenuItem( $slug, $submenu );
		if ( $match === null ) {
			return null;
		}
		$item       = $match['item'];
		$parentSlug = $match['parent'];

		return [
			'label'      => self::cleanLabel( $item[0] ),
			// Voxel builder rows use a bare `edit-post-type-*` child slug
			// whose real route depends on its CPT parent query. Retain the
			// parent discovered during the submenu scan instead of flattening
			// it to the invalid `admin.php?page=*` generic plugin-page shape.
			'href'       => UrlHelper::get_admin_submenu_url( $parentSlug, $slug ),
			'icon'       => '',
			'capability' => isset( $item[1] ) ? (string) $item[1] : 'read',
			'children'   => [],
		];
	}

	/**
	 * @param array<string, array<int, array<int, string>>> $submenu
	 * @return array{parent:string,item:array<int,mixed>}|null
	 */
	private static function findSubmenuItem( string $slug, array $submenu ): ?array {
		foreach ( $submenu as $parentSlug => $items ) {
			if ( ! is_array( $items ) ) {
				continue;
			}
			foreach ( $items as $item ) {
				if ( is_array( $item ) && (string) ( $item[2] ?? '' ) === $slug && ! empty( $item[0] ) ) {
					return [
						'parent' => (string) $parentSlug,
						'item'   => $item,
					];
				}
			}
		}

		return null;
	}

    /**
     * Read the live `$menu` global into normalized available items.
     *
     * @return array<int, array{slug:string,title:string,icon:string,capability:string,href:string}>
     */
    private static function readLive(): array {
        global $menu;

        $items = [];

        if ( ! is_array( $menu ) ) {
            return $items;
        }

        foreach ( $menu as $row ) {
            if ( ! self::isRealMenuRow( $row ) ) {
                continue;
            }

            $slug = (string) $row[2];

            $items[] = [
                'slug'       => $slug,
                'title'      => self::cleanLabel( $row[0] ),
                'icon'       => isset( $row[6] ) ? (string) $row[6] : '',
                'capability' => isset( $row[1] ) ? (string) $row[1] : 'read',
                'href'       => UrlHelper::get_admin_url( $slug ),
            ];
        }

        return $items;
    }

    /**
     * Native submenu rows for a parent slug, as flat leaf nodes.
     *
     * @param array<string, array<int, array<int, string>>> $submenu
     * @return array<int, array{label:string,href:string}>
     */
    private static function resolveSubmenu( string $parentSlug, array $submenu ): array {
        if ( ! isset( $submenu[ $parentSlug ] ) || ! is_array( $submenu[ $parentSlug ] ) ) {
            return [];
        }

        $children = [];

        foreach ( $submenu[ $parentSlug ] as $sub ) {
            if ( ! is_array( $sub ) || empty( $sub[0] ) || empty( $sub[2] ) ) {
                continue;
            }

            $childSlug = (string) $sub[2];

            $children[] = [
                'label' => self::cleanLabel( $sub[0] ),
                // WordPress links a submenu item to its parent file when the
                // slug isn't itself a .php/route — resolve through the same
                // helper the indexer uses so the href matches core's link.
                'href'  => UrlHelper::get_admin_submenu_url( $parentSlug, $childSlug ),
            ];
        }

	    return $children;
	}
}
