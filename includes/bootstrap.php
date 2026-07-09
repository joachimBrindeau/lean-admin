<?php

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'LEAN_ADMIN_BOOTSTRAPPED' ) ) {
	return;
}

define( 'LEAN_ADMIN_BOOTSTRAPPED', true );

if ( ! defined( 'LEAN_ADMIN_PLUGIN_FILE' ) ) {
	define( 'LEAN_ADMIN_PLUGIN_FILE', dirname( __DIR__ ) . '/lean-admin.php' );
}

define( 'LEAN_ADMIN_VERSION', '1.1.0' );
define( 'LEAN_ADMIN_PATH', plugin_dir_path( LEAN_ADMIN_PLUGIN_FILE ) );
define( 'LEAN_ADMIN_URL', plugin_dir_url( LEAN_ADMIN_PLUGIN_FILE ) );

require_once LEAN_ADMIN_PATH . 'includes/autoload.php';

add_action(
	'plugins_loaded',
	static function (): void {
		LeanAdmin\Plugin::instance();
	}
);
