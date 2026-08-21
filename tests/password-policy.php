<?php

declare(strict_types=1);

/**
 * Password policy and trusted devices, end to end over real HTTP.
 *
 * What this proves:
 *
 *   - the complexity rule is a *setting*, not a constant: changing it in
 *     Settings changes what every password form accepts, without a release;
 *   - an account's own policy beats the application's, in both directions —
 *     stricter and more lenient — and an account set never to expire stays
 *     exempt when the site-wide figure is tightened afterwards;
 *   - an account with no override inherits whatever the site currently says,
 *     which is what makes NULL a different value from 0;
 *   - an expired password interrupts the session rather than the sign-in: the
 *     person gets in, is sent to one page, and cannot reach any other until
 *     they have set a new one — no lockout, and nothing for an administrator
 *     to undo;
 *   - the expired-password page refuses the password that has just expired;
 *   - a trusted device survives a change of address. That is the whole of the
 *     2FA relaxation, and it is checked by moving the address rather than by
 *     reading the code.
 *
 * **This test writes.** It edits the site-wide password policy, creates a user,
 * sets and expires passwords, and puts everything it touched back. Point it at
 * a scratch database.
 *
 *   php bin/seed.php
 *   php -S 127.0.0.1:8321 -t public
 *   php tests/password-policy.php [http://127.0.0.1:8321]
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Core\Database;
use App\Models\PasswordPolicy;
use App\Models\Setting;
use App\Models\TrustedDevice;

$base = rtrim((string) ($argv[1] ?? getenv('APP_TEST_URL') ?: 'http://127.0.0.1:8321'), '/');
$jar  = sys_get_temp_dir() . '/kitwell-pwpolicy-' . getmypid() . '.txt';

$passed = 0;
$failed = 0;

/**
 * @param array<string,mixed> $fields
 * @param array<string,string> $headers
 */
function request(
    string $method,
    string $path,
    array $fields = [],
    bool $follow = true,
    array $headers = []
): array {
    global $base, $jar;

    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_FOLLOWLOCATION => $follow,
        CURLOPT_HEADER         => true,
    ]);

    if ($headers !== []) {
        $lines = [];

        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $lines);
    }

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    }

    $raw     = (string) curl_exec($ch);
    $status  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $size    = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $url     = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);

    return [
        'status'  => $status,
        'headers' => substr($raw, 0, $size),
        'body'    => substr($raw, $size),
        'url'     => $url,
    ];
}

function token(string $path): string
{
    $r = request('GET', $path);

    return preg_match('/name="_token" value="([a-f0-9]+)"/', $r['body'], $m) ? $m[1] : '';
}

function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;

    if ($ok) {
        $passed++;
        echo "  ok    $label\n";

        return;
    }

    $failed++;
    echo "  FAIL  $label" . ($detail === '' ? '' : "\n          $detail") . "\n";
}

function signIn(string $email, string $password): array
{
    global $jar;
    @unlink($jar);

    return request('POST', '/login', [
        '_token'   => token('/login'),
        'email'    => $email,
        'password' => $password,
    ]);
}

// ---------------------------------------------------------------------------
// Everything this test changes, put back at the end whatever happens.
//
// The site-wide policy is written with Setting::put() rather than by posting
// the settings form. The form saves every key it renders at once, so driving it
// from a scraped copy of its own markup quietly rewrites two dozen unrelated
// settings — which is a way to break the next test that runs, not a way to test
// this one. The form's own handling of these three keys is checked below by a
// post that is *refused*, which writes nothing at all.
$restore = [
    'password_expiry_days' => (string) (Setting::get('password_expiry_days') ?? '0'),
    'password_min_length'  => (string) (Setting::get('password_min_length') ?? '12'),
    'password_min_classes' => (string) (Setting::get('password_min_classes') ?? '3'),
    // Site-wide two-factor sends every sign-in to a challenge whose code is on
    // somebody's phone, and a working mailer makes "add user" issue an
    // invitation rather than take the password typed into the form. Both are
    // correct behaviour; neither is what is under test here.
    'two_factor_required'  => (string) (Setting::get('two_factor_required') ?? '0'),
    'mail_enabled'         => (string) (Setting::get('mail_enabled') ?? '0'),
];

$nonce   = substr(bin2hex(random_bytes(4)), 0, 8);
$email   = 'policy-' . $nonce . '@example.com';
$adminPw = 'Workshop!Demo2026';

register_shutdown_function(static function () use ($restore, $email): void {
    foreach ($restore as $key => $value) {
        Setting::put($key, $value);
    }

    Database::run('DELETE FROM users WHERE email = ?', [$email]);
});

Setting::put('two_factor_required', '0');
Setting::put('mail_enabled', '0');

/** @param array<string,int> $policy */
function policy(array $policy): void
{
    foreach ($policy as $key => $value) {
        Setting::put($key, (string) $value);
    }

    Setting::flush();
}

// ---------------------------------------------------------------------------
echo "\n== The complexity rule comes from settings ==\n";

$r = signIn('admin@example.com', $adminPw);
check('the administrator signs in', !str_contains($r['body'], 'were not recognised'));

$r = request('GET', '/admin/settings');
check('the settings page offers a password policy',
    str_contains($r['body'], 'password_expiry_days')
    && str_contains($r['body'], 'password_min_length')
    && str_contains($r['body'], 'password_min_classes'));

// The form validates the new keys. A refusal is asserted rather than a save,
// because a refusal writes nothing and cannot disturb anything else.
$r = request('POST', '/admin/settings', [
    '_token'              => token('/admin/settings'),
    'password_min_length' => '4',
]);
check('the settings form refuses a minimum length below 8',
    str_contains($r['body'], 'Minimum password length'));

policy([
    'password_min_length'  => 16,
    'password_min_classes' => 4,
    'password_expiry_days' => 0,
]);

check('a stricter policy is what the application now reports',
    PasswordPolicy::appMinLength() === 16 && PasswordPolicy::appMinClasses() === 4,
    PasswordPolicy::appMinLength() . '/' . PasswordPolicy::appMinClasses());

// And it is enforced where a password is actually set. An administrator
// creating a user is the shortest path to that.
$r = request('POST', '/admin/users', [
    '_token'                => token('/admin/users/create'),
    'name'                  => 'Policy Test ' . $nonce,
    'email'                 => $email,
    'role_id'               => '3',
    'is_active'             => '1',
    'password'              => 'Sh0rt!Pass',
    'password_confirmation' => 'Sh0rt!Pass',
]);
check('a password under the new minimum length is refused',
    str_contains($r['body'], 'at least 16 characters'));

$r = request('POST', '/admin/users', [
    '_token'                => token('/admin/users/create'),
    'name'                  => 'Policy Test ' . $nonce,
    'email'                 => $email,
    'role_id'               => '3',
    'is_active'             => '1',
    'password'              => 'longenoughbutplain',
    'password_confirmation' => 'longenoughbutplain',
]);
check('a long password of one character type is refused',
    str_contains($r['body'], 'at least 4 of'));

$userPassword = 'Kitwell!Policy' . $nonce;

$r = request('POST', '/admin/users', [
    '_token'                => token('/admin/users/create'),
    'name'                  => 'Policy Test ' . $nonce,
    'email'                 => $email,
    'role_id'               => '3',
    'is_active'             => '1',
    'password'              => $userPassword,
    'password_confirmation' => $userPassword,
]);
check('one that satisfies the policy is accepted',
    !str_contains($r['body'], 'at least 4 of') && !str_contains($r['body'], 'at least 16 characters'));
$userId = (int) Database::scalar('SELECT id FROM users WHERE email = ?', [$email]);
check('the account exists', $userId > 0);

// ---------------------------------------------------------------------------
echo "\n== An account's own policy beats the site's ==\n";

$r = request('GET', '/admin/users/' . $userId . '/edit');
check('the user page offers a policy of its own',
    str_contains($r['body'], 'password_expiry_mode') && str_contains($r['body'], 'password_complexity_mode'));

$r = request('POST', '/admin/users/' . $userId . '/password-policy', [
    '_token'                   => token('/admin/users/' . $userId . '/edit'),
    'password_expiry_mode'     => 'never',
    'password_complexity_mode' => 'custom',
    'password_min_length'      => '8',
    'password_min_classes'     => '1',
]);
check('an override saves', str_contains($r['body'], 'Password policy saved'));

$user = Database::selectOne('SELECT * FROM users WHERE id = ?', [$userId]);
check('never-expires is stored as 0, not as NULL',
    $user['password_expiry_days'] !== null && (int) $user['password_expiry_days'] === 0,
    var_export($user['password_expiry_days'], true));

$policy = PasswordPolicy::forUser($user);
check('the account is more lenient than the site',
    $policy['min_length'] === 8 && $policy['min_classes'] === 1,
    $policy['min_length'] . '/' . $policy['min_classes']);
check('while the site itself is unchanged',
    PasswordPolicy::appMinLength() === 16 && PasswordPolicy::appMinClasses() === 4);

// The exemption has to survive somebody tightening the site-wide rule. This is
// the case the whole feature exists for.
policy(['password_expiry_days' => 30]);

$user = Database::selectOne('SELECT * FROM users WHERE id = ?', [$userId]);
check('the site now expires passwords after 30 days', PasswordPolicy::appExpiryDays() === 30);
check('but the exempt account still never expires',
    PasswordPolicy::forUser($user)['expiry_days'] === 0 && PasswordPolicy::expiresAt($user) === null);

// An account that has *not* been given an override follows the site, and keeps
// following it. That is what NULL means, and why it is not 0.
$admin = Database::selectOne('SELECT * FROM users WHERE email = ?', ['admin@example.com']);
check('an account with no override has NULL, not a copy of the figure',
    $admin['password_expiry_days'] === null);
check('and inherits the site policy', PasswordPolicy::forUser($admin)['expiry_days'] === 30);

// Back to inheriting, and check the override clears rather than sticking.
$r = request('POST', '/admin/users/' . $userId . '/password-policy', [
    '_token'                   => token('/admin/users/' . $userId . '/edit'),
    'password_expiry_mode'     => 'inherit',
    'password_complexity_mode' => 'inherit',
]);
check('an override can be cleared', str_contains($r['body'], 'Password policy saved'));

$user = Database::selectOne('SELECT * FROM users WHERE id = ?', [$userId]);
check('and all three columns go back to NULL',
    $user['password_expiry_days'] === null
    && $user['password_min_length'] === null
    && $user['password_min_classes'] === null);

// ---------------------------------------------------------------------------
echo "\n== An expired password interrupts, it does not lock out ==\n";

// Age the password past the site-wide 30 days.
Database::run(
    'UPDATE users SET password_changed_at = ? WHERE id = ?',
    [date('Y-m-d H:i:s', strtotime('-400 days')), $userId]
);

$user = Database::selectOne('SELECT * FROM users WHERE id = ?', [$userId]);
check('the password now reads as expired', PasswordPolicy::hasExpired($user));

$r = signIn($email, $userPassword);
check('the sign-in itself still succeeds', !str_contains($r['body'], 'were not recognised'));
check('and lands on the expired-password page',
    str_contains($r['url'], '/password/expired') || str_contains($r['body'], 'Your password has expired'),
    $r['url']);

$r = request('GET', '/assets');
check('every other page redirects there', str_contains($r['url'], '/password/expired'), $r['url']);

$r = request('POST', '/password/expired', [
    '_token'                => token('/password/expired'),
    'current_password'      => $userPassword,
    'password'              => $userPassword,
    'password_confirmation' => $userPassword,
]);
check('re-entering the expired password is refused',
    str_contains($r['body'], 'password you have not been using')
    || str_contains($r['body'], 'that has just expired'));

$newPassword = 'Kitwell!Renewed' . $nonce;

$r = request('POST', '/password/expired', [
    '_token'                => token('/password/expired'),
    'current_password'      => $userPassword,
    'password'              => $newPassword,
    'password_confirmation' => $newPassword,
]);
check('a new one is accepted', !str_contains($r['url'], '/password/expired'), $r['url']);

$r = request('GET', '/assets');
check('and the rest of the application opens again',
    $r['status'] === 200 && !str_contains($r['url'], '/password/expired'));

$user = Database::selectOne('SELECT * FROM users WHERE id = ?', [$userId]);
check('the password no longer reads as expired', !PasswordPolicy::hasExpired($user));

// ---------------------------------------------------------------------------
echo "\n== A trusted device survives a change of address ==\n";

// The relaxation in full: identity is the cookie plus the browser, and the
// address is recorded but never checked. Written against the model rather than
// through a sign-in because a second factor cannot be answered from here — the
// code is on somebody's phone.
$deviceUser = $userId;

TrustedDevice::forgetAll($deviceUser);
$deviceToken = TrustedDevice::remember($deviceUser);

$row = Database::selectOne(
    'SELECT * FROM trusted_devices WHERE user_id = ? ORDER BY id DESC LIMIT 1',
    [$deviceUser]
);
check('the device is remembered with the address it was trusted from',
    $row !== null && (string) $row['ip_address'] !== '');

// Move it somewhere else entirely: a different network, a different family of
// address. Under the old rule either of these ended the trust.
foreach (['203.0.113.7', '2001:db8::1', ''] as $address) {
    $_SERVER['REMOTE_ADDR'] = $address;

    check(
        'still trusted from ' . ($address === '' ? 'an unknown address' : $address),
        TrustedDevice::isTrusted($deviceUser, $deviceToken)
    );
}

// What does still end it: a different browser.
$_SERVER['REMOTE_ADDR']     = '203.0.113.7';
$_SERVER['HTTP_USER_AGENT'] = 'Something Else/1.0';

check('but a different browser ends it', !TrustedDevice::isTrusted($deviceUser, $deviceToken));
check('and the row is thrown away rather than left to be retried',
    Database::selectOne('SELECT * FROM trusted_devices WHERE user_id = ?', [$deviceUser]) === null);

// And so does the idle window, which is the other half of the rule.
$deviceToken = TrustedDevice::remember($deviceUser);
Database::run(
    'UPDATE trusted_devices SET last_seen_at = ? WHERE user_id = ?',
    [date('Y-m-d H:i:s', strtotime('-' . (TrustedDevice::idleDays() + 1) . ' days')), $deviceUser]
);

check('an unused device is asked again', !TrustedDevice::isTrusted($deviceUser, $deviceToken));

// The IP check is gone from the code, not merely bypassed by the data.
$source = (string) file_get_contents(__DIR__ . '/../src/Models/TrustedDevice.php');
check('and nothing in the model compares addresses',
    !str_contains($source, 'sameNetwork') && !str_contains($source, 'Request::ip(), '));

echo "\n----------------------------------------------\n";
echo "passed: $passed   failed: $failed\n";

@unlink($jar);

exit($failed === 0 ? 0 : 1);
