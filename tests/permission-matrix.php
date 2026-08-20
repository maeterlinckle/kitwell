<?php

declare(strict_types=1);

/*
 * Permission matrix.
 *
 * Drives every GET route in the application as each of the four roles and
 * checks the server's answer — not what the UI chose to show. Anything that
 * returns 200 where it should not, or 403 where it should not, is reported.
 *
 * The expected outcome for each route/role is declared below, so this doubles
 * as the written specification of who can see what.
 */

/*
 * ⚠ This one WRITES. It posts to state-changing routes to prove the server
 * refuses them for the wrong roles, which means the permitted roles really do
 * perform those actions. Run it only against a test or demo database.
 *
 * Usage: php tests/permission-matrix.php [base-url]
 */

define('BASE', rtrim($argv[1] ?? 'http://127.0.0.1:8321', '/'));

/*
 * The four accounts bin/seed.php creates, with its DEMO_PASSWORD. Keep this in
 * step with the seeder: a sign-in that fails here does not fail loudly, it just
 * leaves the session signed out, and every "deny" then looks like an "allow"
 * because the redirect to /login is not a 403. A stale hirer address in this
 * array once produced 70 phantom mismatches that read exactly like a
 * permissions hole.
 */
$accounts = [
    'admin'   => ['admin@example.com',   'Workshop!Demo2026'],
    'manager' => ['manager@example.com', 'Workshop!Demo2026'],
    'viewer'  => ['viewer@example.com',  'Workshop!Demo2026'],
    'hirer'   => ['hirer@example.com',   'Workshop!Demo2026'],
];

/**
 * route => [role => 'allow'|'deny']
 *
 * 'allow' means a 2xx (or a redirect that is part of normal flow).
 * 'deny'  means 403.
 */
$matrix = [
    // Everyone signed in
    '/'                          => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'allow', 'hirer' => 'allow'],
    '/profile'                   => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'allow', 'hirer' => 'allow'],
    '/my-hires'                  => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'allow', 'hirer' => 'allow'],

    // The register
    '/assets'                    => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'allow', 'hirer' => 'deny'],
    '/assets/1'                  => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'allow', 'hirer' => 'deny'],
    '/assets/create'             => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'deny',  'hirer' => 'deny'],
    '/assets/1/edit'             => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'deny',  'hirer' => 'deny'],
    '/assets/1/copy'             => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'deny',  'hirer' => 'deny'],
    '/assets/1/apply'            => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'deny',  'hirer' => 'deny'],
    '/assets/1/label'            => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'allow', 'hirer' => 'deny'],
    '/assets/labels?ids=1'       => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'allow', 'hirer' => 'deny'],
    '/assets/export'             => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'allow', 'hirer' => 'deny'],
    '/assets/print'              => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'allow', 'hirer' => 'deny'],
    '/assets/1/print'            => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'allow', 'hirer' => 'deny'],
    '/assets/1/photos'           => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'allow', 'hirer' => 'deny'],
    '/assets/1/pat'              => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'allow', 'hirer' => 'deny'],
    '/assets/1/maintenance/log'  => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'deny',  'hirer' => 'deny'],

    // Faults. Reading the history is part of seeing the asset; reporting one
    // is its own permission, held by admin and manager but not read-only.
    '/assets/1/faults'           => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'allow', 'hirer' => 'deny'],
    '/assets/1/faults/report'    => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'deny',  'hirer' => 'deny'],
    '/reports/faulty-assets'     => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'allow', 'hirer' => 'deny'],

    // Defining a report is `reports.manage` — admin and manager. Reading one is
    // still just reports.view plus the data's own permission.
    '/reports/custom/create'     => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'deny',  'hirer' => 'deny'],

    // API keys: issuing a credential that acts as somebody is administrator-only.
    '/admin/api'                 => ['admin' => 'allow', 'manager' => 'deny',  'viewer' => 'deny',  'hirer' => 'deny'],

    // The shared media library is readable by anyone who may see assets, since
    // that is what its contents describe. Maintaining the templates that draw
    // on it is `templates.manage` — admin and manager, like the categories.
    '/media'                     => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'allow', 'hirer' => 'deny'],
    '/admin/templates'           => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'deny',  'hirer' => 'deny'],
    '/admin/templates/create'    => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'deny',  'hirer' => 'deny'],

    // The API documentation page is readable by anyone signed in — it describes
    // an interface whose every endpoint enforces its own permissions, and it
    // reads no data itself.
    '/api/docs'                  => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'allow', 'hirer' => 'allow'],

    // Maintenance
    '/maintenance'               => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'allow', 'hirer' => 'deny'],
    '/maintenance/history'       => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'allow', 'hirer' => 'deny'],
    '/maintenance/create'        => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'deny',  'hirer' => 'deny'],
    '/maintenance/1'             => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'allow', 'hirer' => 'deny'],
    '/maintenance/1/edit'        => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'deny',  'hirer' => 'deny'],
    '/maintenance/1/complete'    => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'deny',  'hirer' => 'deny'],

    // Routines. The separation this feature exists for: reading what a
    // procedure asks and carrying it out are ordinary maintenance rights,
    // while changing what it asks is `routines.manage` — administrator only
    // out of the box, and addable to any role from Settings → Roles.
    '/maintenance/routines'        => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'allow', 'hirer' => 'deny'],
    '/maintenance/routines/create' => ['admin' => 'allow', 'manager' => 'deny',  'viewer' => 'deny',  'hirer' => 'deny'],
    '/assets/1/routines'           => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'deny',  'hirer' => 'deny'],

    // LOLER. Reading a report is part of reading the maintenance record;
    // making one is `loler.inspect`, which a fresh install grants to nobody —
    // so only the superuser role reaches it until a site decides who is
    // competent to carry out a thorough examination.
    '/loler'                     => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'allow', 'hirer' => 'deny'],
    '/assets/1/loler'            => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'allow', 'hirer' => 'deny'],
    '/assets/1/loler/examine'    => ['admin' => 'allow', 'manager' => 'deny',  'viewer' => 'deny',  'hirer' => 'deny'],

    // PAT
    '/pat'                       => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'allow', 'hirer' => 'deny'],
    '/pat/create'                => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'deny',  'hirer' => 'deny'],
    '/pat/1'                     => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'allow', 'hirer' => 'deny'],
    '/pat/1/edit'                => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'deny',  'hirer' => 'deny'],

    // Hires and hirers
    '/hires'                     => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'allow', 'hirer' => 'deny'],
    '/hires/1'                   => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'allow', 'hirer' => 'deny'],
    '/hires/checkout'            => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'deny',  'hirer' => 'deny'],
    '/hires/1/return'            => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'deny',  'hirer' => 'deny'],
    '/hirers'                 => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'allow', 'hirer' => 'deny'],
    '/hirers/1'               => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'allow', 'hirer' => 'deny'],
    '/hirers/create'          => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'deny',  'hirer' => 'deny'],
    '/hirers/1/edit'          => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'deny',  'hirer' => 'deny'],

    // Scanning
    '/scan'                      => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'allow', 'hirer' => 'deny'],
    '/scan/lookup?code=AST-0001' => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'allow', 'hirer' => 'deny'],

    // Reports
    '/reports'                   => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'allow', 'hirer' => 'deny'],
    '/reports/all-assets'        => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'allow', 'hirer' => 'deny'],
    '/reports/pat-due'           => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'allow', 'hirer' => 'deny'],
    '/reports/hires-due-back'    => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'allow', 'hirer' => 'deny'],
    '/reports/all-assets?format=csv' => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'allow', 'hirer' => 'deny'],

    // Import
    '/import'                    => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'deny',  'hirer' => 'deny'],

    // Export lives in its own place now; the hub needs either export or
    // reports, which is why a read-only user reaches it.
    '/export'                    => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'allow', 'hirer' => 'deny'],
    '/export/assets'             => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'allow', 'hirer' => 'deny'],
    '/export/assets/select'      => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'allow', 'hirer' => 'deny'],
    '/import/assets'             => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'deny',  'hirer' => 'deny'],
    '/import/pat'                => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'deny',  'hirer' => 'deny'],
    '/import/assets/template'    => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'deny',  'hirer' => 'deny'],

    // Administration
    '/admin/users'               => ['admin' => 'allow', 'manager' => 'deny', 'viewer' => 'deny', 'hirer' => 'deny'],
    '/admin/users/create'        => ['admin' => 'allow', 'manager' => 'deny', 'viewer' => 'deny', 'hirer' => 'deny'],
    '/admin/users/1/edit'        => ['admin' => 'allow', 'manager' => 'deny', 'viewer' => 'deny', 'hirer' => 'deny'],
    '/admin/roles'               => ['admin' => 'allow', 'manager' => 'deny', 'viewer' => 'deny', 'hirer' => 'deny'],
    '/admin/roles/create'        => ['admin' => 'allow', 'manager' => 'deny', 'viewer' => 'deny', 'hirer' => 'deny'],
    '/admin/teams'               => ['admin' => 'allow', 'manager' => 'deny', 'viewer' => 'deny', 'hirer' => 'deny'],
    '/admin/teams/create'        => ['admin' => 'allow', 'manager' => 'deny', 'viewer' => 'deny', 'hirer' => 'deny'],
    '/admin/teams/1/edit'        => ['admin' => 'allow', 'manager' => 'deny', 'viewer' => 'deny', 'hirer' => 'deny'],
    '/admin/settings'            => ['admin' => 'allow', 'manager' => 'deny', 'viewer' => 'deny', 'hirer' => 'deny'],
    '/admin/activity'            => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'deny', 'hirer' => 'deny'],
    '/admin/categories'          => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'deny', 'hirer' => 'deny'],
    '/admin/categories/create'   => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'deny', 'hirer' => 'deny'],
    '/admin/categories/1/edit'   => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'deny', 'hirer' => 'deny'],
    '/admin/locations'           => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'deny', 'hirer' => 'deny'],
    '/admin/locations/create'    => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'deny', 'hirer' => 'deny'],
    '/admin/locations/1/edit'    => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'deny', 'hirer' => 'deny'],

    // Anybody signed in may manage their own second factor — including a hirer,
    // whose account is worth protecting exactly as much as anyone else's.
    '/profile/security'          => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'allow', 'hirer' => 'allow'],
    '/profile/security/totp'     => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'allow', 'hirer' => 'allow'],
];

/** State-changing routes, checked with a valid CSRF token for that session. */
$writeMatrix = [
    '/assets'                 => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'deny', 'hirer' => 'deny'],
    '/assets/1/archive'       => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'deny', 'hirer' => 'deny'],
    '/assets/1/delete'        => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'deny', 'hirer' => 'deny'],
    '/assets/1/photos'        => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'deny', 'hirer' => 'deny'],
    '/assets/1/faults'        => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'deny', 'hirer' => 'deny'],
    '/reports/custom'         => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'deny', 'hirer' => 'deny'],
    '/admin/api/keys'         => ['admin' => 'allow', 'manager' => 'deny',  'viewer' => 'deny', 'hirer' => 'deny'],
    '/assets/1/media'         => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'deny', 'hirer' => 'deny'],
    '/assets/1/media/upload'  => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'deny', 'hirer' => 'deny'],
    '/maintenance'            => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'deny', 'hirer' => 'deny'],
    '/pat'                    => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'deny', 'hirer' => 'deny'],
    '/hires/checkout'         => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'deny', 'hirer' => 'deny'],
    '/hirers'              => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'deny', 'hirer' => 'deny'],
    '/admin/users'            => ['admin' => 'allow', 'manager' => 'deny',  'viewer' => 'deny', 'hirer' => 'deny'],
    '/admin/users/1/invite'   => ['admin' => 'allow', 'manager' => 'deny',  'viewer' => 'deny', 'hirer' => 'deny'],
    '/admin/teams'            => ['admin' => 'allow', 'manager' => 'deny',  'viewer' => 'deny', 'hirer' => 'deny'],
    '/admin/teams/1/status'   => ['admin' => 'allow', 'manager' => 'deny',  'viewer' => 'deny', 'hirer' => 'deny'],
    '/admin/teams/1/members'  => ['admin' => 'allow', 'manager' => 'deny',  'viewer' => 'deny', 'hirer' => 'deny'],
    '/admin/settings'         => ['admin' => 'allow', 'manager' => 'deny',  'viewer' => 'deny', 'hirer' => 'deny'],
    '/admin/categories'       => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'deny', 'hirer' => 'deny'],
    // Removing somebody else's second factor is an administrative act, and the
    // one that turns a stolen password into an account — so it is gated where
    // the user's own controls are not.
    '/admin/users/1/two-factor/reset' => ['admin' => 'allow', 'manager' => 'deny', 'viewer' => 'deny', 'hirer' => 'deny'],
    '/profile/security/disable'       => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'allow', 'hirer' => 'allow'],
    '/import/assets/preview'  => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'deny', 'hirer' => 'deny'],
];

/*
 * Extra fields for routes that look something up before they validate.
 *
 * The write checks deliberately post almost nothing, so a permitted role fails
 * validation and redirects — which still proves it got past the permission
 * gate. That breaks down where a controller resolves a record first:
 * PatController::store() reads asset_id and 404s when it finds nothing, so an
 * empty payload proved nothing about permissions either way. Give those routes
 * the minimum they need to reach their validation.
 */
$writePayloads = [
    '/pat' => ['asset_id' => '1'],
];

final class Session
{
    public string $jar;
    public int $status = 0;
    public string $body = '';

    public function __construct(public string $role, string $email, string $password)
    {
        $this->jar = sys_get_temp_dir() . '/pm-' . $role . '-' . getmypid() . '.txt';
        @unlink($this->jar);

        $this->get('/login');
        preg_match('/name="_token" value="([a-f0-9]+)"/', $this->body, $m);
        $this->post('/login', ['_token' => $m[1] ?? '', 'email' => $email, 'password' => $password]);

        // Stop dead if that did not sign in. A failed sign-in is invisible
        // otherwise: every later request redirects to /login, which is not a
        // 403, so the whole run reports "allow" where it should report "deny"
        // — a false permissions hole in a script whose entire job is to prove
        // there is not one. Better to refuse to run than to lie.
        $this->get('/profile');

        if (!str_contains($this->body, 'Sign out')) {
            // Say *which* wall was hit. A password that is right but owes a
            // second factor looks identical from here to one that is wrong, and
            // the fix is completely different — so name it rather than sending
            // somebody to re-seed a database that is fine.
            $blocked = str_contains($this->body, 'One more step')
                || str_contains($this->body, 'two-factor')
                    ? "That account has two-factor authentication switched on, so a password alone will not\n"
                        . "sign it in. This harness cannot answer a challenge. Turn it off for the demo accounts:\n"
                        . "  UPDATE users SET two_factor_enabled = 0, totp_secret = NULL, totp_confirmed_at = NULL;\n"
                        . "  UPDATE settings SET setting_value = '0' WHERE setting_key = 'two_factor_required';\n"
                    : "The accounts at the top of this script must match bin/seed.php.\n"
                        . "Re-seed with:  php bin/migrate.php && php bin/seed.php\n";

            fwrite(STDERR, sprintf("Could not sign in as %s (%s).\n%s", $role, $email, $blocked));
            exit(1);
        }
    }

    public function get(string $path): self
    {
        return $this->send('GET', $path, []);
    }

    public function post(string $path, array $fields): self
    {
        return $this->send('POST', $path, $fields);
    }

    private function send(string $method, string $path, array $fields): self
    {
        $ch = curl_init(BASE . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR      => $this->jar,
            CURLOPT_COOKIEFILE     => $this->jar,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 20,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        }

        $this->body   = (string) curl_exec($ch);
        $this->status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $this;
    }

    public function token(): string
    {
        $this->get('/profile');
        preg_match('/name="_token" value="([a-f0-9]+)"/', $this->body, $m);

        return $m[1] ?? '';
    }

    public function __destruct()
    {
        @unlink($this->jar);
    }
}

$sessions = [];
foreach ($accounts as $role => [$email, $password]) {
    $sessions[$role] = new Session($role, $email, $password);
}

$problems = 0;
$checks   = 0;

echo "\nGET routes\n";
printf("%-34s %-9s %-9s %-9s %-9s\n", 'route', 'admin', 'manager', 'viewer', 'hirer');
echo str_repeat('-', 74) . "\n";

foreach ($matrix as $path => $expectations) {
    $cells = [];

    foreach (['admin', 'manager', 'viewer', 'hirer'] as $role) {
        $expected = $expectations[$role];
        $status   = $sessions[$role]->get($path)->status;
        $checks++;

        // 404 is acceptable where a record simply is not there for that role's
        // scope; it still means "no data reached them".
        $actual = $status === 403 ? 'deny' : (($status >= 200 && $status < 400) ? 'allow' : 'other:' . $status);

        if ($actual !== $expected) {
            $problems++;
            $cells[] = strtoupper($actual) . '!';
        } else {
            $cells[] = $actual;
        }
    }

    printf("%-34s %-9s %-9s %-9s %-9s\n", substr($path, 0, 33), ...$cells);
}

echo "\nPOST routes (with a valid CSRF token for that session)\n";
printf("%-34s %-9s %-9s %-9s %-9s\n", 'route', 'admin', 'manager', 'viewer', 'hirer');
echo str_repeat('-', 74) . "\n";

foreach ($writeMatrix as $path => $expectations) {
    $cells = [];

    foreach (['admin', 'manager', 'viewer', 'hirer'] as $role) {
        $expected = $expectations[$role];
        $session  = $sessions[$role];
        $token    = $session->token();

        // Deliberately incomplete payloads: a permitted role should get past
        // the permission gate and fail validation (a redirect), which is still
        // "allow" for our purposes. A forbidden role must get 403.
        $status = $session->post($path, ['_token' => $token] + ($writePayloads[$path] ?? []))->status;
        $checks++;

        $actual = $status === 403 ? 'deny' : (($status >= 200 && $status < 400) ? 'allow' : 'other:' . $status);

        if ($actual !== $expected) {
            $problems++;
            $cells[] = strtoupper($actual) . '!';
        } else {
            $cells[] = $actual;
        }
    }

    printf("%-34s %-9s %-9s %-9s %-9s\n", substr($path, 0, 33), ...$cells);
}

echo "\n$checks checks, $problems mismatch(es).\n";

if ($problems === 0) {
    echo "Every role sees exactly what it should, enforced server-side.\n";
}

exit($problems === 0 ? 0 : 1);
