<?php

declare(strict_types=1);

/**
 * URL Helper for Lean Admin
 * Ensures proper admin URL generation.
 */

namespace LeanAdmin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class UrlHelper {

    /**
     * Resolve a WP admin-menu slug/path to its admin URL.
     *
     * The one behavior that matters: a bare custom-page slug (no `.php`, no
     * query string, no slash) — e.g. `voxel-settings` — is rewritten to
     * `admin.php?page=<slug>`, which `admin_url()` alone would not do. Anything
     * already shaped as a core file/query (`edit.php?post_type=…`) passes
     * straight through. URL construction itself is delegated to WordPress core's
     * `admin_url()` — its contract is trusted, so no hand-rolled fallback.
     */
    public static function get_admin_url( string $path = '', string $scheme = 'admin' ): string {
        if (
            $path !== ''
            && strpos( $path, '.php' ) === false
            && strpos( $path, '?' ) === false
            && strpos( $path, '/' ) === false
            && strpos( $path, 'http' ) !== 0
        ) {
            $path = 'admin.php?page=' . $path;
        }

        return admin_url( $path, $scheme );
    }

    public static function get_voxel_edit_post_type_url( string $postType, string $tab = '', string $scheme = 'admin' ): string {
        $postType = sanitize_key( $postType );
        if ( $postType === '' ) {
            return self::get_admin_url( '', $scheme );
        }

        $path = $postType === 'post'
            ? 'edit.php?page=edit-post-type-post'
            : sprintf( 'edit.php?post_type=%s&page=edit-post-type-%s', $postType, $postType );

        if ( $tab !== '' ) {
            $path .= '&tab=' . rawurlencode( $tab );
        }

        return self::get_admin_url( $path, $scheme );
    }

    /**
     * Resolve a submenu slug/path to its admin URL.
     *
     * WordPress submenu entries store their target in `$submenu[ $parent ][*][2]`.
     * For custom plugin pages that value is already the full page slug registered
	 * with `add_submenu_page()` — e.g. `lean-seo-variables`. It must resolve to
	 * `admin.php?page=lean-seo-variables`, not `<parent>?page=lean-seo-variables`.
	 * Voxel's builder submenu slugs (`edit-post-type-*`) are the exception:
	 * those routes are registered beneath their CPT parent file and need the
	 * parent query shape. Core admin paths (`edit.php?...`, `options-general.php`)
	 * still pass through unchanged via `get_admin_url()`.
	 */
    public static function get_admin_submenu_url( string $parentPath, string $childPath, string $scheme = 'admin' ): string {
		if (
			str_starts_with( $childPath, 'edit-post-type-' )
			&& strpos( $parentPath, '.php' ) !== false
			&& strpos( $parentPath, 'http' ) !== 0
		) {
			$separator = strpos( $parentPath, '?' ) === false ? '?' : '&';

			return self::get_admin_url( $parentPath . $separator . 'page=' . rawurlencode( $childPath ), $scheme );
		}

        return self::get_admin_url( $childPath, $scheme );
    }
}
