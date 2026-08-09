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
use App\Core\Database;
use App\Models\ActivityLog;
use App\Models\Loan;
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

/** @param array<int,string> $argv */
function cmdRefreshOverdue(array $argv): int
{
    $changed = Loan::refreshOverdue();

    line($changed === 0
        ? 'No loan statuses needed changing.'
        : "Updated the stored status on {$changed} loan(s).");

    return 0;
}

/** @param array<int,string> $argv */
function cmdStats(array $argv): int
{
    $loans       = Loan::summary();
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
        ['Loans out', (string) $loans['out']],
        ['Loans overdue', (string) $loans['overdue']],
        ['Loans due in ' . $loans['due_days'] . ' days', (string) $loans['due_soon']],
        ['Maintenance overdue', (string) $maintenance['overdue']],
        ['Maintenance due in ' . $maintenance['due_days'] . ' days', (string) $maintenance['due_soon']],
        ['PAT failed', (string) $pat['failed']],
        ['PAT never tested', (string) $pat['never_tested']],
    ], false);

    $activeUsers = (int) Database::scalar('SELECT COUNT(*) FROM users WHERE is_active = 1');
    $borrowers   = (int) Database::scalar('SELECT COUNT(*) FROM borrowers');
    $auditRows   = (int) Database::scalar('SELECT COUNT(*) FROM activity_log');

    heading('People and history');
    table(['Metric', 'Count'], [
        ['Users (active)', (string) $activeUsers],
        ['Administrators (active)', (string) User::countActiveAdmins()],
        ['Borrowers', (string) $borrowers],
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

// ---------------------------------------------------------------------------
// Dispatch
// ---------------------------------------------------------------------------

/** @var array<string,array{0:string,1:callable}> $commands */
$commands = [
    'doctor'                => ['Check PHP, configuration, storage and the database', 'cmdDoctor'],
    'stats'                 => ['Counts from the register: assets, loans, maintenance, PAT', 'cmdStats'],
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
    'loans:refresh-overdue' => ['Recompute the stored overdue flag on loans', 'cmdRefreshOverdue'],
];

$command = $argv[1] ?? '';

if ($command === '' || $command === '--help' || $command === '-h' || $command === 'help') {
    line('Asset Register — administrative console');
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
