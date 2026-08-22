<?php

declare(strict_types=1);

/**
 * The fault flow, end to end, over real HTTP against a running server.
 *
 * What it proves, in the order it proves it:
 *
 *   - a responsible party can be set from the asset edit form, as a person or
 *     as a team, and never as both;
 *   - the report form refuses a submission with no photo, and one dated in the
 *     future, *before* writing anything;
 *   - a complete submission creates a fault_reports row with its photo, sets
 *     the asset's status to Faulty, and records the activity;
 *   - the responsible party is emailed immediately — the named person, or every
 *     member of the named team;
 *   - an asset with nobody responsible sends nothing, and does not error;
 *   - a second report on an already-faulty asset is kept as history rather than
 *     overwriting the first;
 *   - the dashboard count, the report, and the database agree;
 *   - the digest consolidates: one message per person listing every faulty
 *     asset of theirs, however many teams it reaches them through.
 *
 * Requires the dev server, and a mail catcher on 127.0.0.1:2525 for the email
 * assertions (they are skipped, loudly, if mail is not configured for one).
 *
 *   php -S 127.0.0.1:8321 -t public
 *   php tests/fault-flow.php
 */

// http_response_code() throws under the CLI SAPI once output has been written,
// and APP_DEBUG turns that warning into an exception. Buffer from the top.
ob_start();

require __DIR__ . '/../src/bootstrap.php';

ob_end_clean();

use App\Core\Database;
use App\Mail\Reminders;
use App\Models\Setting;

// Where the site under test is being served, the same way
// permission-matrix.php and report-figures.php take it.
define('BASE', rtrim($argv[1] ?? 'http://127.0.0.1:8321', '/'));
const MAILBOX = __DIR__ . '/../storage/test-mailbox';

// The seeder's administrator. Keep in step with bin/seed.php and
// tests/permission-matrix.php.
$admin = ['admin@example.com', 'Workshop!Demo2026'];

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
        echo "  FAIL  {$label}" . ($detail !== '' ? "\n          {$detail}" : '') . "\n";
    }
}

function heading(string $text): void
{
    echo "\n== {$text} ==\n";
}

/** A signed-in browser session. */
final class Session
{
    private string $jar;
    public int $status = 0;
    public string $body = '';
    public string $location = '';

    public function __construct(string $email, string $password)
    {
        $this->jar = sys_get_temp_dir() . '/fault-' . getmypid() . '.txt';
        @unlink($this->jar);

        $this->get('/login');
        $this->post('/login', ['_token' => $this->tokenIn($this->body), 'email' => $email, 'password' => $password]);
        $this->get('/profile');

        if (!str_contains($this->body, 'Sign out')) {
            fwrite(STDERR, "Could not sign in as {$email}. Re-seed, and make sure 2FA is off for the demo accounts.\n");
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

    /** A multipart POST, for the photo upload. */
    public function upload(string $path, array $fields, array $files): self
    {
        foreach ($files as $name => $file) {
            $fields[$name] = new CURLFile($file['path'], $file['type'], $file['name']);
        }

        return $this->send('POST', $path, $fields, true);
    }

    private function send(string $method, string $path, array $fields, bool $multipart = false): self
    {
        $ch = curl_init(BASE . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR      => $this->jar,
            CURLOPT_COOKIEFILE     => $this->jar,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => 30,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart ? $fields : http_build_query($fields));
        }

        $raw        = (string) curl_exec($ch);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers    = substr($raw, 0, $headerSize);
        $this->body = substr($raw, $headerSize);
        $this->status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->location = preg_match('/^Location:\s*(.+)$/mi', $headers, $m) === 1 ? trim($m[1]) : '';

        return $this;
    }

    public function tokenIn(string $html): string
    {
        return preg_match('/name="_token" value="([a-f0-9]+)"/', $html, $m) === 1 ? $m[1] : '';
    }

    /** Fetch a page and take its CSRF token, for a form on that page. */
    public function tokenFor(string $path): string
    {
        return $this->tokenIn($this->get($path)->body);
    }

    public function __destruct()
    {
        @unlink($this->jar);
    }
}

// -- Mailbox -----------------------------------------------------------------

function clearMailbox(): void
{
    if (!is_dir(MAILBOX)) {
        mkdir(MAILBOX, 0777, true);

        return;
    }

    foreach (glob(MAILBOX . '/*.json') ?: [] as $file) {
        @unlink($file);
    }
}

/**
 * Every message the catcher has taken, decoded.
 *
 * PHPMailer base64-encodes the parts, so the raw body is asserted against only
 * after decoding — asserting on the encoded form passes for the wrong reason.
 *
 * @return array<int,array{to:array<int,string>,subject:string,text:string}>
 */
function mailbox(): array
{
    $messages = [];

    foreach (glob(MAILBOX . '/*.json') ?: [] as $file) {
        $decoded = json_decode((string) file_get_contents($file), true);

        if (!is_array($decoded)) {
            continue;
        }

        $raw     = (string) $decoded['raw'];
        $subject = '';

        if (preg_match('/^Subject:\s*(.*)$/mi', $raw, $m) === 1) {
            $subject = trim($m[1]);

            // Long subjects are folded and may be RFC 2047 encoded.
            if (str_contains($subject, '=?')) {
                $subject = (string) iconv_mime_decode($subject, 0, 'UTF-8');
            }
        }

        $text = $raw;

        foreach (preg_split('/\r?\n\r?\n/', $raw) ?: [] as $part) {
            $candidate = base64_decode(preg_replace('/\s+/', '', $part) ?? '', true);

            if ($candidate !== false && $candidate !== '' && mb_check_encoding($candidate, 'UTF-8')) {
                $text .= "\n" . $candidate;
            }
        }

        $messages[] = [
            'to'      => array_map('strval', (array) $decoded['to']),
            'subject' => $subject,
            'text'    => $text,
        ];
    }

    return $messages;
}

function messagesTo(string $email): array
{
    return array_values(array_filter(mailbox(), static fn (array $m): bool => in_array($email, $m['to'], true)));
}

// -- A 1x1 PNG to upload as the fault photo ----------------------------------

function samplePhoto(string $name): array
{
    $path = sys_get_temp_dir() . '/' . $name;

    // A real 8x8 PNG rather than a stub: Upload::validate() sniffs the MIME
    // from the file's contents, and Image::normalise() opens it.
    $image = imagecreatetruecolor(8, 8);
    imagefilledrectangle($image, 0, 0, 7, 7, imagecolorallocate($image, 200, 30, 30));
    imagepng($image, $path);
    imagedestroy($image);

    return ['path' => $path, 'type' => 'image/png', 'name' => $name];
}

// -- Fixtures ----------------------------------------------------------------

echo "Fault flow — end to end\n";
echo str_repeat('=', 40) . "\n";

$session = new Session(...$admin);

$mailReady = (string) Setting::get('mail_enabled', '0') === '1'
    && (string) Setting::get('mail_host', '') === '127.0.0.1';

if (!$mailReady) {
    echo "\nNOTE: mail is not pointed at the local catcher, so the email assertions are skipped.\n";
    echo "      Point mail_host at 127.0.0.1:2525 with mail_encryption=none to run them.\n";
}

// Three assets: one owned by a person, one by a team, one by nobody.
//
// Nothing that is out on hire. This file forces a status on its fixtures and
// then saves them through the real edit form, and an asset with an open hire
// has no status field on that form and refuses one if it is posted anyway —
// correctly: "In Stock" and an open hire is the contradiction that guard
// exists to prevent. Picking by id alone put a hired-out asset in the middle
// of a test about responsible parties, and the failure read like a broken
// notifier.
$assetIds = array_map(
    static fn (array $r): int => (int) $r['id'],
    Database::select(
        "SELECT a.id
           FROM assets a
           LEFT JOIN hires h ON h.asset_id = a.id AND h.returned_at IS NULL
          WHERE a.status <> 'Retired' AND h.id IS NULL
          ORDER BY a.id
          LIMIT 3"
    )
);

if (count($assetIds) < 3) {
    fwrite(STDERR, "Needs at least three live assets that are not out on hire. Run bin/seed.php first.\n");
    exit(1);
}

[$personAsset, $teamAsset, $orphanAsset] = $assetIds;

// Two people, so a team digest can be shown reaching more than one.
$people = Database::select(
    "SELECT u.id, u.name, u.email
       FROM users u
       INNER JOIN roles r ON r.id = u.role_id
      WHERE u.is_active = 1 AND u.email <> '' AND r.slug IN ('admin','manager')
      ORDER BY u.id LIMIT 2"
);

if (count($people) < 2) {
    fwrite(STDERR, "Needs at least two active admin/manager accounts. Run bin/seed.php first.\n");
    exit(1);
}

$teamId = (int) Database::scalar('SELECT id FROM teams WHERE is_active = 1 ORDER BY id LIMIT 1');

if ($teamId === 0) {
    $teamId = (int) Database::insert('teams', ['name' => 'Fault test team ' . bin2hex(random_bytes(3)), 'is_active' => 1]);
}

foreach ($people as $person) {
    Database::run(
        'INSERT IGNORE INTO team_members (team_id, user_id) VALUES (?, ?)',
        [$teamId, (int) $person['id']]
    );
}

// Pin the settings this run depends on, and put them back at the end. Without
// this the whole email half of the file silently inverts if somebody has been
// clicking about in Settings — which is exactly what happened once: five
// failures that all read like a broken notifier and were a leftover checkbox.
$restoreSettings = [
    'fault_notify_immediately' => (string) Setting::get('fault_notify_immediately', '1'),
    'reminder_faulty_enabled'  => (string) Setting::get('reminder_faulty_enabled', '0'),
];

Setting::put('fault_notify_immediately', '1');

// Start from a known state: nothing faulty, no reports on the three assets.
Database::run('DELETE FROM fault_reports WHERE asset_id IN (?, ?, ?)', $assetIds);
Database::run("UPDATE assets SET status = 'In Stock' WHERE id IN (?, ?, ?)", $assetIds);
Database::run(
    'UPDATE assets SET responsible_user_id = NULL, responsible_team_id = NULL WHERE id IN (?, ?, ?)',
    $assetIds
);

/** Save an asset through the real edit form, changing only the fields given. */
function saveAsset(Session $session, int $id, array $overrides): void
{
    $html   = $session->get('/assets/' . $id . '/edit')->body;
    $asset  = \App\Models\Asset::find($id);
    $fields = [
        '_token'           => $session->tokenIn($html),
        'asset_tag'        => (string) $asset['asset_tag'],
        'name'             => (string) $asset['name'],
        'condition_rating' => (string) $asset['condition_rating'],
        'status'           => (string) $asset['status'],
        'responsible'      => \App\Models\Asset::responsibleValue($asset),
    ];

    if ((int) $asset['is_hireable'] === 1) {
        $fields['is_hireable'] = '1';
    }

    $session->post('/assets/' . $id, array_merge($fields, $overrides));
}

// -- 1. Responsible party ----------------------------------------------------

heading('Responsible party on the asset form');

saveAsset($session, $personAsset, ['responsible' => 'user:' . (int) $people[0]['id']]);
$row = Database::selectOne('SELECT responsible_user_id, responsible_team_id FROM assets WHERE id = ?', [$personAsset]);

check(
    'a person can be set as responsible',
    (int) $row['responsible_user_id'] === (int) $people[0]['id'] && $row['responsible_team_id'] === null,
    json_encode($row)
);

saveAsset($session, $teamAsset, ['responsible' => 'team:' . $teamId]);
$row = Database::selectOne('SELECT responsible_user_id, responsible_team_id FROM assets WHERE id = ?', [$teamAsset]);

check(
    'a team can be set as responsible, and clears any person',
    (int) $row['responsible_team_id'] === $teamId && $row['responsible_user_id'] === null,
    json_encode($row)
);

saveAsset($session, $personAsset, ['responsible' => 'team:' . $teamId]);
$row = Database::selectOne('SELECT responsible_user_id, responsible_team_id FROM assets WHERE id = ?', [$personAsset]);

check(
    'switching from a person to a team leaves only one of them set',
    $row['responsible_user_id'] === null && (int) $row['responsible_team_id'] === $teamId,
    json_encode($row)
);

// Put it back to the person for the rest of the run.
saveAsset($session, $personAsset, ['responsible' => 'user:' . (int) $people[0]['id']]);

saveAsset($session, $orphanAsset, ['responsible' => '']);
$row = Database::selectOne('SELECT responsible_user_id, responsible_team_id FROM assets WHERE id = ?', [$orphanAsset]);

check(
    'it can be left unset',
    $row['responsible_user_id'] === null && $row['responsible_team_id'] === null,
    json_encode($row)
);

saveAsset($session, $orphanAsset, ['responsible' => 'user:999999']);
check(
    'a person who does not exist is refused, not stored',
    str_contains($session->get('/assets/' . $orphanAsset . '/edit')->body, 'no longer has an account'),
    'expected a field error on the edit form'
);

$html = $session->get('/assets/' . $personAsset)->body;
check('the asset page names the responsible party', str_contains($html, 'Responsible party')
    && str_contains($html, (string) $people[0]['name']));

check(
    'an unassigned asset says so rather than showing a blank',
    str_contains($session->get('/assets/' . $orphanAsset)->body, 'Unassigned')
);

// -- 2. The report form refuses bad input ------------------------------------

heading('The report form');

$html = $session->get('/assets/' . $personAsset . '/faults/report')->body;
check('the form is reachable and offers all four urgencies', $session->status === 200
    && substr_count($html, 'name="urgency"') === 4);

check(
    'the form says who will be told',
    str_contains($html, 'will be emailed as soon as this is submitted')
);

check(
    'an unassigned asset warns that nobody will be told',
    str_contains(
        $session->get('/assets/' . $orphanAsset . '/faults/report')->body,
        'no email will go out'
    )
);

$before = (int) Database::scalar('SELECT COUNT(*) FROM fault_reports');

$session->post('/assets/' . $personAsset . '/faults', [
    '_token'           => $session->tokenFor('/assets/' . $personAsset . '/faults/report'),
    'description'      => 'No photo attached, so this must be refused.',
    'faulty_on'        => date('Y-m-d'),
    'urgency'          => 'High',
    'condition_rating' => 'Poor',
]);

check(
    'a report with no photo is refused',
    (int) Database::scalar('SELECT COUNT(*) FROM fault_reports') === $before
        && str_contains($session->get('/assets/' . $personAsset . '/faults/report')->body, 'at least one photo'),
    'a row was written, or no field error was shown'
);

check(
    'and the asset was not marked faulty by the refused attempt',
    (string) Database::scalar('SELECT status FROM assets WHERE id = ?', [$personAsset]) !== 'Faulty'
);

$session->upload('/assets/' . $personAsset . '/faults', [
    '_token'           => $session->tokenFor('/assets/' . $personAsset . '/faults/report'),
    'description'      => 'Dated tomorrow, which must be refused.',
    'faulty_on'        => date('Y-m-d', strtotime('+1 day')),
    'urgency'          => 'High',
    'condition_rating' => 'Poor',
], ['photos[]' => samplePhoto('fault-future.png')]);

check(
    'a fault dated in the future is refused',
    (int) Database::scalar('SELECT COUNT(*) FROM fault_reports') === $before
        && str_contains($session->get('/assets/' . $personAsset . '/faults/report')->body, 'cannot be in the future')
);

// -- 3. A real report --------------------------------------------------------

heading('Reporting a fault on an asset owned by one person');

clearMailbox();

$noticedOn = date('Y-m-d', strtotime('-3 days'));

$session->upload('/assets/' . $personAsset . '/faults', [
    '_token'           => $session->tokenFor('/assets/' . $personAsset . '/faults/report'),
    'description'      => 'Pressure switch will not cut out; the tank gets hot.',
    'faulty_on'        => $noticedOn,
    'urgency'          => 'Critical',
    'condition_rating' => 'Poor',
], ['photos[]' => samplePhoto('fault-one.png')]);

check('the form redirects back to the asset', $session->status === 302
    && str_contains($session->location, '/assets/' . $personAsset));

// Followed once, immediately, and kept. A flash message is consumed by the
// first request that renders it, so the later GETs in this file would find it
// gone — reading it here rather than there is the difference between testing
// the banner and testing the order of the assertions.
$afterReport = $session->get('/assets/' . $personAsset)->body;

$report = Database::selectOne('SELECT * FROM fault_reports WHERE asset_id = ? ORDER BY id DESC LIMIT 1', [$personAsset]);

check('a fault report was stored', $report !== null);
check('with the urgency as submitted', (string) ($report['urgency'] ?? '') === 'Critical');
check('with the back-dated faulty date, not today', (string) ($report['faulty_on'] ?? '') === $noticedOn);
check('with the condition at the time of the report', (string) ($report['condition_rating'] ?? '') === 'Poor');
check('and the reporter\'s name snapshotted', trim((string) ($report['reported_by_name'] ?? '')) !== '');

$photoRow = Database::selectOne('SELECT * FROM fault_report_photos WHERE fault_report_id = ?', [(int) $report['id']]);
check('the photo was stored against the report', $photoRow !== null);

if ($photoRow !== null) {
    $absolute = \App\Core\Upload::absolutePath((string) $photoRow['file_path']);
    check('and the file is on disk, outside the document root', $absolute !== null && is_file($absolute)
        && !str_contains(str_replace('\\', '/', $absolute), '/public/'));

    $session->get('/faults/' . (int) $report['id'] . '/photos/' . (int) $photoRow['id']);
    check('the photo streams back through the app', $session->status === 200 && strlen($session->body) > 0);

    $session->get('/faults/' . ((int) $report['id'] + 9999) . '/photos/' . (int) $photoRow['id']);
    check('a photo cannot be pulled out of a different report', $session->status === 404);
}

$asset = Database::selectOne('SELECT status, condition_rating FROM assets WHERE id = ?', [$personAsset]);
check('the asset status is now Faulty', (string) $asset['status'] === 'Faulty');
check('and its condition was carried across', (string) $asset['condition_rating'] === 'Poor');

check(
    'the activity log records it',
    (int) Database::scalar(
        "SELECT COUNT(*) FROM activity_log WHERE action = 'fault_reported' AND entity_type = 'asset' AND entity_id = ?",
        [$personAsset]
    ) > 0
);

$html = $session->get('/assets/' . $personAsset)->body;
check('the asset page shows the fault banner', str_contains($html, 'fault-banner'));
check('with the description', str_contains($html, 'Pressure switch will not cut out'));
check('and the urgency', str_contains($html, 'Critical urgency'));
check('and a link to the history', str_contains($html, '/assets/' . $personAsset . '/faults'));

// -- 4. The immediate notification -------------------------------------------

heading('Immediate notification');

if ($mailReady) {
    $mail = messagesTo((string) $people[0]['email']);

    check('the named person was emailed', count($mail) === 1, count($mail) . ' message(s)');

    if ($mail !== []) {
        check('the subject carries the urgency', str_contains($mail[0]['subject'], 'CRITICAL')
            || str_contains($mail[0]['subject'], 'Critical'), $mail[0]['subject']);
        check('the body carries the fault description', str_contains($mail[0]['text'], 'Pressure switch will not cut out'));
        check('and a link to the asset', str_contains($mail[0]['text'], '/assets/' . $personAsset));
        check('and mentions the photo', str_contains($mail[0]['text'], 'photo'));
    }

    check(
        'exactly one message went out for a single named person',
        count(mailbox()) === 1,
        count(mailbox()) . ' message(s) in the mailbox'
    );
} else {
    echo "  skip  email assertions (no catcher configured)\n";
}

check(
    'the confirmation says who was told',
    !$mailReady || str_contains($afterReport, 'Notified ' . (string) $people[0]['name']),
    'expected the flash on the page the report redirected to'
);

// -- 5. A team, and nobody ---------------------------------------------------

heading('A team is told in full; nobody set means nobody told');

clearMailbox();

$session->upload('/assets/' . $teamAsset . '/faults', [
    '_token'           => $session->tokenFor('/assets/' . $teamAsset . '/faults/report'),
    'description'      => 'Blade guard catches when lowered.',
    'faulty_on'        => date('Y-m-d'),
    'urgency'          => 'Medium',
    'condition_rating' => 'Fair',
], ['photos[]' => samplePhoto('fault-team.png')]);

check(
    'the team-owned asset is faulty',
    (string) Database::scalar('SELECT status FROM assets WHERE id = ?', [$teamAsset]) === 'Faulty'
);

if ($mailReady) {
    $recipients = [];
    foreach (mailbox() as $message) {
        $recipients = array_merge($recipients, $message['to']);
    }

    $expected = array_map(static fn (array $p): string => (string) $p['email'], $people);

    check(
        'every member of the team was emailed',
        count(array_intersect($expected, $recipients)) === count($expected),
        'expected ' . implode(', ', $expected) . ' — got ' . implode(', ', $recipients)
    );
}

clearMailbox();

$session->upload('/assets/' . $orphanAsset . '/faults', [
    '_token'           => $session->tokenFor('/assets/' . $orphanAsset . '/faults/report'),
    'description'      => 'Nobody is responsible for this one.',
    'faulty_on'        => date('Y-m-d'),
    'urgency'          => 'Low',
    'condition_rating' => 'Fair',
], ['photos[]' => samplePhoto('fault-orphan.png')]);

check(
    'an unassigned asset still records the fault',
    (int) Database::scalar('SELECT COUNT(*) FROM fault_reports WHERE asset_id = ?', [$orphanAsset]) === 1
);

check(
    'and is still marked faulty',
    (string) Database::scalar('SELECT status FROM assets WHERE id = ?', [$orphanAsset]) === 'Faulty'
);

check(
    'and the page says plainly that nobody was emailed',
    str_contains($session->get('/assets/' . $orphanAsset)->body, 'Nobody is set as responsible')
);

if ($mailReady) {
    check('and no email went out at all', mailbox() === [], count(mailbox()) . ' message(s) sent');
}

// -- 6. The immediate notification can be switched off ------------------------

heading('The immediate notification has its own switch');

$wasImmediate = (string) Setting::get('fault_notify_immediately', '1');
Setting::put('fault_notify_immediately', '0');
clearMailbox();

$session->upload('/assets/' . $teamAsset . '/faults', [
    '_token'           => $session->tokenFor('/assets/' . $teamAsset . '/faults/report'),
    'description'      => 'Reported while immediate notification is switched off.',
    'faulty_on'        => date('Y-m-d'),
    'urgency'          => 'Low',
    'condition_rating' => 'Fair',
], ['photos[]' => samplePhoto('fault-quiet.png')]);

$quiet = $session->get('/assets/' . $teamAsset)->body;

check(
    'switched off, the report is still recorded',
    (int) Database::scalar('SELECT COUNT(*) FROM fault_reports WHERE asset_id = ?', [$teamAsset]) === 2
);

check('and the page says why nothing was sent', str_contains($quiet, 'switched off in Settings'));

if ($mailReady) {
    check('and nothing was sent', mailbox() === [], count(mailbox()) . ' message(s)');
}

Setting::put('fault_notify_immediately', $wasImmediate);

// -- 7. History rather than a status flip ------------------------------------

heading('History is kept');

$session->upload('/assets/' . $personAsset . '/faults', [
    '_token'           => $session->tokenFor('/assets/' . $personAsset . '/faults/report'),
    'description'      => 'A second, different fault on the same machine.',
    'faulty_on'        => date('Y-m-d'),
    'urgency'          => 'Low',
    'condition_rating' => 'Fair',
], ['photos[]' => samplePhoto('fault-two.png')]);

check(
    'a second report is added, not substituted',
    (int) Database::scalar('SELECT COUNT(*) FROM fault_reports WHERE asset_id = ?', [$personAsset]) === 2
);

$html = $session->get('/assets/' . $personAsset . '/faults')->body;
check('the history page lists both', str_contains($html, 'Pressure switch will not cut out')
    && str_contains($html, 'A second, different fault'));

check(
    'the banner shows the later one',
    str_contains($session->get('/assets/' . $personAsset)->body, 'A second, different fault')
);

// -- 8. Dashboard and report agree -------------------------------------------

heading('Dashboard, report and database agree');

$dbCount = (int) Database::scalar("SELECT COUNT(*) FROM assets WHERE status = 'Faulty'");
$summary = \App\Models\FaultReport::summary();

check('the summary count matches the database', $summary['total'] === $dbCount, "{$summary['total']} vs {$dbCount}");

$rows = \App\Models\FaultReport::currentFaults();
check('the report returns one row per faulty asset', count($rows) === $dbCount, count($rows) . " vs {$dbCount}");

$html = $session->get('/')->body;
check('the dashboard shows a faulty tile', str_contains($html, 'Assets faulty'));
check('linking to the report', str_contains($html, '/reports/faulty-assets'));

$html = $session->get('/reports/faulty-assets')->body;
check('the report page renders', $session->status === 200 && str_contains($html, 'Faulty equipment'));

$criticalRows = \App\Models\FaultReport::currentFaults(['urgency' => 'Critical']);
check(
    'filtering by urgency narrows it',
    count($criticalRows) < count($rows) || $summary['critical'] === count($rows),
    count($criticalRows) . ' critical of ' . count($rows)
);

$ordered = array_map(static fn (array $r): string => (string) ($r['urgency'] ?? ''), $rows);
$rank    = ['Critical' => 0, 'High' => 1, 'Medium' => 2, 'Low' => 3];
$sorted  = true;

for ($i = 1, $n = count($ordered); $i < $n; $i++) {
    if (($rank[$ordered[$i - 1]] ?? 9) > ($rank[$ordered[$i]] ?? 9)) {
        $sorted = false;
        break;
    }
}

check('and the default order is most urgent first', $sorted, implode(', ', $ordered));

check(
    'an asset with no responsible party is still listed',
    in_array($orphanAsset, array_map(static fn (array $r): int => (int) $r['asset_id'], $rows), true)
);

// -- 9. The digest -----------------------------------------------------------

heading('The digest is consolidated per person');

$groups = \App\Services\FaultNotifier::digestGroups();

check('somebody has a digest to receive', $groups !== []);

$orphanInDigest = false;
foreach ($groups as $group) {
    foreach ($group['items'] as $item) {
        if ((int) $item['asset_id'] === $orphanAsset) {
            $orphanInDigest = true;
        }
    }
}

check('an asset with nobody responsible is in nobody\'s digest', !$orphanInDigest);

$firstId = (int) $people[0]['id'];
check(
    'the person responsible for two assets gets them in one group',
    isset($groups[$firstId]) && count($groups[$firstId]['items']) >= 2,
    isset($groups[$firstId]) ? count($groups[$firstId]['items']) . ' item(s)' : 'no group'
);

if ($mailReady) {
    clearMailbox();

    $wasEnabled = (string) Setting::get('reminder_faulty_enabled', '0');
    Setting::put('reminder_faulty_enabled', '1');

    Database::run("DELETE FROM email_reminders WHERE reminder_key = 'faulty_open'");

    $result = Reminders::run(['faulty'], false, true);

    $sent     = (int) $result['faulty']['sent'];
    $messages = mailbox();

    check('the digest run sends', $sent > 0, json_encode($result['faulty']));
    check(
        'one message per recipient, not one per asset',
        count($messages) === $sent && $sent <= count($groups),
        count($messages) . ' message(s) for ' . count($groups) . ' recipient(s)'
    );

    $toFirst = messagesTo((string) $people[0]['email']);
    check('the person with two faulty assets got exactly one email', count($toFirst) === 1, count($toFirst) . ' message(s)');

    if ($toFirst !== []) {
        // Taken from the digest group, not from a fresh query on
        // responsible_user_id — this person is responsible for one asset by
        // name and another through the team, and a query on the column alone
        // would only find the first. That is exactly the consolidation being
        // tested, so the expectation has to include both routes.
        $tags = array_map(
            static fn (array $i): string => (string) $i['asset_tag'],
            $groups[$firstId]['items'] ?? []
        );

        $allListed = $tags !== [];
        foreach ($tags as $tag) {
            if (!str_contains($toFirst[0]['text'], $tag)) {
                $allListed = false;
            }
        }

        check(
            'and it lists all of them, by name and through a team alike',
            $allListed && count($tags) >= 2,
            count($tags) . ' asset(s) expected: ' . implode(', ', $tags)
        );
        check('with the unassigned asset absent', !str_contains(
            $toFirst[0]['text'],
            (string) Database::scalar('SELECT asset_tag FROM assets WHERE id = ?', [$orphanAsset])
        ));
    }

    // Second run, without --force: the repeat window must suppress it.
    clearMailbox();
    $again = Reminders::run(['faulty'], false, false);

    check(
        'running it again straight away sends nothing',
        (int) $again['faulty']['sent'] === 0 && mailbox() === [],
        json_encode($again['faulty'])
    );

    check('and says so as suppressed', (int) $again['faulty']['suppressed'] > 0);

    Setting::put('reminder_faulty_enabled', $wasEnabled);
    Database::run("DELETE FROM email_reminders WHERE reminder_key = 'faulty_open'");
} else {
    echo "  skip  digest send assertions (no catcher configured)\n";
}

// -- 10. Switched off ---------------------------------------------------------

heading('The digest respects its own switch');

$wasEnabled = (string) Setting::get('reminder_faulty_enabled', '0');
Setting::put('reminder_faulty_enabled', '0');

$off = Reminders::run(['faulty'], true, true);
check('disabled means the run does nothing', $off['faulty']['enabled'] === false
    && (int) $off['faulty']['would_send'] === 0);

Setting::put('reminder_faulty_enabled', $wasEnabled);

// -- Tidy up -----------------------------------------------------------------

Database::run("UPDATE assets SET status = 'In Stock' WHERE id IN (?, ?, ?)", $assetIds);
Database::run(
    'UPDATE assets SET responsible_user_id = NULL, responsible_team_id = NULL WHERE id IN (?, ?, ?)',
    $assetIds
);

foreach ($restoreSettings as $key => $value) {
    Setting::put($key, $value);
}

echo "\n" . str_repeat('-', 40) . "\n";
printf("passed: %d   failed: %d\n", $passed, $failed);

exit($failed === 0 ? 0 : 1);
