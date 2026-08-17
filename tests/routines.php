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
 *     is checked against the object it claims to point at.
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

@unlink($photo);
@unlink($document);
@unlink($jar);

echo "\n----------------------------------------------\n";
echo "passed: $passed   failed: $failed\n";

exit($failed === 0 ? 0 : 1);
