<?php

declare(strict_types=1);

namespace LeanAdmin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PluginPaths {

	public static function path(): string {
		if ( defined( 'LEAN_ADMIN_PATH' ) ) {
			return (string) constant( 'LEAN_ADMIN_PATH' );
		}

		return dirname( __DIR__ ) . '/';
	}

	public static function url(): string {
		if ( defined( 'LEAN_ADMIN_URL' ) ) {
			return (string) constant( 'LEAN_ADMIN_URL' );
		}

		if ( function_exists( 'plugin_dir_url' ) ) {
			return plugin_dir_url( self::path() . 'lean-admin.php' );
		}

		return '';
	}

	public static function version(): string {
		if ( defined( 'LEAN_ADMIN_VERSION' ) ) {
			return (string) constant( 'LEAN_ADMIN_VERSION' );
		}

		return '1.1.0';
	}
}
