<?php

declare(strict_types=1);

namespace LeanAdmin\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PluginConstants {

	public const OPTION_METAMENU      = 'lean_admin_metamenu';
	public const OPTION_ADMIN_TWEAKS  = 'lean_admin_admin_tweaks';
	public const ADMIN_TWEAKS_GROUP   = 'lean_admin_admin_tweaks_group';
	public const ADMIN_TWEAKS_SECTION = 'lean_admin_admin_tweaks_main';
}
