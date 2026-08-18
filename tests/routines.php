<?php

declare(strict_types=1);

/**
 * Maintenance routines, end to end over real HTTP.
 *
 * What this proves:
 *   - a routine can be built from several pages of steps using every field
 *     type, and required/optional is enforced by the server rather than only
 *     by the wizard;
 *   - designing a routine and carrying one out are separate rights: a
 *     Manager / Staff account can run any published routine and cannot reach
 *     the builder at all;
 *   - a routine runs against an asset both directly and through a scheduled
 *     job, and a scheduled run rolls the schedule forward exactly as the
 *     free-text form does;
 *   - editing a routine that has been used publishes a new version and leaves
 *     the old completions showing what they were actually asked;
 *   - photographs and documents captured against a step belong to that
 *     completion alone, stream back through the path guard, and appear in the
 *     generated PDF;
 *   - the PDF is a structurally valid document — every cross-reference offset
 *     is checked against the object it claims to point at;
 *   - a routine restricted to a category is offered for that category and
 *     everything nested beneath it, and refused for anything else — in the
 *     picker and by typing the URL;
 *   - a checklist routine's steps can be answered by different people in any
 *     order, each answer keeping its own name, and the run is refused a
 *     sign-off while a required step is blank;
 *   - the Routine scan target lands on an open run, a recent record, or the
 *     maintenance log page, according to what the asset actually has.
 *
 * **This test writes.** It creates routines, completions, schedules and
 * maintenance log entries. Point it at a scratch database.
 *
 * Requires the dev server and the demo data:
 *
 *   php bin/seed.php
 *   php -S 127.0.0.1:8321 -t public
 *   php tests/routines.php [http://127.0.0.1:8321]
 */

$base = rtrim((string) ($argv[1] ?? getenv('APP_TEST_URL') ?: 'http://127.0.0.1:8321'), '/');
$jar  = sys_get_temp_dir() . '/kitwell-routines-' . getmypid() . '.txt';

$passed = 0;
$failed = 0;

/** @param array<string,mixed> $fields */
function request(string $method, string $path, array $fields = [], bool $multipart = false, bool $follow = true): array
{
    global $base, $jar;

    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_FOLLOWLOCATION => $follow,
        CURLOPT_HEADER         => true,
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart ? flatten($fields) : http_build_query($fields));
    }

    $raw     = (string) curl_exec($ch);
    $status  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headers = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $url     = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);

    return [
        'status'  => $status,
        'headers' => substr($raw, 0, $headers),
        'body'    => substr($raw, $headers),
        'url'     => $url,
    ];
}

/**
 * Flatten a nested field array into the `a[b][c]` keys a multipart body needs.
 *
 * cURL accepts only a flat map when it is building a multipart body, and
 * silently stringifies anything nested — which arrives at PHP as the literal
 * word "Array" and looks exactly like a validation bug.
 *
 * @param array<string,mixed> $fields
 * @return array<string,mixed>
 */
function flatten(array $fields, string $prefix = ''): array
{
    $flat = [];

    foreach ($fields as $key => $value) {
        $name = $prefix === '' ? (string) $key : $prefix . '[' . $key . ']';

        if (is_array($value)) {
            $flat += flatten($value, $name);
            continue;
        }

        $flat[$name] = $value;
    }

    return $flat;
}

/** A fresh CSRF token, read from the page it will be posted from. */
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

function signIn(string $email): void
{
    global $jar;
    @unlink($jar);

    request('POST', '/login', [
        '_token'   => token('/login'),
        'email'    => $email,
        'password' => 'Workshop!Demo2026',
    ]);
}

/**
 * Add a step to a page and configure it in one go.
 *
 * The editor saves the whole page form and then acts on the button, so adding
 * and configuring are two round trips by design.
 */
function addStep(int $routineId, int $pageId, string $pageTitle, string $label, string $type, bool $required, string $unit = '', string $options = ''): int
{
    $editPath = '/maintenance/routines/' . $routineId . '/edit';

    request('POST', '/maintenance/routines/' . $routineId . '/pages/' . $pageId, [
        '_token' => token($editPath),
        'do'     => 'add-step',
        'title'  => $pageTitle,
    ]);

    $r = request('GET', $editPath);
    preg_match_all('#name="steps\[(\d+)\]\[label\]"#', $r['body'], $m);
    $stepId = (int) end($m[1]);

    $fields = [
        '_token' => token($editPath),
        'do'     => 'save',
        'title'  => $pageTitle,
        'steps'  => [$stepId => [
            'label'      => $label,
            'help_text'  => '',
            'field_type' => $type,
            'unit'       => $unit,
            'options'    => $options,
        ]],
    ];

    if ($required) {
        $fields['step_required'] = [$stepId => '1'];
    }

    request('POST', '/maintenance/routines/' . $routineId . '/pages/' . $pageId, $fields);

    return $stepId;
}

/**
 * Each step of the wizard as id => field type, read from the rendered form.
 *
 * @return array<int,string>
 */
function stepsOnForm(string $body): array
{
    preg_match_all(
        '#data-routine-step="(\d+)"\s+data-required="[01]"\s+data-field-type="([a-z_]+)"#',
        $body,
        $m,
        PREG_SET_ORDER
    );

    $steps = [];

    foreach ($m as [, $id, $type]) {
        $steps[(int) $id] = $type;
    }

    return $steps;
}

// A nonce so a second run does not collide with the first on the unique name,
// and so the photograph's contents differ from last time.
$nonce = substr(bin2hex(random_bytes(4)), 0, 8);

echo "Maintenance routines — " . $base . "\n\n";

// ---------------------------------------------------------------------------
echo "== Building a routine ==\n";

signIn('admin@example.com');

$name = 'Forklift daily check ' . $nonce;
$r = request('POST', '/maintenance/routines', [
    '_token'      => token('/maintenance/routines/create'),
    'name'        => $name,
    'description' => 'Carried out before the first shift of the day.',
]);
check('creating a routine lands in the editor', str_contains($r['url'], '/edit'), $r['url']);

preg_match('#/maintenance/routines/(\d+)/edit#', $r['url'], $m);
$routineId = (int) ($m[1] ?? 0);
$editPath  = '/maintenance/routines/' . $routineId . '/edit';
check('the routine has an id', $routineId > 0);

foreach (['Before starting', 'Running checks'] as $title) {
    request('POST', '/maintenance/routines/' . $routineId . '/pages', [
        '_token' => token($editPath),
        'title'  => $title,
    ]);
}

$r = request('GET', $editPath);
preg_match_all('#/pages/(\d+)"#', $r['body'], $m);
$pages = array_values(array_unique(array_map('intval', $m[1])));
check('both pages exist', count($pages) === 2, implode(', ', $pages));

// One of every field type, so nothing is only exercised by hand.
addStep($routineId, $pages[0], 'Before starting', 'Hours on the clock',           'number',        true, 'hours');
addStep($routineId, $pages[0], 'Before starting', 'Any visible damage?',          'boolean',       true);
addStep($routineId, $pages[0], 'Before starting', 'Fluid levels',                 'single_choice', true, '', "Full\nTopped up\nLow");
addStep($routineId, $pages[0], 'Before starting', 'Attachments fitted',           'multi_choice',  false, '', "Forks\nJib\nBucket");
addStep($routineId, $pages[0], 'Before starting', 'Serial on the plate',          'short_text',    false);
addStep($routineId, $pages[0], 'Before starting', 'Photograph of the data plate', 'photo',         false);
addStep($routineId, $pages[0], 'Before starting', 'Notes',                        'long_text',     false);
addStep($routineId, $pages[1], 'Running checks',  'Date of the last service',     'date',          false);
addStep($routineId, $pages[1], 'Running checks',  'Service report',               'document',      false);

$r = request('GET', $editPath);
check('nine steps configured', substr_count($r['body'], '[label]"') === 9, (string) substr_count($r['body'], '[label]"'));
check('a number step keeps its unit', str_contains($r['body'], 'value="hours"'));
check('a choice step keeps its options', str_contains($r['body'], 'Topped up'));

// ---------------------------------------------------------------------------
echo "\n== Publishing ==\n";

$r = request('GET', '/assets/1/routines');
check('an unpublished routine is not offered', !str_contains($r['body'], $name));

$r = request('POST', '/maintenance/routines/' . $routineId . '/publish', ['_token' => token($editPath)]);
check('publishing lands on the routine', str_contains($r['url'], '/maintenance/routines/' . $routineId), $r['url']);
check('version 1 is live', str_contains($r['body'], 'v1 live'));

$r = request('GET', '/maintenance/routines/' . $routineId . '/preview');
check('the preview renders the real controls, switched off', $r['status'] === 200 && substr_count($r['body'], 'disabled') >= 9);

// ---------------------------------------------------------------------------
echo "\n== Running it against an asset ==\n";

// Seeded asset 1 is retired, and every maintenance query excludes retired
// assets — so pick one that is not.
$r = request('GET', '/assets?status%5B%5D=In+Stock');
preg_match('#href="/assets/(\d+)"#', $r['body'], $m);
$assetId = (int) ($m[1] ?? 0);
check('found a live asset to run against', $assetId > 0);

$runPath = '/assets/' . $assetId . '/routines/' . $routineId . '/run';

$r = request('GET', '/assets/' . $assetId . '/routines');
check('the chooser offers the published routine', str_contains($r['body'], $name));

$r = request('GET', $runPath);
check('the wizard renders', $r['status'] === 200 && str_contains($r['body'], 'Before starting'));
check('it says which version is being followed', str_contains($r['body'], '>v1<'));

// The wizard's gating is a convenience; the refusal has to come from the server.
$r = request('POST', $runPath, [
    '_token'       => token($runPath),
    'performed_on' => date('Y-m-d'),
    'result'       => 'Completed',
]);
check('a blank required step is refused server-side', str_contains($r['body'], 'has to be answered'));
check('nothing was recorded', !str_contains($r['url'], '/maintenance/completions/'));

$photo = tempnam(sys_get_temp_dir(), 'kw') . '.jpg';
$image = imagecreatetruecolor(900, 600);
imagefill($image, 0, 0, (int) imagecolorallocate($image, 210, 225, 240));
imagestring($image, 5, 40, 40, 'DATA PLATE ' . $nonce, (int) imagecolorallocate($image, 20, 40, 70));
imagejpeg($image, $photo, 90);
imagedestroy($image);

$document = tempnam(sys_get_temp_dir(), 'kw') . '.pdf';
file_put_contents($document, "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
    . "2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
    . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\ntrailer<</Size 4/Root 1 0 R>>\n%%EOF");

$r    = request('GET', $runPath);
$form = stepsOnForm($r['body']);
check('the wizard marks every step with its type', count($form) === 9, (string) count($form));

$fields = [
    '_token'       => token($runPath),
    'performed_on' => date('Y-m-d'),
    'result'       => 'Completed',
    'notes'        => 'Washer bottle topped up.',
];

foreach ($form as $id => $type) {
    switch ($type) {
        case 'number':        $fields['step'][$id] = '1421.5'; break;
        case 'boolean':       $fields['step'][$id] = '0'; break;
        case 'single_choice': $fields['step'][$id] = 'Low'; break;
        case 'multi_choice':  $fields['step'][$id] = ['Forks', 'Jib']; break;
        case 'long_text':     $fields['step'][$id] = "Bottle low.\nRefilled."; break;
        // 'short_text' is deliberately left blank: it is optional, and a
        // record has to say plainly that a step was not answered rather than
        // leaving a gap a reader has to interpret.
        case 'date':          $fields['step'][$id] = '2026-02-14'; break;
        case 'photo':         $fields['step_file_' . $id . '[]'] = new CURLFile($photo, 'image/jpeg', 'data-plate.jpg'); break;
        case 'document':      $fields['step_file_' . $id . '[]'] = new CURLFile($document, 'application/pdf', 'service-report.pdf'); break;
    }
}

$r = request('POST', $runPath, $fields, true);
check('the completion is saved', str_contains($r['url'], '/maintenance/completions/'), $r['url']);

preg_match('#/maintenance/completions/(\d+)#', $r['url'], $m);
$completionId = (int) ($m[1] ?? 0);
$view = $r['body'];

check('the version followed is on the record', str_contains($view, 'version 1'));
check('a number carries its unit', str_contains($view, '1,421.5 hours'));
check('a yes/no answer reads back as No', str_contains($view, '>No</span>'));
check('a single choice reads back', str_contains($view, 'Low'));
check('every multi-choice answer reads back', str_contains($view, 'Forks') && str_contains($view, 'Jib'));
check('a date reads back formatted', str_contains($view, '14 Feb 2026'));
check('an unanswered optional step says so', str_contains($view, 'Not answered'));
check('the photograph is shown inline', str_contains($view, '/files/') && str_contains($view, '<img'));
check('the document is offered for download', str_contains($view, 'service-report.pdf') && str_contains($view, '?download=1'));

preg_match_all('#/maintenance/completions/' . $completionId . '/files/(\d+)#', $view, $m);
$fileIds = array_values(array_unique(array_map('intval', $m[1])));
check('both files are attached to the completion', count($fileIds) === 2, implode(', ', $fileIds));

$r = request('GET', '/maintenance/completions/' . $completionId . '/files/' . $fileIds[0]);
check('an attached file streams back through PHP', $r['status'] === 200 && strlen($r['body']) > 1000);
check('and is served with nosniff', str_contains($r['headers'], 'X-Content-Type-Options: nosniff'));

// ---------------------------------------------------------------------------
echo "\n== The maintenance entry it produced ==\n";

$r = request('GET', '/assets/' . $assetId);
check('the asset history links to the completion', str_contains($r['body'], '/maintenance/completions/' . $completionId));
check('and names the version followed', str_contains($r['body'], 'Routine v1'));

// ---------------------------------------------------------------------------
echo "\n== The document ==\n";

$r   = request('GET', '/maintenance/completions/' . $completionId . '/pdf');
$pdf = $r['body'];

check('the PDF downloads as an attachment', $r['status'] === 200 && str_contains($r['headers'], 'Content-Disposition: attachment'));
check('it is a PDF', str_starts_with($pdf, '%PDF-') && str_contains(substr($pdf, -16), '%%EOF'));
check('it declares the core fonts it uses', str_contains($pdf, '/Helvetica-Bold') && str_contains($pdf, '/WinAnsiEncoding'));
check('it embeds the photograph', str_contains($pdf, '/Subtype /Image') && str_contains($pdf, '/DCTDecode'));

// The text is compressed when zlib is there, so read it back the way a viewer
// would rather than grepping the raw bytes.
$text = '';
if (preg_match_all('/stream\n(.*?)\nendstream/s', $pdf, $sm)) {
    foreach ($sm[1] as $stream) {
        $plain = @gzuncompress($stream);
        $text .= is_string($plain) ? $plain : '';
    }
}

check('the document prints the routine name', str_contains($text, 'Forklift daily check'));
check('and the version that was followed', str_contains($text, 'Version 1'));
check('and every question it asked', str_contains($text, 'Fluid levels') && str_contains($text, 'Date of the last service'));
check('and the answers given', str_contains($text, '1,421.5 hours') && str_contains($text, 'Forks'));
check('and says where a step was left blank', str_contains($text, 'Not answered'));

// A reader trusts the cross-reference table absolutely: an offset that misses
// its object gives a file that opens on one viewer and not on another.
if (preg_match('/\nxref\n0 (\d+)\n(.*?)trailer/s', $pdf, $m)) {
    $entries = preg_split('/\R/', trim($m[2])) ?: [];
    $wrong   = 0;

    foreach ($entries as $index => $entry) {
        if ($index === 0) {
            continue;
        }

        if (!str_starts_with(substr($pdf, (int) substr(trim($entry), 0, 10), 20), $index . ' 0 obj')) {
            $wrong++;
        }
    }

    check('every cross-reference offset points at its object', $wrong === 0, $wrong . ' of ' . (count($entries) - 1) . ' wrong');
} else {
    check('every cross-reference offset points at its object', false, 'no xref table found');
}

// ---------------------------------------------------------------------------
echo "\n== Versioning ==\n";

$r = request('GET', $editPath);
check('a version that has been used cannot be edited', str_contains($r['body'], 'has been used'));
check('and the editor offers the next version instead', str_contains($r['body'], 'Start version 2'));

request('POST', '/maintenance/routines/' . $routineId . '/new-version', ['_token' => token($editPath)]);

$r = request('GET', $editPath);
check('version 2 opens as a draft', str_contains($r['body'], 'Editing draft v2'));
check('the draft is a copy of what was live', substr_count($r['body'], '[label]"') === 9);

preg_match_all('#name="steps\[(\d+)\]\[label\]"#', $r['body'], $m);
$draftStep = (int) $m[1][0];
preg_match_all('#/pages/(\d+)"#', $r['body'], $pm);
$draftPage = (int) $pm[1][0];

request('POST', '/maintenance/routines/' . $routineId . '/pages/' . $draftPage, [
    '_token'        => token($editPath),
    'do'            => 'save',
    'title'         => 'Before starting',
    'steps'         => [$draftStep => [
        'label'      => 'Hours on the clock at start of shift',
        'help_text'  => 'Read it off the dash, not the service book.',
        'field_type' => 'number',
        'unit'       => 'hours',
        'options'    => '',
    ]],
    'step_required' => [$draftStep => '1'],
]);

$r = request('GET', $runPath);
check('the draft is not what a run follows', !str_contains($r['body'], 'at start of shift'));

request('POST', '/maintenance/routines/' . $routineId . '/publish', ['_token' => token($editPath)]);

$r = request('GET', '/maintenance/routines/' . $routineId);
check('version 2 is now live', str_contains($r['body'], 'v2 live'));
check('version 1 is marked superseded', str_contains($r['body'], 'Superseded'));

$r = request('GET', '/maintenance/completions/' . $completionId);
check('the earlier completion still names version 1', str_contains($r['body'], 'version 1'));
check('and still shows the wording that was asked', str_contains($r['body'], 'Hours on the clock'));
check('not the wording that replaced it', !str_contains($r['body'], 'at start of shift'));

$r = request('GET', $runPath);
check('a new run follows version 2', str_contains($r['body'], 'at start of shift'));

// ---------------------------------------------------------------------------
echo "\n== A scheduled job that calls for a routine ==\n";

$r = request('POST', '/maintenance', [
    '_token'             => token('/maintenance/create?asset=' . $assetId),
    'asset_id'           => $assetId,
    'title'              => 'Daily forklift check ' . $nonce,
    'maintenance_type'   => 'routine',
    'frequency_interval' => 1,
    'frequency_unit'     => 'weeks',
    'next_due_date'      => date('Y-m-d'),
    'routine_id'         => $routineId,
    'assigned_to'        => '',
]);
preg_match('#/maintenance/(\d+)$#', $r['url'], $m);
$scheduleId = (int) ($m[1] ?? 0);
check('the schedule is created', $scheduleId > 0, $r['url']);
check('and says it follows a routine', str_contains($r['body'], 'follows a routine'));

$r = request('GET', '/maintenance/' . $scheduleId . '/complete');
check('completing it launches the wizard', str_contains($r['url'], '/routines/' . $routineId . '/run'), $r['url']);
check('and the wizard knows which job it satisfies', str_contains($r['url'], 'schedule=' . $scheduleId));

$scheduledRun = '/assets/' . $assetId . '/routines/' . $routineId . '/run?schedule=' . $scheduleId;
$fields = [
    '_token'       => token($scheduledRun),
    'schedule_id'  => $scheduleId,
    'performed_on' => date('Y-m-d'),
    'result'       => 'Completed',
];

foreach (stepsOnForm($r['body']) as $id => $type) {
    switch ($type) {
        case 'number':        $fields['step'][$id] = '1500'; break;
        case 'boolean':       $fields['step'][$id] = '1'; break;
        case 'single_choice': $fields['step'][$id] = 'Full'; break;
    }
}

$r = request('POST', $scheduledRun, $fields);
check('the scheduled run is recorded', str_contains($r['url'], '/maintenance/completions/'), $r['url']);
check('against version 2', str_contains($r['body'], 'version 2'));
check('and names the job it came from', str_contains($r['body'], 'Daily forklift check ' . $nonce));

$r = request('GET', '/maintenance/' . $scheduleId);
$nextDue = date('Y-m-d', strtotime('+1 week'));
check('the schedule rolled forward by its own cadence',
    str_contains($r['body'], date('j M Y', strtotime($nextDue))) || str_contains($r['body'], $nextDue), $nextDue);
check('the completion is listed against the schedule', str_contains($r['body'], '/maintenance/completions/'));

// A team-assigned job is picked up by whoever gets to it: assignment decides
// who is reminded, never who may complete.
request('POST', '/maintenance/' . $scheduleId, [
    '_token'           => token('/maintenance/' . $scheduleId . '/edit'),
    'title'            => 'Daily forklift check ' . $nonce,
    'maintenance_type' => 'routine',
    'frequency_interval' => 1,
    'frequency_unit'   => 'weeks',
    'next_due_date'    => $nextDue,
    'routine_id'       => $routineId,
    'assigned_to'      => 'team:1',
    'is_active'        => '1',
]);

signIn('manager@example.com');
$r = request('GET', '/maintenance/' . $scheduleId . '/complete');
check('any staff member can pick up a team-assigned routine',
    str_contains($r['url'], '/routines/' . $routineId . '/run'), $r['url']);

// ---------------------------------------------------------------------------
echo "\n== Who may design, and who may carry out ==\n";

$r = request('GET', '/maintenance/routines');
check('a manager can read the routine list', $r['status'] === 200);
check('and is offered no Edit button', !str_contains($r['body'], '/edit">Edit'));

$r = request('GET', '/maintenance/routines/create', [], false, false);
check('a manager cannot open the builder', $r['status'] === 403, (string) $r['status']);

$r = request('GET', $editPath, [], false, false);
check('a manager cannot open the editor', $r['status'] === 403, (string) $r['status']);

$r = request('POST', '/maintenance/routines/' . $routineId . '/publish', ['_token' => token('/maintenance/routines')], false, false);
check('a manager cannot publish', $r['status'] === 403, (string) $r['status']);

$r = request('GET', '/assets/' . $assetId . '/routines');
check('a manager can still run one', $r['status'] === 200 && str_contains($r['body'], $name));

$r = request('GET', '/maintenance/completions/' . $completionId);
check('and read a completed one', $r['status'] === 200);

signIn('viewer@example.com');
$r = request('GET', '/maintenance/routines');
check('read-only can read the routine list', $r['status'] === 200);

$r = request('GET', '/assets/' . $assetId . '/routines', [], false, false);
check('read-only cannot run one', $r['status'] === 403, (string) $r['status']);

// ---------------------------------------------------------------------------
echo "\n== Restricting a routine to a category ==\n";

signIn('admin@example.com');

// A parent and a child, so the "and everything beneath it" rule has something
// to be true of.
$parentName = 'Access equipment ' . $nonce;
$childName  = 'Podium steps ' . $nonce;

request('POST', '/admin/categories', [
    '_token' => token('/admin/categories/create'),
    'name'   => $parentName,
    'parent_id' => '',
]);

$r = request('GET', '/admin/categories');
preg_match('#/admin/categories/(\d+)/edit#', $r['body'], $m);

// The tree lists newest last only by chance, so find the two by name.
$categoryIdOf = static function (string $name): int {
    $r = request('GET', '/admin/categories/create');

    if (preg_match('#<option value="(\d+)"[^>]*>\s*' . preg_quote($name, '#') . '\s*</option>#', $r['body'], $m)) {
        return (int) $m[1];
    }

    // Nested entries are shown as "Parent → Child".
    if (preg_match('#<option value="(\d+)"[^>]*>[^<]*' . preg_quote($name, '#') . '\s*</option>#', $r['body'], $m)) {
        return (int) $m[1];
    }

    return 0;
};

$parentId = $categoryIdOf($parentName);
check('the parent category exists', $parentId > 0);

request('POST', '/admin/categories', [
    '_token'    => token('/admin/categories/create'),
    'name'      => $childName,
    'parent_id' => $parentId,
]);

$childId = $categoryIdOf($childName);
check('the child category exists', $childId > 0);

/**
 * Register an asset, optionally in a category.
 *
 * Registering rather than re-categorising something that already exists: an
 * asset built here is known to have exactly the category the test means, which
 * a partial edit of a seeded record is not.
 */
$registerAsset = static function (string $assetName, int $categoryId): int {
    // The form arrives with the next tag already generated; posting without it
    // is refused, as it should be.
    $form = request('GET', '/assets/create');

    preg_match('/name="_token" value="([a-f0-9]+)"/', $form['body'], $t);
    preg_match('#name="asset_tag"[^>]*value="([^"]+)"#', $form['body'], $tag);

    $fields = [
        '_token'           => $t[1] ?? '',
        'asset_tag'        => $tag[1] ?? '',
        'name'             => $assetName,
        'status'           => 'In Stock',
        'condition_rating' => 'Good',
    ];

    if ($categoryId > 0) {
        $fields['category_id'] = $categoryId;
    }

    $r = request('POST', '/assets', $fields);

    return preg_match('#/assets/(\d+)#', $r['url'], $m) ? (int) $m[1] : 0;
};

$inCategory  = $registerAsset('Podium step unit ' . $nonce, $childId);
$outCategory = $registerAsset('Bench vice ' . $nonce, 0);

check('an asset in the nested category', $inCategory > 0, (string) $inCategory);
check('an asset in no category at all', $outCategory > 0, (string) $outCategory);

// A routine restricted to the *parent*.
$restricted = 'Ladder inspection ' . $nonce;
$r = request('POST', '/maintenance/routines', [
    '_token'      => token('/maintenance/routines/create'),
    'name'        => $restricted,
    'description' => 'Only for access equipment.',
    'category_id' => $parentId,
]);
preg_match('#/maintenance/routines/(\d+)/edit#', $r['url'], $m);
$restrictedId = (int) ($m[1] ?? 0);
$restrictedEdit = '/maintenance/routines/' . $restrictedId . '/edit';
check('the restricted routine is created', $restrictedId > 0);

request('POST', '/maintenance/routines/' . $restrictedId . '/pages', [
    '_token' => token($restrictedEdit),
    'title'  => 'Checks',
]);

$r = request('GET', $restrictedEdit);
preg_match('#/pages/(\d+)"#', $r['body'], $m);
$restrictedPage = (int) $m[1];

addStep($restrictedId, $restrictedPage, 'Checks', 'Feet undamaged?', 'boolean', true);
request('POST', '/maintenance/routines/' . $restrictedId . '/publish', ['_token' => token($restrictedEdit)]);

$r = request('GET', '/maintenance/routines/' . $restrictedId . '/edit');
check('the editor keeps the category', str_contains($r['body'], 'value="' . $parentId . '" selected')
    || str_contains($r['body'], 'value="' . $parentId . '"' . "\n" . '                            selected'), 'category ' . $parentId);

// The picker: offered for the asset in the child category, hidden for the other.
$r = request('GET', '/assets/' . $inCategory . '/routines');
check('offered for an asset in a category nested under the restriction', str_contains($r['body'], $restricted));

$r = request('GET', '/assets/' . $outCategory . '/routines');
check('hidden for an asset outside the restriction', !str_contains($r['body'], $restricted));
check('an unrestricted routine is still offered there', str_contains($r['body'], $name));

// And by URL, which is what makes it a rule rather than a courtesy.
$r = request('GET', '/assets/' . $inCategory . '/routines/' . $restrictedId . '/run');
check('running it against an asset in the category is allowed', $r['status'] === 200);

$r = request('GET', '/assets/' . $outCategory . '/routines/' . $restrictedId . '/run', [], false, false);
check('running it against an asset outside is refused', $r['status'] === 404, (string) $r['status']);

$r = request('POST', '/assets/' . $outCategory . '/routines/' . $restrictedId . '/run', [
    '_token'       => token('/assets/' . $inCategory . '/routines/' . $restrictedId . '/run'),
    'performed_on' => date('Y-m-d'),
    'result'       => 'Completed',
], false, false);
check('and posting to it is refused too', $r['status'] === 404, (string) $r['status']);

// ---------------------------------------------------------------------------
echo "\n== Run routine instead ==\n";

$r = request('GET', '/assets/' . $inCategory . '/maintenance/log');
check('the log page offers to run a routine', str_contains($r['body'], 'Run routine instead'));
check('and the offer points at the picker', str_contains($r['body'], '/assets/' . $inCategory . '/routines"'));

/**
 * The button is offered exactly when the picker has something in it.
 *
 * Asserted as that biconditional rather than as a flat absence, because a
 * database that already holds an unrestricted routine — as any real one will —
 * makes "no routine applies" impossible to arrange from outside.
 */
$offerMatchesPicker = static function (int $assetId): bool {
    $picker = request('GET', '/assets/' . $assetId . '/routines');
    $log    = request('GET', '/assets/' . $assetId . '/maintenance/log');

    $anyOffered = !str_contains($picker['body'], 'No routines are available');

    return $anyOffered === str_contains($log['body'], 'Run routine instead');
};

check('the button tracks the picker for an asset in the category', $offerMatchesPicker($inCategory));
check('and for one outside it', $offerMatchesPicker($outCategory));

// ---------------------------------------------------------------------------
echo "\n== A checklist routine, answered out of order ==\n";

$checklistName = 'Five station build ' . $nonce;
$r = request('POST', '/maintenance/routines', [
    '_token'      => token('/maintenance/routines/create'),
    'name'        => $checklistName,
    'description' => 'Passes between stations.',
    'category_id' => '',
]);
preg_match('#/maintenance/routines/(\d+)/edit#', $r['url'], $m);
$checklistId   = (int) ($m[1] ?? 0);
$checklistEdit = '/maintenance/routines/' . $checklistId . '/edit';
check('the checklist routine is created', $checklistId > 0);

foreach (['Station one', 'Station two'] as $title) {
    request('POST', '/maintenance/routines/' . $checklistId . '/pages', [
        '_token' => token($checklistEdit),
        'title'  => $title,
    ]);
}

$r = request('GET', $checklistEdit);
preg_match_all('#/pages/(\d+)"#', $r['body'], $m);
$checklistPages = array_values(array_unique(array_map('intval', $m[1])));

addStep($checklistId, $checklistPages[0], 'Station one', 'Torque setting', 'number', true, 'Nm');
addStep($checklistId, $checklistPages[0], 'Station one', 'Anything to note', 'long_text', false);
addStep($checklistId, $checklistPages[1], 'Station two', 'Final visual check passed?', 'boolean', true);

request('POST', '/maintenance/routines/' . $checklistId . '/out-of-order', [
    '_token'             => token($checklistEdit),
    'allow_out_of_order' => '1',
]);

$r = request('GET', $checklistEdit);
check('the checklist option is saved', str_contains($r['body'], 'name="allow_out_of_order" value="1"' . "\n" . '                        checked')
    || preg_match('#name="allow_out_of_order"[^>]*checked#', $r['body']) === 1);

request('POST', '/maintenance/routines/' . $checklistId . '/publish', ['_token' => token($checklistEdit)]);

$runUrl = '/assets/' . $outCategory . '/routines/' . $checklistId . '/run';
$r = request('GET', $runUrl);
check('a checklist routine offers to be started rather than filled in', str_contains($r['body'], 'Start the run'));
check('and does not render the one-page-at-a-time wizard', !str_contains($r['body'], 'data-routine-wizard'));

$r = request('POST', '/assets/' . $outCategory . '/routines/' . $checklistId . '/start', ['_token' => token($runUrl)]);
check('starting it lands on the run', str_contains($r['url'], '/maintenance/completions/'), $r['url']);

preg_match('#/maintenance/completions/(\d+)#', $r['url'], $m);
$runId = (int) ($m[1] ?? 0);
$runPage = '/maintenance/completions/' . $runId;

check('the run is open', str_contains($r['body'], '>Open<'));
check('the contents lists every step', substr_count($r['body'], 'class="run-step') >= 3);
check('nothing is answered yet', substr_count($r['body'], 'Not started') === 3, (string) substr_count($r['body'], 'Not started'));
check('it says how many required steps remain', str_contains($r['body'], '2 required steps'));

// Signing off is refused while required steps are blank — including by posting
// straight at it.
$r = request('POST', $runPage . '/submit', [
    '_token'       => token($runPage . '/submit'),
    'performed_on' => date('Y-m-d'),
    'result'       => 'Completed',
]);
check('signing off is refused while a required step is blank', str_contains($r['body'], 'still to answer'));
check('and the run is still open', str_contains($r['body'], '>Open<'));

// Station one, as the administrator.
$r = request('GET', $runPage);
preg_match_all('#/maintenance/completions/' . $runId . '/steps/(\d+)#', $r['body'], $m);
$runSteps = array_values(array_unique(array_map('intval', $m[1])));
check('every step has its own address', count($runSteps) === 3, (string) count($runSteps));

$typeOfStep = [];
foreach ($runSteps as $stepId) {
    $r = request('GET', $runPage . '/steps/' . $stepId);
    $typeOfStep[$stepId] = preg_match('#data-field-type="([a-z_]+)"#', $r['body'], $m) ? $m[1] : '';
}

$numberStep  = (int) array_search('number', $typeOfStep, true);
$booleanStep = (int) array_search('boolean', $typeOfStep, true);
check('the step pages render the real controls', $numberStep > 0 && $booleanStep > 0);

// Deliberately out of order: the last page's step first.
$r = request('POST', $runPage . '/steps/' . $booleanStep, [
    '_token'                 => token($runPage . '/steps/' . $booleanStep),
    'step'                   => [$booleanStep => '1'],
]);
check('a step on the last page can be answered first', str_contains($r['body'], '&check;')
    || str_contains($r['body'], 'is-done'), 'expected a tick on the contents');
check('the earlier step is still outstanding', str_contains($r['body'], '1 required step'));

// Station two, as somebody else entirely.
signIn('manager@example.com');

$r = request('GET', $runPage);
check('a second person sees the run in progress', $r['status'] === 200 && str_contains($r['body'], '>Open<'));
check('and sees who did the first part', str_contains($r['body'], 'Alex Admin'));

$r = request('POST', $runPage . '/steps/' . $numberStep, [
    '_token' => token($runPage . '/steps/' . $numberStep),
    'step'   => [$numberStep => '42.5'],
]);
check('the second person can answer their own step', str_contains($r['body'], '42.5 Nm'));
check('each answer carries its own name',
    str_contains($r['body'], 'Alex Admin') && str_contains($r['body'], 'Sam Staff'));
check('the run is now ready to sign off', str_contains($r['body'], 'ready to sign off'));

// A third party signs it off.
$r = request('POST', $runPage . '/submit', [
    '_token'       => token($runPage . '/submit'),
    'performed_on' => date('Y-m-d'),
    'result'       => 'Completed',
    'notes'        => 'Built across two stations.',
]);
check('the run signs off', !str_contains($r['body'], '>Open<') && str_contains($r['body'], 'Download PDF'));
check('the record names who signed it off', str_contains($r['body'], 'Signed off by'));
check('and who started it', str_contains($r['body'], 'Started by'));
check('the per-step names survive on the record',
    str_contains($r['body'], 'Alex Admin') && str_contains($r['body'], 'Sam Staff'));

$r = request('GET', $runPage . '/pdf');
check('a checklist run produces a PDF like any other', str_starts_with($r['body'], '%PDF-'));

signIn('admin@example.com');

// ---------------------------------------------------------------------------
echo "\n== The Routine scan target ==\n";

$r = request('GET', '/scan?mode=routine');
check('the scan page offers a Routine mode', $r['status'] === 200 && str_contains($r['body'], 'Scan to work on a routine'));

$tagOf = static function (int $assetId): string {
    $r = request('GET', '/assets/' . $assetId . '/edit');

    return preg_match('#name="asset_tag"[^>]*value="([^"]+)"#', $r['body'], $m)
        ? html_entity_decode($m[1], ENT_QUOTES)
        : '';
};

$scanTag = $tagOf($outCategory);
check('found the asset tag to scan', $scanTag !== '', $scanTag);

// It has a routine completed just now, so a scan lands on that record.
$r = request('POST', '/scan', ['_token' => token('/scan?mode=routine'), 'mode' => 'routine', 'code' => $scanTag]);
check('a recent completion is shown rather than started again',
    str_contains($r['url'], '/maintenance/completions/' . $runId), $r['url']);
check('and the reason is said out loud', str_contains($r['body'], 'Check it before starting the work again'));

// Open a second run: an open one wins over a recent record.
request('POST', '/assets/' . $outCategory . '/routines/' . $checklistId . '/start', ['_token' => token($runUrl)]);
$r = request('GET', '/assets/' . $outCategory . '/routines/' . $checklistId . '/run');
preg_match('#/maintenance/completions/(\d+)#', $r['url'], $m);
$secondRun = (int) ($m[1] ?? 0);
check('a second run opens', $secondRun > 0 && $secondRun !== $runId, (string) $secondRun);

$r = request('POST', '/scan', ['_token' => token('/scan?mode=routine'), 'mode' => 'routine', 'code' => $scanTag]);
check('an open run wins over a recent record',
    str_contains($r['url'], '/maintenance/completions/' . $secondRun), $r['url']);
check('and it opens at the contents', str_contains($r['body'], '>Open<'));

// The JSON lookup agrees with the form post.
$r = request('GET', '/scan/lookup?code=' . rawurlencode($scanTag));
$json = json_decode($r['body'], true);
check('the lookup reports the same destination',
    is_array($json) && str_contains((string) ($json['routine']['url'] ?? ''), '/maintenance/completions/' . $secondRun),
    (string) ($json['routine']['url'] ?? 'missing'));
check('and says why', ($json['routine']['reason'] ?? '') === 'open');

// Discard it, and the asset falls back to its recent record.
request('POST', '/maintenance/completions/' . $secondRun . '/discard', [
    '_token' => token('/maintenance/completions/' . $secondRun),
]);

$r = request('POST', '/scan', ['_token' => token('/scan?mode=routine'), 'mode' => 'routine', 'code' => $scanTag]);
check('discarding the run falls back to the recent record',
    str_contains($r['url'], '/maintenance/completions/' . $runId), $r['url']);

// An asset with neither goes to the maintenance log page. Registered here
// rather than picked from the register, because every asset the test has
// touched so far now has a routine against it.
$untouched = $registerAsset('Never touched ' . $nonce, 0);
$freshTag  = $tagOf($untouched);

$r = request('POST', '/scan', ['_token' => token('/scan?mode=routine'), 'mode' => 'routine', 'code' => $freshTag]);
check('an asset with no recent routine lands on the maintenance log page',
    str_contains($r['url'], '/assets/' . $untouched . '/maintenance/log'), $r['url']);

// ---------------------------------------------------------------------------
echo "\n== Add asset: available to hire out ==\n";

$r = request('GET', '/assets/create');
check('the hireable box is unticked by default',
    preg_match('#name="is_hireable" value="1"\s*>#', $r['body']) === 1,
    'expected no `checked` on is_hireable');
check('its help text is a block beneath the label',
    str_contains($r['body'], 'Tick for anything that goes out to a hirer'));
@unlink($photo);
@unlink($document);
@unlink($jar);

echo "\n----------------------------------------------\n";
echo "passed: $passed   failed: $failed\n";

exit($failed === 0 ? 0 : 1);
