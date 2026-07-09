<?php

/**
 * MetaMenu editor screen.
 *
 * Two columns: a palette of available top-level admin-menu entries (left) and
 * the grouping tree (right). Hydrated by metamenu-editor.js from the localized
 * `leanAdminMetaMenuEditor` data ({ items, tree, ajax }). The editor is a
 * JS-driven config surface; the *rendered sidebar* is what degrades gracefully
 * (see the hub pages), so a <noscript> notice is sufficient here.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( esc_html__( 'You do not have permission to access this page.', 'lean-admin' ), 403 );
}
?>
<div class="wrap la-mm-editor">
	<h1 class="wp-heading-inline"><?php echo esc_html__( 'Admin Menu Groups', 'lean-admin' ); ?></h1>
	<p class="description">
		<?php echo esc_html__( 'Drag menu entries into groups to build nested flyout menus. Reorder freely across any depth.', 'lean-admin' ); ?>
	</p>

	<noscript>
		<div class="notice notice-warning"><p>
			<?php echo esc_html__( 'The menu editor requires JavaScript. Your grouped menus still work without it.', 'lean-admin' ); ?>
		</p></div>
	</noscript>

	<div class="la-mm-editor-cols">
		<div class="la-mm-col la-mm-col-palette">
			<h2><?php echo esc_html__( 'Available entries', 'lean-admin' ); ?></h2>
			<p class="description"><?php echo esc_html__( 'Entries not placed in a group stay where WordPress puts them.', 'lean-admin' ); ?></p>
			<ul id="la-mm-palette" class="la-mm-sortable la-mm-palette"></ul>
		</div>

		<div class="la-mm-col la-mm-col-tree">
			<div class="la-mm-tree-toolbar">
				<h2><?php echo esc_html__( 'Your menu', 'lean-admin' ); ?></h2>
				<button type="button" class="button" id="la-mm-add-group">
					<?php echo esc_html__( 'Add group', 'lean-admin' ); ?>
				</button>
			</div>
			<ul id="la-mm-tree" class="la-mm-sortable la-mm-tree"></ul>
			<p class="la-mm-empty" id="la-mm-empty" hidden>
				<?php echo esc_html__( 'Drag entries here, or click “Add group” to start.', 'lean-admin' ); ?>
			</p>
		</div>
	</div>

	<p class="la-mm-actions">
		<button type="button" class="button button-primary" id="la-mm-save">
			<?php echo esc_html__( 'Save menu', 'lean-admin' ); ?>
		</button>
		<span class="spinner" id="la-mm-spinner"></span>
		<span class="la-mm-status" id="la-mm-status" role="status" aria-live="polite"></span>
	</p>
</div>
