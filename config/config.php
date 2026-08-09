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
        'name'            => Env::get('APP_NAME', 'Asset Register'),
        'env'             => Env::get('APP_ENV', 'production'),
        'debug'           => Env::bool('APP_DEBUG', false),
        'url'             => rtrim((string) Env::get('APP_URL', ''), '/'),
        'timezone'        => Env::get('APP_TIMEZONE', 'Europe/London'),
        'currency'        => Env::get('APP_CURRENCY', 'GBP'),
        'currency_symbol' => Env::get('APP_CURRENCY_SYMBOL', '£'),
        'root'            => $root,
    ],

    'database' => [
        'host'     => Env::get('DB_HOST', '127.0.0.1'),
        'port'     => (int) Env::get('DB_PORT', 3306),
        'database' => Env::get('DB_DATABASE', 'asset_register'),
        'username' => Env::get('DB_USERNAME', ''),
        'password' => Env::get('DB_PASSWORD', ''),
        'charset'  => Env::get('DB_CHARSET', 'utf8mb4'),
    ],

    'session' => [
        'name'      => Env::get('SESSION_NAME', 'asset_register_session'),
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
