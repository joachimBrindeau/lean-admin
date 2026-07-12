<?php

declare(strict_types=1);

/**
 * MetaMenu tree builder — the pure, side-effect-free transforms that turn a
 * normalized config tree + the cap-filtered WP `$menu`/`$submenu` into the
 * structural render tree the flyout JS and the hub fallback consume.
 *
 * Extracted from {@see MetaMenuReorganizer} so the WordPress orchestration
 * (registering each group's add_menu_page hub) lives in the reorganizer, while
 * every pure transform — resolution, dedup, active-marking, the hide-href list
 * — lives here and is unit tested in isolation. The originals are NOT removed
 * from `$menu`/`$submenu`; they are hidden in the browser by the runtime, so
 * WordPress's native page authorization stays intact. Nothing in this class
 * touches WP global state beyond
 * READING `$_GET`/`$pagenow` for the active-screen slug and resolving refs
 * through {@see MenuRegistry} (itself a read-only snapshot of the menu globals).
 */

namespace LeanAdmin\MetaMenu;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MenuTreeBuilder {

    /**
     * Prefix for the native top-level menu slug minted per group. SSOT shared
     * with {@see MetaMenuReorganizer}, which registers the pages and reads it
     * back for active-state remapping.
     */
    public const MENU_SLUG_PREFIX = 'la-mm-';

    /**
     * Resolve a normalized config tree into a structural render tree.
     *
     * Top-level nodes become groups (each with a menu slug + position); refs
     * resolve against MenuRegistry (dropped when absent / not permitted);
     * subgroups recurse. Native submenu children of a ref are appended as
     * leaf links, giving the third flyout level for free.
     *
     * @param array<int, array<string, mixed>> $config
     * @param int                               $depth
     * @return array<int, array<string, mixed>>
     */
    public static function buildTree( array $config, int $depth = 0 ): array {
        $out   = [];
        $index = 0;

        foreach ( $config as $node ) {
            $type = $node['type'] ?? null;

            if ( $type === 'group' ) {
                $out[] = self::buildGroup( $node, $depth, $index );
                ++$index;
            } elseif ( $type === 'ref' ) {
                $link = self::buildRef( $node );
                if ( $link !== null ) {
                    $out[] = $link;
                }
            }
        }

        return $out;
    }

    /**
     * Remove dead refs and promote valid children when their parent vanished.
     *
     * Unconfigured live menu entries need no stored node: WordPress keeps them
     * at the native root automatically. This transform only repairs configured
     * nodes, so a newly registered entry can never disappear because Lean Admin
     * failed to infer a parent.
     *
     * @param array<int, array<string, mixed>> $config
     * @return array<int, array<string, mixed>>
     */
    public static function reconcileConfig( array $config ): array {
        $out = [];

        foreach ( $config as $node ) {
            array_push( $out, ...self::reconcileNode( $node ) );
        }

        return $out;
    }

    /**
     * Flat list of every hide-node slug in the config tree.
     *
     * @param array<int, array<string, mixed>> $config Normalized config tree
     * @return array<int, string>
     */
    public static function configHideSlugs( array $config ): array {
        return self::collectConfigSlugs( $config, 'hide' );
    }

    /**
     * Drop flyout leaves that duplicate a grouped ref's destination.
     *
     * A grouped ref is the single canonical home for its destination, so any
     * native submenu leaf elsewhere in the tree pointing at the same href (a
     * mirror like Voxel's "Profiles (Voxel)" under Users, or a taxonomy screen
     * still listed under its CPT now that it lives in a Taxonomies hub) is
     * pruned. A ref's own self-link (leaf href === the ref's href, e.g. the
     * "All Posts" row under Posts) is preserved.
     *
     * @param array<int, array<string, mixed>> $groups
     * @return array<int, array<string, mixed>>
     */
    public static function dedupeGroupedHrefs( array $groups ): array {
        $refHrefs = [];
        self::collectRefHrefs( $groups, $refHrefs );

        return self::pruneDuplicateLeaves( $groups, $refHrefs );
    }

    /**
     * Absolute admin URLs of every original sidebar entry that should be hidden
     * because it was grouped or explicitly hidden in the config.
     *
     * The runtime (metamenu.js) matches these against the native sidebar's own
     * anchors and hides the matching `<li>` — a CSS/DOM hide, NOT a removal. We
     * deliberately never call remove_menu_page()/remove_submenu_page(): leaving
     * `$menu`/`$submenu` untouched keeps WordPress's own
     * user_can_access_admin_page() authorization byte-for-byte native, so a
     * grouped page can never 403. Resolving through the same UrlHelper the
     * sidebar uses guarantees the hrefs match what core renders.
     *
     * @param array<int, array<string, mixed>> $config Normalized config tree
     * @return array<int, string>
     */
    public static function hideHrefs( array $config ): array {
        $hrefs = [];

        foreach ( array_unique( self::configRefSlugs( $config ) ) as $slug ) {
            $resolved = MenuRegistry::resolveRef( $slug );
            if ( $resolved !== null ) {
                $hrefs[] = $resolved['href'];
            }
        }

        foreach ( array_unique( self::configHideSlugs( $config ) ) as $slug ) {
            $hrefs[] = \LeanAdmin\UrlHelper::get_admin_url( $slug );
        }

        return array_values( array_unique( $hrefs ) );
    }

    /**
     * Flat list of every ref slug in the config tree (the extracted CPTs).
     *
     * @param array<int, array<string, mixed>> $config Normalized config tree
     * @return array<int, string>
     */
    public static function configRefSlugs( array $config ): array {
        return self::collectConfigSlugs( $config, 'ref' );
    }

    /**
     * Mark the node matching the active slug (and its link children) as active.
     *
     * @param array<int, array<string, mixed>> $nodes
     * @return array<int, array<string, mixed>>
     */
    public static function markActive( array $nodes, string $activeSlug ): array {
        if ( $activeSlug === '' ) {
            return $nodes;
        }

        foreach ( $nodes as &$node ) {
            if ( ( $node['type'] ?? null ) === 'link' ) {
                if ( ( $node['slug'] ?? null ) === $activeSlug ) {
                    $node['active'] = true;
                }
                if ( ! empty( $node['children'] ) ) {
                    $node['children'] = self::markActive( $node['children'], $activeSlug );
                }
            } elseif ( ( $node['type'] ?? null ) === 'group' ) {
                $node['children'] = self::markActive( $node['children'] ?? [], $activeSlug );
            }
        }
        unset( $node );

        return $nodes;
    }

    /**
     * Reconstruct the menu slug for the current admin screen, matching the
     * shape WordPress stores in `$menu[$i][2]`.
     *
     * Covers the common destinations (plugin pages, posts, pages, CPTs, core
     * file pages). Uncommon deep screens fall back to `$pagenow`, which simply
     * means the owning group isn't auto-highlighted — a benign degradation.
     */
    public static function currentMenuSlug(): string {
        // Plugin pages store their bare page slug in $menu[2]. Read-only routing
        // detection — no state change, so no nonce applies.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = isset( $_GET['page'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['page'] ) ) : '';
        if ( $page !== '' ) {
            return $page;
        }

        global $pagenow;
        $slug = is_string( $pagenow ?? null ) ? $pagenow : '';

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing detection.
        $postType = isset( $_GET['post_type'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['post_type'] ) ) : '';
        if ( $postType !== '' && in_array( $slug, [ 'edit.php', 'post-new.php' ], true ) ) {
            $slug .= '?post_type=' . $postType;
        }

        return $slug;
    }

    /**
     * Normalize a managed menu label to Title Case (first letter of each word
     * uppercase, the rest lowercase).
     *
     * Every label the MetaMenu RENDERS — group headers, resolved refs, native
     * submenu leaves, and explicit config leaves, at any depth — flows through
     * here so ALL-CAPS ("ELEMENTOR" -> "Elementor") and all-lowercase
     * ("woocommerce" -> "Woocommerce") plugin names read uniformly in the
     * flyout and hub. Applied only to the flyout render tree; stored refs keep
     * WordPress's original slugs.
     *
     * `mb_convert_case( …, MB_CASE_TITLE )` is UTF-8 aware and titlecases after
     * every non-letter, so separators (spaces, hyphens, the decoded em-dash
     * Voxel indents with) are preserved while each following word is capped.
     * Falls back to `ucwords( strtolower() )` if mbstring is unavailable.
     */
    public static function titleCase( string $label ): string {
        if ( $label === '' ) {
            return '';
        }

        if ( function_exists( 'mb_convert_case' ) ) {
            return mb_convert_case( $label, MB_CASE_TITLE, 'UTF-8' );
        }

        return ucwords( strtolower( $label ) );
    }

    /**
     * Flat list of slugs for every node of $type in the config tree, recursing
     * through groups. Single source for the ref/hide slug walks.
     *
     * @param array<int, array<string, mixed>> $config Normalized config tree
     * @param string                           $type   Node type to collect ('ref' | 'hide')
     * @return array<int, string>
     */
    private static function collectConfigSlugs( array $config, string $type ): array {
        $slugs = [];

        foreach ( $config as $node ) {
            $nodeType = $node['type'] ?? null;
			if ( $nodeType === $type && isset( $node['slug'] ) ) {
				$slugs[] = (string) $node['slug'];
			}

			if ( isset( $node['children'] ) && is_array( $node['children'] ) ) {
				$slugs = array_merge( $slugs, self::collectConfigSlugs( $node['children'], $type ) );
			}
        }

        return $slugs;
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     * @param array<string, bool>              $set
     */
    private static function collectRefHrefs( array $nodes, array &$set ): void {
        foreach ( $nodes as $node ) {
            if ( ( $node['type'] ?? null ) === 'link' && isset( $node['slug'], $node['href'] ) ) {
                $set[ (string) $node['href'] ] = true;
            }
            if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
                self::collectRefHrefs( $node['children'], $set );
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     * @param array<string, bool>              $refHrefs
     * @return array<int, array<string, mixed>>
     */
    private static function pruneDuplicateLeaves( array $nodes, array $refHrefs ): array {
        $out = [];

        foreach ( $nodes as $node ) {
            $type = $node['type'] ?? null;

            if ( $type === 'link' ) {
                $out[] = self::pruneDuplicateLinkChildren( $node, $refHrefs );
            } elseif ( $type === 'group' ) {
                $node['children'] = self::pruneDuplicateLeaves( $node['children'] ?? [], $refHrefs );
                $out[]            = $node;
            } else {
                $out[] = $node;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, bool>  $refHrefs
     * @return array<string, mixed>
     */
    private static function pruneDuplicateLinkChildren( array $node, array $refHrefs ): array {
        if ( empty( $node['children'] ) || ! is_array( $node['children'] ) ) {
            return $node;
        }

        $own              = (string) ( $node['href'] ?? '' );
        $node['children'] = array_values(
            array_filter(
                $node['children'],
                static function ( array $child ) use ( $own, $refHrefs ): bool {
                    $href = (string) ( $child['href'] ?? '' );

                    return $href === '' || $href === $own || ! isset( $refHrefs[ $href ] );
                },
            )
        );

        return $node;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, array<string, mixed>>
     */
    private static function reconcileNode( array $node ): array {
        return match ( $node['type'] ?? null ) {
            'group' => self::reconcileGroup( $node ),
            'ref'   => self::reconcileRef( $node ),
            'hide'  => MenuRegistry::resolveRef( (string) ( $node['slug'] ?? '' ) ) === null ? [] : [ $node ],
            default => [],
        };
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, array<string, mixed>>
     */
    private static function reconcileGroup( array $node ): array {
        $node['children'] = self::reconcileConfig( is_array( $node['children'] ?? null ) ? $node['children'] : [] );

        return $node['children'] === [] ? [] : [ $node ];
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, array<string, mixed>>
     */
    private static function reconcileRef( array $node ): array {
        $children = self::reconcileConfig( is_array( $node['children'] ?? null ) ? $node['children'] : [] );
        if ( MenuRegistry::resolveRef( (string) ( $node['slug'] ?? '' ) ) === null ) {
            return $children;
        }
        if ( isset( $node['children'] ) ) {
            $node['children'] = $children;
        }

        return [ $node ];
    }

    /**
     * Build a resolved group node. Top-level groups get a menu position.
     *
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private static function buildGroup( array $node, int $depth, int $index ): array {
        $group = [
            'type'     => 'group',
            'id'       => (string) ( $node['id'] ?? '' ),
            'label'    => self::titleCase( (string) ( $node['label'] ?? '' ) ),
            'icon'     => (string) ( $node['icon'] ?? '' ),
            'children' => self::buildTree( $node['children'] ?? [], $depth + 1 ),
        ];

        if ( $depth === 0 ) {
            // Slot groups just after the Dashboard (position 2) in declared
            // order, using decimals to avoid collisions.
            $group['position'] = 2.9 + ( $index / 100 );
        }

        return $group;
    }

    /**
     * Resolve a ref into a link node (with native submenu children), or null
     * when the slug is absent / not permitted (capability safety).
     *
     * @param array<string, mixed> $node
     * @return array<string, mixed>|null
     */
    private static function buildRef( array $node ): ?array {
        $resolved = MenuRegistry::resolveRef( (string) ( $node['slug'] ?? '' ) );
        if ( $resolved === null ) {
            return null;
        }

        // Explicit config children replace the native submenu with labelled
        // leaves (each still cap-resolved, so an inaccessible one drops). Used
        // for uniform leaves like List / Create / Edit. Otherwise fall back to
        // the entry's native submenu.
        if ( isset( $node['children'] ) && is_array( $node['children'] ) && $node['children'] !== [] ) {
            $children = self::buildExplicitLeaves( $node['children'] );
        } else {
            $children = [];
            foreach ( $resolved['children'] as $child ) {
                $children[] = [
                    'type'   => 'link',
                    'label'  => self::titleCase( $child['label'] ),
                    'href'   => $child['href'],
                    'active' => false,
                ];
            }
        }

        return [
            'type'     => 'link',
            'slug'     => (string) $node['slug'],
            'label'    => self::titleCase( self::overrideOr( $node, 'label', $resolved['label'] ) ),
            'href'     => $resolved['href'],
            'icon'     => self::overrideOr( $node, 'icon', $resolved['icon'] ),
            'active'   => false,
            'children' => $children,
        ];
    }

    /**
     * A node's non-empty string override for $key, else the resolved fallback.
     * Single source for the label/icon override-or-default rule.
     *
     * @param array<string, mixed> $node
     */
    private static function overrideOr( array $node, string $key, string $fallback ): string {
        return isset( $node[ $key ] ) && $node[ $key ] !== '' ? (string) $node[ $key ] : $fallback;
    }

    /**
     * Build explicit config child refs as cap-resolved leaf links, applying any
     * per-child label override. Children that don't resolve (gone / not
     * permitted) are dropped — preserving the cap-safe guarantee.
     *
     * @param array<int, array<string, mixed>> $children Normalized ref children
     * @return array<int, array{type:string,label:string,href:string,active:bool}>
     */
    private static function buildExplicitLeaves( array $children ): array {
        $leaves = [];
        foreach ( $children as $child ) {
            if ( ( $child['type'] ?? null ) !== 'ref' || ! isset( $child['slug'] ) ) {
                continue;
            }
            $resolved = MenuRegistry::resolveRef( (string) $child['slug'] );
            if ( $resolved === null ) {
                continue;
            }
            $leaves[] = [
                'type'   => 'link',
                'label'  => self::titleCase( self::overrideOr( $child, 'label', $resolved['label'] ) ),
                'href'   => $resolved['href'],
                'active' => false,
            ];
        }

        return $leaves;
    }
}
