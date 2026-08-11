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

$accounts = [
    'admin'    => ['admin@example.com',        'Workshop!Demo2026'],
    'manager'  => ['manager@example.com',      'Workshop!Demo2026'],
    'viewer'   => ['viewer@example.com',       'Workshop!Demo2026'],
    'hirer' => ['chris.portal@example.com', 'PortalTest!2026x'],
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

    // Maintenance
    '/maintenance'               => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'allow', 'hirer' => 'deny'],
    '/maintenance/history'       => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'allow', 'hirer' => 'deny'],
    '/maintenance/create'        => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'deny',  'hirer' => 'deny'],
    '/maintenance/1'             => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'allow', 'hirer' => 'deny'],
    '/maintenance/1/edit'        => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'deny',  'hirer' => 'deny'],
    '/maintenance/1/complete'    => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'deny',  'hirer' => 'deny'],

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
    '/admin/settings'            => ['admin' => 'allow', 'manager' => 'deny', 'viewer' => 'deny', 'hirer' => 'deny'],
    '/admin/activity'            => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'deny', 'hirer' => 'deny'],
    '/admin/categories'          => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'deny', 'hirer' => 'deny'],
    '/admin/locations'           => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'deny', 'hirer' => 'deny'],
];

/** State-changing routes, checked with a valid CSRF token for that session. */
$writeMatrix = [
    '/assets'                 => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'deny', 'hirer' => 'deny'],
    '/assets/1/archive'       => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'deny', 'hirer' => 'deny'],
    '/assets/1/delete'        => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'deny', 'hirer' => 'deny'],
    '/assets/1/photos'        => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'deny', 'hirer' => 'deny'],
    '/assets/1/manuals'       => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'deny', 'hirer' => 'deny'],
    '/maintenance'            => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'deny', 'hirer' => 'deny'],
    '/pat'                    => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'deny', 'hirer' => 'deny'],
    '/hires/checkout'         => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'deny', 'hirer' => 'deny'],
    '/hirers'              => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'deny', 'hirer' => 'deny'],
    '/admin/users'            => ['admin' => 'allow', 'manager' => 'deny',  'viewer' => 'deny', 'hirer' => 'deny'],
    '/admin/settings'         => ['admin' => 'allow', 'manager' => 'deny',  'viewer' => 'deny', 'hirer' => 'deny'],
    '/admin/categories'       => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'deny', 'hirer' => 'deny'],
    '/import/assets/preview'  => ['admin' => 'allow', 'manager' => 'allow', 'viewer' => 'deny', 'hirer' => 'deny'],
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
        $status = $session->post($path, ['_token' => $token])->status;
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
