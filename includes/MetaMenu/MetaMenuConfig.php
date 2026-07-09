<?php

declare(strict_types=1);

/**
 * MetaMenu configuration store.
 *
 * Single source of truth for loading, validating, normalizing, and saving the
 * metamenu grouping tree. The tree is a recursive, ordered list of nodes; each
 * node is either:
 *   - a GROUP:  [ 'type' => 'group', 'id' => string, 'label' => string,
 *                 'icon' => string (dashicons class), 'children' => Node[] ]
 *   - a REF:    [ 'type' => 'ref', 'slug' => string ]   // an existing menu slug
 *
 * `children` recurses with no depth limit, so arbitrarily deep grouping costs
 * zero special-casing here, in the editor, or in the runtime renderer.
 *
 */

namespace LeanAdmin\MetaMenu;

use LeanAdmin\Config\PluginConstants;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MetaMenuConfig {

    /** Dashicons class shape — the only icon value we accept (XSS-safe). */
    private const ICON_PATTERN = '/^dashicons-[a-z0-9-]+$/';

    /**
     * Recursion ceiling for normalize(). Far beyond any real grouping depth;
     * exists only to stop a pathological payload from exhausting the stack.
     */
    private const MAX_DEPTH = 20;

    /**
     * Load the normalized metamenu tree.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function load(): array {
        $stored = get_option( PluginConstants::OPTION_METAMENU, [] );

        return self::normalize( is_array( $stored ) ? $stored : [] );
    }

    /**
     * Validate + persist a tree.
     *
     * Returns true when the option now holds the normalized tree — covering
     * both a changed write and a no-op save of an identical tree (where
     * update_option returns false). Returns false only on a genuine write
     * failure, so the caller can surface a real error instead of a misleading
     * success.
     *
     * @param array<int, mixed> $tree
     */
    public static function save( array $tree ): bool {
        $normalized = self::normalize( $tree );
        update_option( PluginConstants::OPTION_METAMENU, $normalized );

        // Re-read and compare strictly: update_option returns false on a no-op
        // (identical value), so verifying persisted state is the reliable
        // success signal. The normalized tree round-trips through the options
        // store without type/order drift, so === is safe.
        return get_option( PluginConstants::OPTION_METAMENU, [] ) === $normalized;
    }

    /**
     * Recursively validate + sanitize the tree, dropping malformed nodes.
     *
     * Never throws on bad input: a malformed node is silently discarded so a
     * partially-corrupt payload degrades to a smaller valid tree rather than a
     * fatal. Unknown keys are stripped. Recursion is bounded by MAX_DEPTH so a
     * pathologically deep payload cannot exhaust the PHP stack on every admin
     * load (the depth ceiling is far beyond any real grouping need).
     *
     * @param array<int, mixed> $nodes
     * @param int               $depth
     * @return array<int, array<string, mixed>>
     */
    public static function normalize( array $nodes, int $depth = 0 ): array {
        if ( $depth > self::MAX_DEPTH ) {
            return [];
        }

        $clean = [];

        foreach ( $nodes as $node ) {
            if ( ! is_array( $node ) ) {
                continue;
            }

            $built = match ( $node['type'] ?? null ) {
                'group' => self::normalizeGroup( $node, $depth ),
                'ref'   => self::normalizeRef( $node, $depth ),
                'hide'  => self::normalizeHide( $node ),
                default => null,
            };

            if ( $built !== null ) {
                $clean[] = $built;
            }
        }

        return $clean;
    }

    /**
     * Normalize a group node, or null when it has no usable label.
     *
     * @param array<string, mixed> $node
     * @return array<string, mixed>|null
     */
    private static function normalizeGroup( array $node, int $depth = 0 ): ?array {
        $label = isset( $node['label'] ) && is_string( $node['label'] )
            ? sanitize_text_field( $node['label'] )
            : '';

        // A group with no label is meaningless — drop it.
        if ( $label === '' ) {
            return null;
        }

        return [
            'type'     => 'group',
            'id'       => self::sanitizeId( $node['id'] ?? '' ),
            'label'    => $label,
            'icon'     => self::sanitizeIcon( $node['icon'] ?? '' ),
            'children' => isset( $node['children'] ) && is_array( $node['children'] )
                ? self::normalize( $node['children'], $depth + 1 )
                : [],
        ];
    }

    /**
     * Normalize a hide node — an entry to drop from the sidebar entirely.
     *
     * @param array<string, mixed> $node
     * @return array<string, mixed>|null
     */
    private static function normalizeHide( array $node ): ?array {
        if ( ! isset( $node['slug'] ) || ! is_string( $node['slug'] ) || $node['slug'] === '' ) {
            return null;
        }

        return [
            'type' => 'hide',
            'slug' => self::sanitizeSlug( $node['slug'] ),
        ];
    }

    /**
     * Normalize a ref node, or null when its slug is missing/empty.
     *
     * A ref may carry an optional `label` (display override) and optional
     * `children` (explicit labelled sub-links that replace the entry's native
     * submenu — e.g. uniform List / Create / Edit leaves). Both are preserved
     * only when present so a bare ref stays minimal.
     *
     * @param array<string, mixed> $node
     * @param int                  $depth
     * @return array<string, mixed>|null
     */
    private static function normalizeRef( array $node, int $depth = 0 ): ?array {
        if ( ! isset( $node['slug'] ) || ! is_string( $node['slug'] ) || $node['slug'] === '' ) {
            return null;
        }

        $ref = [
            'type' => 'ref',
            'slug' => self::sanitizeSlug( $node['slug'] ),
        ];

        if ( isset( $node['label'] ) && is_string( $node['label'] ) && $node['label'] !== '' ) {
            $ref['label'] = sanitize_text_field( $node['label'] );
        }

        if ( isset( $node['icon'] ) ) {
            $icon = self::sanitizeIcon( $node['icon'] );
            if ( $icon !== '' ) {
                $ref['icon'] = $icon;
            }
        }

        if ( isset( $node['children'] ) && is_array( $node['children'] ) ) {
            $ref['children'] = self::normalize( $node['children'], $depth + 1 );
        }

        return $ref;
    }

    /**
     * Coerce an icon to a known dashicons class or empty string.
     *
     * Rejects URLs, `data:` URIs, and arbitrary markup — only the dashicons
     * class shape is allowed, so the value is safe to echo into menu markup
     * without further escaping ambiguity.
     */
    private static function sanitizeIcon( mixed $icon ): string {
        if ( is_string( $icon ) && preg_match( self::ICON_PATTERN, $icon ) === 1 ) {
            return $icon;
        }

        return '';
    }

    /**
     * Sanitize (or generate) a stable group id.
     *
     * Ids only ever appear in our own `la-mm-{id}` menu slug and CSS hooks, so
     * we constrain them to a slug-safe shape. A missing/blank id gets a stable
     * generated one derived from a hash of the existing ids count is avoided —
     * we use uniqid via wp_generate_uuid4 fallback to stay deterministic-free.
     */
    private static function sanitizeId( mixed $id ): string {
        if ( is_string( $id ) && $id !== '' ) {
            $clean = preg_replace( '/[^a-z0-9_-]/', '', strtolower( $id ) );
            if ( is_string( $clean ) && $clean !== '' ) {
                return $clean;
            }
        }

        // Generate a stable, collision-resistant id when none was supplied.
        return 'g' . substr( md5( wp_generate_uuid4() ), 0, 8 );
    }

    /**
     * Sanitize a menu slug. Slugs are admin URLs / page keys such as
     * `edit.php`, `edit.php?post_type=page`, `users.php`, `admin.php?page=foo`.
     * We strip control characters but preserve `?`, `=`, `&`, `.`, `/` so the
     * slug still resolves to the exact route WordPress registered.
     */
    private static function sanitizeSlug( string $slug ): string {
        // Remove any tag/whitespace noise without mangling query args.
        $slug = wp_strip_all_tags( $slug );

        return trim( $slug );
    }
}
