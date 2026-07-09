<?php

/**
 * MetaMenu group hub page.
 *
 * The no-JS / folded-rail fallback: renders a group's resolved subtree as a
 * scannable launchpad using ONLY WordPress core admin styles — core `.button`
 * link chips, native headings inside `.wrap`, and dashicons. No custom CSS.
 *
 * Leaf destinations render as core button chips (the active one as
 * `button-primary`); parent nodes (refs with native submenus, or subgroups)
 * render as a heading followed by their own chips, recursing for depth.
 *
 * Expects `$group` (resolved group node) in scope, provided by
 * MetaMenuReorganizer::renderHub().
 *
 * @var array<string, mixed> $group
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! isset( $group ) || ! is_array( $group ) ) {
    return;
}

/**
 * Render one node's icon (dashicon) when present, as core markup.
 *
 * @param array<string, mixed> $node
 */
$icon_markup = static function ( array $node ): string {
    $icon = (string) ( $node['icon'] ?? '' );
    return $icon !== '' ? '<span class="dashicons ' . esc_attr( $icon ) . '" style="vertical-align:middle"></span> ' : '';
};

/**
 * Render a single leaf destination as a core button chip.
 *
 * @param array<string, mixed> $node
 */
$chip = static function ( array $node ) use ( $icon_markup ): void {
    $classes = 'button button-secondary' . ( ! empty( $node['active'] ) ? ' button-primary' : '' );
    printf(
        '<a class="%s" href="%s" style="margin:0 6px 6px 0">%s%s</a>',
        esc_attr( $classes ),
        esc_url( (string) ( $node['href'] ?? '#' ) ),
        $icon_markup( $node ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped dashicon span
        esc_html( (string) ( $node['label'] ?? '' ) )
    );
};

/**
 * Recursively render resolved nodes: leaves as a chip row, parents as a heading
 * (linked when navigable) + their children.
 *
 * @param array<int, array<string, mixed>> $nodes
 * @param int                              $level
 */
$render_nodes = static function ( array $nodes, int $level ) use ( &$render_nodes, $chip, $icon_markup ): void {
    if ( $nodes === [] ) {
        return;
    }

    $leaves  = array_filter( $nodes, static fn( $n ): bool => empty( $n['children'] ) );
    $parents = array_filter( $nodes, static fn( $n ): bool => ! empty( $n['children'] ) );

    if ( $leaves !== [] ) {
        echo '<p>';
        foreach ( $leaves as $leaf ) {
            $chip( $leaf );
        }
        echo '</p>';
    }

    foreach ( $parents as $parent ) {
        $tag   = $level <= 1 ? 'h2' : 'h3';
        $label = esc_html( (string) ( $parent['label'] ?? '' ) );
        if ( ! empty( $parent['href'] ) ) {
            $label = '<a href="' . esc_url( (string) $parent['href'] ) . '">' . $label . '</a>';
        }
        printf( '<%1$s>%2$s%3$s</%1$s>', $tag, $icon_markup( $parent ), $label ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- label + icon pre-escaped above
        $render_nodes( (array) $parent['children'], $level + 1 );
    }
};
?>
<div class="wrap">
	<h1 class="wp-heading-inline">
		<?php if ( ! empty( $group['icon'] ) ) : ?>
			<span class="dashicons <?php echo esc_attr( (string) $group['icon'] ); ?>" style="vertical-align:middle"></span>
		<?php endif; ?>
		<?php echo esc_html( (string) ( $group['label'] ?? '' ) ); ?>
	</h1>
	<hr class="wp-header-end">
	<p class="description"><?php echo esc_html__( 'Pages grouped under this menu.', 'lean-admin' ); ?></p>
	<?php $render_nodes( (array) ( $group['children'] ?? [] ), 1 ); ?>
</div>
