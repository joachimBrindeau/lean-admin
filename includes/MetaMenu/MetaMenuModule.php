<?php

declare(strict_types=1);

/**
 * MetaMenu module wiring.
 *
 * Single activation entry point: registers the reorganizer (which owns the
 * native sidebar reshape), the editor submenu page, the AJAX save handler, and
 * the asset enqueues. Held by Plugin so the module lives with the plugin.
 */

namespace LeanAdmin\MetaMenu;

use LeanAdmin\PluginPaths;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MetaMenuModule {

    private MetaMenuReorganizer $reorganizer;
    private MetaMenuAjaxHandler $ajax;
    private string $editorHookSuffix = '';

    public function __construct() {
        $this->reorganizer = new MetaMenuReorganizer();
        $this->ajax        = new MetaMenuAjaxHandler();
    }

    public function register(): void {
        $this->reorganizer->register();
        add_action( 'admin_menu', [ $this, 'registerEditorPage' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueueAssets' ] );
    }

    /**
     * Editor page sits beside the existing Lean Admin settings (both under
     * Tools) so all Lean Admin configuration is discoverable together.
     */
    public function registerEditorPage(): void {
        $this->editorHookSuffix = (string) add_submenu_page(
            'tools.php',
            __( 'Admin Menu Groups', 'lean-admin' ),
            __( 'Admin Menus', 'lean-admin' ),
            'manage_options',
            'lean-admin-metamenu',
            [ $this, 'renderEditor' ]
        );
    }

    public function renderEditor(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'lean-admin' ), 403 );
        }

        $template = PluginPaths::path() . 'templates/metamenu-editor.php';
        if ( file_exists( $template ) ) {
            include $template;
        }
    }

    public function enqueueAssets( string $hookSuffix ): void {
        $isEditor  = $this->editorHookSuffix !== '' && $hookSuffix === $this->editorHookSuffix;
        $groups    = $this->reorganizer->getLocalizedTree();
        $hideHrefs = $this->reorganizer->getHideHrefs();

        // Runtime flyout assets only where they do something — when groups or
        // hidden originals are configured.
        if ( $groups !== [] || $hideHrefs !== [] ) {
            wp_enqueue_style(
                'lean-admin-metamenu',
                PluginPaths::url() . 'assets/css/metamenu.css',
                [],
                PluginPaths::version()
            );
            wp_enqueue_script(
                'lean-admin-metamenu',
                PluginPaths::url() . 'assets/js/metamenu.js',
                [],
                PluginPaths::version(),
                true
            );
            wp_localize_script(
                'lean-admin-metamenu',
                'leanAdminMetaMenu',
                [
                    'groups' => $groups,
                    'hide'   => $hideHrefs,
                ]
            );
        }

        // Editor-only assets: the full palette is localized here, not on every
        // admin page.
        if ( ! $isEditor ) {
            return;
        }

        wp_enqueue_style(
            'lean-admin-metamenu-editor',
            PluginPaths::url() . 'assets/css/metamenu-editor.css',
            [],
            PluginPaths::version()
        );
        wp_enqueue_script(
            'lean-admin-sortable',
            PluginPaths::url() . 'assets/vendor/sortable.min.js',
            [],
            '1.15.6',
            true
        );
        wp_enqueue_script(
            'lean-admin-metamenu-editor',
            PluginPaths::url() . 'assets/js/metamenu-editor.js',
            [ 'lean-admin-sortable' ],
            PluginPaths::version(),
            true
        );
        wp_localize_script(
            'lean-admin-metamenu-editor',
            'leanAdminMetaMenuEditor',
            [
				'items' => MenuRegistry::getAvailableItems(),
				'tree'  => MetaMenuConfig::load(),
				'ajax'  => $this->ajax->getJsConfig(),
			]
        );
    }
}
