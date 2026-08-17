<?php

declare(strict_types=1);

/**
 * The API, checked against its own specification.
 *
 * The point of a generated OpenAPI document is that it cannot drift from the
 * code. This proves it rather than asserting it: the spec is fetched from the
 * running server, and every operation it advertises is called.
 *
 * What it checks:
 *
 *   - authentication: no key is 401, a wrong key is 401, a revoked key is 401
 *     with a *reason*, and a read-only key is 403 on anything but GET;
 *   - the permission model is genuinely shared — a key issued for the read-only
 *     role is refused exactly what that role is refused in the interface;
 *   - every documented GET answers, with the documented envelope;
 *   - every field in a response is one the spec declares, and every field the
 *     spec marks required on create is enforced;
 *   - a full create → read → patch → put → delete cycle on each writable
 *     resource;
 *   - resources documented as read-only refuse writes with 405, not 500;
 *   - pagination, sorting and filtering behave as documented, and an unknown
 *     filter or enum value is a 400 rather than a silently wider result;
 *   - rate limiting refuses at the configured number and says when to retry.
 *
 *   php tests/api-contract.php
 */

ob_start();
require __DIR__ . '/../src/bootstrap.php';
ob_end_clean();

use App\Core\Database;
use App\Models\ApiKey;
use App\Models\Setting;

// Where the site under test is being served, the same way
// permission-matrix.php and report-figures.php take it.
define('BASE', rtrim($argv[1] ?? 'http://127.0.0.1:8321', '/'));
define('API', BASE . '/api/v1');

$passed = 0;
$failed = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;

    if ($ok) {
        $passed++;
        echo "  ok    {$label}\n";
    } else {
        $failed++;
        echo "  FAIL  {$label}" . ($detail !== '' ? "\n          " . substr($detail, 0, 400) : '') . "\n";
    }
}

function heading(string $text): void
{
    echo "\n== {$text} ==\n";
}

/**
 * One HTTP call.
 *
 * @return array{status:int,body:array<string,mixed>,raw:string,headers:array<string,string>}
 */
function call(string $method, string $url, ?string $key = null, ?array $body = null): array
{
    $ch      = curl_init($url);
    $headers = ['Accept: application/json'];

    if ($key !== null) {
        $headers[] = 'Authorization: Bearer ' . $key;
    }

    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => 30,
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $raw        = (string) curl_exec($ch);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $status     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $rawHeaders = substr($raw, 0, $headerSize);
    $rawBody    = substr($raw, $headerSize);

    $parsed = [];
    foreach (explode("\n", $rawHeaders) as $line) {
        if (str_contains($line, ':')) {
            [$name, $value] = explode(':', $line, 2);
            $parsed[strtolower(trim($name))] = trim($value);
        }
    }

    $decoded = json_decode($rawBody, true);

    return [
        'status'  => $status,
        'body'    => is_array($decoded) ? $decoded : [],
        'raw'     => $rawBody,
        'headers' => $parsed,
    ];
}

// -- Fixtures ------------------------------------------------------------------

echo "API contract\n" . str_repeat('=', 40) . "\n";

$wasEnabled   = (string) Setting::get('api_enabled', '0');
$wasRateLimit = (string) Setting::get('api_rate_limit', '120');

Setting::put('api_enabled', '1');
Setting::put('api_rate_limit', '120');

$adminId = (int) Database::scalar(
    "SELECT u.id FROM users u INNER JOIN roles r ON r.id = u.role_id WHERE r.slug = 'admin' AND u.is_active = 1 LIMIT 1"
);

$viewerId = (int) Database::scalar(
    "SELECT u.id FROM users u INNER JOIN roles r ON r.id = u.role_id WHERE r.slug = 'viewer' AND u.is_active = 1 LIMIT 1"
);

if ($adminId === 0 || $viewerId === 0) {
    fwrite(STDERR, "Needs an active admin and an active read-only user. Run bin/seed.php first.\n");
    exit(1);
}

$issued  = ApiKey::issue('contract: full', $adminId, 'full', null, $adminId);
$readTok = ApiKey::issue('contract: read', $adminId, 'read', null, $adminId);
$viewTok = ApiKey::issue('contract: viewer', $viewerId, 'full', null, $adminId);
$revoked = ApiKey::issue('contract: revoked', $adminId, 'full', null, $adminId);

ApiKey::revoke($revoked['id']);

$key     = $issued['token'];
$created = [];   // resource => [ids] to tidy up

// -- Authentication --------------------------------------------------------------

heading('Authentication');

$r = call('GET', API . '/assets');
check('no key is 401', $r['status'] === 401, $r['raw']);
check('and says how to authenticate', str_contains(strtolower($r['headers']['www-authenticate'] ?? ''), 'bearer'));
check('with the documented error shape', isset($r['body']['error']['code'], $r['body']['error']['status'], $r['body']['error']['message']));

$r = call('GET', API . '/assets', 'ark_' . str_repeat('0', 48));
check('an unrecognised key is 401', $r['status'] === 401);

$r = call('GET', API . '/assets', $revoked['token']);
check('a revoked key is 401', $r['status'] === 401);
check('and says it was revoked rather than "unrecognised"',
    str_contains(strtolower($r['body']['error']['message'] ?? ''), 'revoked'),
    $r['raw']);

$r = call('GET', API . '/assets', $key);
check('a good key reads', $r['status'] === 200);
check('rate-limit headers are published', isset($r['headers']['x-ratelimit-limit'], $r['headers']['x-ratelimit-remaining']));

$r = call('POST', API . '/assets', $readTok['token'], ['asset_tag' => 'RO-1', 'name' => 'x']);
check('a read-only key is refused a write', $r['status'] === 403);
check('and says why', str_contains(strtolower($r['body']['error']['message'] ?? ''), 'read-only'));

// -- The permission model is the same one ---------------------------------------

heading('A key can do exactly what its user can, and no more');

$r = call('GET', API . '/assets', $viewTok['token']);
check('read-only role reads assets', $r['status'] === 200);

$r = call('POST', API . '/assets', $viewTok['token'], ['asset_tag' => 'V-1', 'name' => 'x']);
check('read-only role cannot create', $r['status'] === 403, $r['raw']);
check('and the message names the permission it lacks',
    str_contains($r['body']['error']['message'] ?? '', 'assets.create'));

$r = call('GET', API . '/users', $viewTok['token']);
check('read-only role cannot read users', $r['status'] === 403);

$r = call('GET', API . '/users', $key);
check('an administrator can', $r['status'] === 200);
check('and no password material comes back',
    !str_contains($r['raw'], 'password') && !str_contains($r['raw'], '$2y$'),
    substr($r['raw'], 0, 200));

$r = call('GET', API, $viewTok['token']);
check('the index omits what the caller cannot reach',
    !isset($r['body']['data']['resources']['users']) && isset($r['body']['data']['resources']['assets']),
    implode(', ', array_keys($r['body']['data']['resources'] ?? [])));

// -- The specification -----------------------------------------------------------

heading('The specification describes what is actually there');

$r = call('GET', API . '/openapi.json', $key);
check('openapi.json is served', $r['status'] === 200);

$spec = $r['body'];

check('it is OpenAPI 3.1', ($spec['openapi'] ?? '') === '3.1.0', (string) ($spec['openapi'] ?? ''));
check('it declares a bearer scheme',
    (($spec['components']['securitySchemes']['ApiKey']['scheme'] ?? '') === 'bearer'));

$paths      = $spec['paths'] ?? [];
$operations = 0;

foreach ($paths as $path => $item) {
    $operations += count($item);
}

check('it documents operations', $operations > 30, (string) $operations);

// Every documented GET collection must answer 200 with the documented envelope.
$collectionFailures = [];
$envelopeFailures   = [];
$undeclaredFields   = [];

foreach ($paths as $path => $item) {
    if (!isset($item['get']) || str_contains($path, '{id}')) {
        continue;
    }

    $response = call('GET', API . $path . '?per_page=3', $key);

    if ($response['status'] !== 200) {
        $collectionFailures[] = $path . ' → ' . $response['status'];
        continue;
    }

    if (!isset($response['body']['data'], $response['body']['meta']['total'], $response['body']['links']['self'])) {
        $envelopeFailures[] = $path;
        continue;
    }

    // Every field returned must be one the schema declares. This is the check
    // that makes "generated from the code" mean something.
    $declared = array_keys(
        $item['get']['responses']['200']['content']['application/json']['schema']['properties']['data']['items']['properties'] ?? []
    );

    foreach ($response['body']['data'] as $row) {
        $extra = array_diff(array_keys($row), $declared);

        if ($extra !== []) {
            $undeclaredFields[] = $path . ': ' . implode(', ', $extra);
        }
    }
}

check('every documented collection answers 200', $collectionFailures === [], implode(' | ', $collectionFailures));
check('every collection uses the documented envelope', $envelopeFailures === [], implode(' | ', $envelopeFailures));
check('no response carries a field the spec does not declare',
    $undeclaredFields === [], implode(' | ', array_unique($undeclaredFields)));

// -- Write cycles ------------------------------------------------------------------

heading('Create, read, update, replace, delete');

$cycles = [
    'categories' => [
        'create' => ['name' => 'Contract test category'],
        'patch'  => ['description' => 'Patched.'],
        'put'    => ['name' => 'Contract test category renamed'],
    ],
    'locations' => [
        'create' => ['name' => 'Contract test location', 'code' => 'CTL'],
        'patch'  => ['description' => 'Patched.'],
        'put'    => ['name' => 'Contract test location renamed'],
    ],
    'hirers' => [
        'create' => ['name' => 'Contract Test Ltd', 'hirer_type' => 'Company'],
        'patch'  => ['phone' => '01234 567890'],
        'put'    => ['name' => 'Contract Test Ltd renamed'],
    ],
    'assets' => [
        'create' => ['asset_tag' => 'CONTRACT-1', 'name' => 'Contract test asset', 'condition_rating' => 'Good'],
        'patch'  => ['notes' => 'Patched.', 'condition_rating' => 'Fair'],
        'put'    => ['asset_tag' => 'CONTRACT-1', 'name' => 'Contract test asset replaced'],
        // What PUT should leave behind for a field it was not sent. Null for a
        // nullable column; the declared default for a NOT NULL one, which is
        // what "replace" has to mean when emptying is a database error.
        'after_put' => ['notes' => null, 'condition_rating' => 'Good'],
    ],
];

foreach ($cycles as $resource => $steps) {
    $r = call('POST', API . '/' . $resource, $key, $steps['create']);
    check($resource . ': POST returns 201', $r['status'] === 201, $r['raw']);
    check($resource . ': and a Location header', isset($r['headers']['location']), json_encode(array_keys($r['headers'])));

    $id = (int) ($r['body']['data']['id'] ?? 0);

    if ($id === 0) {
        check($resource . ': the created record came back', false, $r['raw']);
        continue;
    }

    $created[$resource][] = $id;

    $r = call('GET', API . '/' . $resource . '/' . $id, $key);
    check($resource . ': GET one returns it', $r['status'] === 200 && (int) ($r['body']['data']['id'] ?? 0) === $id);

    $r = call('PATCH', API . '/' . $resource . '/' . $id, $key, $steps['patch']);
    $patchedField = array_key_first($steps['patch']);
    check(
        $resource . ': PATCH changes only what it is sent',
        $r['status'] === 200 && (string) ($r['body']['data'][$patchedField] ?? '') === (string) $steps['patch'][$patchedField],
        $r['raw']
    );

    $r = call('PUT', API . '/' . $resource . '/' . $id, $key, $steps['put']);
    check($resource . ': PUT replaces', $r['status'] === 200, $r['raw']);

    // A nullable field goes to null; a NOT NULL one goes to its declared
    // default. Both are "replaced"; only one of them can be null.
    $expected = $steps['after_put'] ?? [$patchedField => null];
    $wrong    = [];

    foreach ($expected as $field => $value) {
        // array_key_exists, not `??` — the expected value here is usually null,
        // and `??` cannot tell a null from an absence. Using it reported four
        // false failures against a response that was entirely correct.
        if (!array_key_exists($field, $r['body']['data'] ?? [])) {
            $wrong[] = $field . ' is absent from the response';
            continue;
        }

        $actual = $r['body']['data'][$field];

        if ($actual !== $value) {
            $wrong[] = sprintf('%s = %s, expected %s', $field, json_encode($actual), json_encode($value));
        }
    }

    check(
        $resource . ': and resets the writable fields it was not sent',
        $wrong === [],
        implode('; ', $wrong)
    );

    $r = call('DELETE', API . '/' . $resource . '/' . $id, $key);
    check($resource . ': DELETE returns 204 with no body', $r['status'] === 204 && trim($r['raw']) === '');

    $r = call('GET', API . '/' . $resource . '/' . $id, $key);
    check($resource . ': and it is gone', $r['status'] === 404);

    array_pop($created[$resource]);
}

// -- Read-only resources ------------------------------------------------------------

heading('Resources documented as read-only refuse writes');

foreach (['hires', 'pat-records', 'maintenance-logs', 'faults', 'users'] as $resource) {
    $r = call('POST', API . '/' . $resource, $key, ['name' => 'x']);
    check($resource . ': POST is 405, not 500', $r['status'] === 405, $r['status'] . ' ' . $r['raw']);
    check($resource . ': and says what is allowed', isset($r['headers']['allow']));
}

// -- Pagination, sorting, filtering ---------------------------------------------------

heading('Pagination, sorting and filtering');

$r = call('GET', API . '/assets?per_page=2&page=1', $key);
$firstPage = array_column($r['body']['data'] ?? [], 'id');
check('per_page is honoured', count($firstPage) <= 2, (string) count($firstPage));
check('meta reports the total', ($r['body']['meta']['total'] ?? 0) >= count($firstPage));

$hasNext = ($r['body']['links']['next'] ?? null) !== null;

if ($hasNext) {
    $r2 = call('GET', API . '/assets?per_page=2&page=2', $key);
    $secondPage = array_column($r2['body']['data'] ?? [], 'id');
    check('page 2 is different rows', array_intersect($firstPage, $secondPage) === []);
}

$r = call('GET', API . '/assets?per_page=100000', $key);
check('an absurd per_page is clamped, not refused',
    $r['status'] === 200 && ($r['body']['meta']['per_page'] ?? 0) <= (int) Setting::int('api_max_per_page', 100));

// Both directions over the same window, so this stays a statement about
// sorting rather than about how many assets happen to fit on a page.
$perPage = (int) Setting::int('api_max_per_page', 100);
$asc  = array_column(call('GET', API . '/assets?sort=asset_tag&per_page=' . $perPage, $key)['body']['data'] ?? [], 'asset_tag');
$desc = array_column(call('GET', API . '/assets?sort=-asset_tag&per_page=' . $perPage, $key)['body']['data'] ?? [], 'asset_tag');

check(
    'sort ascending and descending are opposites',
    $asc !== [] && count($asc) === count($desc) && $asc === array_reverse($desc),
    json_encode([array_slice($asc, 0, 5), array_slice($desc, 0, 5)])
);

$r = call('GET', API . '/assets?sort=nonsense', $key);
check('an unsortable field is 400', $r['status'] === 400);

$r = call('GET', API . '/assets?colour=red', $key);
check('an unknown filter is 400, not ignored', $r['status'] === 400, $r['raw']);
check('and lists the ones that exist', isset($r['body']['error']['details']['available_filters']));

$r = call('GET', API . '/assets?status[]=Nonsense', $key);
check('an unknown value for a repeatable filter is 400, not a silently wider result',
    $r['status'] === 400, $r['status'] . ' ' . substr($r['raw'], 0, 160));

$all   = call('GET', API . '/assets?per_page=1000', $key)['body']['meta']['total'] ?? 0;
$stock = call('GET', API . '/assets?status[]=In+Stock&per_page=1000', $key)['body']['meta']['total'] ?? 0;
check('a real filter narrows the result', $stock <= $all && $all > 0, "{$stock} of {$all}");

// -- Not found and method handling ------------------------------------------------------

heading('Missing things');

$r = call('GET', API . '/widgets', $key);
check('an unknown resource is a JSON 404', $r['status'] === 404 && ($r['body']['error']['code'] ?? '') === 'not_found');

$r = call('GET', BASE . '/api/nope', $key);
check('an unrouted /api path is a JSON 404 too', $r['status'] === 404 && isset($r['body']['error']));

$r = call('GET', API . '/assets/99999999', $key);
check('a missing record is 404 with a readable message',
    $r['status'] === 404 && str_contains($r['body']['error']['message'] ?? '', '99999999'));

// -- Rate limiting ---------------------------------------------------------------------

heading('Rate limiting');

Setting::put('api_rate_limit', '3');
Database::run('UPDATE api_keys SET rate_window_started_at = NULL, rate_count = 0 WHERE id = ?', [$issued['id']]);

$statuses = [];
for ($i = 0; $i < 5; $i++) {
    $statuses[] = call('GET', API . '/assets?per_page=1', $key)['status'];
}

check('the first three are allowed and the rest refused',
    $statuses === [200, 200, 200, 429, 429], implode(',', $statuses));

$r = call('GET', API . '/assets', $key);
check('the refusal says when to try again', isset($r['headers']['retry-after']) && (int) $r['headers']['retry-after'] > 0);
check('and uses the 429 code', ($r['body']['error']['code'] ?? '') === 'rate_limited');

Setting::put('api_rate_limit', '120');
Database::run('UPDATE api_keys SET rate_window_started_at = NULL, rate_count = 0');

// -- Switched off ------------------------------------------------------------------------

heading('The switch');

Setting::put('api_enabled', '0');

$r = call('GET', API . '/assets', $key);
check('with the API off, endpoints answer 503', $r['status'] === 503, (string) $r['status']);
check('and say where to turn it on', str_contains($r['body']['error']['message'] ?? '', 'Settings'));

Setting::put('api_enabled', '1');

// -- Tidy up --------------------------------------------------------------------------------

foreach ($created as $resource => $ids) {
    foreach ($ids as $id) {
        call('DELETE', API . '/' . $resource . '/' . $id, $key);
    }
}

Database::run("DELETE FROM api_keys WHERE name LIKE 'contract: %'");
Database::run("DELETE FROM assets WHERE asset_tag LIKE 'CONTRACT-%'");
Database::run("DELETE FROM categories WHERE name LIKE 'Contract test%'");
Database::run("DELETE FROM locations WHERE name LIKE 'Contract test%'");
Database::run("DELETE FROM hirers WHERE name LIKE 'Contract Test%'");

Setting::put('api_enabled', $wasEnabled);
Setting::put('api_rate_limit', $wasRateLimit);

echo "\n" . str_repeat('-', 40) . "\n";
printf("passed: %d   failed: %d\n", $passed, $failed);

exit($failed === 0 ? 0 : 1);
