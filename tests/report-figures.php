<?php

declare(strict_types=1);

/*
 * Cross-check what each report renders against the same figures taken
 * straight from the database, so a report cannot quietly disagree with the
 * data it claims to summarise.
 */

/*
 * Usage: php tests/report-figures.php [base-url]
 *
 * Read-only, but it signs in as the demo administrator, so it expects a
 * database loaded with bin/seed.php.
 */

define('BASE_URL', rtrim($argv[1] ?? 'http://127.0.0.1:8321', '/'));

$jar = sys_get_temp_dir() . '/report-figures-cookies.txt';
@unlink($jar);

function req(string $path, array $post = []): string
{
    global $jar;

    $ch = curl_init(BASE_URL . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_FOLLOWLOCATION => true,
    ]);

    if ($post !== []) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }

    $body = (string) curl_exec($ch);
    curl_close($ch);

    return $body;
}

$login = req('/login');
preg_match('/name="_token" value="([a-f0-9]+)"/', $login, $m);
req('/login', ['_token' => $m[1], 'email' => 'admin@example.com', 'password' => 'Workshop!Demo2026']);

/** Count <tr> rows in the report's tbody. */
function rowCount(string $html): int
{
    if (preg_match('#<tbody>([\s\S]*?)</tbody>#', $html, $body) !== 1) {
        return 0;
    }

    return substr_count($body[1], '<tr');
}

// Talk to the database directly for the comparison figures.
require dirname(__DIR__) . '/src/bootstrap.php';

use App\Core\Database;

$checks = [
    [
        'report' => '/reports/all-assets',
        'label'  => 'All assets (excluding retired)',
        'sql'    => "SELECT COUNT(*) FROM assets WHERE status <> 'Retired'",
    ],
    [
        'report' => '/reports/all-assets?archived=1',
        'label'  => 'All assets (including retired)',
        'sql'    => 'SELECT COUNT(*) FROM assets',
    ],
    [
        'report' => '/reports/maintenance-due?window=overdue',
        'label'  => 'Maintenance overdue',
        'sql'    => "SELECT COUNT(*) FROM maintenance_schedules s
                       JOIN assets a ON a.id = s.asset_id
                      WHERE s.is_active = 1 AND a.status <> 'Retired' AND s.next_due_date < CURDATE()",
    ],
    [
        'report' => '/reports/assets-on-hire',
        'label'  => 'Assets currently on hire',
        'sql'    => 'SELECT COUNT(*) FROM hires WHERE returned_at IS NULL',
    ],
    [
        'report' => '/reports/hires-due-back?window=all',
        'label'  => 'All open hires',
        'sql'    => 'SELECT COUNT(*) FROM hires WHERE returned_at IS NULL',
    ],
    [
        'report' => '/reports/pat-due?window=all',
        'label'  => 'Assets requiring PAT',
        'sql'    => "SELECT COUNT(*) FROM assets WHERE requires_pat = 1 AND status <> 'Retired'",
    ],
    [
        'report' => '/reports/pat-due?window=never',
        'label'  => 'Never PAT tested',
        'sql'    => "SELECT COUNT(*) FROM assets a
                      WHERE a.requires_pat = 1 AND a.status <> 'Retired'
                        AND NOT EXISTS (SELECT 1 FROM pat_records p WHERE p.asset_id = a.id)",
    ],
];

$failures = 0;

echo "\nReport                                    rendered  database\n";
echo str_repeat('-', 62) . "\n";

foreach ($checks as $check) {
    $rendered = rowCount(req($check['report']));
    $expected = (int) Database::scalar($check['sql']);
    $ok       = $rendered === $expected;

    if (!$ok) {
        $failures++;
    }

    printf("%-40s %8d  %8d  %s\n", $check['label'], $rendered, $expected, $ok ? 'ok' : 'MISMATCH');
}

// The CSV must carry exactly the same rows as the screen.
$csv      = req('/reports/all-assets?format=csv');
$csvLines = array_values(array_filter(explode("\n", trim($csv))));
$csvRows  = count($csvLines) - 1;                     // less the heading
$rendered = rowCount(req('/reports/all-assets'));

printf("%-40s %8d  %8d  %s\n", 'CSV rows match the screen', $csvRows, $rendered, $csvRows === $rendered ? 'ok' : 'MISMATCH');

if ($csvRows !== $rendered) {
    $failures++;
}

@unlink($jar);

echo "\n" . ($failures === 0 ? "All report figures agree with the database.\n" : "$failures mismatch(es).\n");

exit($failures === 0 ? 0 : 1);
