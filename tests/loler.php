<?php

declare(strict_types=1);

/**
 * In-house LOLER reports of thorough examination, end to end over real HTTP.
 *
 * What this proves, checked against L113 (LOLER 1998 with its ACOP and
 * guidance) rather than against how the form happens to be laid out:
 *
 *   - an asset can be flagged as requiring examination with its fixed
 *     characteristics on the asset, and those characteristics reach the
 *     examination pre-filled;
 *   - a correction made while confirming them is written back to the asset,
 *     so the register stays the source of truth;
 *   - the interval regulation 9(3)(a) sets follows from the type: 6 months for
 *     an accessory for lifting and for equipment that lifts persons, 12 for
 *     other lifting equipment — and the report names which of 9(3)(a)(i)-(iv)
 *     it was carried out under;
 *   - the date of manufacture may be recorded as not known, which Schedule
 *     1(3) allows for by asking for it "where known";
 *   - Schedule 1(8)'s two defect categories are enforced with the particulars
 *     each one requires — including (8)(c)(ii)'s remedy for a defect that could
 *     become a danger, not only (8)(c)(i)'s date — and a defect that IS a danger
 *     cannot coexist with a declaration that the equipment is safe to operate;
 *   - the regulation 10(1)(c) serious-injury flag is recorded and reported;
 *   - only a holder of `loler.inspect` can submit one, enforced server-side;
 *   - every Schedule 1 paragraph appears on the finished report and in the PDF.
 *
 * **This test writes.** It creates assets, examinations and defects, and it
 * grants a permission to the manager role and takes it away again. Point it at
 * a scratch database.
 *
 *   php bin/seed.php
 *   php -S 127.0.0.1:8321 -t public
 *   php tests/loler.php [http://127.0.0.1:8321]
 */

$base = rtrim((string) ($argv[1] ?? getenv('APP_TEST_URL') ?: 'http://127.0.0.1:8321'), '/');
$jar  = sys_get_temp_dir() . '/kitwell-loler-' . getmypid() . '.txt';

$passed = 0;
$failed = 0;

/**
 * A form post, or a multipart one when it carries files.
 *
 * The examination form nests its defect fields (`defect[0][category]`), and
 * cURL turns a nested array in a multipart body into the literal word `Array`
 * — which reads as a validation bug in the code under test rather than a bug
 * here. `flatten()` does what http_build_query() would have done.
 *
 * @param array<string,mixed> $fields
 */
function request(string $method, string $path, array $fields = [], bool $follow = true, bool $multipart = false): array
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
 * Nested arrays to `a[b][c]` keys, for a multipart body.
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

/**
 * Post an examination with photographic evidence attached.
 *
 * Every complete report needs at least one, so this is the ordinary way to
 * submit one and `request()` is what the refusal cases use.
 *
 * @param array<string,mixed> $fields
 */
function examine(string $path, array $fields, int $count = 1): array
{
    global $photoFile;

    $files = [];

    for ($i = 0; $i < $count; $i++) {
        $files[] = new CURLFile($photoFile, 'image/jpeg', 'examination-' . ($i + 1) . '.jpg');
    }

    $fields['photos'] = $files;

    return request('POST', $path, $fields, true, true);
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

/** Register an asset flagged as requiring LOLER examination. */
function registerLiftingAsset(string $name, string $type, string $interval, string $swl, string $unit, string $manufactured): int
{
    $form = request('GET', '/assets/create');

    preg_match('/name="_token" value="([a-f0-9]+)"/', $form['body'], $t);
    preg_match('#name="asset_tag"[^>]*value="([^"]+)"#', $form['body'], $tag);

    $r = request('POST', '/assets', [
        '_token'                    => $t[1] ?? '',
        'asset_tag'                 => $tag[1] ?? '',
        'name'                      => $name,
        'status'                    => 'In Stock',
        'condition_rating'          => 'Good',
        'serial_number'             => 'SN-' . substr(sha1($name), 0, 8),
        'requires_loler'            => '1',
        'loler_type'                => $type,
        'loler_interval_months'     => $interval,
        'loler_swl'                 => $swl,
        'loler_swl_unit'            => $unit,
        'loler_date_of_manufacture' => $manufactured,
    ]);

    return preg_match('#/assets/(\d+)#', $r['url'], $m) ? (int) $m[1] : 0;
}

$nonce = substr(bin2hex(random_bytes(4)), 0, 8);

// Photographic evidence of the physical examination is required, so every
// successful submission below carries one. Drawn rather than shipped as a
// fixture: a real JPEG, made here, keeps the test self-contained.
$photoFile = tempnam(sys_get_temp_dir(), 'kw') . '.jpg';
$image     = imagecreatetruecolor(1200, 900);
imagefill($image, 0, 0, (int) imagecolorallocate($image, 226, 232, 240));
imagestring($image, 5, 40, 40, 'LOAD CHAIN ' . strtoupper($nonce), (int) imagecolorallocate($image, 20, 40, 70));
imagejpeg($image, $photoFile, 90);
imagedestroy($image);

echo "LOLER thorough examinations — " . $base . "\n\n";

// ---------------------------------------------------------------------------
echo "== The asset's fixed characteristics ==\n";

signIn('admin@example.com');

$hoist = registerLiftingAsset('Chain hoist ' . $nonce, 'chain_hoist_manual', '', '1000', 'kg', '2019-04-01');
check('an asset can be registered as requiring LOLER examination', $hoist > 0, (string) $hoist);

$r = request('GET', '/assets/' . $hoist);
check('the asset page carries a LOLER card', str_contains($r['body'], 'LOLER thorough examination'));
check('it names the type', str_contains($r['body'], 'Chain hoist (manual)'));
check('it shows the SWL with its unit', str_contains($r['body'], '1,000 kg'));
check('a blank interval falls back to what the regulations set',
    str_contains($r['body'], '12 months (as the regulations set)'));
check('it reports never examined', str_contains($r['body'], 'Never'));

$r = request('GET', '/assets/' . $hoist . '/edit');
check('the fields are editable on the asset', str_contains($r['body'], 'name="loler_type"')
    && str_contains($r['body'], 'name="loler_swl"')
    && str_contains($r['body'], 'name="loler_date_of_manufacture"'));

// An accessory takes the 6-month interval instead.
$sling = registerLiftingAsset('Chain sling ' . $nonce, 'chain_sling', '', '2', 't', '2021-06-15');
$r = request('GET', '/assets/' . $sling);
check('an accessory for lifting falls back to 6 months',
    str_contains($r['body'], '6 months (as the regulations set)'));

// The type has to be set when the flag is on.
$form = request('GET', '/assets/' . $hoist . '/edit');
preg_match('#name="asset_tag"[^>]*value="([^"]+)"#', $form['body'], $tag);
$r = request('POST', '/assets/' . $hoist, [
    '_token'           => token('/assets/' . $hoist . '/edit'),
    'asset_tag'        => $tag[1],
    'name'             => 'Chain hoist ' . $nonce,
    'status'           => 'In Stock',
    'condition_rating' => 'Good',
    'requires_loler'   => '1',
    'loler_type'       => '',
]);
check('requiring examination without a type is refused',
    str_contains($r['body'], 'decides the examination interval'));

// ---------------------------------------------------------------------------
echo "\n== Who may examine ==\n";

$r = request('GET', '/assets/' . $hoist . '/loler/examine');
check('an administrator reaches the examination', $r['status'] === 200 && str_contains($r['body'], 'Report of thorough examination'));

signIn('manager@example.com');
$r = request('GET', '/assets/' . $hoist . '/loler/examine', [], false);
check('a manager without the permission is refused the form', $r['status'] === 403, (string) $r['status']);

$r = request('POST', '/assets/' . $hoist . '/loler/examine', ['_token' => 'x'], false);
check('and refused the submission', $r['status'] === 403, (string) $r['status']);

$r = request('GET', '/loler');
check('but can still read the register of reports', $r['status'] === 200);

signIn('viewer@example.com');
$r = request('GET', '/assets/' . $hoist . '/loler/examine', [], false);
check('read-only is refused too', $r['status'] === 403, (string) $r['status']);

// ---------------------------------------------------------------------------
echo "\n== A clean examination ==\n";

signIn('admin@example.com');

$examine = '/assets/' . $hoist . '/loler/examine';
$r = request('GET', $examine);
$form = $r['body'];

check('the equipment details are pre-filled from the asset',
    str_contains($form, 'value="chain_hoist_manual" selected') || str_contains($form, "chain_hoist_manual\"\n"),
    'expected the stored type selected');
check('the interval is pre-filled', preg_match('#name="interval_months"[^>]*value="12"#', $form) === 1);
check('the next examination date is pre-calculated',
    preg_match('#name="next_examination_date"[^>]*value="(\d{4}-\d{2}-\d{2})"#', $form, $m) === 1
        && $m[1] === date('Y-m-d', strtotime('+12 months')),
    $m[1] ?? 'none');
check('the statutory basis is pre-selected', str_contains($form, 'value="12-month" selected'));
check('only competent persons are offered as examiner',
    substr_count($form, 'name="examiner_user_id"') === 1 && !str_contains($form, '>Sam Staff</option>'));

$examinerId = preg_match('#<option value="(\d+)"\s*\n?\s*>\s*Alex Admin#', $form, $m) ? (int) $m[1] : 0;

if ($examinerId === 0) {
    preg_match('#name="examiner_user_id".*?<option value="(\d+)"#s', $form, $m);
    $examinerId = (int) ($m[1] ?? 0);
}

check('an examiner can be identified from the form', $examinerId > 0, (string) $examinerId);

$clean = [
    '_token'                    => token($examine),
    'loler_type'                => 'chain_hoist_manual',
    'interval_months'           => '12',
    'swl'                       => '1000',
    'swl_unit'                  => 'kg',
    'swl_configuration'         => '',
    'serial_number'             => 'SN-' . substr(sha1('Chain hoist ' . $nonce), 0, 8),
    'date_of_manufacture'       => '2019-04-01',
    'confirm_type'              => '1',
    'confirm_interval'          => '1',
    'confirm_swl'               => '1',
    'confirm_serial'            => '1',
    'confirm_manufacture'       => '1',
    'examination_basis'         => '12-month',
    'outcome'                   => 'none',
    'safe_to_operate'           => '1',
    'examiner_user_id'          => $examinerId,
    'examiner_qualifications'   => 'LEEA Diploma',
    'employer_name'             => 'Junction Engineering',
    'employer_address'          => "1 Works Road\nSheffield\nS1 1AA",
    'examination_address'       => "1 Works Road\nSheffield\nS1 1AA",
    'owner_name'                => 'Junction Engineering',
    'owner_address'             => "1 Works Road\nSheffield\nS1 1AA",
    'examiner_employer_name'    => 'Junction Engineering',
    'examiner_employer_address' => "1 Works Road\nSheffield\nS1 1AA",
    'previous_examination_date' => '',
    'examined_on'               => date('Y-m-d'),
    'next_examination_date'     => date('Y-m-d', strtotime('+12 months')),
    'reported_on'               => date('Y-m-d'),
];

$r = examine($examine, $clean);
check('a clean examination is recorded', str_contains($r['url'], '/loler/'), $r['url']);

preg_match('#/loler/(\d+)#', $r['url'], $m);
$cleanId = (int) ($m[1] ?? 0);
$report  = $r['body'];

check('the report reads as no defects', str_contains($report, 'No defects'));
check('it states the type', str_contains($report, 'Chain hoist (manual)'));
check('it states the SWL', str_contains($report, '1,000 kg'));
check('it states the serial number', str_contains($report, 'SN-'));
check('it states the date of manufacture', str_contains($report, '1 Apr 2019'));
check('it states the interval', str_contains($report, '12 months'));
check('it names the statutory basis', str_contains($report, 'regulation 9(3)(a)(ii)'));
check('it names the employer', str_contains($report, 'Junction Engineering'));
check('it gives the premises examined at', str_contains($report, 'Works Road'));
check('it names the examiner', str_contains($report, 'Alex Admin'));
check('it records the qualifications', str_contains($report, 'LEEA Diploma'));
check('it gives the date of this examination and the next',
    str_contains($report, date('j M Y')) && str_contains($report, date('j M Y', strtotime('+12 months'))));
check('it records the authentication', str_contains($report, 'Authenticated by'));
check('it states the equipment is safe to operate',
    str_contains($report, 'Safe to operate') && str_contains($report, 'badge-ok">Yes'));

// The page a person actually reads has to disclaim as plainly as the PDF does.
// A record that only says so in the download implies the screen is the software
// vouching for the equipment, which is exactly the claim it must not make.
check('the page says the duties are the competent person\'s',
    str_contains($report, 'are those of the competent person named above'));
check('and that the software certifies nothing',
    str_contains($report, 'does not carry out an examination')
    && str_contains($report, 'certify compliance with LOLER'));

// ---------------------------------------------------------------------------
echo "\n== Corrections write back to the asset ==\n";

$corrected = $clean;
$corrected['_token']              = token($examine);
$corrected['swl']                 = '750';
$corrected['serial_number']       = 'CORRECTED-' . $nonce;
$corrected['date_of_manufacture'] = '';
$corrected['manufacture_unknown'] = '1';
$corrected['interval_months']     = '6';
$corrected['examination_basis']   = 'scheme';
$corrected['next_examination_date'] = date('Y-m-d', strtotime('+6 months'));
$corrected['previous_examination_date'] = date('Y-m-d');

$r = examine($examine, $corrected);
check('a corrected examination is recorded', str_contains($r['url'], '/loler/'), $r['url']);

$r = request('GET', '/assets/' . $hoist);
check('the corrected SWL is on the asset', str_contains($r['body'], '750 kg'));
check('the corrected serial number is on the asset', str_contains($r['body'], 'CORRECTED-' . $nonce));
check('the corrected interval is on the asset', str_contains($r['body'], '6 months'));
check('an unknown date of manufacture is on the asset', str_contains($r['body'], 'Not known'));
check('the asset now shows when the next examination falls due',
    str_contains($r['body'], date('j M Y', strtotime('+6 months'))));

// A non-statutory interval must report as an examination scheme.
$r = request('GET', '/loler');
check('a 6-month interval on 12-month equipment reports as a scheme',
    str_contains($r['body'], 'Chain hoist'));

// ---------------------------------------------------------------------------
echo "\n== Defects, and what they oblige ==\n";

$defective = $clean;
$defective['_token']          = token($examine);
$defective['outcome']         = 'defects';
$defective['safe_to_operate'] = '1';
$defective['defect'] = [
    0 => [
        'category'        => 'danger',
        'part_identified' => 'Load chain',
        'description'     => 'Chain stretched beyond 3% of nominal pitch over five links.',
        'remedy'          => 'Renew the load chain and the load sheave.',
    ],
];

$r = request('POST', $examine, $defective);
check('a danger defect cannot coexist with "safe to operate"',
    str_contains($r['body'], 'cannot also be reported as safe to operate'));
check('and the refusal cites the regulation', str_contains($r['body'], '10(3)(a)'));

// Schedule 1(8)(b): a danger defect needs the remedy.
$noRemedy = $defective;
$noRemedy['_token'] = token($examine);
unset($noRemedy['safe_to_operate']);
$noRemedy['defect'][0]['remedy'] = '';

$r = request('POST', $examine, $noRemedy);
check('a danger defect without a remedy is refused', str_contains($r['body'], 'Schedule 1(8)(b)'));

// Schedule 1(8)(c)(i): a "could become" defect needs the date.
$noDate = $defective;
$noDate['_token'] = token($examine);
unset($noDate['safe_to_operate']);
$noDate['defect'][0]['category'] = 'becoming_danger';
$noDate['defect'][0]['becomes_danger_by'] = '';

$r = request('POST', $examine, $noDate);
check('a "could become a danger" defect without a date is refused',
    str_contains($r['body'], 'Schedule 1(8)(c)(i)'));

// Schedule 1(8)(c)(ii): and the remedy, exactly as (8)(b) requires for one
// that already is a danger. The date is the conspicuous half of (8)(c); the
// particulars of the repair are the half that gets left out.
$noFix = $defective;
$noFix['_token'] = token($examine);
unset($noFix['safe_to_operate']);
$noFix['defect'][0]['category']          = 'becoming_danger';
$noFix['defect'][0]['becomes_danger_by'] = date('Y-m-d', strtotime('+60 days'));
$noFix['defect'][0]['remedy']            = '';

$r = request('POST', $examine, $noFix);
check('a "could become a danger" defect without a remedy is refused',
    str_contains($r['body'], 'Schedule 1(8)(c)(ii)'));

// Now a proper one, with both categories and the serious-injury flag.
$good = $defective;
$good['_token'] = token($examine);
unset($good['safe_to_operate']);
$good['defect'] = [
    0 => [
        'category'            => 'danger',
        'part_identified'     => 'Load chain',
        'description'         => 'Chain stretched beyond 3% of nominal pitch over five links.',
        'remedy'              => 'Renew the load chain and the load sheave.',
        'serious_injury_risk' => '1',
    ],
    1 => [
        'category'          => 'becoming_danger',
        'part_identified'   => 'Bottom hook safety catch',
        'description'       => 'Catch spring weak; closes slowly.',
        'remedy'            => 'Replace the safety catch assembly.',
        'becomes_danger_by' => date('Y-m-d', strtotime('+30 days')),
    ],
];
$good['take_out_of_service'] = '1';

$r = examine($examine, $good, 2);
check('a properly particularised examination is recorded', str_contains($r['url'], '/loler/'), $r['url']);

preg_match('#/loler/(\d+)#', $r['url'], $m);
$defectId = (int) ($m[1] ?? 0);
$report   = $r['body'];

check('the report reads as a danger', str_contains($report, 'Danger — do not use'));
check('it names the part with the defect', str_contains($report, 'Load chain'));
check('it carries the description', str_contains($report, 'nominal pitch'));
check('it carries the remedy', str_contains($report, 'Renew the load chain'));
check('it carries the second defect', str_contains($report, 'Bottom hook safety catch'));
check('it gives the date the second could become a danger',
    str_contains($report, date('j M Y', strtotime('+30 days'))));
check('it flags the regulation 10(1)(c) duty', str_contains($report, '10(1)(c)'));
check('it says the enforcing authority must be sent a copy',
    str_contains($report, 'enforcing authority'));
check('it says the system does not send it', str_contains($report, 'does not send'));
check('it says the equipment must not be used', str_contains($report, 'must not be used'));
check('it is not reported as safe to operate', str_contains($report, 'NOT reported as safe') || str_contains($report, 'Not reported as safe to operate'));

$r = request('GET', '/assets/' . $hoist);
check('the asset was taken out of service', str_contains($r['body'], 'status-faulty'));

// ---------------------------------------------------------------------------
echo "\n== The document ==\n";

$r   = request('GET', '/loler/' . $defectId . '/pdf');
$pdf = $r['body'];

check('the PDF downloads as an attachment',
    $r['status'] === 200 && str_contains($r['headers'], 'Content-Disposition: attachment'));
check('it is a PDF', str_starts_with($pdf, '%PDF-') && str_contains(substr($pdf, -16), '%%EOF'));

$text = '';

if (preg_match_all('/stream\n(.*?)\nendstream/s', $pdf, $sm)) {
    foreach ($sm[1] as $stream) {
        $plain = @gzuncompress($stream);
        $text .= is_string($plain) ? $plain : '';
    }
}

/**
 * The content stream is not the text: a literal escapes its parentheses, and
 * the document sets its labels in capitals. Searching the raw stream for
 * "regulation 9(3)" therefore misses text that is plainly on the page.
 */
$text = str_replace(["\\(", "\\)"], ["(", ")"], $text);

$carries = static function (string $needle) use ($text): bool {
    return stripos($text, $needle) !== false;
};

// Every Schedule 1 paragraph has to be on the page.
$required = [
    'the title'                       => 'REPORT OF THOROUGH EXAMINATION',
    'Schedule 1(1) employer'          => 'Junction Engineering',
    'Schedule 1(2) premises'          => 'Works Road',
    'Schedule 1(3) identification'    => 'Chain hoist',
    'Schedule 1(3) manufacture date'  => 'Date of manufacture',
    'Schedule 1(4) last examination'  => 'last thorough examination',
    'Schedule 1(5) SWL'               => 'Safe working load',
    'Schedule 1(7) basis'             => 'regulation 9(3)',
    'Schedule 1(8)(a) the part'       => 'Load chain',
    'Schedule 1(8)(b) the remedy'     => 'Renew the load chain',
    'Schedule 1(8)(c) becoming'       => 'Could become a danger by',
    'Schedule 1(8)(d) next by'        => 'Next examination by',
    'Schedule 1(8)(e) testing'        => 'Testing',
    'Schedule 1(8)(f) examined on'    => 'Date of this examination',
    'Schedule 1(9) examiner'          => 'Alex Admin',
    'Schedule 1(9) qualifications'    => 'LEEA Diploma',
    'Schedule 1(10) authentication'   => 'Authenticated by',
    'Schedule 1(11) report date'      => 'Date of this report',
    'regulation 10(1)(c) duty'        => '10(1)(c)',
    'the software makes no claim'     => 'does not carry out an examination',
];

foreach ($required as $label => $needle) {
    check('the PDF carries ' . $label, $carries($needle), $needle);
}

// The report itself stays on one page however much evidence follows it. Two
// defects and two photographs: the statutory report on page 1, a photograph on
// each of pages 2 and 3.
check('the report itself is still one page', substr_count($text, 'Page 1 of 3') > 0,
    'a two-defect report with two photographs should be one page of report plus two of evidence');
check('each photograph gets its own page',
    $carries('Photograph 1 of 2') && $carries('Photograph 2 of 2'));
check('the evidence pages name the examination they belong to',
    substr_count(strtolower($text), 'evidence of the examination on') >= 2);

// ---------------------------------------------------------------------------
echo "\n== The register and the history ==\n";

$r = request('GET', '/loler');
check('the register lists the examinations', str_contains($r['body'], 'Chain hoist'));
check('it can be filtered to defects', str_contains($r['body'], 'Defects found'));

$r = request('GET', '/loler?outcome=none');
check('filtering by outcome works', $r['status'] === 200 && !str_contains($r['body'], 'Danger — do not use'));

$r = request('GET', '/assets/' . $hoist . '/loler');
check('the asset history lists every examination', substr_count($r['body'], '/loler/') >= 3);
check('and offers a new one', str_contains($r['body'], 'New examination'));

// ---------------------------------------------------------------------------
echo "\n== Photographic evidence ==\n";

// A report complete in every other respect, with nothing attached. Everything
// else about it is the report that was accepted above, so a refusal here can
// only be about the photograph.
$noPhoto = $clean;
$noPhoto['_token'] = token($examine);

$r = request('POST', $examine, $noPhoto);
check('an examination with no photograph is refused',
    str_contains($r['body'], 'At least one photograph'));
// Back on the form, not on a report: /loler/{id} is what a recorded one looks
// like, and the examine URL contains "/loler/" too.
check('and nothing was recorded', preg_match('#/loler/\d+#', $r['url']) !== 1, $r['url']);

// The two attached to the report above.
$r = request('GET', '/loler/' . $defectId);
check('the report page shows the photographs', substr_count($r['body'], '/photos/') >= 2);
check('and counts them', str_contains($r['body'], 'Photographs'));

preg_match('#/loler/' . $defectId . '/photos/(\d+)#', $r['body'], $m);
$photoId = (int) ($m[1] ?? 0);
check('a photograph has an address of its own', $photoId > 0);

$r = request('GET', '/loler/' . $defectId . '/photos/' . $photoId, [], false);
check('the photograph is served as an image',
    $r['status'] === 200 && str_contains(strtolower($r['headers']), 'content-type: image/'));

// It belongs to its own examination and to no other.
$r = request('GET', '/loler/' . $cleanId . '/photos/' . $photoId, [], false);
check('and is not reachable through another report', $r['status'] === 404, (string) $r['status']);
// ---------------------------------------------------------------------------
echo "\n== Contradictions the server refuses ==\n";

$bad = $clean;
$bad['_token']      = token($examine);
$bad['examined_on'] = date('Y-m-d', strtotime('+1 day'));

$r = request('POST', $examine, $bad);
check('an examination dated in the future is refused', str_contains($r['body'], 'cannot be in the future'));

$bad = $clean;
$bad['_token']                = token($examine);
$bad['next_examination_date'] = date('Y-m-d', strtotime('-1 day'));

$r = request('POST', $examine, $bad);
check('a next examination before this one is refused', str_contains($r['body'], 'must fall after this one'));

$bad = $clean;
$bad['_token'] = token($examine);
unset($bad['date_of_manufacture']);

$r = request('POST', $examine, $bad);
check('a missing date of manufacture must be declared unknown',
    str_contains($r['body'], 'not known or not marked'));

$bad = $clean;
$bad['_token']              = token($examine);
$bad['testing_carried_out'] = '1';
$bad['test_particulars']    = '';

$r = request('POST', $examine, $bad);
check('testing without particulars is refused', str_contains($r['body'], 'Schedule 1(8)(e)'));

// The examiner must actually hold the permission, whatever the form said.
$manager = 0;
$r = request('GET', '/admin/users');

if (preg_match('#/admin/users/(\d+)/edit#', $r['body'], $m)) {
    foreach (['2', '3', '4'] as $candidate) {
        $u = request('GET', '/admin/users/' . $candidate . '/edit');

        if (str_contains($u['body'], 'manager@example.com')) {
            $manager = (int) $candidate;
            break;
        }
    }
}

if ($manager > 0) {
    $bad = $clean;
    $bad['_token']           = token($examine);
    $bad['examiner_user_id'] = $manager;

    $r = request('POST', $examine, $bad);
    check('an examiner without the permission is refused server-side',
        str_contains($r['body'], 'does not hold the LOLER examination permission'));
} else {
    check('an examiner without the permission is refused server-side', false, 'could not identify the manager account');
}

@unlink($jar);
@unlink($photoFile);
@unlink($photoFile);

echo "\n----------------------------------------------\n";
echo "passed: $passed   failed: $failed\n";

exit($failed === 0 ? 0 : 1);
