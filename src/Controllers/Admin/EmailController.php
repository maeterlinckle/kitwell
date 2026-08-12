<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Crypto;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Mail\EmailLog;
use App\Mail\EmailReminder;
use App\Mail\EmailTemplate;
use App\Mail\Mailer;
use App\Mail\Merge;
use App\Mail\Reminders;
use App\Models\ActivityLog;
use App\Models\Setting;
use App\Models\User;

/**
 * Settings → Email: the SMTP connection, the reminder schedule, the templates
 * and the send log.
 *
 * All four live under one nav entry rather than four, because the whole point
 * of the stage 11 navigation work was that Settings is a place you visit
 * rarely and set up once.
 */
final class EmailController extends Controller
{
    // -- SMTP ---------------------------------------------------------------

    public function index(): void
    {
        $this->view('admin/email/index', [
            'pageTitle'      => 'Email',
            'section'        => 'smtp',
            'settings'       => Setting::all(),
            'problems'       => Mailer::problems(),
            'ready'          => Mailer::isReady(),
            'passwordSource' => Mailer::passwordSource(),
            'libraryOk'      => Mailer::libraryInstalled(),
            'cryptoOk'       => Crypto::isAvailable() && Crypto::hasKey(),
            'encryptions'    => Mailer::ENCRYPTIONS,
            'logSummary'     => EmailLog::summary(),
        ]);
    }

    public function update(): void
    {
        $data = $this->validate([
            'mail_host'         => 'max:191',
            'mail_port'         => 'required|integer|min_value:1|max_value:65535',
            'mail_encryption'   => 'required|in:tls,ssl,none',
            'mail_username'     => 'max:191',
            'mail_from_address' => 'email|max:190',
            'mail_from_name'    => 'max:120',
            'mail_reply_to'     => 'email|max:190',
            'mail_timeout'      => 'required|integer|min_value:5|max_value:120',
            'mail_password'     => 'max:255',
            'invite_expiry_hours'         => 'required|integer|min_value:1|max_value:720',
            'password_reset_expiry_hours' => 'required|integer|min_value:1|max_value:168',
        ], [
            'mail_host'         => 'SMTP host',
            'mail_port'         => 'Port',
            'mail_encryption'   => 'Encryption',
            'mail_username'     => 'Username',
            'mail_from_address' => '“From” address',
            'mail_from_name'    => '“From” name',
            'mail_reply_to'     => 'Reply-to address',
            'mail_timeout'      => 'Timeout',
            'mail_password'     => 'Password',
            'invite_expiry_hours'         => 'Invitation link expiry',
            'password_reset_expiry_hours' => 'Password reset link expiry',
        ], '/admin/email');

        $enabled = Request::boolean('mail_enabled');

        // Switching sending *on* with nothing to send through would just fill
        // the log with failures, so the requirements only bite at that point.
        if ($enabled) {
            $missing = [];

            if ((string) $data['mail_host'] === '') {
                $missing['mail_host'] = 'An SMTP host is needed before email can be switched on.';
            }

            if ((string) $data['mail_from_address'] === '') {
                $missing['mail_from_address'] = 'A “from” address is needed before email can be switched on.';
            }

            if ($missing !== []) {
                $this->failValidation($missing, '/admin/email');
            }
        }

        $before = Setting::all();

        Setting::put('mail_enabled',      $enabled ? '1' : '0');
        Setting::put('mail_host',         (string) $data['mail_host']);
        Setting::put('mail_port',         (string) (int) $data['mail_port']);
        Setting::put('mail_encryption',   (string) $data['mail_encryption']);
        Setting::put('mail_username',     (string) $data['mail_username']);
        Setting::put('mail_from_address', (string) $data['mail_from_address']);
        Setting::put('mail_from_name',    (string) $data['mail_from_name']);
        Setting::put('mail_reply_to',     (string) $data['mail_reply_to']);
        Setting::put('mail_timeout',      (string) (int) $data['mail_timeout']);

        // How long an invitation and a password-reset link stay usable. Kept on
        // this page because both only exist when email works, and this is where
        // somebody comes to make email work.
        Setting::put('invite_expiry_hours',         (string) (int) $data['invite_expiry_hours']);
        Setting::put('password_reset_expiry_hours', (string) (int) $data['password_reset_expiry_hours']);

        // The password field is left blank on every page load, so a blank
        // submission means "leave it alone" rather than "clear it" — clearing
        // is its own checkbox. Otherwise saving an unrelated change would
        // silently wipe the password.
        if (Request::boolean('mail_password_clear')) {
            Mailer::storePassword('');
        } elseif ((string) $data['mail_password'] !== '') {
            if (!Mailer::storePassword((string) $data['mail_password'])) {
                $this->failValidation([
                    'mail_password' => Crypto::isAvailable()
                        ? 'The password could not be encrypted because APP_KEY is not set in .env. Generate one with “php bin/console.php key:generate”, then try again.'
                        : 'The password could not be encrypted because the PHP openssl extension is not loaded.',
                ], '/admin/email');
            }
        }

        // The password never goes near the audit trail, in either direction.
        ActivityLog::record('updated', 'settings', null, 'Updated email settings', [
            'before' => self::auditable($before),
            'after'  => self::auditable(Setting::all()),
        ]);

        Flash::success('Email settings saved.' . ($enabled ? ' Send yourself a test message to prove the connection.' : ''));
        Response::redirect('/admin/email');
    }

    /**
     * @param array<string,string|null> $settings
     * @return array<string,string|null>
     */
    private static function auditable(array $settings): array
    {
        return array_intersect_key($settings, array_flip([
            'mail_enabled', 'mail_host', 'mail_port', 'mail_encryption',
            'mail_username', 'mail_from_address', 'mail_from_name', 'mail_reply_to', 'mail_timeout',
        ]));
    }

    public function test(): void
    {
        $data = $this->validate([
            'test_email' => 'required|email|max:190',
        ], [
            'test_email' => 'Test address',
        ], '/admin/email');

        $problems = Mailer::problems();

        if ($problems !== []) {
            Flash::error('Email is not usable yet: ' . implode(' ', $problems));
            Response::redirect('/admin/email');
        }

        if (!Setting::bool('mail_enabled', false)) {
            Flash::error('Email sending is switched off. Tick “Send email from this application” and save before testing.');
            Response::redirect('/admin/email');
        }

        if (!Mailer::isTemplateActive('smtp_test')) {
            Flash::error('The “SMTP test message” template is switched off, so nothing was sent. Switch it back on under Templates.');
            Response::redirect('/admin/email/templates/smtp_test');
        }

        $user = Auth::user();
        $sent = Mailer::sendTemplate(
            'smtp_test',
            (string) $data['test_email'],
            (string) ($user['name'] ?? ''),
            [
                'mail_host' => (string) Setting::get('mail_host', ''),
                'recipient' => (string) $data['test_email'],
                'sent_at'   => date('j M Y, H:i'),
                'sent_by'   => (string) ($user['name'] ?? 'an administrator'),
            ],
            ['trigger' => 'user']
        );

        ActivityLog::record('sent', 'email', null, 'Sent a test email to ' . $data['test_email']);

        if ($sent) {
            Flash::success('Test message sent to ' . $data['test_email'] . '. If it does not arrive, check the spam folder, then the log below.');
        } else {
            $latest = EmailLog::search(['status' => 'failed'], 1, 1);
            $reason = $latest['rows'][0]['error'] ?? 'See Settings → Email → Log for the reason.';
            Flash::error('The test message could not be sent: ' . $reason);
        }

        Response::redirect('/admin/email');
    }

    // -- Reminders ----------------------------------------------------------

    public function reminders(): void
    {
        $this->view('admin/email/reminders', [
            'pageTitle'   => 'Email reminders',
            'section'     => 'reminders',
            'settings'    => Setting::all(),
            'types'       => Reminders::TYPES,
            'windows'     => [
                'pat'         => Reminders::windowDays('pat'),
                'maintenance' => Reminders::windowDays('maintenance'),
                'hire'        => Reminders::windowDays('hire'),
                // Faulty equipment has no due date, so no window — see
                // Reminders::WINDOWED_TYPES. The template shows it a repeat
                // interval instead.
                'faulty'      => 0,
            ],
            'candidates'  => self::notifyCandidates(),
            'selectedIds' => Reminders::notifyUserIds(),
            'tracking'    => EmailReminder::summary(),
            'ready'       => Mailer::isReady(),
            'lastRun'     => self::lastRun(),
        ]);
    }

    /**
     * The outcome of the last reminder run, or null if it has never been run.
     *
     * @return array<string,mixed>|null
     */
    private static function lastRun(): ?array
    {
        $raw = (string) Setting::get('reminder_last_run', '');

        if (trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Users who could be on the notify list.
     *
     * Anyone active with an email address; the permission each one actually
     * holds is shown beside them, because a reminder is only ever sent to
     * someone entitled to see that kind of record — ticking a box does not
     * override the permission model.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function notifyCandidates(): array
    {
        $byId = [];

        foreach (['pat' => 'pat.view', 'maintenance' => 'maintenance.view', 'hire' => 'hires.view'] as $type => $permission) {
            foreach (User::withPermission($permission) as $user) {
                $id = (int) $user['id'];

                if (!isset($byId[$id])) {
                    $byId[$id]          = $user;
                    $byId[$id]['types'] = [];
                }

                $byId[$id]['types'][] = $type;
            }
        }

        usort($byId, static fn (array $a, array $b): int => strcasecmp((string) $a['name'], (string) $b['name']));

        return array_values($byId);
    }

    public function updateReminders(): void
    {
        $data = $this->validate([
            'reminder_pat_days'         => 'integer|min_value:0|max_value:365',
            'reminder_maintenance_days' => 'integer|min_value:0|max_value:365',
            'reminder_hire_days'        => 'integer|min_value:0|max_value:365',
            'reminder_repeat_days'      => 'required|integer|min_value:1|max_value:90',
            'reminder_faulty_repeat_days' => 'integer|min_value:0|max_value:90',
        ], [
            'reminder_pat_days'         => 'PAT reminder window',
            'reminder_maintenance_days' => 'Maintenance reminder window',
            'reminder_hire_days'        => 'Hire reminder window',
            'reminder_repeat_days'      => 'Remind again after',
            'reminder_faulty_repeat_days' => 'Faulty equipment repeat',
        ], '/admin/email/reminders');

        foreach (array_keys(Reminders::TYPES) as $type) {
            Setting::put('reminder_' . $type . '_enabled', Request::boolean('reminder_' . $type . '_enabled') ? '1' : '0');
        }

        // Only the types that count items against a date have a "days before
        // due" window; faulty equipment has a repeat interval instead.
        foreach (Reminders::WINDOWED_TYPES as $type) {
            Setting::put('reminder_' . $type . '_days', (string) (int) $data['reminder_' . $type . '_days']);
        }

        Setting::put('reminder_repeat_days', (string) (int) $data['reminder_repeat_days']);
        Setting::put('reminder_faulty_repeat_days', (string) (int) $data['reminder_faulty_repeat_days']);
        Setting::put('fault_notify_immediately', Request::boolean('fault_notify_immediately') ? '1' : '0');
        Setting::put('reminder_maintenance_assignee', Request::boolean('reminder_maintenance_assignee') ? '1' : '0');
        Setting::put('reminder_hire_notify_hirer', Request::boolean('reminder_hire_notify_hirer') ? '1' : '0');

        // Only ids that belong to a real, active user are stored, so a stale
        // list cannot quietly accumulate references to deleted accounts.
        $submitted = Request::all()['reminder_recipient_user_ids'] ?? [];
        $submitted = is_array($submitted) ? array_map('intval', $submitted) : [];

        $valid = [];
        foreach (self::notifyCandidates() as $candidate) {
            if (in_array((int) $candidate['id'], $submitted, true)) {
                $valid[] = (int) $candidate['id'];
            }
        }

        Setting::put('reminder_recipient_user_ids', implode(',', $valid));

        ActivityLog::record('updated', 'settings', null, 'Updated email reminder settings');

        Flash::success($valid === [] && self::anyReminderOn()
            ? 'Reminder settings saved — but nobody is on the notify list, so nothing will be sent.'
            : 'Reminder settings saved.');

        Response::redirect('/admin/email/reminders');
    }

    private static function anyReminderOn(): bool
    {
        foreach (array_keys(Reminders::TYPES) as $type) {
            if (Reminders::isEnabled($type)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Run the reminders from the UI.
     *
     * A dry run reports what would go out and sends nothing; without it this
     * does exactly what the cron job does, which is the only way to prove the
     * cron job will work before waiting a day for it.
     */
    public function runReminders(): void
    {
        $dryRun  = Request::boolean('dry_run');
        $reports = Reminders::run([], $dryRun, false);

        $lines = [];
        $sent  = 0;
        $would = 0;
        $failed = 0;

        foreach ($reports as $report) {
            if (!$report['enabled']) {
                continue;
            }

            $sent   += (int) $report['sent'];
            $would  += (int) $report['would_send'];
            $failed += (int) $report['failed'];

            // Faulty equipment has no "due soon" half — a fault is open or it
            // is not — so it gets a sentence that says what its numbers mean
            // rather than one with a permanent "0 due soon" in it.
            $lines[] = $report['type'] === 'faulty'
                ? sprintf(
                    '%s: %d faulty, %d already reminded',
                    $report['label'],
                    $report['overdue_items'],
                    $report['suppressed']
                )
                : sprintf(
                    '%s: %d overdue, %d due soon, %d already reminded',
                    $report['label'],
                    $report['overdue_items'],
                    $report['due_items'],
                    $report['suppressed']
                );
        }

        if ($lines === []) {
            Flash::error('No reminder type is switched on, so there was nothing to run.');
            Response::redirect('/admin/email/reminders');
        }

        ActivityLog::record(
            'ran',
            'email',
            null,
            ($dryRun ? 'Previewed' : 'Ran') . ' the email reminders from Settings'
        );

        $summary = implode(' · ', $lines);

        // Kept, not just announced.
        //
        // This was the one message in the application that reported something
        // nowhere else recorded it. A *preview* deliberately writes no log rows,
        // so its result existed only in the banner — and the per-type breakdown
        // ("3 overdue, 1 due soon, 12 already reminded") is not in the send log
        // even after a real run, because the log holds one row per message and
        // knows nothing about what was suppressed. Now that confirmations time
        // out, that would have been information with a six-second life.
        Setting::put('reminder_last_run', json_encode([
            'at'      => date('Y-m-d H:i:s'),
            'by'      => Auth::name() ?? 'System',
            'dry_run' => $dryRun,
            'sent'    => $sent,
            'would'   => $would,
            'failed'  => $failed,
            'summary' => $summary,
        ], JSON_UNESCAPED_UNICODE));

        if ($dryRun) {
            Flash::success(sprintf('Preview only, nothing sent. %d message(s) would go out. %s', $would, $summary));
        } elseif ($failed > 0) {
            Flash::error(sprintf('%d message(s) sent, %d failed. %s See the log for the reason.', $sent, $failed, $summary));
        } else {
            Flash::success(sprintf('%d message(s) sent. %s', $sent, $summary));
        }

        Response::redirect('/admin/email/reminders');
    }

    // -- Templates ----------------------------------------------------------

    public function templates(): void
    {
        $grouped = [];

        foreach (EmailTemplate::all() as $template) {
            $grouped[(string) $template['group']][] = $template;
        }

        $this->view('admin/email/templates', [
            'pageTitle' => 'Email templates',
            'section'   => 'templates',
            'grouped'   => $grouped,
            'customised'=> EmailTemplate::customisedCount(),
        ]);
    }

    public function editTemplate(string $key): void
    {
        $template = EmailTemplate::find($key);

        if ($template === null) {
            $this->notFound('There is no email template with that name.');
        }

        // Rendered with the sample values, so the wording can be judged without
        // having to find a genuinely overdue item first.
        $sample = array_merge(Mailer::commonFields('Sam Example'), $template['sample']);

        // Not 'template': View::renderFile() takes the template path in a
        // parameter of that name, and its extract() uses EXTR_SKIP, so a view
        // variable called $template is silently dropped and the page sees the
        // path string instead.
        $this->view('admin/email/template-form', [
            'pageTitle'       => $template['name'],
            'section'         => 'templates',
            'emailTemplate'   => $template,
            'previewSubject'  => Merge::render((string) $template['subject'], $sample),
            'previewBody'     => Merge::render((string) $template['body'], $sample, (bool) $template['is_html']),
            'unknownFields'   => Merge::unknown(
                (string) $template['subject'] . ' ' . (string) $template['body'],
                $template['fields']
            ),
        ]);
    }

    public function updateTemplate(string $key): void
    {
        $template = EmailTemplate::find($key);

        if ($template === null) {
            $this->notFound('There is no email template with that name.');
        }

        $data = $this->validate([
            'subject' => 'required|max:255',
            'body'    => 'required|max:65000',
        ], [
            'subject' => 'Subject',
            'body'    => 'Message',
        ], '/admin/email/templates/' . $key);

        EmailTemplate::save(
            $key,
            (string) $data['subject'],
            (string) $data['body'],
            Request::boolean('is_html'),
            // The invite and reset messages carry a link somebody is waiting
            // for, so they cannot be silenced from here — see
            // EmailTemplate::LOCKED_ACTIVE. The form hides the switch; this is
            // what makes hiding it more than a suggestion.
            EmailTemplate::canBeDisabled($key) ? Request::boolean('is_active') : true
        );

        ActivityLog::record('updated', 'email_template', null, 'Edited the “' . $template['name'] . '” email template');

        // A placeholder the sending code does not supply comes out blank. That
        // is a typo worth mentioning at the moment it is made, but not worth
        // refusing the save over — the wording might still be an improvement.
        $unknown = Merge::unknown((string) $data['subject'] . ' ' . (string) $data['body'], $template['fields']);

        if ($unknown !== []) {
            Flash::error('Saved, but these merge fields are not available in this template and will come out blank: '
                . implode(', ', array_map(static fn (string $f): string => '{{' . $f . '}}', $unknown)));
        } else {
            Flash::success('Template saved.');
        }

        Response::redirect('/admin/email/templates/' . $key);
    }

    public function resetTemplate(string $key): void
    {
        $template = EmailTemplate::find($key);

        if ($template === null) {
            $this->notFound('There is no email template with that name.');
        }

        EmailTemplate::reset($key);

        ActivityLog::record('updated', 'email_template', null, 'Reset the “' . $template['name'] . '” email template to its default');
        Flash::success('“' . $template['name'] . '” has been reset to the wording it ships with.');

        Response::redirect('/admin/email/templates/' . $key);
    }

    // -- Log ----------------------------------------------------------------

    public function log(): void
    {
        $filters = [
            'status'       => (string) Request::query('status', ''),
            'template_key' => (string) Request::query('template', ''),
            'q'            => (string) Request::query('q', ''),
        ];

        $result = EmailLog::search($filters, max(1, (int) Request::query('page', 1)));

        $names = [];
        foreach (EmailTemplate::all() as $template) {
            $names[(string) $template['key']] = (string) $template['name'];
        }

        $this->view('admin/email/log', [
            'pageTitle'     => 'Email log',
            'section'       => 'log',
            'result'        => $result,
            'filters'       => $filters,
            'summary'       => EmailLog::summary(),
            'templateNames' => $names,
        ]);
    }
}
