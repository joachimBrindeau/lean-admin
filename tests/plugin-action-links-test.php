<?php

declare(strict_types=1);

function add_action(string $hook, callable $callback): bool
{
    return true;
}

function add_filter(string $hook, callable $callback): bool
{
    $GLOBALS['plugin_action_links_callback'] = $callback;

    return true;
}

function plugin_basename(string $file): string
{
    return basename($file);
}

function plugin_dir_path(string $file): string
{
    return dirname($file) . '/';
}

function plugin_dir_url(string $file): string
{
    return 'https://example.test/wp-content/plugins/lean-admin/';
}

function current_user_can(string $capability): bool
{
    return $GLOBALS['can_manage_options'];
}

function admin_url(string $path): string
{
    return 'https://example.test/wp-admin/' . $path;
}

function esc_url(string $url): string
{
    return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
}

function esc_html__(string $text, string $domain): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function register_uninstall_hook(string $file, callable|array $callback): void
{
}

define('ABSPATH', __DIR__ . '/');
require dirname(__DIR__) . '/lean-admin.php';

$callback = $GLOBALS['plugin_action_links_callback'];
$links = ['<a href="https://example.test/deactivate">Deactivate</a>'];

$GLOBALS['can_manage_options'] = false;
assert($callback($links) === $links);

$GLOBALS['can_manage_options'] = true;
assert($callback($links) === [
    '<a href="https://example.test/wp-admin/tools.php?page=lean-admin-tweaks">Settings</a>',
    $links[0],
]);
