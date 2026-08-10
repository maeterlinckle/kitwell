<?php

declare(strict_types=1);

/*
 * Application bootstrap: autoloading, environment, configuration, error
 * handling, session and security headers. Included by the web front controller
 * and by the CLI scripts in bin/.
 */

use App\Core\Config;
use App\Core\Env;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;

define('APP_ROOT', dirname(__DIR__));
define('APP_START', microtime(true));

// --- Autoloading ----------------------------------------------------------
// Composer is the supported path. The application has exactly one runtime
// package — PHPMailer, which sends the outbound email added in stage 12 — and
// everything else still runs from a plain upload without `composer install`.
// Without vendor/ the app works normally and mail reports itself as
// unconfigured (App\Mail\Mailer::problems() says to run composer install),
// rather than failing somewhere confusing.
if (is_file(APP_ROOT . '/vendor/autoload.php')) {
    require APP_ROOT . '/vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class): void {
        if (!str_starts_with($class, 'App\\')) {
            return;
        }

        $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, 4));
        $file     = APP_ROOT . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . $relative . '.php';

        if (is_file($file)) {
            require $file;
        }
    });

    require APP_ROOT . '/src/helpers.php';
}

// --- Environment and configuration ---------------------------------------
Env::load(APP_ROOT . '/.env');
Config::load(require APP_ROOT . '/config/config.php');

date_default_timezone_set((string) Config::get('app.timezone', 'UTC'));
mb_internal_encoding('UTF-8');

// --- Error handling -------------------------------------------------------
$debug = (bool) Config::get('app.debug', false);

error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');

$logDir = (string) Config::get('storage.logs');
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
ini_set('error_log', $logDir . DIRECTORY_SEPARATOR . 'app.log');

set_exception_handler(static function (Throwable $e) use ($debug): void {
    error_log('[' . date('Y-m-d H:i:s') . '] Uncaught ' . get_class($e) . ': ' . $e->getMessage()
        . ' in ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL . $e->getTraceAsString());

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }

    if (!headers_sent()) {
        http_response_code(500);
    }

    if ($debug) {
        echo '<pre style="padding:1rem;font:14px/1.5 monospace;white-space:pre-wrap">'
            . htmlspecialchars(get_class($e) . ': ' . $e->getMessage() . "\n\n"
                . $e->getFile() . ':' . $e->getLine() . "\n\n" . $e->getTraceAsString(), ENT_QUOTES)
            . '</pre>';

        return;
    }

    View::renderError(500, 'Something went wrong', 'The problem has been logged. Please try again, and tell an administrator if it keeps happening.');
});

set_error_handler(static function (int $severity, string $message, string $file, int $line) use ($debug): bool {
    if ((error_reporting() & $severity) === 0) {
        return false; // suppressed with @
    }

    // Notices, warnings and deprecations are logged rather than thrown in
    // production: a cosmetic notice should never take a page down in a workshop.
    $soft = E_WARNING | E_NOTICE | E_DEPRECATED | E_USER_WARNING | E_USER_NOTICE | E_USER_DEPRECATED;

    if (!$debug && ($severity & $soft) !== 0) {
        error_log(sprintf('[%s] PHP %d: %s in %s:%d', date('Y-m-d H:i:s'), $severity, $message, $file, $line));

        return true;
    }

    throw new ErrorException($message, 0, $severity, $file, $line);
});

// --- Web-only concerns ----------------------------------------------------
if (PHP_SAPI !== 'cli') {
    // Enforce HTTPS when the deployment says it is available.
    if ((bool) Config::get('security.force_https', true) && !Request::isSecure()) {
        header('Location: https://' . Request::host() . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
        exit;
    }

    Response::securityHeaders();
    Session::start();

    View::share('appName', Config::get('app.name', 'Asset Register'));
}
