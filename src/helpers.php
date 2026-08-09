<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Request;

if (!function_exists('e')) {
    /** Escape for HTML output. Use on every dynamic value in a template. */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('url')) {
    /** Build an application URL that respects a subdirectory install. */
    function url(string $path = '/'): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return Request::basePath() . '/' . ltrim($path, '/');
    }
}

if (!function_exists('asset_url')) {
    /** Static asset URL with a cache-busting stamp based on file mtime. */
    function asset_url(string $path): string
    {
        $relative = ltrim($path, '/');
        $file     = Config::get('app.root') . '/public/' . $relative;
        $version  = is_file($file) ? (string) filemtime($file) : '1';

        return url($relative) . '?v=' . $version;
    }
}

if (!function_exists('partial')) {
    /**
     * Render a template fragment from inside another template.
     *
     * @param array<string,mixed> $data
     */
    function partial(string $template, array $data = []): string
    {
        return \App\Core\View::partial($template, $data);
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return Csrf::field();
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return Csrf::token();
    }
}

if (!function_exists('method_field')) {
    function method_field(string $method): string
    {
        return '<input type="hidden" name="_method" value="' . e(strtoupper($method)) . '">';
    }
}

if (!function_exists('can')) {
    function can(string $permission): bool
    {
        return Auth::can($permission);
    }
}

if (!function_exists('can_any')) {
    function can_any(string ...$permissions): bool
    {
        return Auth::canAny(...$permissions);
    }
}

if (!function_exists('auth_user')) {
    /** @return array<string,mixed>|null */
    function auth_user(): ?array
    {
        return Auth::user();
    }
}

if (!function_exists('old')) {
    /**
     * Repopulate a form field after a validation failure.
     *
     * @param array<string,mixed> $old
     */
    function old(array $old, string $key, mixed $default = ''): string
    {
        return (string) ($old[$key] ?? $default ?? '');
    }
}

if (!function_exists('is_active_path')) {
    /** True when the current request path is (or is under) the given path. */
    function is_active_path(string $path): bool
    {
        $current = Request::path();
        $path    = '/' . trim($path, '/');

        if ($path === '/') {
            return $current === '/';
        }

        return $current === $path || str_starts_with($current, $path . '/');
    }
}

if (!function_exists('format_date')) {
    function format_date(?string $date, string $format = 'j M Y'): string
    {
        if ($date === null || $date === '' || str_starts_with($date, '0000')) {
            return '—';
        }

        $timestamp = strtotime($date);

        return $timestamp === false ? '—' : date($format, $timestamp);
    }
}

if (!function_exists('format_datetime')) {
    function format_datetime(?string $date): string
    {
        return format_date($date, 'j M Y, H:i');
    }
}

if (!function_exists('format_money')) {
    function format_money(mixed $amount): string
    {
        if ($amount === null || $amount === '') {
            return '—';
        }

        return (string) Config::get('app.currency_symbol', '£') . number_format((float) $amount, 2);
    }
}

if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        return Config::get($key, $default);
    }
}

if (!function_exists('str_limit')) {
    function str_limit(?string $value, int $length = 80): string
    {
        $value = (string) $value;

        return mb_strlen($value) <= $length ? $value : mb_substr($value, 0, $length - 1) . '…';
    }
}
