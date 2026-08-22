<?php

declare(strict_types=1);

/**
 * Per-account permission overrides, and the asset status while on hire.
 *
 * Two unrelated changes that landed together, both about a record saying
 * something that is not true.
 *
 * What this proves:
 *
 *   - a permission granted to one account opens the routes it gates, for that
 *     account and nobody else on the same role;
 *   - a permission denied to one account closes them, even though the role
 *     still gives it;
 *   - both directions are enforced *server-side*, over real HTTP, not merely in
 *     what the page offers;
 *   - the two independent readers of the rule agree — `Auth::can()`, which
 *     decides 403s, and `User::withPermission()`, which decides who gets
 *     emailed. The second is the one nobody would notice going stale;
 *   - a superuser is unaffected by either direction, deliberately, and the form
 *     says so rather than appearing to save;
 *   - an asset out on hire has no status dropdown on its edit page, a hand-made
 *     post trying to change its status is refused with the hirer named, and an
 *     ordinary save leaves it On Hire — which repairs a record already broken
 *     by the old behaviour;
 *   - and the mirror image: an asset with nothing out cannot be marked On Hire
 *     by hand, since that produces the same lie from the other direction.
 *
 * **This test writes.** It sets and clears permission overrides on the seeded
 * Manager / Staff and Administrator accounts, and saves one asset. Everything
 * it changes is put back on exit, including if it fails part way. Point it at a
 * scratch database.
 *
 *   php bin/seed.php
 *   php -S 127.0.0.1:8321 -t public
 *   php tests/user-permissions.php [http://127.0.0.1:8321]
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermission;

$base = rtrim((string) ($argv[1] ?? getenv('APP_TEST_URL') ?: 'http://127.0.0.1:8321'), '/');

$passed = 0;
$failed = 0;

/** One cookie jar per signed-in account, so two can be held open at once. */
function jar(string $who): string
{
    return sys_get_temp_dir() . '/kitwell-userperm-' . $who . '-' . getmypid() . '.txt';
}

/** @param array<string,mixed> $fields */
function request(string $who, string $method, string $path, array $fields = [], bool $follow = true): array
{
    global $base;

    $jar = jar($who);
    $ch  = curl_init($base . $path);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_FOLLOWLOCATION => $follow,
        CURLOPT_HEADER         => true,
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    }

    $raw    = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $size   = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $url    = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);

    return [
        'status'  => $status,
        'headers' => substr($raw, 0, $size),
        'body'    => substr($raw, $size),
        'url'     => $url,
    ];
}

function token(string $who, string $path): string
{
    $r = request($who, 'GET', $path);

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

function signIn(string $who, string $email): void
{
    @unlink(jar($who));

    request($who, 'POST', '/login', [
        '_token'   => token($who, '/login'),
        'email'    => $email,
        'password' => 'Workshop!Demo2026',
    ]);
}

echo "Per-account permissions — $base\n";

// ---------------------------------------------------------------------------
$permissionIds = [];

foreach (Permission::all() as $permission) {
    $permissionIds[(string) $permission['slug']] = (int) $permission['id'];
}

$admin   = Database::selectOne("SELECT * FROM users WHERE email = 'admin@example.com'");
$manager = Database::selectOne("SELECT * FROM users WHERE email = 'manager@example.com'");
$viewer  = Database::selectOne("SELECT * FROM users WHERE email = 'viewer@example.com'");

if ($admin === null || $manager === null || $viewer === null) {
    echo "\nNOTE: the seeded accounts are not present. Run `php bin/seed.php` first.\n";
    exit(1);
}

$managerId = (int) $manager['id'];
$adminId   = (int) $admin['id'];
$viewerId  = (int) $viewer['id'];

// Whatever this database already had, put back on the way out — including if a
// check below throws.
$existing = [
    $managerId => UserPermission::forUser($managerId),
    $adminId   => UserPermission::forUser($adminId),
    $viewerId  => UserPermission::forUser($viewerId),
];

register_shutdown_function(static function () use ($existing): void {
    foreach ($existing as $userId => $effects) {
        UserPermission::replace($userId, $effects, null);
    }

    foreach (['admin', 'manager', 'viewer'] as $who) {
        @unlink(jar($who));
    }
});

signIn('admin', 'admin@example.com');
signIn('manager', 'manager@example.com');
signIn('viewer', 'viewer@example.com');

// ---------------------------------------------------------------------------
echo "\n== A grant opens what the role does not ==\n";

// `loler.inspect` ships held by nobody at all, which makes it the cleanest
// thing to grant: nothing else can be supplying it.
// Not a retired one: other test files leave LOLER assets behind in various
// states, and this file only needs *an* asset the examine route will serve.
$lolerAsset = (int) Database::scalar(
    "SELECT id FROM assets WHERE requires_loler = 1 AND status <> 'Retired' ORDER BY id LIMIT 1"
);
check('there is an asset to examine', $lolerAsset > 0);

$examine = '/assets/' . $lolerAsset . '/loler/examine';

$r = request('manager', 'GET', $examine);
check('the manager is refused it to begin with', $r['status'] === 403, (string) $r['status']);

$r = request('admin', 'POST', '/admin/users/' . $managerId . '/permissions', [
    '_token'                                   => token('admin', '/admin/users/' . $managerId . '/edit'),
    'override[' . $permissionIds['loler.inspect'] . ']' => 'grant',
]);
check('an administrator can grant it to that account', str_contains($r['body'], 'Permissions saved'));
check('and the flash says what changed', str_contains($r['body'], '1 added to the role'));

$r = request('manager', 'GET', $examine);
check('the manager now reaches it', $r['status'] === 200, (string) $r['status']);

$r = request('viewer', 'GET', $examine);
check('and nobody else on another role does', $r['status'] === 403, (string) $r['status']);

// Somebody else on the *same* role must be unaffected — this is a per-account
// override, not a quiet edit to the role.
$otherOnSameRole = (int) Database::scalar(
    'SELECT id FROM users WHERE role_id = ? AND id <> ? AND is_active = 1 LIMIT 1',
    [(int) $manager['role_id'], $managerId]
);

if ($otherOnSameRole > 0) {
    check('somebody else on the same role still does not hold it',
        !User::holdsPermission($otherOnSameRole, 'loler.inspect'));
} else {
    check('somebody else on the same role still does not hold it', true, 'no second account on that role');
}

// The other reader of the same rule. This is the one that decides who gets
// emailed, and the one that would go stale unnoticed.
$holders = array_map('intval', array_column(User::withPermission('loler.inspect'), 'id'));
check('User::withPermission() agrees the account holds it', in_array($managerId, $holders, true));

// ---------------------------------------------------------------------------
echo "\n== A deny closes what the role gives ==\n";

// The premise the next few checks rest on. Asserted rather than assumed: if a
// site has edited the Manager / Staff role, "the deny closed it" would pass for
// the wrong reason.
check('the manager role gives pat.manage', (int) Database::scalar(
    'SELECT COUNT(*) FROM role_permissions WHERE role_id = ? AND permission_id = ?',
    [(int) $manager['role_id'], $permissionIds['pat.manage']]
) === 1);

$r = request('manager', 'GET', '/pat/create');
check('so the manager reaches the PAT form', $r['status'] === 200, (string) $r['status']);

$r = request('admin', 'POST', '/admin/users/' . $managerId . '/permissions', [
    '_token' => token('admin', '/admin/users/' . $managerId . '/edit'),
    'override[' . $permissionIds['loler.inspect'] . ']' => 'grant',
    'override[' . $permissionIds['pat.manage'] . ']'    => 'deny',
]);
check('a deny saves alongside the grant', str_contains($r['body'], '1 added to the role, 1 withheld'));

$r = request('manager', 'GET', '/pat/create');
check('the manager is now refused it', $r['status'] === 403, (string) $r['status']);

$r = request('manager', 'GET', $examine);
check('while the grant is untouched', $r['status'] === 200, (string) $r['status']);

$holders = array_map('intval', array_column(User::withPermission('pat.manage'), 'id'));
check('User::withPermission() agrees the account has lost it', !in_array($managerId, $holders, true));

// A grant and a deny for the same permission cannot both exist: the primary key
// is the pair, so the second write replaces the first rather than adding to it.
UserPermission::replace($managerId, [
    $permissionIds['pat.manage'] => UserPermission::GRANT,
], null);
UserPermission::replace($managerId, [
    $permissionIds['pat.manage'] => UserPermission::DENY,
], null);
check('an account cannot hold both effects for one permission',
    (int) Database::scalar(
        'SELECT COUNT(*) FROM user_permissions WHERE user_id = ? AND permission_id = ?',
        [$managerId, $permissionIds['pat.manage']]
    ) === 1);

// ---------------------------------------------------------------------------
echo "\n== Clearing an override goes back to the role ==\n";

$r = request('admin', 'POST', '/admin/users/' . $managerId . '/permissions', [
    '_token' => token('admin', '/admin/users/' . $managerId . '/edit'),
]);
check('saving with everything on "role" clears the lot',
    UserPermission::forUser($managerId) === [],
    json_encode(UserPermission::forUser($managerId)));

$r = request('manager', 'GET', '/pat/create');
check('the role permission is back', $r['status'] === 200, (string) $r['status']);

$r = request('manager', 'GET', $examine);
check('and the granted one is gone again', $r['status'] === 403, (string) $r['status']);

// ---------------------------------------------------------------------------
echo "\n== A superuser is not affected, in either direction ==\n";

$r = request('admin', 'POST', '/admin/users/' . $adminId . '/permissions', [
    '_token' => token('admin', '/admin/users/' . $adminId . '/edit'),
    'override[' . $permissionIds['users.manage'] . ']' => 'deny',
]);
check('the form refuses to set anything on a superuser account',
    str_contains($r['body'], 'superuser role'));
check('and nothing was written', UserPermission::forUser($adminId) === []);

// Even written straight to the table, the superuser escape holds. This is what
// stops an administrator locking the installation out of its own administration
// with no way back except SQL.
UserPermission::replace($adminId, [
    $permissionIds['users.manage'] => UserPermission::DENY,
    $permissionIds['roles.manage'] => UserPermission::DENY,
], null);

Auth::actAs(User::find($adminId));
check('Auth::can() still allows the superuser', Auth::can('users.manage') && Auth::can('roles.manage'));
check('User::withPermission() agrees', User::holdsPermission($adminId, 'users.manage'));

$r = request('admin', 'GET', '/admin/users');
check('and the administrator can still reach user administration', $r['status'] === 200, (string) $r['status']);

UserPermission::replace($adminId, [], null);

// The card is not offered to somebody who could not save it.
$r = request('manager', 'GET', '/admin/users/' . $viewerId . '/edit');
check('a manager cannot open a user page at all', $r['status'] === 403, (string) $r['status']);

$r = request('manager', 'POST', '/admin/users/' . $viewerId . '/permissions', [
    'override[' . $permissionIds['assets.delete'] . ']' => 'grant',
]);
check('nor post permissions for anybody', $r['status'] === 403, (string) $r['status']);
check('and nothing was written', UserPermission::forUser($viewerId) === []);

// ---------------------------------------------------------------------------
echo "\n== The status of an asset that is out on hire ==\n";

$hire = Database::selectOne(
    "SELECT h.id, h.asset_id, h.hirer_id, a.asset_tag, a.name, a.condition_rating
       FROM hires h
       INNER JOIN assets a ON a.id = h.asset_id
      WHERE h.returned_at IS NULL
      LIMIT 1"
);

if ($hire === null) {
    check('there is an asset out on hire to test with', false, 'no open hire in this database');
} else {
    $assetId = (int) $hire['asset_id'];
    $edit    = '/assets/' . $assetId . '/edit';

    $r = request('admin', 'GET', $edit);
    check('the edit page offers no status dropdown', !str_contains($r['body'], 'id="status"'));
    check('it offers Book in instead',
        str_contains($r['body'], '/hires/' . $hire['id'] . '/return'));
    check('and says who has it', str_contains($r['body'], 'Book it in to change the status'));

    // A post by hand, which is the only way to get here now.
    $r = request('admin', 'POST', '/assets/' . $assetId, [
        '_token'           => token('admin', $edit),
        'asset_tag'        => (string) $hire['asset_tag'],
        'name'             => (string) $hire['name'],
        'condition_rating' => (string) $hire['condition_rating'],
        'status'           => 'In Stock',
    ]);
    check('changing the status by hand is refused', str_contains($r['body'], 'Book it in before changing its status'));
    check('and the refusal names the hirer and the date',
        str_contains($r['body'], 'This asset is out with'));

    check('the asset is still On Hire',
        (string) Database::scalar('SELECT status FROM assets WHERE id = ?', [$assetId]) === 'On Hire');

    // The ordinary save, which sends no status at all. It must not fail
    // validation for a missing required field, and must leave the status alone
    // — which also repairs a record the old behaviour had already broken.
    Database::run("UPDATE assets SET status = 'In Stock' WHERE id = ?", [$assetId]);

    $r = request('admin', 'POST', '/assets/' . $assetId, [
        '_token'           => token('admin', $edit),
        'asset_tag'        => (string) $hire['asset_tag'],
        'name'             => (string) $hire['name'],
        'condition_rating' => (string) $hire['condition_rating'],
    ]);
    check('an ordinary save is accepted with no status field', str_contains($r['body'], 'has been saved'));
    check('and puts the status back to On Hire',
        (string) Database::scalar('SELECT status FROM assets WHERE id = ?', [$assetId]) === 'On Hire');
}

// ---------------------------------------------------------------------------
echo "\n== And the same lie from the other direction ==\n";

$free = Database::selectOne(
    "SELECT a.id, a.asset_tag, a.name, a.condition_rating, a.status
       FROM assets a
       LEFT JOIN hires h ON h.asset_id = a.id AND h.returned_at IS NULL
      WHERE h.id IS NULL AND a.status <> 'Retired'
      LIMIT 1"
);

if ($free === null) {
    check('there is a free asset to test with', false, 'every asset is out');
} else {
    $freeId = (int) $free['id'];

    $r = request('admin', 'GET', '/assets/' . $freeId . '/edit');
    check('a free asset does have a status dropdown', str_contains($r['body'], 'id="status"'));
    check('but On Hire is not one of the options', !str_contains($r['body'], '<option value="On Hire"'));

    $r = request('admin', 'POST', '/assets/' . $freeId, [
        '_token'           => token('admin', '/assets/' . $freeId . '/edit'),
        'asset_tag'        => (string) $free['asset_tag'],
        'name'             => (string) $free['name'],
        'condition_rating' => (string) $free['condition_rating'],
        'status'           => 'On Hire',
    ]);
    check('marking it On Hire by hand is refused', str_contains($r['body'], 'cannot be marked On Hire'));
    check('and it points at Check out', str_contains($r['body'], 'Use Check out'));
    check('the status is unchanged',
        (string) Database::scalar('SELECT status FROM assets WHERE id = ?', [$freeId]) === (string) $free['status']);
}

echo "\n----------------------------------------------\n";
echo "passed: $passed   failed: $failed\n";

exit($failed === 0 ? 0 : 1);
