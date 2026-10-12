<?php
// Bouchons minimaux des fonctions WordPress utilisées par wawp-updater, pour les tests unitaires.

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
define('WP_PLUGIN_DIR', '/var/www/wp-content/plugins');

final class WawpTestState
{
    /** @var array<string, list<callable>> */
    public static array $hooks = [];
    /** @var array<string, array{value: mixed, ttl: int}> */
    public static array $transients = [];
    /** @var array<string, mixed> */
    public static array $options = [];
    /** @var callable|null */
    public static $http = null;
    /** @var callable|null */
    public static $download = null;
    /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> */
    public static array $requests = [];
    public static bool $can = true;
    public static ?object $screen = null;
    /** @var array<string, string> */
    public static array $versions = [];
    public static bool $multisite = false;

    public static function reset(): void
    {
        self::$hooks = [];
        self::$transients = [];
        self::$options = [];
        self::$http = null;
        self::$download = null;
        self::$requests = [];
        self::$can = true;
        self::$screen = null;
        self::$versions = [];
        self::$multisite = false;
    }
}

class WP_Error
{
    public string $code;
    public string $message;

    public function __construct(string $code = '', string $message = '')
    {
        $this->code = $code;
        $this->message = $message;
    }

    public function get_error_code(): string
    {
        return $this->code;
    }

    public function get_error_message(): string
    {
        return $this->message;
    }
}

function add_filter(string $hook, callable $callback, int $priority = 10, int $args = 1): bool
{
    WawpTestState::$hooks[$hook][] = $callback;

    return true;
}

function add_action(string $hook, callable $callback, int $priority = 10, int $args = 1): bool
{
    return add_filter($hook, $callback, $priority, $args);
}

function plugin_basename(string $file): string
{
    return ltrim(str_replace(WP_PLUGIN_DIR, '', str_replace('\\', '/', $file)), '/');
}

/** @param array<string, string> $headers @return array<string, string> */
function get_file_data(string $file, array $headers): array
{
    return ['Version' => WawpTestState::$versions[$file] ?? ''];
}

function get_site_transient(string $key): mixed
{
    return isset(WawpTestState::$transients[$key]) ? WawpTestState::$transients[$key]['value'] : false;
}

function set_site_transient(string $key, mixed $value, int $ttl = 0): bool
{
    WawpTestState::$transients[$key] = ['value' => $value, 'ttl' => $ttl];

    return true;
}

function delete_site_transient(string $key): bool
{
    unset(WawpTestState::$transients[$key]);

    return true;
}

function get_site_option(string $key, mixed $default = false): mixed
{
    return WawpTestState::$options[$key] ?? $default;
}

function update_site_option(string $key, mixed $value): bool
{
    WawpTestState::$options[$key] = $value;

    return true;
}

function delete_site_option(string $key): bool
{
    unset(WawpTestState::$options['site:' . $key], WawpTestState::$options[$key]);

    return true;
}

function delete_option(string $key): bool
{
    unset(WawpTestState::$options[$key]);

    return true;
}

function is_multisite(): bool
{
    return WawpTestState::$multisite;
}

function home_url(): string
{
    return 'https://client.fr';
}

function network_home_url(): string
{
    return 'https://reseau-client.fr';
}

function get_bloginfo(string $show): string
{
    return '6.8.1';
}

function wp_json_encode(mixed $data): string|false
{
    return json_encode($data);
}

/** @param array<string, mixed> $args @return array<string, mixed>|WP_Error */
function wp_safe_remote_post(string $url, array $args): array|WP_Error
{
    WawpTestState::$requests[] = ['POST', $url, $args];

    return (WawpTestState::$http)('POST', $url, $args);
}

/** @param array<string, mixed> $args @return array<string, mixed>|WP_Error */
function wp_safe_remote_get(string $url, array $args): array|WP_Error
{
    WawpTestState::$requests[] = ['GET', $url, $args];

    return (WawpTestState::$http)('GET', $url, $args);
}

function is_wp_error(mixed $thing): bool
{
    return $thing instanceof WP_Error;
}

function wp_remote_retrieve_response_code(mixed $response): int|string
{
    return is_array($response) ? ($response['response']['code'] ?? '') : '';
}

function wp_remote_retrieve_body(mixed $response): string
{
    return is_array($response) ? (string) ($response['body'] ?? '') : '';
}

function download_url(string $url, int $timeout = 300): string|WP_Error
{
    return (WawpTestState::$download)($url);
}

function wp_delete_file(string $file): void
{
    if (is_file($file)) {
        unlink($file);
    }
}

function wp_kses_post(string $html): string
{
    return strip_tags($html, '<p><h4><ul><li>');
}

function wp_strip_all_tags(string $text): string
{
    return strip_tags($text);
}

function __(string $text, string $domain = 'default'): string
{
    return $text;
}

function esc_html__(string $text, string $domain = 'default'): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function current_user_can(string $capability): bool
{
    return WawpTestState::$can;
}

function get_current_screen(): ?object
{
    return WawpTestState::$screen;
}
