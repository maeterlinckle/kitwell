<?php

declare(strict_types=1);

/*
 * Create the first administrator (or any other user) from the command line.
 *
 *   php bin/create-admin.php
 *   php bin/create-admin.php --name="Jo Bloggs" --email=jo@example.com --role=admin
 *
 * The password is asked for interactively so it never lands in shell history.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Core\Database;
use App\Models\Role;
use App\Models\User;

/** @param array<int,string> $argv */
function option(array $argv, string $name): ?string
{
    foreach ($argv as $arg) {
        if (str_starts_with($arg, "--{$name}=")) {
            return trim(substr($arg, strlen($name) + 3), " \"'");
        }
    }

    return null;
}

function prompt(string $question, bool $hidden = false): string
{
    echo $question;

    if ($hidden && DIRECTORY_SEPARATOR !== '\\') {
        // POSIX: turn off terminal echo while the password is typed.
        shell_exec('stty -echo 2>/dev/null');
        $value = trim((string) fgets(STDIN));
        shell_exec('stty echo 2>/dev/null');
        echo PHP_EOL;

        return $value;
    }

    return trim((string) fgets(STDIN));
}

echo "Asset Register — create user\n\n";

// Roles must exist before a user can be created.
try {
    $roles = Role::all();
} catch (Throwable $e) {
    exit("Could not read the roles table. Have you run `php bin/migrate.php` yet?\n");
}

if ($roles === []) {
    exit("No roles found. Run `php bin/migrate.php` first.\n");
}

$name  = option($argv, 'name')  ?? prompt('Full name: ');
$email = option($argv, 'email') ?? prompt('Email address: ');
$roleSlug = option($argv, 'role') ?? 'admin';

if ($name === '' || $email === '') {
    exit("Name and email are both required.\n");
}

if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    exit("That is not a valid email address.\n");
}

$role = Role::findBySlug($roleSlug);
if ($role === null) {
    echo "Unknown role '{$roleSlug}'. Available roles:\n";
    foreach ($roles as $r) {
        echo "  - {$r['slug']} ({$r['name']})\n";
    }
    exit(1);
}

if (User::emailExists($email)) {
    echo "A user with that email already exists.\n";
    $answer = prompt('Reset their password instead? [y/N]: ');
    if (strtolower($answer) !== 'y') {
        exit(0);
    }

    $existing = User::findByEmail($email);
    $password = prompt('New password (min 12 characters): ', true);
    $confirm  = prompt('Confirm password: ', true);

    if ($password !== $confirm) {
        exit("Passwords did not match.\n");
    }
    if (strlen($password) < 12) {
        exit("Password must be at least 12 characters.\n");
    }

    User::updatePassword((int) $existing['id'], $password);
    Database::update('users', ['is_active' => 1], (int) $existing['id']);

    echo "\nPassword updated for {$email}.\n";
    exit(0);
}

$password = option($argv, 'password') ?? prompt('Password (min 12 characters): ', true);
if (option($argv, 'password') === null) {
    $confirm = prompt('Confirm password: ', true);
    if ($password !== $confirm) {
        exit("Passwords did not match.\n");
    }
}

if (strlen($password) < 12) {
    exit("Password must be at least 12 characters.\n");
}

$id = User::create($name, $email, $password, (int) $role['id'], true, null);

echo "\nCreated user #{$id}: {$name} <{$email}> as {$role['name']}.\n";
echo "You can now sign in at " . (config('app.url') ?: 'your site URL') . "/login\n";
