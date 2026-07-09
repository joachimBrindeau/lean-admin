<?php

declare(strict_types=1);

/**
 * MetaMenu AJAX handler.
 *
 * One action — `save_config` — persists the grouping tree. The registered hook
 * is `wp_ajax_lean_admin_metamenu_save_config` and the nonce action is
 * `lean_admin_metamenu_ajax`.
 */

namespace LeanAdmin\MetaMenu;

use LeanAdmin\AjaxBase;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MetaMenuAjaxHandler extends AjaxBase {

    public function __construct() {
        parent::__construct( 'lean_admin_metamenu' );
    }

    public function handle_save_config(): void {
        // Pass null so the base verifies against the module nonce that
        // getJsConfig() actually mints — a literal action string would never
        // match and would 403 every save.
        if ( ! $this->verify_ajax_security( null, 'manage_options' ) ) {
            $this->ajax_security_error();
        }

        // Read the tree as RAW JSON. Text-field sanitization would corrupt the
        // JSON string; validation/sanitization happens in MetaMenuConfig::save().
        // Nonce + capability verified above via verify_ajax_security(); the raw
        // JSON is intentionally unsanitized here and validated in normalize().
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $raw = isset( $_POST['tree'] ) ? wp_unslash( $_POST['tree'] ) : '';
        if ( ! is_string( $raw ) || $raw === '' ) {
            $this->ajaxError( 'No menu data received.', null, 400, 'metamenu_empty' );
        }

        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) {
            $this->ajaxError( 'Invalid menu data.', null, 400, 'metamenu_invalid_json' );
        }

        if ( ! MetaMenuConfig::save( $decoded ) ) {
            $this->ajaxError( 'Could not save the menu.', null, 500, 'metamenu_save_failed' );
        }

        $this->ajaxSuccess(
            [ 'tree' => MetaMenuConfig::load() ],
            'Menu saved.'
        );
    }

    protected function initAjaxActions(): void {
        $this->ajax_actions = [
            'save_config' => 'handle_save_config',
        ];
    }
}
