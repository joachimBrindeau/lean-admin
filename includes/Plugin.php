<?php

declare(strict_types=1);

namespace LeanAdmin;

use LeanAdmin\AdminTweaks\AdminTweaksModule;
use LeanAdmin\Config\PluginConstants;
use LeanAdmin\MetaMenu\MetaMenuModule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	private MetaMenuModule $meta_menu;
	private AdminTweaksModule $admin_tweaks;

	private static ?self $instance = null;

	private function __construct() {
		$this->meta_menu    = new MetaMenuModule();
		$this->admin_tweaks = new AdminTweaksModule();

		$this->meta_menu->register();
		$this->admin_tweaks->register();
	}

	public static function instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public static function uninstall(): void {
		delete_option( PluginConstants::OPTION_METAMENU );
		delete_option( PluginConstants::OPTION_ADMIN_TWEAKS );
	}
}
