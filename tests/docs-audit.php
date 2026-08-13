<?php

declare(strict_types=1);

/*
 * Documentation audit. Reads the files in docs/ and proves three things:
 *
 *   1. Every link and every `#anchor` resolves, using the same anchor rule the
 *      in-app renderer uses, so Help and GitHub agree.
 *   2. Every `{{setting:key}}` token names a key App\Services\HelpSettings will
 *      substitute — a token nobody resolves is printed to the reader as-is.
 *   3. No page in the user half contains a shell command, a SQL statement or a
 *      server path. That content belongs in the Administration half, which a
 *      user with no access to the machine never has to read past.
 *
 * Static: reads files, needs no database and no running site.
 */

$root = dirname(__DIR__);
chdir($root);

require $root . '/src/Services/Markdown.php';
require $root . '/src/Services/HelpSettings.php';

use App\Services\HelpSettings;
use App\Services\Markdown;

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

/** @return string The file with fenced blocks and inline code removed. */
function withoutCode(string $source): string
{
    $source = preg_replace('/```.*?```/s', '', $source) ?? $source;

    return preg_replace('/`[^`]*`/', '', $source) ?? $source;
}

$docs = glob($root . '/docs/*.md') ?: [];
sort($docs);

echo "Documentation audit\n";
echo str_repeat('=', 40) . "\n";
echo "\n" . count($docs) . " page(s) in docs/\n";

/* --- 1. The index, and the two halves ------------------------------------- */

heading('The index describes every page');

$index = str_replace("\r\n", "\n", (string) file_get_contents($root . '/docs/README.md'));

/** @var array<string,string> slug => the index heading it sits under */
$grouped = [];
foreach (preg_split('/^## /m', $index) ?: [] as $position => $section) {
    if ($position === 0) {
        continue;
    }

    $label = trim(strtok($section, "\n") ?: '');

    if (preg_match_all('/]\(([a-z0-9-]+)\.md(?:#[^)]*)?\)/', $section, $matches) > 0) {
        foreach ($matches[1] as $slug) {
            $grouped[$slug] ??= $label;
        }
    }
}

$missing = [];
foreach ($docs as $file) {
    $slug = basename($file, '.md');

    if ($slug !== 'README' && !isset($grouped[$slug])) {
        $missing[] = $slug;
    }
}

check('every page is listed on the index', $missing === [], implode(', ', $missing));
check('the index has a user half and an Administration half',
    in_array('Using Kitwell', $grouped, true) && in_array('Administration', $grouped, true),
    implode(' | ', array_unique(array_values($grouped))));

$adminPages = array_keys($grouped, 'Administration', true);
$userPages  = array_keys(array_diff($grouped, ['Administration']));

check('Administration is the last group on the index',
    array_key_last($grouped) !== null
        && ($grouped[array_key_last($grouped)] ?? '') === 'Administration');

check('the Administration group holds the five technical pages',
    $adminPages === ['installation', 'administration', 'development', 'security', 'api'],
    implode(', ', $adminPages));

/* --- 2. No server-level content in the user half -------------------------- */

heading('The user half needs no shell, SQL or server access');

/** Patterns that mean "you are at a terminal or looking at the filesystem". */
$serverOnly = [
    'a shell command'    => '/^\s*(sudo|php bin\/|composer |mariadb |mysql |chmod |chown |crontab|systemctl|a2ensite|tar |cp |ln -s)/mi',
    'a fenced bash block' => '/```(bash|sh|shell|console)/i',
    'a fenced SQL block'  => '/```sql/i',
    'a bare SQL statement' => '/^\s*(CREATE|GRANT|INSERT INTO|UPDATE |DELETE FROM|ALTER|DROP) /m',
    'an absolute server path' => '#(?<![\w`/])/(var|etc|opt|srv|root)/#',
    'a manage.sh command' => '/manage\.sh [a-z-]+/',
    'a cron entry'        => '/cron\.d|\* \* \* \*/',
];

foreach ($userPages as $slug) {
    $source = (string) file_get_contents($root . '/docs/' . $slug . '.md');
    $hits   = [];

    foreach ($serverOnly as $label => $pattern) {
        if (preg_match($pattern, $source, $m) === 1) {
            $hits[] = $label . ': ' . trim((string) ($m[0] ?? ''));
        }
    }

    check("$slug.md", $hits === [], implode("\n", $hits));
}

heading('The Administration half is where that content lives');

$adminHasCommands = 0;
foreach ($adminPages as $slug) {
    $source = (string) file_get_contents($root . '/docs/' . $slug . '.md');

    if (preg_match('/```(bash|sh|sql)/i', $source) === 1) {
        $adminHasCommands++;
    }
}

check('at least three Administration pages carry commands', $adminHasCommands >= 3,
    "$adminHasCommands of " . count($adminPages));

/* --- 3. Setting tokens ---------------------------------------------------- */

heading('Every {{setting:…}} token is resolvable');

$known   = HelpSettings::keys();
$unknown = [];
$used    = [];

foreach (array_merge($docs, [$root . '/README.md', $root . '/INSTALL.md']) as $file) {
    $bare = withoutCode((string) file_get_contents($file));

    if (preg_match_all('/\{\{setting:([a-z0-9_.]+)\}\}/i', $bare, $matches) === 0) {
        continue;
    }

    foreach ($matches[1] as $key) {
        $used[$key] = true;

        if (!in_array(strtolower($key), $known, true)) {
            $unknown[] = basename($file) . ' → ' . $key;
        }
    }
}

check(count($used) . ' token(s) used, all on the allow-list', $unknown === [], implode("\n", $unknown));

// A token that starts its line becomes the start of an ordered list once it
// resolves to a number followed by a full stop — "120." reads as list item 120.
// Keep at least one word in front of it.
$lineStart = [];
foreach ($docs as $file) {
    $source = str_replace("\r\n", "\n", (string) file_get_contents($file));

    if (preg_match_all('/^\s*(?:\*\*)?\{\{setting:[a-z0-9_.]+\}\}/mi', $source, $m) > 0) {
        foreach ($m[0] as $hit) {
            $lineStart[] = basename($file) . ' → ' . trim($hit);
        }
    }
}

check('no token begins a line', $lineStart === [], implode("\n", $lineStart));

// The allow-list is what stops a documentation file printing an arbitrary
// settings row, so assert the obvious secrets are not on it.
$secrets = ['mail_password', 'mail_username', 'mail_host', 'reminder_recipient_user_ids'];
$leaked  = array_values(array_intersect($secrets, $known));

check('no credential is resolvable from a documentation page', $leaked === [], implode(', ', $leaked));
check('an unknown key resolves to nothing', HelpSettings::value('mail_password') === null);
check('an unknown token is left as written',
    HelpSettings::resolve('before {{setting:mail_password}} after') === 'before {{setting:mail_password}} after');

/* --- 4. Links and anchors ------------------------------------------------- */

heading('Links and anchors');

$files   = array_merge($docs, [$root . '/README.md', $root . '/INSTALL.md']);
$anchors = [];

foreach ($files as $file) {
    $source = str_replace("\r\n", "\n", (string) file_get_contents($file));
    preg_match_all('/^#{1,6}\s+(.+)$/m', $source, $m);
    $anchors[realpath($file)] = array_map([Markdown::class, 'anchor'], $m[1]);
}

$broken = [];
$links  = 0;

foreach ($files as $file) {
    $directory = dirname($file);
    $source    = str_replace("\r\n", "\n", (string) file_get_contents($file));
    preg_match_all('/\]\(([^)\s]+)\)/', $source, $m);

    foreach ($m[1] as $target) {
        $links++;

        if (preg_match('#^(https?:)?//|^mailto:#i', $target) === 1) {
            continue;
        }

        [$path, $fragment] = array_pad(explode('#', $target, 2), 2, null);
        $resolved = $path === '' ? realpath($file) : realpath($directory . '/' . $path);

        if ($resolved === false || !is_file($resolved)) {
            $broken[] = basename($file) . ' → ' . $target . ' (no such file)';
            continue;
        }

        if ($fragment === null || $fragment === '') {
            continue;
        }

        $target_anchors = $anchors[$resolved] ?? null;

        if ($target_anchors === null) {
            $s = str_replace("\r\n", "\n", (string) file_get_contents($resolved));
            preg_match_all('/^#{1,6}\s+(.+)$/m', $s, $hm);
            $target_anchors = $anchors[$resolved] = array_map([Markdown::class, 'anchor'], $hm[1]);
        }

        if (!in_array($fragment, $target_anchors, true)) {
            $broken[] = basename($file) . ' → ' . $target . ' (no such heading)';
        }
    }
}

check("$links link(s) resolve", $broken === [], implode("\n", $broken));

/* --- 5. Page shape -------------------------------------------------------- */

heading('Every page keeps the house shape');

foreach ($docs as $file) {
    $slug   = basename($file, '.md');
    $source = str_replace("\r\n", "\n", (string) file_get_contents($file));
    $faults = [];

    if (preg_match('/^# .+/m', $source) !== 1) {
        $faults[] = 'no title';
    }

    if ($slug !== 'README') {
        if (!str_contains($source, '**On this page**')) {
            $faults[] = 'no contents list';
        }

        if (!str_contains($source, '**See also:**')) {
            $faults[] = 'no See also footer';
        }

        preg_match('/\*\*On this page\*\*\n\n((?:- .*\n)+)/', $source, $toc);
        $listed = isset($toc[1]) ? substr_count($toc[1], "\n") : 0;
        $count  = preg_match_all('/^## /m', $source);

        if ($listed !== $count) {
            $faults[] = "contents lists $listed of $count section(s)";
        }
    }

    check("$slug.md", $faults === [], implode(', ', $faults));
}

/* --- Result --------------------------------------------------------------- */

echo "\n" . str_repeat('-', 40) . "\n";
echo "passed: $passed   failed: $failed\n";

exit($failed === 0 ? 0 : 1);
