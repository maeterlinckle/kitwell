<?php

declare(strict_types=1);

/*
 * Static security and consistency audit.
 *
 * Reads the routes, source and templates and asserts the invariants the whole
 * application depends on: CSRF on every state-changing route, a permission on
 * every route that is not deliberately open, no SQL built from interpolated
 * variables, no unescaped template output, and no upload that skips validation.
 */

$root = dirname(__DIR__);

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;

    if ($ok) {
        $pass++;
        echo "  ok    $label\n";
    } else {
        $fail++;
        echo "  FAIL  $label" . ($detail !== '' ? "\n        $detail" : '') . "\n";
    }
}

/** All PHP files under a directory. */
function phpFiles(string $directory): array
{
    if (!is_dir($directory)) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

    foreach ($iterator as $entry) {
        if ($entry->isFile() && $entry->getExtension() === 'php') {
            $files[] = $entry->getPathname();
        }
    }

    sort($files);

    return $files;
}

/* -------------------------------------------------------------------------
 * Routes
 * ---------------------------------------------------------------------- */
echo "\n== Routes ==\n";

$routesSource = (string) file_get_contents($root . '/routes/web.php');

// Track the middleware applied by the enclosing group(...) call.
$routes      = [];
$groupStack  = [];
$lines       = explode("\n", $routesSource);
$braceDepth  = 0;

foreach ($lines as $line) {
    if (preg_match('/\$router->group\(\[([^\]]*)\]/', $line, $groupMatch) === 1) {
        preg_match_all("/'([^']+)'/", $groupMatch[1], $names);
        $groupStack[] = ['depth' => $braceDepth, 'middleware' => $names[1]];
    }

    if (preg_match('/\$router->(get|post|put|delete)\(\s*\'([^\']+)\'\s*,\s*\[([^\]]+)\]\s*(?:,\s*\[([^\]]*)\])?/', $line, $m) === 1) {
        preg_match_all("/'([^']+)'/", $m[4] ?? '', $middlewareNames);

        $inherited = [];
        foreach ($groupStack as $group) {
            $inherited = array_merge($inherited, $group['middleware']);
        }

        $routes[] = [
            'method'     => strtoupper($m[1]),
            'path'       => $m[2],
            'handler'    => trim($m[3]),
            'middleware' => array_merge($inherited, $middlewareNames[1]),
        ];
    }

    $braceDepth += substr_count($line, '{') - substr_count($line, '}');

    // Leaving a group closure.
    while ($groupStack !== [] && $braceDepth <= end($groupStack)['depth'] && str_contains($line, '});')) {
        array_pop($groupStack);
    }
}

check('routes parsed', count($routes) > 60, count($routes) . ' found');

// 1. Every state-changing route must verify CSRF.
$missingCsrf = [];
foreach ($routes as $route) {
    if (in_array($route['method'], ['POST', 'PUT', 'DELETE'], true)
        && !in_array('csrf', $route['middleware'], true)) {
        $missingCsrf[] = $route['method'] . ' ' . $route['path'];
    }
}
check('every POST/PUT/DELETE route verifies CSRF', $missingCsrf === [], implode("\n        ", $missingCsrf));

// 2. Every route must be authenticated, except the deliberately public ones.
//
//    The calendar feed is the third. It carries its own credential — a
//    64-character random token in the path, unique to one user and revocable
//    by them — because a calendar client cannot complete an interactive
//    sign-in. What the feed *contains* still runs through the ordinary
//    permission model: App\Services\CalendarFeed asks
//    User::holdsPermission() for the token's owner, which is the same rule
//    Auth::can() applies. So this is an alternative way of proving who you
//    are, not an exemption from what you may see.
//    The logo is the fourth. The sign-in page carries it and nobody has a
//    session at that point, so requiring one would mean no branding on the one
//    page every user sees first. It is safe to expose because it exposes
//    nothing: the route takes no id, reads one of two settings, and returns an
//    image an administrator deliberately published. An organisation's logo is
//    on the side of their van.
$publicRoutes = [
    '/login',
    '/health',
    '/calendar/{token:[a-f0-9]+}.ics',
    '/branding/logo/{variant:light|dark}',
];
$unauthed     = [];

foreach ($routes as $route) {
    if (in_array($route['path'], $publicRoutes, true)) {
        continue;
    }

    $hasAuth = in_array('auth', $route['middleware'], true)
        || in_array('guest', $route['middleware'], true)
        || (bool) preg_grep('/^can(any)?:/', $route['middleware']);

    if (!$hasAuth) {
        $unauthed[] = $route['method'] . ' ' . $route['path'];
    }
}
check('every non-public route requires a session', $unauthed === [], implode("\n        ", $unauthed));

// 3. Every route should carry a permission, except a small documented set
//    that is self-scoping or applies to any signed-in user.
$permissionExempt = [
    '/login', '/logout', '/health', '/', '/profile', '/profile/password',
    '/my-hires', '/my-hires/{hireId:\d+}', '/my-hires/{hireId:\d+}/photo',
    '/my-hires/{hireId:\d+}/manuals/{manualId:\d+}',
    // A user's own calendar subscription. Self-scoping in the strongest
    // sense: these three only ever read or replace the signed-in user's own
    // token, there is no id in the path, and the feed they produce is
    // filtered by that user's permissions. An administrator has no route to
    // anybody else's — a feed URL is a credential, not an admin surface.
    '/profile/calendar', '/profile/calendar/revoke',
    '/calendar/{token:[a-f0-9]+}.ics',
    // A user's own second factor, and self-scoping for the same reason: every
    // one of these acts on Auth::id() and nothing else. There is no id in any
    // path, so there is no route by which one person reaches another's
    // enrolment, backup codes or trusted devices. An administrator *removing*
    // somebody's second factor is a different act on a different route
    // (/admin/users/{id}/two-factor/reset) and does carry users.manage.
    '/profile/security',
    '/profile/security/totp',
    '/profile/security/email',
    '/profile/security/disable',
    '/profile/security/backup-codes',
    '/profile/security/devices/{id:\d+}/forget',
    '/profile/security/devices/forget-all',
    // Public branding, per the reasoning above. There is no permission that
    // would make sense here: the page that needs it most is the one shown to
    // people who have not signed in.
    '/branding/logo/{variant:light|dark}',
    // Accepting an invitation and resetting a forgotten password. A permission
    // check here is a contradiction in terms: the person has no session, and in
    // the invite case has never had one. What stands in for it is the token —
    // 32 random bytes, stored only as a SHA-256, good for one use, with an
    // expiry an administrator sets. The forgotten-password pages take no token
    // at all and reveal nothing: the same answer is given whether or not the
    // address exists, and requests are metered on the sign-in throttle.
    // See App\Controllers\AccountController and App\Models\UserToken.
    '/invite/{token:[a-f0-9]+}',
    '/forgot-password',
    '/reset-password/{token:[a-f0-9]+}',
    // The second factor. Same contradiction, one step further along: the
    // password has been accepted but no session exists yet, on purpose — see
    // App\Services\TwoFactor. What identifies these requests is the pending
    // challenge in the session, which only Auth::attempt() can create and which
    // names the user; without one, every route here redirects to /login. The
    // codes themselves are metered twice over, on a per-challenge counter and
    // on the ordinary sign-in throttle, so six digits cannot be walked through.
    '/two-factor',
    '/two-factor/resend',
    '/two-factor/cancel',
    '/two-factor/setup',
];

$noPermission = [];
foreach ($routes as $route) {
    if (in_array($route['path'], $permissionExempt, true)) {
        continue;
    }

    if (!preg_grep('/^can(any)?:/', $route['middleware'])) {
        $noPermission[] = $route['method'] . ' ' . $route['path'];
    }
}
check('every other route carries a permission check', $noPermission === [], implode("\n        ", $noPermission));

// 4. A page that only displays data should not demand a write permission —
//    a viewer must not need assets.edit merely to read something.
//
//    Excluded: /admin/* (every admin page is a management surface, with its
//    edit controls inline), and any path that is itself an action.
$actionWords = ['create', 'edit', 'copy', 'apply', 'complete', 'log', 'import',
                'return', 'checkout', 'delete', 'template', 'preview'];
$suspicious  = [];

foreach ($routes as $route) {
    if ($route['method'] !== 'GET' || str_starts_with($route['path'], '/admin/')) {
        continue;
    }

    foreach ($route['middleware'] as $middleware) {
        if (preg_match('/^can:.*\.(create|edit|delete|manage)$/', $middleware) !== 1) {
            continue;
        }

        $isAction = false;
        foreach ($actionWords as $word) {
            if (str_contains($route['path'], $word)) {
                $isAction = true;
                break;
            }
        }

        if (!$isAction) {
            $suspicious[] = $route['method'] . ' ' . $route['path'] . ' → ' . $middleware;
        }
    }
}
check('display-only pages are not gated behind write permissions', $suspicious === [], implode("\n        ", $suspicious));

/* -------------------------------------------------------------------------
 * SQL
 * ---------------------------------------------------------------------- */
echo "\n== SQL ==\n";

$sourceFiles = array_merge(phpFiles($root . '/src'), phpFiles($root . '/bin'));

// Interpolating a variable inside a double-quoted SQL string is the classic
// injection route. Nothing in this codebase should do it.
$interpolated = [];

foreach ($sourceFiles as $file) {
    $code = (string) file_get_contents($file);

    if (preg_match_all('/"[^"\n]*\b(SELECT|INSERT|UPDATE|DELETE)\b[^"\n]*\$[a-zA-Z_]/i', $code, $matches) > 0) {
        foreach ($matches[0] as $match) {
            $interpolated[] = basename($file) . ': ' . trim($match);
        }
    }
}
check('no variables interpolated into SQL strings', $interpolated === [], implode("\n        ", $interpolated));

// Concatenation into SQL is allowed only for values this code controls
// completely: generated placeholder lists, integer limits and offsets, and
// ORDER BY / status fragments chosen from a fixed whitelist in the model.
//
// Only the SQL argument of a Database:: call is examined, so ordinary string
// building elsewhere (log lines, flash messages) is not mistaken for SQL.
$concatenations = [];

/** Variables and calls that can only ever produce SQL this code wrote. */
$controlled = [
    '$whereSql', '$orderBy', '$perPage', '$offset', '$sql', '$placeholders',
    '$sortKey', '$limit', '$dueDays', '$stem',
    'implode(', '(int)', 'max(', 'min(', 'array_fill(',
    'self::', 'static::', 'SELECT', 'statusSqlLiteral(', 'selectSql(',
];

foreach ($sourceFiles as $file) {
    $code = (string) file_get_contents($file);

    if (preg_match_all(
        // Terminates at the parameter array, a statement end, or a chained
        // call such as ->rowCount().
        '/Database::(?:run|select|selectOne|scalar)\s*\(\s*(.*?)(?:,\s*\[|\)\s*(?:;|->))/s',
        $code,
        $matches
    ) === 0) {
        continue;
    }

    foreach ($matches[1] as $argument) {
        // Remove every quoted literal; what is left is the dynamic part.
        $dynamic = preg_replace("/'(?:[^'\\\\]|\\\\.)*'/s", '', $argument) ?? '';
        $dynamic = preg_replace('/"(?:[^"\\\\]|\\\\.)*"/s', '', $dynamic) ?? '';

        if (trim(str_replace(['.', ' ', "\n", "\r", "\t"], '', $dynamic)) === '') {
            continue;   // a plain literal query
        }

        $safe = false;
        foreach ($controlled as $token) {
            if (str_contains($dynamic, $token)) {
                $safe = true;
                break;
            }
        }

        if (!$safe) {
            $concatenations[] = basename($file) . ': ' . preg_replace('/\s+/', ' ', trim($dynamic));
        }
    }
}
check('SQL is built only from literals and controlled fragments', $concatenations === [], implode("\n        ", $concatenations));

// Every query must go through the Database wrapper.
$rawPdo = [];
foreach ($sourceFiles as $file) {
    if (basename($file) === 'Database.php') {
        continue;
    }

    $code = (string) file_get_contents($file);

    if (preg_match('/->(query|exec)\s*\(/', $code) === 1 && !str_contains($code, 'Migrator')) {
        $rawPdo[] = basename($file);
    }
}
check('no raw PDO query()/exec() outside the wrapper and migrator', $rawPdo === [], implode(', ', $rawPdo));

/* -------------------------------------------------------------------------
 * Templates — delegated to the token-based auditor, which is far more
 * reliable than pattern matching for this.
 * ---------------------------------------------------------------------- */
echo "\n== Template output escaping ==\n";

$escapeOutput = [];
$escapeStatus = 0;
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/escape-audit.php') . ' 2>&1', $escapeOutput, $escapeStatus);

check(
    'no unescaped variable output in any template',
    $escapeStatus === 0,
    implode("\n        ", array_slice($escapeOutput, 0, 12))
);
check('the escaping audit examined every template', (bool) preg_grep('/Checked \d+ output expressions/', $escapeOutput),
    implode(' ', array_slice($escapeOutput, 0, 2)));

$templateFiles = phpFiles($root . '/templates');
check('templates found', count($templateFiles) > 30, (string) count($templateFiles));

/* -------------------------------------------------------------------------
 * Uploads
 * ---------------------------------------------------------------------- */
echo "\n== File uploads ==\n";

$uploadCallers = [];
$unvalidated   = [];

foreach ($sourceFiles as $file) {
    $code = (string) file_get_contents($file);

    if (!str_contains($code, 'Upload::files(')) {
        continue;
    }

    $uploadCallers[] = basename($file);

    if (!str_contains($code, 'Upload::validate(')) {
        $unvalidated[] = basename($file);
    }
}

check('every upload entry point validates', $unvalidated === [], implode(', ', $unvalidated));
check('uploads are handled in the expected places', count($uploadCallers) >= 4, implode(', ', $uploadCallers));

// Files must never be written to the document root.
$publicWrites = [];
foreach ($sourceFiles as $file) {
    $code = (string) file_get_contents($file);

    if (preg_match('/(move_uploaded_file|file_put_contents|imagejpeg)\s*\([^;]*public/i', $code) === 1) {
        $publicWrites[] = basename($file);
    }
}
check('nothing is written into the document root', $publicWrites === [], implode(', ', $publicWrites));

// Served files must resolve through the traversal guard.
$streamers = [];
foreach ($sourceFiles as $file) {
    $code = (string) file_get_contents($file);

    if (str_contains($code, 'readfile(')) {
        $streamers[] = basename($file);

        if (!str_contains($code, 'Upload::absolutePath(')) {
            $unvalidated[] = basename($file) . ' (readfile without absolutePath)';
        }
    }
}
check('streamed files resolve through the path guard', !in_array(true, array_map(
    static fn (string $entry): bool => str_contains($entry, 'readfile'),
    $unvalidated
), true), implode(', ', $unvalidated));

/* -------------------------------------------------------------------------
 * Session and cookies
 * ---------------------------------------------------------------------- */
echo "\n== Session and headers ==\n";

$session = (string) file_get_contents($root . '/src/Core/Session.php');

check('session cookie is HttpOnly', str_contains($session, "'httponly' => true"));
check('session cookie sets SameSite', str_contains($session, "'samesite'"));
check('session cookie is Secure under HTTPS', str_contains($session, "'secure'   => Request::isSecure()"));
check('session uses strict mode', str_contains($session, "session.use_strict_mode', '1'"));
check('session id is regenerated', str_contains($session, 'session_regenerate_id(true)'));
check('session has an idle timeout', str_contains($session, '__last_activity'));

$response = (string) file_get_contents($root . '/src/Core/Response.php');

foreach ([
    'X-Content-Type-Options: nosniff',
    'X-Frame-Options: SAMEORIGIN',
    'Referrer-Policy',
    'Content-Security-Policy',
    'Strict-Transport-Security',
] as $header) {
    check('sends ' . explode(':', $header)[0], str_contains($response, $header));
}

check('CSP does not allow off-origin scripts', !preg_match('/script-src[^;]*https?:\/\//', $response));

$auth = (string) file_get_contents($root . '/src/Core/Auth.php');
check('passwords are hashed with password_hash', str_contains((string) file_get_contents($root . '/src/Models/User.php'), 'password_hash('));
check('passwords are checked with password_verify', str_contains($auth, 'password_verify('));
check('password hashes are upgraded on sign-in', str_contains($auth, 'password_needs_rehash('));
check('sign-in timing does not leak account existence', str_contains($auth, 'Always run a hash comparison'));

$throttle = (string) file_get_contents($root . '/src/Core/LoginThrottle.php');
check('failed logins are throttled by email', str_contains($throttle, 'WHERE email = ?'));
check('failed logins are throttled by IP', str_contains($throttle, 'WHERE ip_address = ?'));
check('login attempts are recorded', str_contains($throttle, "Database::insert('login_attempts'"));

/* -------------------------------------------------------------------------
 * Forms
 * ---------------------------------------------------------------------- */
echo "\n== Forms ==\n";

$formsWithoutToken = [];

foreach ($templateFiles as $file) {
    $code = (string) file_get_contents($file);

    if (preg_match_all('/<form\b[^>]*>/i', $code, $matches, PREG_OFFSET_CAPTURE) === 0) {
        continue;
    }

    foreach ($matches[0] as $match) {
        $tag = $match[0];

        // GET forms are navigation, not state changes.
        if (preg_match('/method\s*=\s*"post"/i', $tag) !== 1) {
            continue;
        }

        // Look for csrf_field() before the next </form>.
        $start = $match[1];
        $end   = stripos($code, '</form>', $start);
        $body  = substr($code, $start, $end === false ? 400 : $end - $start);

        if (!str_contains($body, 'csrf_field()') && !str_contains($body, '_token')) {
            $formsWithoutToken[] = basename(dirname($file)) . '/' . basename($file);
        }
    }
}

check('every POST form includes a CSRF token', $formsWithoutToken === [], implode(', ', array_unique($formsWithoutToken)));

echo "\n----------------------------------------\n";
echo "passed: $pass   failed: $fail\n";

exit($fail === 0 ? 0 : 1);
