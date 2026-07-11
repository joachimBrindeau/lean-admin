<?php

/**
 * Plugin Name: Lean Admin
 * Description: Admin menu grouping and lightweight admin chrome tweaks.
 * Version: 1.1.0
 * Author: Joachim Brindeau
 * License: GPL-2.0-or-later
 * Requires PHP: 8.1
 * Requires at least: 6.0
 * Text Domain: lean-admin
 * Domain Path: /languages
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'LEAN_ADMIN_PLUGIN_FILE' ) ) {
	define( 'LEAN_ADMIN_PLUGIN_FILE', __FILE__ );
}

add_action(
	'init',
	static function (): void {
		$locale = apply_filters( 'plugin_locale', determine_locale(), 'lean-admin' );
		load_textdomain( 'lean-admin', __DIR__ . '/languages/lean-admin-' . $locale . '.mo' );
	}
);

require_once __DIR__ . '/includes/bootstrap.php';

register_uninstall_hook( __FILE__, [ 'LeanAdmin\Plugin', 'uninstall' ] );
