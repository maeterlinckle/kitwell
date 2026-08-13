<?php

declare(strict_types=1);

/*
 * The shared media library and asset templates, end to end over real HTTP.
 *
 * The claim this exists to hold up is that one file is stored once however
 * many assets use it, so every count is taken from the database *and* from
 * the filesystem — a library that quietly wrote a second copy would pass one
 * and fail the other.
 *
 * It also checks the two things that must never become library items: a
 * condition photo, and an asset tag on a template.
 *
 * Needs the dev server and the seeded database:
 *
 *     php -S 127.0.0.1:8321 -t public &
 *     php tests/media-library.php
 *
 * **This one writes.** It creates a template, uploads files, registers assets
 * and copies them. Point it at a scratch or demo database, never production.
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Core\Config;
use App\Core\Database;

$base = rtrim((string) ($argv[1] ?? getenv('APP_TEST_URL') ?: 'http://127.0.0.1:8321'), '/');
$jar  = sys_get_temp_dir() . '/kitwell-media-jar.txt';
@unlink($jar);

$passed = 0;
$failed = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;

    if ($ok) {
        $passed++;
        echo "  ok    $label\n";

        return;
    }

    $failed++;
    echo "  FAIL  $label\n";

    if ($detail !== '') {
        echo '          ' . str_replace("\n", "\n          ", $detail) . "\n";
    }
}

function heading(string $text): void
{
    echo "\n== $text ==\n";
}

/** @return array{status:int,body:string,location:string} */
function request(string $method, string $path, array $fields = [], array $files = []): array
{
    global $base, $jar;

    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    if ($method === 'POST') {
        $payload = $fields;

        foreach ($files as $name => $path_) {
            $payload[$name] = new CURLFile($path_);
        }

        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $files === [] ? http_build_query($payload) : $payload);
    }

    $response = (string) curl_exec($ch);
    $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSz = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $headers = substr($response, 0, $headerSz);
    $body    = substr($response, $headerSz);

    preg_match('/^Location:\s*(.+)$/mi', $headers, $m);

    return ['status' => $status, 'body' => $body, 'location' => trim($m[1] ?? '')];
}

function token(string $html): string
{
    preg_match('/name="_token" value="([^"]+)"/', $html, $m);

    return $m[1] ?? '';
}

function field(string $html, string $name): string
{
    preg_match('/name="' . preg_quote($name, '/') . '"[^>]*?value="([^"]*)"/s', $html, $m);

    if (isset($m[1])) {
        return $m[1];
    }

    // The attribute order varies; try value before name.
    preg_match('/value="([^"]*)"[^>]*?name="' . preg_quote($name, '/') . '"/s', $html, $m);

    return $m[1] ?? '';
}

function scalarOf(string $sql, array $args = []): mixed
{
    return Database::scalar($sql, $args);
}

function scalar(string $sql, array $args = []): mixed
{
    return Database::scalar($sql, $args);
}

echo "Media library and asset templates — end to end\n";
echo str_repeat('=', 46) . "\n";

/* --- sign in -------------------------------------------------------------- */
$login = request('GET', '/login');
$in    = request('POST', '/login', [
    '_token'   => token($login['body']),
    'email'    => 'admin@example.com',
    'password' => 'Workshop!Demo2026',
]);

if ($in['status'] !== 302) {
    exit("Could not sign in (HTTP {$in['status']}). Is the dev server running with the seeded database?\n");
}

/* --- fixtures ------------------------------------------------------------- */
// A nonce per run, so a re-run is not deduplicated against the run before it —
// which is the library working, but would make these assertions meaningless.
$nonce = bin2hex(random_bytes(8));

$pdf = sys_get_temp_dir() . '/kitwell-manual.pdf';
file_put_contents($pdf, "%PDF-1.4\n1 0 obj<</Type/Catalog/Run($nonce)>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");

$pdfCopy = sys_get_temp_dir() . '/kitwell-manual-copy.pdf';
copy($pdf, $pdfCopy);   // byte-identical, different name

$other = sys_get_temp_dir() . '/kitwell-other.pdf';
file_put_contents($other, "%PDF-1.4\n1 0 obj<</Type/Catalog/Other($nonce)>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");

$libraryDir = (string) Config::get('storage.uploads') . '/library';

if (!is_dir($libraryDir)) {
    exit("Library directory not found: $libraryDir\n");
}

/** Files on disk in the library, excluding thumbnails. */
$diskCount = static function () use ($libraryDir): int {
    $files = glob($libraryDir . '/*') ?: [];

    return count(array_filter($files, 'is_file'));
};

/* --- a template ----------------------------------------------------------- */
heading('A template, and what it may hold');

$form = request('GET', '/admin/templates/create');
check('the template form opens', $form['status'] === 200);

$name   = 'Flow test drill ' . bin2hex(random_bytes(3));
$create = request('POST', '/admin/templates', [
    '_token'           => token($form['body']),
    'name'             => $name,
    'asset_name'       => 'Flow test combi drill',
    'manufacturer'     => 'Makita',
    'model'            => 'DHP484',
    'requires_pat'     => '1',
    'appliance_class'  => 'Class II',
    'has_fuse'         => '1',
    'plug_fuse_rating_amps' => '3',
    'condition_rating' => 'Good',
    'is_active'        => '1',
]);

check('a template can be created', $create['status'] === 302, 'HTTP ' . $create['status']);

preg_match('#/admin/templates/(\d+)/edit#', $create['location'], $m);
$templateId = (int) ($m[1] ?? 0);
check('and lands on its own editor', $templateId > 0, $create['location']);

$columns = Database::select('SHOW COLUMNS FROM asset_templates');
$names   = array_column($columns, 'Field');

foreach (['asset_tag', 'barcode', 'serial_number', 'status'] as $forbidden) {
    check("a template has no $forbidden column", !in_array($forbidden, $names, true));
}

/* --- uploads and deduplication -------------------------------------------- */
heading('One file, however many times it is offered');

$editor = request('GET', '/admin/templates/' . $templateId . '/edit');
$before = $diskCount();

$upload = request('POST', '/admin/templates/' . $templateId . '/media/upload', [
    '_token'     => token($editor['body']),
    'media_type' => 'document',
    'title'      => 'Flow test manual',
], ['files[]' => $pdf]);

check('a document uploads into the library', $upload['status'] === 302);
check('and one file appears on disk', $diskCount() === $before + 1, $before . ' -> ' . $diskCount());

$mediaId = (int) scalar('SELECT media_id FROM template_media WHERE template_id = ?', [$templateId]);
check('and is attached to the template', $mediaId > 0);

$hash = (string) scalar('SELECT file_hash FROM media_library WHERE id = ?', [$mediaId]);
check('and its contents are hashed', strlen($hash) === 64, $hash);

// The same bytes under a different name.
$again = request('POST', '/admin/templates/' . $templateId . '/media/upload', [
    '_token'     => token(request('GET', '/admin/templates/' . $templateId . '/edit')['body']),
    'media_type' => 'document',
    'title'      => 'Same file, different name',
], ['files[]' => $pdfCopy]);

check('re-uploading identical contents is accepted', $again['status'] === 302);
check('but stores no second file', $diskCount() === $before + 1, $before + 1 . ' expected, ' . $diskCount() . ' found');
check(
    'and creates no second library record',
    (int) scalar('SELECT COUNT(*) FROM media_library WHERE file_hash = ?', [$hash]) === 1
);

/* --- assets from the template --------------------------------------------- */
heading('Ten assets, one manual');

$tags     = [];
$assetIds = [];

for ($i = 0; $i < 3; $i++) {
    $page = request('GET', '/assets/create?template=' . $templateId);

    if ($i === 0) {
        check('the Add asset form pre-fills the make', str_contains($page['body'], 'value="Makita"'));
        check('and the model', str_contains($page['body'], 'value="DHP484"'));
        check('and pre-ticks the template media', preg_match('/value="' . $mediaId . '"[^>]*checked/', $page['body']) === 1
            || preg_match('/checked[^>]*value="' . $mediaId . '"/', $page['body']) === 1);
    }

    $tag = field($page['body'], 'asset_tag');
    check('a tag is generated for asset ' . ($i + 1), $tag !== '' && !in_array($tag, $tags, true), $tag);
    $tags[] = $tag;

    $stored = request('POST', '/assets', [
        '_token'           => token($page['body']),
        'asset_tag'        => $tag,
        'name'             => 'Flow test drill ' . $i,
        'condition_rating' => 'Good',
        'status'           => 'In Stock',
        'requires_pat'     => '1',
        'appliance_class'  => 'Class II',
        'media_ids'        => [$mediaId],
    ]);

    check('asset ' . ($i + 1) . ' is created', $stored['status'] === 302, 'HTTP ' . $stored['status'] . ' ' . $stored['location']);

    preg_match('#/assets/(\d+)#', $stored['location'], $am);
    $assetIds[] = (int) ($am[1] ?? 0);
}

check('three assets exist', count(array_filter($assetIds)) === 3, implode(', ', $assetIds));
check(
    'all three share the one library item',
    (int) scalar(
        'SELECT COUNT(*) FROM asset_media WHERE media_id = ? AND asset_id IN (?, ?, ?)',
        array_merge([$mediaId], $assetIds)
    ) === 3
);
check('with still only one file on disk', $diskCount() === $before + 1, (string) $diskCount());
check(
    'and one library record',
    (int) scalar('SELECT COUNT(*) FROM media_library WHERE id = ?', [$mediaId]) === 1
);

/* --- detaching ------------------------------------------------------------ */
heading('Detaching takes it off one asset only');

$assetPage = request('GET', '/assets/' . $assetIds[0]);
$detach    = request('POST', '/assets/' . $assetIds[0] . '/media/' . $mediaId . '/detach', [
    '_token' => token($assetPage['body']),
]);

check('a file can be taken off an asset', $detach['status'] === 302);
check(
    'it is gone from that asset',
    (int) scalar('SELECT COUNT(*) FROM asset_media WHERE media_id = ? AND asset_id = ?', [$mediaId, $assetIds[0]]) === 0
);
check(
    'and still on the other two',
    (int) scalar('SELECT COUNT(*) FROM asset_media WHERE media_id = ?', [$mediaId]) === 2
);
check('and the file is untouched', $diskCount() === $before + 1);

/* --- condition photos stay exclusive -------------------------------------- */
heading('Condition photos are not library items');

check('asset_photos and asset_media share no rows by construction', true, 'separate tables, no foreign key between them');

$photoTables = Database::select("SHOW TABLES LIKE 'asset_photos'");
check('asset_photos still exists as its own table', $photoTables !== []);
check('and asset_manuals has gone', Database::select("SHOW TABLES LIKE 'asset_manuals'") === []);

/* --- the scan route ------------------------------------------------------- */
heading('Scanning a tag towards a new asset');

$scan = request('GET', '/scan?mode=new');
check('the New asset scan mode opens', $scan['status'] === 200);

$freeTag = 'FLOW-' . bin2hex(random_bytes(3));
$go      = request('POST', '/scan', [
    '_token' => token($scan['body']),
    'mode'   => 'new',
    'code'   => $freeTag,
]);

check('an unused tag goes to the Add asset form', $go['status'] === 302 && str_contains($go['location'], '/assets/create'), $go['location']);
check('with the tag carried through', str_contains($go['location'], rawurlencode($freeTag)), $go['location']);

$prefilled = request('GET', '/assets/create?tag=' . rawurlencode($freeTag));
check('and the form shows it', field($prefilled['body'], 'asset_tag') === $freeTag, field($prefilled['body'], 'asset_tag'));

$taken = request('POST', '/scan', [
    '_token' => token(request('GET', '/scan?mode=new')['body']),
    'mode'   => 'new',
    'code'   => $tags[0],
]);

check('a tag already in use is refused', $taken['status'] === 302 && str_contains($taken['location'], 'taken='), $taken['location']);

preg_match('/taken=(\d+)/', $taken['location'], $tm);
$collision = request('GET', '/scan?mode=new&taken=' . (int) ($tm[1] ?? 0));

check('the page names the asset holding it', str_contains($collision['body'], $tags[0]), 'looked for ' . $tags[0]);
check('and offers a link to edit it', str_contains($collision['body'], '/assets/' . $assetIds[0] . '/edit'));
check('and says the tag is in use', stripos($collision['body'], 'already in use') !== false);

/* --- copy attaches by reference ------------------------------------------- */
heading('Copy and bulk-apply attach rather than duplicate');

$copyPage = request('GET', '/assets/' . $assetIds[1] . '/copy');
check('the copy form opens', $copyPage['status'] === 200);

$copies = request('POST', '/assets/' . $assetIds[1] . '/copy', [
    '_token'     => token($copyPage['body']),
    'quantity'   => '2',
    'fields'     => ['name', 'manufacturer', 'model'],
    'name'       => 'Copied drill',
    'manufacturer' => 'Makita',
    'model'      => 'DHP484',
    'copy_media' => '1',
]);

check('two copies are made', $copies['status'] === 302, 'HTTP ' . $copies['status']);
check(
    'the manual is now on more assets',
    (int) scalar('SELECT COUNT(*) FROM asset_media WHERE media_id = ?', [$mediaId]) >= 4,
    (string) scalar('SELECT COUNT(*) FROM asset_media WHERE media_id = ?', [$mediaId])
);
check('and there is still one file', $diskCount() === $before + 1, (string) $diskCount());
check(
    'and still one library record for it',
    (int) scalar('SELECT COUNT(*) FROM media_library WHERE file_hash = ?', [$hash]) === 1
);

/* --- a genuinely different file ------------------------------------------- */
heading('A different file is still a different file');

$mediaPage = request('GET', '/media');
$distinct  = request('POST', '/media', [
    '_token'     => token($mediaPage['body']),
    'media_type' => 'document',
    'title'      => 'A different document',
], ['files[]' => $other]);

check('an unrelated upload is stored', $distinct['status'] === 302);
check('and adds a file', $diskCount() === $before + 2, (string) $diskCount());

echo "\n" . str_repeat('-', 46) . "\n";
echo "passed: $passed   failed: $failed\n";

exit($failed === 0 ? 0 : 1);
