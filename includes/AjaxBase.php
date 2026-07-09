<?php

declare(strict_types=1);

namespace LeanAdmin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

abstract class AjaxBase {

    protected string $module_name  = '';
    protected string $nonce_action = '';

	/** @var array<string, string> AJAX action suffix => handler method */
    protected array $ajax_actions = [];

	abstract protected function initAjaxActions(): void;

    public function __construct( string $module_name ) {
        $this->module_name  = $module_name;
        $this->nonce_action = $module_name . '_ajax';
        $this->initAjaxActions();
        $this->registerAjaxHandlers();
    }

    public function createModuleNonce(): string {
        return wp_create_nonce( $this->nonce_action );
    }

    public function getJsConfig(): array {
        return [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => $this->createModuleNonce(),
            'module'   => $this->module_name,
            'actions'  => array_keys( $this->ajax_actions ),
        ];
    }

    protected function registerAjaxHandlers(): void {
        foreach ( $this->ajax_actions as $action => $method ) {
            if ( ! method_exists( $this, $method ) ) {
                continue;
            }
            $full_action = $this->module_name . '_' . $action;
            add_action( 'wp_ajax_' . $full_action, [ $this, $method ] );
        }
    }

    protected function verify_ajax_security( ?string $action = null, string $capability = 'manage_options' ): bool {
        return $this->verify_security( $action, $capability );
    }

    protected function verify_security( ?string $action = null, string $capability = 'manage_options' ): bool {
        if ( ! is_user_logged_in() ) {
            return false;
        }
        $nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, $action ?? $this->nonce_action ) ) {
            return false;
        }
        return empty( $capability ) || current_user_can( $capability );
    }

    protected function ajaxSuccess( $data = null, string $message = '' ): never {
        $response = [ 'success' => true ];
        if ( ! empty( $message ) ) {
            $response['message'] = sanitize_text_field( $message );
        }
        if ( null !== $data ) {
            $response['data'] = $data;
        }
        wp_send_json( $response );
    }

    protected function ajaxError( string $message, $data = null, int $code = 400, ?string $error_code = null ): never {
        $response = [
			'success' => false,
			'message' => sanitize_text_field( $message ),
		];
        if ( null !== $data ) {
            $response['data'] = $data;
        }
        // Machine-readable error slug for the client to branch on.
        if ( null !== $error_code ) {
            $response['code'] = $error_code;
        }
        wp_send_json_error( $response, $code );
    }

    protected function ajax_security_error(): never {
        $this->ajaxError(
            __( 'Security check failed. Please refresh the page and try again.', 'lean-admin' ),
            null,
            403,
        );
    }
}
