<?php

declare(strict_types=1);

use App\Core\Env;

/*
 * Central configuration. Values come from the environment (.env), never from
 * hardcoded credentials in the repository.
 */

$root = dirname(__DIR__);

$storage = Env::get('STORAGE_PATH', 'storage');
if (!preg_match('#^([A-Za-z]:[\\\\/]|/)#', $storage)) {
    $storage = $root . DIRECTORY_SEPARATOR . $storage;
}

return [
    'app' => [
        'name'            => Env::get('APP_NAME', 'Kitwell'),

        // Product branding, as distinct from `name` (what this instance calls
        // itself) and the `organisation_name` setting (whose workshop it is).
        // `full_name` is the longer form used where a line has room for it:
        // footer branding, print headers and email templates. `mark` is the
        // two-letter fallback shown in the header when no logo has been
        // uploaded; it is a brand mark, not initials of `name`.
        'product'         => Env::get('APP_PRODUCT', 'Kitwell'),
        'full_name'       => Env::get('APP_FULL_NAME', 'Kitwell by Junction'),
        'product_tagline' => Env::get('APP_PRODUCT_TAGLINE', 'Asset Management'),
        'mark'            => Env::get('APP_MARK', 'KW'),
        'vendor'          => Env::get('APP_VENDOR', 'Junction Inc Ltd'),
        'vendor_url'      => Env::get('APP_VENDOR_URL', 'https://www.junctioninc.co.uk/'),

        'env'             => Env::get('APP_ENV', 'production'),
        'debug'           => Env::bool('APP_DEBUG', false),
        'url'             => rtrim((string) Env::get('APP_URL', ''), '/'),
        'timezone'        => Env::get('APP_TIMEZONE', 'Europe/London'),
        'currency'        => Env::get('APP_CURRENCY', 'GBP'),
        'currency_symbol' => Env::get('APP_CURRENCY_SYMBOL', '£'),
        'root'            => $root,

        // Encryption key for secrets that have to live in the database (today,
        // only the SMTP password). Generate one with `php bin/console.php
        // key:generate`. Without it the mail password simply cannot be saved
        // from the UI — App\Core\Crypto fails closed rather than storing it in
        // the clear.
        'key'             => Env::get('APP_KEY', ''),
    ],

    'database' => [
        'host'     => Env::get('DB_HOST', '127.0.0.1'),
        'port'     => (int) Env::get('DB_PORT', 3306),
        'database' => Env::get('DB_DATABASE', 'kitwell'),
        'username' => Env::get('DB_USERNAME', ''),
        'password' => Env::get('DB_PASSWORD', ''),
        'charset'  => Env::get('DB_CHARSET', 'utf8mb4'),
    ],

    'session' => [
        'name'      => Env::get('SESSION_NAME', 'kitwell_session'),
        'lifetime'  => (int) Env::get('SESSION_LIFETIME', 480), // minutes
        'samesite'  => Env::get('SESSION_SAMESITE', 'Lax'),
    ],

    'security' => [
        'force_https' => Env::bool('FORCE_HTTPS', true),
        'trust_proxy' => Env::bool('TRUST_PROXY', true),
        'login' => [
            'max_attempts'     => (int) Env::get('LOGIN_MAX_ATTEMPTS', 5),
            'decay_minutes'    => (int) Env::get('LOGIN_DECAY_MINUTES', 15),
            'lockout_minutes'  => (int) Env::get('LOGIN_LOCKOUT_MINUTES', 15),
        ],
    ],

    // Outbound mail. Everything else — host, port, encryption, from address —
    // is an application setting an administrator edits in Settings → Email.
    // Only the password can come from the environment, because that is the one
    // value a site may prefer to keep out of the database entirely; when it is
    // set here it wins, and the Settings page says so rather than pretending
    // the field it shows is the one in use.
    'mail' => [
        'password' => (string) Env::get('MAIL_PASSWORD', ''),
    ],

    'storage' => [
        'path'      => $storage,
        'uploads'   => $storage . DIRECTORY_SEPARATOR . 'uploads',
        'logs'      => $storage . DIRECTORY_SEPARATOR . 'logs',
    ],

    'uploads' => [
        'max_photo_bytes' => ((int) Env::get('UPLOAD_MAX_PHOTO_MB', 10)) * 1024 * 1024,
        'max_pdf_bytes'   => ((int) Env::get('UPLOAD_MAX_PDF_MB', 25)) * 1024 * 1024,
        'photo_mimes'     => ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'],
        'photo_extensions'=> ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif'],
        'pdf_mimes'       => ['application/pdf'],
        'pdf_extensions'  => ['pdf'],

        // CSV imports. Spreadsheet software and browsers disagree wildly about
        // what a .csv file's content type is, so the list is deliberately
        // broad — the parser is what actually decides whether it is usable.
        'max_csv_bytes'   => 8 * 1024 * 1024,
        'csv_mimes'       => [
            'text/csv', 'text/plain', 'application/csv', 'application/x-csv',
            'text/comma-separated-values', 'text/x-comma-separated-values',
            'application/vnd.ms-excel', 'application/octet-stream',
        ],
        'csv_extensions'  => ['csv', 'txt', 'tsv'],
    ],
];
