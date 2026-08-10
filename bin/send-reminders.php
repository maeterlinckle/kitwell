<?php

declare(strict_types=1);

/*
 * Send the scheduled reminder emails.
 *
 * Meant for cron. Install it with `sudo ./manage.sh cron-install`, or by hand:
 *
 *     0 8 * * *  cd /var/www/asset-register && php bin/send-reminders.php >/dev/null
 *
 * Once a day in the morning is the right cadence for a workshop. Running it
 * more often does not send more mail — App\Mail\EmailReminder suppresses an
 * item that has already been reminded about within `reminder_repeat_days` —
 * but there is nothing to gain from it either.
 *
 *   php bin/send-reminders.php                  every enabled reminder type
 *   php bin/send-reminders.php --type=pat       just one (pat|maintenance|hire)
 *   php bin/send-reminders.php --dry-run        report what would go out
 *   php bin/send-reminders.php --force          ignore the repeat window
 *   php bin/send-reminders.php --quiet          only print if something failed
 *
 * Exit codes: 0 all good, 1 something failed to send, 2 mail is not usable.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Mail\Mailer;
use App\Mail\Reminders;

$options = getopt('', ['type::', 'dry-run', 'force', 'quiet', 'help']);

if (isset($options['help'])) {
    echo "Usage: php bin/send-reminders.php [--type=pat|maintenance|hire] [--dry-run] [--force] [--quiet]\n";
    exit(0);
}

$dryRun = isset($options['dry-run']);
$force  = isset($options['force']);
$quiet  = isset($options['quiet']);

$types = [];
if (isset($options['type']) && $options['type'] !== false) {
    $requested = array_filter(array_map('trim', explode(',', (string) $options['type'])));
    $types     = array_values(array_intersect($requested, array_keys(Reminders::TYPES)));

    if ($types === []) {
        fwrite(STDERR, "Unknown reminder type. Use pat, maintenance or hire.\n");
        exit(2);
    }
}

/** Print unless --quiet. */
$say = static function (string $line) use ($quiet): void {
    if (!$quiet) {
        echo $line . PHP_EOL;
    }
};

$say('Asset Register — reminders' . ($dryRun ? ' (dry run)' : ''));
$say(date('Y-m-d H:i:s'));
$say('');

// A misconfigured mail setup would otherwise produce one logged failure per
// recipient per run, for ever. Stop before that happens and say why.
$problems = Mailer::problems();

if ($problems !== [] && !$dryRun) {
    fwrite(STDERR, "Email is not usable, so nothing was sent:\n");
    foreach ($problems as $problem) {
        fwrite(STDERR, '  - ' . $problem . "\n");
    }
    fwrite(STDERR, "Configure it in Settings → Email.\n");
    exit(2);
}

$reports  = Reminders::run($types, $dryRun, $force);
$failures = 0;
$sent     = 0;

foreach ($reports as $report) {
    if (!$report['enabled']) {
        $say(sprintf('  %-14s off', $report['label']));
        continue;
    }

    $say(sprintf(
        '  %-14s %d overdue, %d due within %d day(s), %d recipient(s)',
        $report['label'],
        $report['overdue_items'],
        $report['due_items'],
        $report['window_days'],
        $report['recipients']
    ));

    if ($report['note'] !== '') {
        $say('                 ' . $report['note']);
    }

    $detail = [];
    if ($report['would_send'] > 0) {
        $detail[] = $report['would_send'] . ' would be sent';
    }
    if ($report['sent'] > 0) {
        $detail[] = $report['sent'] . ' sent';
    }
    if ($report['suppressed'] > 0) {
        $detail[] = $report['suppressed'] . ' already reminded';
    }
    if ($report['no_address'] > 0) {
        $detail[] = $report['no_address'] . ' hirer(s) with no email address';
    }
    if ($report['failed'] > 0) {
        $detail[] = $report['failed'] . ' FAILED';
    }

    if ($detail !== []) {
        $say('                 ' . implode(', ', $detail));
    }

    $failures += (int) $report['failed'];
    $sent     += (int) $report['sent'];
}

$say('');

if ($failures > 0) {
    fwrite(STDERR, sprintf("%d message(s) failed to send. See Settings → Email → Log.\n", $failures));
    exit(1);
}

$say($dryRun ? 'Dry run complete — nothing was sent.' : sprintf('Done. %d message(s) sent.', $sent));
exit(0);
