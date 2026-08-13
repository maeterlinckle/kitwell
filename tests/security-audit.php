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

    if (preg_match('/\$router->(get|post|put|patch|delete)\(\s*\'([^\']+)\'\s*,\s*\[([^\]]+)\]\s*(?:,\s*\[([^\]]*)\])?/', $line, $m) === 1) {
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

/**
 * The REST API is deliberately outside the browser middleware, and this is the
 * one place that says why.
 *
 * `auth` redirects to a sign-in page, which is the wrong answer to a request
 * carrying an API key. `csrf` protects a browser form, which this is not. In
 * their place `App\Api\Gate::authenticate()` runs on every request and does
 * strictly more: it checks the key, its expiry, its revocation, its scope and
 * its rate limit, and then calls `Auth::actAs()` so that every later permission
 * check is the *same* `Auth::can()` the interface runs.
 *
 * The CSRF exemption is safe for a reason that has to be stated, not assumed:
 *
 *   - a write requires an API key in a request header, and a cross-site HTML
 *     form cannot set one. A cross-origin `fetch` that tried would need a CORS
 *     preflight, which this application does not answer;
 *   - a request authenticated by an ordinary **session cookie** — the thing a
 *     cross-site form *can* ride — is refused anything but GET by Gate, before
 *     it reaches a resource.
 *
 * So the attack CSRF protects against cannot be constructed here. That claim is
 * not left as prose: the checks below assert both halves of it, and
 * tests/api-contract.php exercises them over HTTP.
 */
$isApiRoute = static fn (array $route): bool => str_starts_with($route['path'], '/api/v1');

// 1. Every state-changing route must verify CSRF.
$missingCsrf = [];
foreach ($routes as $route) {
    if (in_array($route['method'], ['POST', 'PUT', 'PATCH', 'DELETE'], true)
        && !in_array('csrf', $route['middleware'], true)
        && !$isApiRoute($route)) {
        $missingCsrf[] = $route['method'] . ' ' . $route['path'];
    }
}
check('every POST/PUT/PATCH/DELETE route verifies CSRF', $missingCsrf === [], implode("\n        ", $missingCsrf));

// 1b. …and the API, which is exempt, earns it.
$gate       = (string) file_get_contents(__DIR__ . '/../src/Api/Gate.php');
$controller = (string) file_get_contents(__DIR__ . '/../src/Controllers/Api/ApiController.php');

check(
    'every API request is authenticated by Gate',
    str_contains($controller, 'Gate::authenticate()'),
    'ApiController::handle() must call it'
);

check(
    'a session-authenticated API request may only read',
    str_contains($gate, "Request::method() !== 'GET'")
        && str_contains($gate, 'may only read from the API'),
    'without this, a cross-site form post could ride the session cookie'
);

check(
    'an API key is presented in a header, never a form field or query string',
    str_contains($gate, "Request::header('Authorization')")
        && str_contains($gate, "Request::header('X-API-Key')")
        && !preg_match('/Request::(post|query)\(\s*[\'"](?:api_key|token|key)/', $gate),
    'a key in a query string ends up in access logs and browser history'
);

check(
    'API keys are stored hashed, never in clear',
    str_contains((string) file_get_contents(__DIR__ . '/../src/Models/ApiKey.php'), "hash('sha256', \$token)")
        && !str_contains((string) file_get_contents(__DIR__ . '/../src/Models/ApiKey.php'), "'token' => \$token,\n            'user_id'"),
    'the database must not be a set of working credentials'
);

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

    // The API proves who you are with a key rather than a session — see the
    // block above, and the four checks that hold it to that.
    if ($isApiRoute($route)) {
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
    // The API documentation page. An ordinary signed-in HTML page with no
    // permission of its own, deliberately: it describes an interface whose
    // every endpoint enforces its own permissions, and it reads no data — the
    // specification it renders is fetched by the browser from
    // /api/v1/openapi.json, which *is* authenticated. Gating the page would
    // mean somebody being told the API exists but not what it does.
    '/api/docs',
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
    // The documentation. Any signed-in user may read it, and it renders files
    // that ship with the source rather than anything from the register — the
    // slug is matched against a fixed pattern and resolved inside /docs, so no
    // path outside that directory is reachable.
    '/help',
    '/help/{page:[A-Za-z0-9][A-Za-z0-9-]*}',
];

$noPermission = [];
foreach ($routes as $route) {
    if (in_array($route['path'], $permissionExempt, true)) {
        continue;
    }

    // The API's permission check is per *resource*, not per route: one generic
    // controller serves eleven resources whose permissions differ, so the check
    // cannot live in the route table. `Gate::require()` runs it from
    // `ResourceController::authorise()` on every action, and the check below
    // asserts that every declared resource actually names a permission for
    // everything it offers — which is the property this rule is really after.
    if ($isApiRoute($route)) {
        continue;
    }

    if (!preg_grep('/^can(any)?:/', $route['middleware'])) {
        $noPermission[] = $route['method'] . ' ' . $route['path'];
    }
}
check('every other route carries a permission check', $noPermission === [], implode("\n        ", $noPermission));

// 3b. …and the API resources, exempt from the route rule above, each name a
//     permission for every action they offer. A resource that supported an
//     action without declaring its permission would be reachable by anyone the
//     Gate let in.
$resourceRegistry = (string) file_get_contents(__DIR__ . '/../src/Api/ResourceRegistry.php');
$resourceController = (string) file_get_contents(__DIR__ . '/../src/Controllers/Api/ResourceController.php');

check(
    'every API action runs a permission check',
    substr_count($resourceController, '$this->authorise(') >= 5,
    'index, show, store, update and destroy must each call it'
);

/**
 * Strip comments before searching for a call.
 *
 * Gate.php contains the sentence "…never `Auth::authorize()` — the latter
 * renders an HTML error page", which is exactly the right thing to have written
 * and exactly the wrong thing to match on. A grep that reads documentation as
 * code fails on the file that documents the rule best.
 */
$codeOnly = static function (string $path): string {
    $source = (string) file_get_contents($path);
    $out    = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $out .= is_array($token) ? $token[1] : $token;
    }

    return $out;
};

check(
    'the API never renders an HTML 403',
    !str_contains($codeOnly(__DIR__ . '/../src/Controllers/Api/ResourceController.php'), 'Auth::authorize(')
        && !str_contains($codeOnly(__DIR__ . '/../src/Api/Gate.php'), 'Auth::authorize(')
        && !str_contains($codeOnly(__DIR__ . '/../src/Controllers/Api/MetaController.php'), 'Auth::authorize('),
    'Auth::authorize() renders a page; the API must throw a Problem'
);

// Every `permissions:` block must name a slug for each of list/read, and any
// action a resource supports must have one — checked structurally rather than
// by eye, since there are eleven of them.
$declaredResources = substr_count($resourceRegistry, 'return new Resource(');
check(
    'every declared resource sets its list and read permissions',
    $declaredResources > 0
        && substr_count($resourceRegistry, "'list'") >= $declaredResources
        && substr_count($resourceRegistry, "'read'") >= $declaredResources,
    $declaredResources . ' resources declared'
);

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

// A file may validate for itself, or hand each upload to one of the two
// intakes that do it — which is why both are held to it just below.
$delegates = ['MediaIntake::store(', 'PhotoController::intake('];

foreach ($sourceFiles as $file) {
    $code = (string) file_get_contents($file);

    if (!str_contains($code, 'Upload::files(')) {
        continue;
    }

    $uploadCallers[] = basename($file);

    $validates = str_contains($code, 'Upload::validate(');

    foreach ($delegates as $delegate) {
        $validates = $validates || str_contains($code, $delegate);
    }

    if (!$validates) {
        $unvalidated[] = basename($file);
    }
}

check('every upload entry point validates', $unvalidated === [], implode(', ', $unvalidated));
check('uploads are handled in the expected places', count($uploadCallers) >= 4, implode(', ', $uploadCallers));

// The delegation above is only safe if the things delegated to really do
// validate, so that is asserted rather than assumed.
$intake = (string) @file_get_contents($root . '/src/Services/MediaIntake.php');
check(
    'MediaIntake validates every file it stores',
    str_contains($intake, 'Upload::validate(')
        && preg_match('/public static function store\([^)]*\).*?self::validate\(/s', $intake) === 1,
    'src/Services/MediaIntake.php'
);

$photos = (string) @file_get_contents($root . '/src/Controllers/PhotoController.php');
check(
    'PhotoController::intake validates every photo it stores',
    preg_match('/public static function intake\([^)]*\).*?Upload::validate\(/s', $photos) === 1,
    'src/Controllers/PhotoController.php'
);

// Condition photos and the evidence on PAT, fault and maintenance records are
// owned by one asset or one record. Routing any of them through the shared
// library would silently make one item's history describe another's.
$exclusive = [
    'AssetPhoto'     => 'condition photos',
    'FaultReport'    => 'fault report photos',
    'MaintenanceLog' => 'maintenance evidence',
    'PatRecord'      => 'PAT records',
];
$leaked = [];

foreach ($exclusive as $model => $what) {
    $code = (string) @file_get_contents($root . '/src/Models/' . $model . '.php');

    if (str_contains($code, 'MediaLibrary') || str_contains($code, 'asset_media')) {
        $leaked[] = $what;
    }
}

check('exclusive media never routes through the shared library', $leaked === [], implode(', ', $leaked));

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
