<?php

declare(strict_types=1);

/**
 * Minimal PHPUnit bootstrap for lean-admin pure-logic tests.
 *
 * The plugin has no full WordPress test scaffold; these tests cover
 * framework-free logic (tree normalization, menu-array transforms). We define
 * just enough WordPress shims for the classes under test to load and run.
 */

define('ABSPATH', __DIR__ . '/');
define('LEAN_ADMIN_PATH', dirname(__DIR__) . '/');
define('LEAN_ADMIN_URL', 'http://example.test/wp-content/plugins/lean-admin/');
define('LEAN_ADMIN_VERSION', 'test');
define('HOUR_IN_SECONDS', 3600);

/** In-memory option store so get_option/update_option round-trip in tests. */
$GLOBALS['__lean_admin_test_options'] = [];
$GLOBALS['__lean_admin_test_taxonomies'] = [];

if (! function_exists('get_option')) {
    function get_option(string $name, $default = false)
    {
        return $GLOBALS['__lean_admin_test_options'][$name] ?? $default;
    }
}

if (! function_exists('update_option')) {
    function update_option(string $name, $value): bool
    {
        $GLOBALS['__lean_admin_test_options'][$name] = $value;

        return true;
    }
}

if (! function_exists('delete_option')) {
    function delete_option(string $name): bool
    {
        unset($GLOBALS['__lean_admin_test_options'][$name]);

        return true;
    }
}

if (! function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $str): string
    {
        $str = wp_strip_all_tags($str);
        $str = preg_replace('/[\r\n\t ]+/', ' ', $str) ?? $str;

        return trim($str);
    }
}

if (! function_exists('sanitize_key')) {
    function sanitize_key(string $key): string
    {
        return preg_replace('/[^a-z0-9_-]/', '', strtolower($key)) ?? '';
    }
}

if (! function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags(string $str, bool $remove_breaks = false): string
    {
        $str = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $str) ?? $str;
        $str = strip_tags($str);

        return trim($str);
    }
}

if (! function_exists('wp_unslash')) {
    function wp_unslash($value)
    {
        return is_string($value) ? stripslashes($value) : $value;
    }
}

if (! function_exists('wp_generate_uuid4')) {
    function wp_generate_uuid4(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff)
        );
    }
}

if (! function_exists('menu_page_url')) {
    function menu_page_url(string $slug, bool $display = true): string
    {
        $url = 'http://example.test/wp-admin/admin.php?page=' . $slug;

        return $url;
    }
}

if (! function_exists('admin_url')) {
    function admin_url(string $path = '', string $scheme = 'admin'): string
    {
        return 'http://example.test/wp-admin/' . ltrim($path, '/');
    }
}

if (! function_exists('get_site_url')) {
    function get_site_url(): string
    {
        return 'http://example.test';
    }
}

/**
 * wp_send_json* die in WordPress; in tests they throw a catchable exception
 * carrying the payload + HTTP status so handler branches can be asserted.
 */
class LeanAdmin_Test_JsonResponse extends \RuntimeException
{
    public array $payload;
    public int $status;

    public function __construct(array $payload, int $status = 200)
    {
        $this->payload = $payload;
        $this->status = $status;
        parent::__construct('json-response');
    }
}

if (! function_exists('wp_send_json')) {
    function wp_send_json($response, int $status = 0): never
    {
        throw new LeanAdmin_Test_JsonResponse((array) $response, $status ?: 200);
    }
}

if (! function_exists('wp_send_json_error')) {
    function wp_send_json_error($data = null, int $status = 0): never
    {
        $response = ['success' => false];
        if (null !== $data) {
            $response['data'] = $data;
        }
        wp_send_json($response, $status ?: 200);
    }
}

if (! function_exists('remove_submenu_page')) {
    function remove_submenu_page(string $menu_slug, string $submenu_slug)
    {
        global $submenu;
        if (! isset($submenu[$menu_slug]) || ! is_array($submenu[$menu_slug])) {
            return false;
        }
        foreach ($submenu[$menu_slug] as $i => $item) {
            if (($item[2] ?? null) === $submenu_slug) {
                $removed = $submenu[$menu_slug][$i];
                unset($submenu[$menu_slug][$i]);
                return $removed;
            }
        }
        return false;
    }
}

if (! function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

if (! function_exists('add_action')) {
    function add_action(...$args): bool
    {
        return true;
    }
}

if (! function_exists('wp_create_nonce')) {
    function wp_create_nonce($action = -1): string
    {
        return 'test-nonce';
    }
}

if (! function_exists('wp_verify_nonce')) {
    function wp_verify_nonce($nonce, $action = -1)
    {
        return ($GLOBALS['__lean_admin_test_nonce_valid'] ?? true) ? 1 : false;
    }
}

if (! function_exists('is_user_logged_in')) {
    function is_user_logged_in(): bool
    {
        return $GLOBALS['__lean_admin_test_logged_in'] ?? true;
    }
}

if (! function_exists('current_user_can')) {
    function current_user_can(...$args): bool
    {
        return $GLOBALS['__lean_admin_test_can'] ?? true;
    }
}

if (! function_exists('register_taxonomy')) {
    function register_taxonomy(string $taxonomy, $object_type, array $args = [])
    {
        $labels = (object) ($args['labels'] ?? []);
        $caps = (object) array_merge(
            ['manage_terms' => 'manage_categories'],
            $args['capabilities'] ?? []
        );
        $object = (object) [
            'name' => $taxonomy,
            'object_type' => (array) $object_type,
            'labels' => $labels,
            'cap' => $caps,
        ];
        $GLOBALS['__lean_admin_test_taxonomies'][$taxonomy] = $object;

        return $object;
    }
}

if (! function_exists('get_taxonomy')) {
    function get_taxonomy(string $taxonomy)
    {
        return $GLOBALS['__lean_admin_test_taxonomies'][$taxonomy] ?? false;
    }
}

if (! function_exists('unregister_taxonomy')) {
    function unregister_taxonomy(string $taxonomy): bool
    {
        unset($GLOBALS['__lean_admin_test_taxonomies'][$taxonomy]);

        return true;
    }
}

// Load the plugin's own PSR-style autoloader.
require_once LEAN_ADMIN_PATH . 'includes/autoload.php';
