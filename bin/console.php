<?php

declare(strict_types=1);

/*
 * Administrative console — the tasks that need database access.
 *
 *   php bin/console.php                 list the commands
 *   php bin/console.php doctor          check the installation over
 *   php bin/console.php user:password --email=jo@example.com
 *
 * `manage.sh` wraps this with the tasks that need root instead (services,
 * file ownership, backups, web-server config). Everything here goes through
 * the application's own models and the Database wrapper, so the same rules,
 * prepared statements and audit logging apply as on the web.
 *
 * Passwords are never accepted as an argument: they are prompted for with the
 * terminal echo turned off, or piped in with --stdin-password, so they stay
 * out of shell history and out of the process list.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Core\Config;
use App\Core\Crypto;
use App\Core\Database;
use App\Mail\EmailLog;
use App\Mail\EmailReminder;
use App\Mail\Mailer;
use App\Mail\Reminders;
use App\Models\ActivityLog;
use App\Models\Hire;
use App\Models\MaintenanceSchedule;
use App\Models\PatRecord;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;

const MIN_PASSWORD_LENGTH = 12;

// ---------------------------------------------------------------------------
// Tiny CLI helpers
// ---------------------------------------------------------------------------

/** @param array<int,string> $argv */
function option(array $argv, string $name, ?string $default = null): ?string
{
    foreach ($argv as $arg) {
        if (str_starts_with($arg, "--{$name}=")) {
            return trim(substr($arg, strlen($name) + 3), " \"'");
        }
    }

    return $default;
}

/** @param array<int,string> $argv */
function flag(array $argv, string $name): bool
{
    return in_array('--' . $name, $argv, true);
}

function prompt(string $question, bool $hidden = false): string
{
    echo $question;

    if ($hidden && DIRECTORY_SEPARATOR !== '\\') {
        shell_exec('stty -echo 2>/dev/null');
        $value = trim((string) fgets(STDIN));
        shell_exec('stty echo 2>/dev/null');
        echo PHP_EOL;

        return $value;
    }

    return trim((string) fgets(STDIN));
}

function confirm(string $question): bool
{
    return strtolower(prompt($question . ' [y/N]: ')) === 'y';
}

/**
 * A password, from a pipe (--stdin-password) or from the terminal with echo off.
 *
 * @param array<int,string> $argv
 */
function readPassword(array $argv, string $label = 'Password'): string
{
    if (flag($argv, 'stdin-password')) {
        $password = rtrim((string) stream_get_contents(STDIN), "\r\n");
    } else {
        $password = prompt($label . ' (min ' . MIN_PASSWORD_LENGTH . " characters): ", true);
        $confirm  = prompt('Confirm password: ', true);

        if ($password !== $confirm) {
            fail('Passwords did not match.');
        }
    }

    if (strlen($password) < MIN_PASSWORD_LENGTH) {
        fail('Password must be at least ' . MIN_PASSWORD_LENGTH . ' characters.');
    }

    return $password;
}

function fail(string $message, int $code = 1): never
{
    fwrite(STDERR, "Error: {$message}\n");
    exit($code);
}

function line(string $text = ''): void
{
    echo $text . "\n";
}

function heading(string $text): void
{
    line();
    line($text);
    line(str_repeat('-', strlen($text)));
}

/**
 * Print rows as an aligned table.
 *
 * @param array<int,string>            $headers
 * @param array<int,array<int,string>> $rows
 */
function table(array $headers, array $rows, bool $withCount = true): void
{
    $widths = array_map('strlen', $headers);

    foreach ($rows as $row) {
        foreach (array_values($row) as $i => $cell) {
            $widths[$i] = max($widths[$i] ?? 0, strlen((string) $cell));
        }
    }

    $render = static function (array $cells) use ($widths): string {
        $out = [];
        foreach (array_values($cells) as $i => $cell) {
            $out[] = str_pad((string) $cell, $widths[$i]);
        }

        return '  ' . rtrim(implode('  ', $out));
    };

    line($render($headers));
    line('  ' . implode('  ', array_map(static fn (int $w): string => str_repeat('-', $w), $widths)));

    foreach ($rows as $row) {
        line($render($row));
    }

    if ($withCount) {
        line();
        line('  ' . count($rows) . ' row(s)');
    }
}

/** @return array<string,mixed> */
function userByEmail(string $email): array
{
    if ($email === '') {
        $email = prompt('Email address: ');
    }

    $user = User::findByEmail($email);
    if ($user === null) {
        fail("No user found with the email address '{$email}'.");
    }

    return $user;
}

/**
 * The application refuses to leave itself with no way in; the console honours
 * the same rule rather than quietly routing around it.
 */
function guardLastAdmin(array $user, string $what): void
{
    $isActiveAdmin = (int) $user['is_active'] === 1 && (int) ($user['role_is_superuser'] ?? 0) === 1;

    if ($isActiveAdmin && User::countActiveAdmins() <= 1) {
        fail("{$user['name']} is the only active administrator, so they cannot be {$what}. Create another administrator first.");
    }
}

// ---------------------------------------------------------------------------
// Commands
// ---------------------------------------------------------------------------

/** @param array<int,string> $argv */
function cmdDoctor(array $argv): int
{
    $problems = 0;
    $warnings = 0;

    $check = static function (string $label, string $state, string $detail = '') use (&$problems, &$warnings): void {
        if ($state === 'FAIL') {
            $problems++;
        }
        if ($state === 'WARN') {
            $warnings++;
        }

        printf("  [%-4s] %-34s %s\n", $state, $label, $detail);
    };

    heading('PHP');
    $check(
        'Version',
        version_compare(PHP_VERSION, '8.1.0', '>=') ? 'ok' : 'FAIL',
        PHP_VERSION . ' (8.1 or newer required)'
    );

    foreach (['pdo', 'pdo_mysql', 'mbstring', 'fileinfo', 'json'] as $ext) {
        $check("Extension {$ext}", extension_loaded($ext) ? 'ok' : 'FAIL', 'required');
    }

    foreach (['gd' => 'photo resizing and thumbnails', 'exif' => 'photo orientation and capture date'] as $ext => $why) {
        $check("Extension {$ext}", extension_loaded($ext) ? 'ok' : 'WARN', $why);
    }

    $uploadMax = ini_get('upload_max_filesize');
    $postMax   = ini_get('post_max_size');
    $wantBytes = max((int) Config::get('uploads.max_photo_bytes'), (int) Config::get('uploads.max_pdf_bytes'));
    $haveBytes = min(iniBytes((string) $uploadMax), iniBytes((string) $postMax));
    $check(
        'Upload limits',
        $haveBytes >= $wantBytes ? 'ok' : 'WARN',
        sprintf(
            'upload_max_filesize=%s post_max_size=%s; the app allows up to %s',
            $uploadMax,
            $postMax,
            round($wantBytes / 1048576) . 'M'
        )
    );

    heading('Configuration');
    $envFile = Config::get('app.root') . '/.env';
    $check('.env present', is_file($envFile) ? 'ok' : 'FAIL', $envFile);

    if (is_file($envFile) && DIRECTORY_SEPARATOR !== '\\') {
        $mode = fileperms($envFile) & 0777;
        $check(
            '.env not world-readable',
            ($mode & 0o004) === 0 ? 'ok' : 'FAIL',
            'mode ' . decoct($mode) . ' — it holds the database password'
        );
    }

    $url   = (string) Config::get('app.url');
    $env   = (string) Config::get('app.env');
    $debug = (bool) Config::get('app.debug');

    $check('APP_URL set', $url !== '' ? 'ok' : 'WARN', $url !== '' ? $url : 'empty — emails and printed links have no host');
    $check('APP_ENV', $env === 'production' ? 'ok' : 'WARN', $env);
    $check(
        'APP_DEBUG off in production',
        ($env !== 'production' || !$debug) ? 'ok' : 'FAIL',
        ($debug && $env === 'production')
            ? 'true — stack traces would be visible to visitors'
            : ($debug ? 'true, but APP_ENV is ' . $env : 'false')
    );

    $forceHttps = (bool) Config::get('security.force_https');
    $check(
        'HTTPS settings agree',
        (!$forceHttps || $url === '' || str_starts_with($url, 'https://')) ? 'ok' : 'WARN',
        'FORCE_HTTPS=' . ($forceHttps ? 'true' : 'false') . ', APP_URL=' . ($url ?: 'unset')
    );

    heading('Storage');
    $paths = [
        'storage'         => (string) Config::get('storage.path'),
        'storage/uploads' => (string) Config::get('storage.uploads'),
        'storage/logs'    => (string) Config::get('storage.logs'),
    ];

    foreach ($paths as $label => $path) {
        if (!is_dir($path)) {
            $check($label, 'FAIL', 'missing: ' . $path);
            continue;
        }

        $check($label, is_writable($path) ? 'ok' : 'FAIL', is_writable($path) ? $path : 'not writable by ' . currentUser() . ': ' . $path);
    }

    heading('Database');
    try {
        $pdo = Database::connection();
        $check('Connection', 'ok', sprintf(
            '%s on %s as %s',
            Config::get('database.database'),
            Config::get('database.host'),
            Config::get('database.username')
        ));
        $check('Server', 'ok', (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION));

        $migrator = new App\Core\Migrator();
        $pending  = $migrator->pending();
        $check(
            'Migrations',
            $pending === [] ? 'ok' : 'FAIL',
            $pending === [] ? count($migrator->applied()) . ' applied, none pending' : count($pending) . ' pending — run `php bin/migrate.php`'
        );

        // A grant that cannot run a migration is a problem you want to find on
        // a quiet afternoon, not halfway through an upgrade. Installs made
        // before 2026-08-11 withheld DROP, which RENAME TABLE requires.
        try {
            $grants  = Database::select('SHOW GRANTS FOR CURRENT_USER()');
            $all     = strtoupper(implode(' ', array_map(static fn (array $r): string => (string) reset($r), $grants)));
            $missing = [];

            foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP', 'ALTER', 'INDEX', 'REFERENCES'] as $privilege) {
                if (!str_contains($all, 'ALL PRIVILEGES') && !str_contains($all, $privilege)) {
                    $missing[] = $privilege;
                }
            }

            $check(
                'Database privileges',
                $missing === [] ? 'ok' : 'FAIL',
                $missing === []
                    ? 'enough to run migrations'
                    : 'missing ' . implode(', ', $missing) . ' — run `sudo ./manage.sh db-grant`'
            );
        } catch (Throwable $e) {
            $check('Database privileges', 'WARN', 'could not be read: ' . $e->getMessage());
        }

        $admins = User::countActiveAdmins();
        $check('Active administrators', $admins > 0 ? 'ok' : 'FAIL', (string) $admins);

        $sqlMode = (string) Database::scalar('SELECT @@sql_mode');
        $check(
            'STRICT_TRANS_TABLES',
            str_contains($sqlMode, 'STRICT_TRANS_TABLES') ? 'ok' : 'WARN',
            str_contains($sqlMode, 'STRICT_TRANS_TABLES') ? 'on' : 'off — over-long values would be truncated instead of rejected'
        );

        $collation = (string) Database::scalar(
            'SELECT DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
            [Config::get('database.database')]
        );
        $check('Collation', $collation === 'utf8mb4_unicode_ci' ? 'ok' : 'WARN', $collation);

        $activity = (int) Database::scalar('SELECT COUNT(*) FROM activity_log');
        $check(
            'Audit trail size',
            $activity < 500000 ? 'ok' : 'WARN',
            number_format($activity) . ' rows' . ($activity >= 500000 ? ' — consider `activity:prune`' : '')
        );

        heading('Email');

        $check('Extension openssl', Crypto::isAvailable() ? 'ok' : 'WARN', 'required to store the SMTP password securely');
        $check(
            'APP_KEY set',
            Crypto::hasKey() ? 'ok' : 'WARN',
            Crypto::hasKey() ? 'present in .env' : 'missing — run `key:generate`'
        );
        $check(
            'PHPMailer installed',
            Mailer::libraryInstalled() ? 'ok' : 'WARN',
            Mailer::libraryInstalled() ? 'vendor/ present' : 'run `sudo ./manage.sh composer-install`'
        );

        $mailEnabled  = Setting::bool('mail_enabled', false);
        $mailProblems = Mailer::problems();

        if (!$mailEnabled) {
            $check('Sending', 'WARN', 'switched off in Settings → Email');
        } else {
            $check(
                'Sending',
                $mailProblems === [] ? 'ok' : 'FAIL',
                $mailProblems === []
                    ? 'via ' . Setting::get('mail_host', '') . ':' . Setting::get('mail_port', '')
                    : implode(' ', $mailProblems)
            );
        }

        $failed7 = (int) Database::scalar(
            "SELECT COUNT(*) FROM email_log WHERE status = 'failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );
        $check(
            'Failed sends (7 days)',
            $failed7 === 0 ? 'ok' : 'WARN',
            $failed7 === 0 ? 'none' : $failed7 . ' — see Settings → Email → Log'
        );

        $remindersOn = [];
        foreach (array_keys(Reminders::TYPES) as $type) {
            if (Reminders::isEnabled($type)) {
                $remindersOn[] = $type;
            }
        }
        $check(
            'Reminders',
            'ok',
            $remindersOn === [] ? 'none switched on' : implode(', ', $remindersOn) . ' — needs `bin/send-reminders.php` on cron'
        );
    } catch (Throwable $e) {
        $check('Connection', 'FAIL', $e->getMessage());
    }

    line();

    if ($problems > 0) {
        line("{$problems} problem(s) and {$warnings} warning(s) found.");

        return 1;
    }

    line($warnings > 0 ? "No problems. {$warnings} warning(s) worth a look." : 'All checks passed.');

    return 0;
}

function iniBytes(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }

    $unit   = strtolower($value[strlen($value) - 1]);
    $number = (int) $value;

    return match ($unit) {
        'g' => $number * 1024 * 1024 * 1024,
        'm' => $number * 1024 * 1024,
        'k' => $number * 1024,
        default => $number,
    };
}

function currentUser(): string
{
    if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
        $info = posix_getpwuid(posix_geteuid());

        return is_array($info) ? (string) $info['name'] : 'this user';
    }

    return (string) (getenv('USER') ?: getenv('USERNAME') ?: 'this user');
}

/** @param array<int,string> $argv */
function cmdUserList(array $argv): int
{
    $rows = [];

    foreach (User::all() as $user) {
        if (flag($argv, 'active-only') && (int) $user['is_active'] !== 1) {
            continue;
        }

        $rows[] = [
            (string) $user['id'],
            (string) $user['name'],
            (string) $user['email'],
            (string) $user['role_name'],
            ((int) $user['is_active'] === 1) ? 'active' : 'disabled',
            (string) ($user['last_login_at'] ?? 'never'),
        ];
    }

    heading('Users');
    table(['ID', 'Name', 'Email', 'Role', 'Status', 'Last sign-in'], $rows);

    return 0;
}

/** @param array<int,string> $argv */
function cmdUserCreate(array $argv): int
{
    $name  = option($argv, 'name') ?? prompt('Full name: ');
    $email = option($argv, 'email') ?? prompt('Email address: ');
    $slug  = option($argv, 'role', 'admin');

    if ($name === '' || $email === '') {
        fail('Name and email are both required.');
    }

    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        fail("'{$email}' is not a valid email address.");
    }

    $role = Role::findBySlug((string) $slug);
    if ($role === null) {
        $available = implode(', ', array_map(static fn (array $r): string => (string) $r['slug'], Role::all()));
        fail("Unknown role '{$slug}'. Available: {$available}");
    }

    if (User::emailExists($email)) {
        fail("A user with that email already exists. Use `user:password --email={$email}` to reset their password.");
    }

    $password = readPassword($argv);
    $id       = User::create($name, $email, $password, (int) $role['id'], true, null);

    ActivityLog::record('created', 'user', $id, sprintf('Created user %s (%s) from the console', $name, $role['name']));

    line("Created user #{$id}: {$name} <{$email}> as {$role['name']}.");

    $url = (string) Config::get('app.url');
    if ($url !== '') {
        line("Sign in at {$url}/login");
    }

    return 0;
}

/** @param array<int,string> $argv */
function cmdUserPassword(array $argv): int
{
    $user     = userByEmail((string) option($argv, 'email', ''));
    $password = readPassword($argv, 'New password');

    User::updatePassword((int) $user['id'], $password);

    // A password reset is also the way back in for a disabled account.
    if ((int) $user['is_active'] !== 1) {
        Database::update('users', ['is_active' => 1], (int) $user['id']);
        line("Reactivated {$user['name']}.");
    }

    // Clear any lockout so they can sign in straight away.
    $cleared = Database::run('DELETE FROM login_attempts WHERE email = ?', [$user['email']])->rowCount();

    ActivityLog::record('password_reset', 'user', (int) $user['id'], 'Reset the password for ' . $user['name'] . ' from the console');

    line("Password updated for {$user['email']}.");
    if ($cleared > 0) {
        line("Cleared {$cleared} recorded sign-in attempt(s), so any lockout is lifted.");
    }

    return 0;
}

/** @param array<int,string> $argv */
function cmdUserRole(array $argv): int
{
    $user = userByEmail((string) option($argv, 'email', ''));
    $slug = option($argv, 'role') ?? prompt('New role slug: ');

    $role = Role::findBySlug((string) $slug);
    if ($role === null) {
        $available = implode(', ', array_map(static fn (array $r): string => (string) $r['slug'], Role::all()));
        fail("Unknown role '{$slug}'. Available: {$available}");
    }

    if ((int) $role['id'] === (int) $user['role_id']) {
        line("{$user['name']} is already a {$role['name']}.");

        return 0;
    }

    if ((int) $role['is_superuser'] !== 1) {
        guardLastAdmin($user, 'moved out of the administrator role');
    }

    User::update((int) $user['id'], ['role_id' => (int) $role['id']]);

    ActivityLog::record(
        'updated',
        'user',
        (int) $user['id'],
        sprintf('Changed %s from %s to %s from the console', $user['name'], $user['role_name'], $role['name'])
    );

    line("{$user['name']} is now a {$role['name']}.");

    return 0;
}

/** @param array<int,string> $argv */
function cmdUserActivate(array $argv): int
{
    $user = userByEmail((string) option($argv, 'email', ''));

    if ((int) $user['is_active'] === 1) {
        line("{$user['name']} is already active.");

        return 0;
    }

    Database::update('users', ['is_active' => 1], (int) $user['id']);
    ActivityLog::record('updated', 'user', (int) $user['id'], 'Reactivated ' . $user['name'] . ' from the console');

    line("Reactivated {$user['name']}.");

    return 0;
}

/** @param array<int,string> $argv */
function cmdUserDeactivate(array $argv): int
{
    $user = userByEmail((string) option($argv, 'email', ''));
    guardLastAdmin($user, 'deactivated');

    if ((int) $user['is_active'] !== 1) {
        line("{$user['name']} is already disabled.");

        return 0;
    }

    Database::update('users', ['is_active' => 0], (int) $user['id']);
    ActivityLog::record('updated', 'user', (int) $user['id'], 'Deactivated ' . $user['name'] . ' from the console');

    line("Deactivated {$user['name']}. Their records and history are untouched.");

    return 0;
}

/** @param array<int,string> $argv */
function cmdUnlock(array $argv): int
{
    $email = option($argv, 'email');

    if ($email === null) {
        $count = (int) Database::scalar('SELECT COUNT(*) FROM login_attempts');
        Database::run('DELETE FROM login_attempts');
        line("Cleared {$count} recorded sign-in attempt(s). Every account and IP lockout is lifted.");

        return 0;
    }

    $count = Database::run('DELETE FROM login_attempts WHERE email = ?', [mb_strtolower(trim($email))])->rowCount();
    line("Cleared {$count} recorded sign-in attempt(s) for {$email}.");

    return 0;
}

/** @param array<int,string> $argv */
function cmdSettingList(array $argv): int
{
    $rows = [];
    foreach (Setting::all() as $key => $value) {
        $rows[] = [$key, (string) ($value ?? '')];
    }

    sort($rows);

    heading('Settings');
    table(['Key', 'Value'], $rows);
    line('  Change one with: setting:set --key=asset_tag_prefix --value=AST-');

    return 0;
}

/** @param array<int,string> $argv */
function cmdSettingSet(array $argv): int
{
    $key = option($argv, 'key') ?? prompt('Setting key: ');
    if ($key === '') {
        fail('A setting key is required.');
    }

    $known = array_keys(Setting::all());
    if (!in_array($key, $known, true)) {
        line("'{$key}' is not one of the existing settings:");
        line('  ' . implode(', ', $known));

        if (!confirm('Add it anyway?')) {
            return 1;
        }
    }

    $value = option($argv, 'value');
    if ($value === null) {
        $value = prompt('Value: ');
    }

    $before = Setting::get($key);
    Setting::put($key, $value);

    ActivityLog::record('updated', 'settings', null, "Changed {$key} from the console", [
        $key => ['from' => $before, 'to' => $value],
    ]);

    line("{$key} = {$value}");

    return 0;
}

/** @param array<int,string> $argv */
function cmdActivityPrune(array $argv): int
{
    $days = (int) option($argv, 'days', '365');
    if ($days < 30) {
        fail('Refusing to prune to less than 30 days — an audit trail that short is not worth keeping.');
    }

    $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));
    $count  = (int) Database::scalar('SELECT COUNT(*) FROM activity_log WHERE created_at < ?', [$cutoff]);

    if ($count === 0) {
        line("Nothing older than {$days} days. Nothing to do.");

        return 0;
    }

    if (flag($argv, 'dry-run')) {
        line(number_format($count) . " activity_log row(s) are older than {$days} days (before {$cutoff}).");
        line('Re-run without --dry-run to delete them.');

        return 0;
    }

    if (!flag($argv, 'force') && !confirm('Delete ' . number_format($count) . " audit rows older than {$days} days? This cannot be undone.")) {
        line('Cancelled.');

        return 1;
    }

    // Deleted in batches so a large trail does not lock the table for minutes.
    $deleted = 0;
    do {
        $batch = Database::run('DELETE FROM activity_log WHERE created_at < ? LIMIT 5000', [$cutoff])->rowCount();
        $deleted += $batch;
    } while ($batch > 0);

    ActivityLog::record('deleted', 'activity_log', null, "Pruned {$deleted} audit rows older than {$days} days");

    line('Deleted ' . number_format($deleted) . ' row(s).');

    return 0;
}

/**
 * Assets that require PAT but are missing the electrical details a test needs.
 *
 * Appliance class, load rating and fuse details live on the asset. The guided
 * PAT flow refuses to start without an appliance class, because it cannot tell
 * which electrical tests apply.
 *
 * @param array<int,string> $argv
 */
function cmdPatMissingDetails(array $argv): int
{
    $rows = App\Models\Asset::missingElectricalDetails();

    if ($rows === []) {
        line('Every asset that requires PAT has an appliance class, and every fused one has a rating.');

        return 0;
    }

    $table = [];
    foreach ($rows as $row) {
        $gaps = [];
        if ($row['appliance_class'] === null) {
            $gaps[] = 'appliance class';
        }
        if ((int) $row['has_fuse'] === 1 && $row['plug_fuse_rating_amps'] === null) {
            $gaps[] = 'fuse rating';
        }

        $table[] = [
            (string) $row['asset_tag'],
            str_limit((string) $row['name'], 40),
            implode(', ', $gaps),
        ];
    }

    heading('Assets needing electrical details');
    table(['Tag', 'Name', 'Missing'], $table);

    line();
    line('  Set these on each asset\'s edit page — the guided PAT flow needs the');
    line('  appliance class to know which electrical tests to ask for.');

    return $rows === [] ? 0 : 1;
}

/** @param array<int,string> $argv */
function cmdRefreshOverdue(array $argv): int
{
    $changed = Hire::refreshOverdue();

    line($changed === 0
        ? 'No hire statuses needed changing.'
        : "Updated the stored status on {$changed} hire(s).");

    return 0;
}

/** @param array<int,string> $argv */
function cmdStats(array $argv): int
{
    $hires       = Hire::summary();
    $maintenance = MaintenanceSchedule::summary();
    $pat         = PatRecord::summary();

    // Each count is taken on its own line rather than inline in the table so
    // that every Database:: call ends in a statement terminator — that is the
    // shape tests/security-audit.php can prove is a plain literal query.
    $assets     = (int) Database::scalar('SELECT COUNT(*) FROM assets');
    $inStock    = (int) Database::scalar("SELECT COUNT(*) FROM assets WHERE status = 'In Stock'");
    $retired    = (int) Database::scalar("SELECT COUNT(*) FROM assets WHERE status = 'Retired'");
    $subAssets  = (int) Database::scalar('SELECT COUNT(*) FROM assets WHERE parent_asset_id IS NOT NULL');
    $photos     = (int) Database::scalar('SELECT COUNT(*) FROM asset_photos');
    $manuals    = (int) Database::scalar('SELECT COUNT(*) FROM asset_manuals');

    heading('Register');
    table(['Metric', 'Count'], [
        ['Assets (all)', (string) $assets],
        ['Assets in stock', (string) $inStock],
        ['Assets retired', (string) $retired],
        ['Sub-assets / accessories', (string) $subAssets],
        ['Photos', (string) $photos],
        ['Manuals', (string) $manuals],
    ], false);

    heading('Attention');
    table(['Metric', 'Count'], [
        ['Hires out', (string) $hires['out']],
        ['Hires overdue', (string) $hires['overdue']],
        ['Hires due in ' . $hires['due_days'] . ' days', (string) $hires['due_soon']],
        ['Maintenance overdue', (string) $maintenance['overdue']],
        ['Maintenance due in ' . $maintenance['due_days'] . ' days', (string) $maintenance['due_soon']],
        ['PAT failed', (string) $pat['failed']],
        ['PAT never tested', (string) $pat['never_tested']],
    ], false);

    $activeUsers = (int) Database::scalar('SELECT COUNT(*) FROM users WHERE is_active = 1');
    $hirers   = (int) Database::scalar('SELECT COUNT(*) FROM hirers');
    $auditRows   = (int) Database::scalar('SELECT COUNT(*) FROM activity_log');

    heading('People and history');
    table(['Metric', 'Count'], [
        ['Users (active)', (string) $activeUsers],
        ['Administrators (active)', (string) User::countActiveAdmins()],
        ['Hirers', (string) $hirers],
        ['Audit rows', (string) $auditRows],
    ], false);

    return 0;
}

/** @param array<int,string> $argv */
function cmdDbCheck(array $argv): int
{
    try {
        Database::scalar('SELECT 1');
    } catch (Throwable $e) {
        fail($e->getMessage(), 2);
    }

    line(sprintf(
        'Connected to %s on %s:%s as %s.',
        Config::get('database.database'),
        Config::get('database.host'),
        Config::get('database.port'),
        Config::get('database.username')
    ));

    return 0;
}

/**
 * Generate the APP_KEY that encrypts the SMTP password.
 *
 * Deliberately does not write .env itself: that file may be root-owned and
 * mode 640, and a half-written .env is a much worse outcome than a line to
 * paste. It refuses to print a second key when one already exists, because
 * replacing APP_KEY makes every value encrypted under the old one unreadable.
 *
 * @param array<int,string> $argv
 */
function cmdKeyGenerate(array $argv): int
{
    if (!Crypto::isAvailable()) {
        fail('The PHP openssl extension is not loaded, so no key can be used.');
    }

    if (Crypto::hasKey() && !flag($argv, 'force')) {
        line('APP_KEY is already set in .env.');
        line();
        line('Replacing it makes the stored SMTP password unreadable, and it would have to be');
        line('re-entered in Settings → Email. Pass --force if that is what you want.');

        return 1;
    }

    line('Add this line to .env:');
    line();
    line('  APP_KEY=' . Crypto::generateKey());
    line();
    line('Then re-enter the SMTP password in Settings → Email.');

    return 0;
}

/**
 * Send a test message, so a server install can be proved without the browser.
 *
 * @param array<int,string> $argv
 */
function cmdMailTest(array $argv): int
{
    $to = (string) option($argv, 'to', '');

    if ($to === '') {
        $to = prompt('Send the test message to: ');
    }

    if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
        fail("'{$to}' is not a valid email address.");
    }

    $problems = Mailer::problems();

    if ($problems !== []) {
        foreach ($problems as $problem) {
            line('  - ' . $problem);
        }

        fail('Email is not usable yet.');
    }

    if (!Setting::bool('mail_enabled', false)) {
        fail('Email sending is switched off. Turn it on in Settings → Email, or with `setting:set --key=mail_enabled --value=1`.');
    }

    line('Sending to ' . $to . ' via ' . Setting::get('mail_host', '') . '…');

    $sent = Mailer::sendTemplate('smtp_test', $to, null, [
        'mail_host' => (string) Setting::get('mail_host', ''),
        'recipient' => $to,
        'sent_at'   => date('j M Y, H:i'),
        'sent_by'   => 'the console',
    ]);

    if ($sent) {
        line('Sent. If it does not arrive, check the spam folder.');

        return 0;
    }

    $latest = EmailLog::search(['status' => 'failed'], 1, 1);
    fail((string) ($latest['rows'][0]['error'] ?? 'The send failed; see the email log.'));
}

/**
 * The reminder run's state, without sending anything.
 *
 * @param array<int,string> $argv
 */
function cmdMailStatus(array $argv): int
{
    heading('Configuration');

    $problems = Mailer::problems();

    line('  Enabled:  ' . (Setting::bool('mail_enabled', false) ? 'yes' : 'no'));
    line('  Host:     ' . (Setting::get('mail_host', '') ?: '(unset)') . ':' . Setting::get('mail_port', ''));
    line('  From:     ' . (Setting::get('mail_from_address', '') ?: '(unset)'));
    line('  Password: ' . Mailer::passwordSource());
    line('  Ready:    ' . (Mailer::isReady() ? 'yes' : 'no'));

    foreach ($problems as $problem) {
        line('    - ' . $problem);
    }

    heading('Reminders');

    $rows = [];
    foreach (Reminders::TYPES as $type => $label) {
        $rows[] = [
            $label,
            Reminders::isEnabled($type) ? 'on' : 'off',
            Reminders::windowDays($type) . ' day(s)',
        ];
    }

    table(['Reminder', 'State', 'Window'], $rows, false);

    line();
    line('  Repeat after:  ' . Reminders::repeatDays() . ' day(s)');
    line('  Notify list:   ' . (Reminders::notifyUserIds() === [] ? 'nobody' : count(Reminders::notifyUserIds()) . ' user(s)'));

    foreach (array_keys(Reminders::TYPES) as $type) {
        $names = array_map(static fn (array $u): string => (string) $u['name'], Reminders::recipientsFor($type));
        line('    ' . str_pad($type, 12) . ($names === [] ? '(nobody eligible)' : implode(', ', $names)));
    }

    heading('Log');

    $summary = EmailLog::summary();
    line('  Sent:      ' . number_format($summary['sent']));
    line('  Failed:    ' . number_format($summary['failed']) . ' (' . $summary['failed_7'] . ' in the last 7 days)');
    line('  Last sent: ' . ($summary['last_sent_at'] ?? 'never'));

    return 0;
}

/**
 * Trim the email log and the reminder tracking rows.
 *
 * @param array<int,string> $argv
 */
function cmdMailPrune(array $argv): int
{
    $days = (int) option($argv, 'days', '365');

    if ($days < 30) {
        fail('Refusing to keep less than 30 days of email history.');
    }

    if (flag($argv, 'dry-run')) {
        $logRows = (int) Database::scalar(
            'SELECT COUNT(*) FROM email_log WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
            [$days]
        );

        // sprintf, not interpolation: tests/security-audit.php flags any
        // double-quoted string that contains both a SQL verb and a variable,
        // and it cannot tell a status message from a query. Keeping the rule
        // strict is worth more than the convenience here.
        line(sprintf('Would delete %d email log row(s) older than %d days.', $logRows, $days));

        return 0;
    }

    $logRows      = EmailLog::prune($days);
    $reminderRows = EmailReminder::prune($days);

    line(sprintf('Deleted %d email log row(s) and %d reminder tracking row(s).', $logRows, $reminderRows));

    return 0;
}

/**
 * Print a user's calendar feed URL, creating the token if they have none.
 *
 * The one place an administrator can reach somebody else's feed link, and it
 * exists for the support case ("I cannot find the address"). It is a shell
 * command with an audit entry, not a button in the admin UI.
 *
 * @param array<int,string> $argv
 */
function cmdCalendarUrl(array $argv): int
{
    $user = userByEmail((string) option($argv, 'email', ''));
    $id   = (int) $user['id'];

    if ($user['calendar_token'] === null || flag($argv, 'regenerate')) {
        $regenerating = $user['calendar_token'] !== null;

        if ($regenerating && !flag($argv, 'force') && !confirm('This stops their current link working. Continue?')) {
            line('Cancelled.');

            return 1;
        }

        User::regenerateCalendarToken($id);
        ActivityLog::record(
            'updated',
            'user',
            $id,
            ($regenerating ? 'Regenerated' : 'Created') . ' the calendar feed link for ' . $user['name'] . ' from the console'
        );

        $user = User::find($id);
    }

    line('Calendar feed for ' . $user['name'] . ':');
    line();
    line('  ' . App\Controllers\CalendarController::feedUrl((string) $user['calendar_token']));
    line();
    line('Treat it as a password — it grants read access to the dates that user can see.');

    if ((string) Config::get('app.url', '') === '') {
        line();
        line('APP_URL is not set in .env, so the host above was guessed. Set it.');
    }

    return 0;
}

// ---------------------------------------------------------------------------
// Dispatch
// ---------------------------------------------------------------------------

/** @var array<string,array{0:string,1:callable}> $commands */
$commands = [
    'doctor'                => ['Check PHP, configuration, storage and the database', 'cmdDoctor'],
    'stats'                 => ['Counts from the register: assets, hires, maintenance, PAT', 'cmdStats'],
    'db:check'              => ['Prove the database credentials in .env work', 'cmdDbCheck'],
    'user:list'             => ['List every user  [--active-only]', 'cmdUserList'],
    'user:create'           => ['Create a user  --name= --email= [--role=admin]', 'cmdUserCreate'],
    'user:password'         => ['Reset a password and lift any lockout  --email=', 'cmdUserPassword'],
    'user:role'             => ['Change a role  --email= --role=', 'cmdUserRole'],
    'user:activate'         => ['Reactivate a user  --email=', 'cmdUserActivate'],
    'user:deactivate'       => ['Disable a user  --email=', 'cmdUserDeactivate'],
    'unlock'                => ['Clear sign-in lockouts  [--email=]', 'cmdUnlock'],
    'setting:list'          => ['Show application settings', 'cmdSettingList'],
    'setting:set'           => ['Change a setting  --key= --value=', 'cmdSettingSet'],
    'activity:prune'        => ['Delete old audit rows  --days=365 [--dry-run] [--force]', 'cmdActivityPrune'],
    'pat:missing-details'   => ['Assets needing an appliance class or fuse rating', 'cmdPatMissingDetails'],
    'hires:refresh-overdue' => ['Recompute the stored overdue flag on hires', 'cmdRefreshOverdue'],
    'key:generate'          => ['Generate the APP_KEY that encrypts the SMTP password', 'cmdKeyGenerate'],
    'mail:status'           => ['Show the mail configuration, reminders and log summary', 'cmdMailStatus'],
    'mail:test'             => ['Send a test message  --to=you@example.com', 'cmdMailTest'],
    'mail:prune'            => ['Delete old email log rows  --days=365 [--dry-run]', 'cmdMailPrune'],
    'calendar:url'          => ['Show a user\'s calendar feed URL  --email= [--regenerate]', 'cmdCalendarUrl'],
];

$command = $argv[1] ?? '';

if ($command === '' || $command === '--help' || $command === '-h' || $command === 'help') {
    line('Kitwell — administrative console');
    line();
    line('  php bin/console.php <command> [options]');
    line();

    foreach ($commands as $name => [$description]) {
        printf("  %-23s %s\n", $name, $description);
    }

    line();
    line('Passwords are prompted for with the terminal echo off, or piped in with');
    line('--stdin-password. They are never read from an argument.');
    line();
    line('Root-level tasks (services, backups, file ownership) live in ./manage.sh');

    exit($command === '' ? 1 : 0);
}

if (!isset($commands[$command])) {
    fail("Unknown command '{$command}'. Run `php bin/console.php` for the list.");
}

exit($commands[$command][1]($argv));
