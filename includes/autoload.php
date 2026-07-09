<?php

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'LEAN_ADMIN_PATH' ) ) {
	define( 'LEAN_ADMIN_PATH', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'LEAN_ADMIN_VERSION' ) ) {
	define( 'LEAN_ADMIN_VERSION', '1.0.0' );
}

if ( ! defined( 'LEAN_ADMIN_URL' ) && function_exists( 'plugin_dir_url' ) ) {
	define( 'LEAN_ADMIN_URL', plugin_dir_url( LEAN_ADMIN_PATH . 'lean-admin.php' ) );
}

spl_autoload_register(
    static function ( string $class ) {
		$prefix = 'LeanAdmin\\';
		if ( strncmp( $class, $prefix, strlen( $prefix ) ) !== 0 ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$file     = LEAN_ADMIN_PATH . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
);
