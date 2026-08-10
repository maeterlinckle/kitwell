<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Hire;
use App\Models\Hirer;
use App\Models\MaintenanceSchedule;
use App\Models\PatRecord;
use App\Models\Setting;
use App\Models\User;

/**
 * The scheduled reminder run.
 *
 * Triggered by cron (`bin/send-reminders.php`), not by a resident worker: this
 * has to run on the sort of modest shared hosting the rest of the application
 * is built for, where a long-lived background process is not an option.
 *
 * Two decisions worth knowing about:
 *
 * 1. **One digest per recipient, not one email per item.** A workshop with
 *    forty overdue PAT items should get one message listing forty items, not
 *    forty messages. Volume is what makes people filter reminders away, and a
 *    filtered reminder is worse than none because it looks like it is working.
 *
 * 2. **Recipients are re-checked against their permissions at send time.**
 *    The notify list is a list of user ids, but someone's role can change after
 *    they are added to it. A user who no longer holds `pat.view` stops
 *    receiving PAT reminders, because the reminder would otherwise be a way to
 *    read data the application will not show them. The same rule as
 *    Auth::can(), asked of a user who is not signed in.
 *
 * Which items are "due soon" or "overdue" comes from the same model code the
 * register, dashboard and reports use — PatRecord, MaintenanceSchedule and
 * Hire each own their status rules, and this class does not restate them.
 */
final class Reminders
{
    /** @var array<string,string> */
    public const TYPES = [
        'pat'         => 'PAT testing',
        'maintenance' => 'Maintenance',
        'hire'        => 'Hire returns',
    ];

    /**
     * How long a sent reminder suppresses the next one for the same item.
     * 1 means "every day the cron runs".
     */
    public static function repeatDays(): int
    {
        return max(1, min(90, Setting::int('reminder_repeat_days', 7)));
    }

    /**
     * The "due soon" window for one reminder type.
     *
     * Zero means "whatever the register and dashboard already use", so the
     * defaults agree with the rest of the application rather than restating a
     * number that would then drift.
     */
    public static function windowDays(string $type): int
    {
        $configured = Setting::int('reminder_' . $type . '_days', 0);

        if ($configured > 0) {
            return max(1, min(365, $configured));
        }

        return match ($type) {
            'pat'         => PatRecord::dueDays(),
            'maintenance' => MaintenanceSchedule::dueDays(),
            default       => max(1, min(90, Setting::int('hire_due_soon_days', 2))),
        };
    }

    public static function isEnabled(string $type): bool
    {
        return Setting::bool('reminder_' . $type . '_enabled', false);
    }

    /**
     * The staff notify list as stored: user ids, whether or not they still
     * exist or still hold the relevant permission.
     *
     * @return array<int,int>
     */
    public static function notifyUserIds(): array
    {
        $raw = (string) Setting::get('reminder_recipient_user_ids', '');

        if (trim($raw) === '') {
            return [];
        }

        $ids = array_map('intval', array_filter(array_map('trim', explode(',', $raw)), static fn (string $v): bool => $v !== ''));

        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }

    /**
     * Notify-list members who may actually see this kind of information.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function recipientsFor(string $type): array
    {
        $permission = match ($type) {
            'pat'         => 'pat.view',
            'maintenance' => 'maintenance.view',
            default       => 'hires.view',
        };

        return User::withPermission($permission, self::notifyUserIds());
    }

    /**
     * Run the reminders.
     *
     * @param array<int,string> $types  Empty = every enabled type
     * @return array<string,array<string,mixed>> One report per type
     */
    public static function run(array $types = [], bool $dryRun = false, bool $force = false): array
    {
        $types = $types === [] ? array_keys(self::TYPES) : array_values(array_intersect($types, array_keys(self::TYPES)));

        $reports = [];

        foreach ($types as $type) {
            $reports[$type] = match ($type) {
                'pat'         => self::runPat($dryRun, $force),
                'maintenance' => self::runMaintenance($dryRun, $force),
                default       => self::runHire($dryRun, $force),
            };
        }

        return $reports;
    }

    // -- PAT ----------------------------------------------------------------

    /** @return array<string,mixed> */
    private static function runPat(bool $dryRun, bool $force): array
    {
        $report = self::blankReport('pat');

        if (!self::isEnabled('pat')) {
            return $report;
        }

        $report['enabled'] = true;
        $days = $report['window_days'] = self::windowDays('pat');

        // "Needs attention" is the same set the PAT report shows by default, and
        // for the same reason: an appliance that failed its last test, or has
        // never been tested at all, is not fine merely because no retest date
        // has arrived. Reminding about the retest calendar while staying silent
        // about a failed item would be the wrong way round.
        $rows = PatRecord::assetSearchAll([
            'status'   => ['Overdue', 'Due soon', 'Failed', 'Never tested'],
            'due_days' => $days,
        ]);

        $due = $overdue = [];

        foreach ($rows as $row) {
            $status = (string) $row['pat_status'];

            $when = match ($status) {
                'Failed'       => 'FAILED its last test on ' . format_date((string) $row['test_date']),
                'Never tested' => 'never tested',
                'Overdue'      => self::overdueWords((int) $row['days_until_due']),
                default        => 'due ' . format_date((string) $row['retest_due_date']),
            };

            $item = [
                'id'   => (int) $row['id'],
                'line' => self::line([
                    (string) $row['asset_tag'],
                    (string) $row['name'],
                    (string) ($row['location_name'] ?? ''),
                    $when,
                ]),
            ];

            if ($status === 'Due soon') {
                $due[] = $item;
            } else {
                $overdue[] = $item;
            }
        }

        $recipients = self::recipientsFor('pat');

        $report['recipients']    = count($recipients);
        $report['due_items']     = count($due);
        $report['overdue_items'] = count($overdue);

        if ($recipients === []) {
            $report['note'] = 'Nobody on the notify list can see PAT records.';

            return $report;
        }

        self::deliver('pat_overdue', 'asset', 'pat_overdue', $overdue, self::asDeliveries($recipients, $overdue), [], $report, $dryRun, $force);
        self::deliver('pat_due', 'asset', 'pat_due', $due, self::asDeliveries($recipients, $due), ['days' => (string) $days], $report, $dryRun, $force);

        return $report;
    }

    // -- Maintenance --------------------------------------------------------

    /** @return array<string,mixed> */
    private static function runMaintenance(bool $dryRun, bool $force): array
    {
        $report = self::blankReport('maintenance');

        if (!self::isEnabled('maintenance')) {
            return $report;
        }

        $report['enabled'] = true;
        $days = $report['window_days'] = self::windowDays('maintenance');

        $rows = MaintenanceSchedule::searchAll([
            'status'          => ['Overdue', 'Due soon'],
            'due_within_days' => $days,
        ]);

        $due = $overdue = [];

        foreach ($rows as $row) {
            $item = [
                'id'          => (int) $row['id'],
                'assigned_to' => $row['assigned_to_user_id'] === null ? null : (int) $row['assigned_to_user_id'],
                'line'        => self::line([
                    (string) $row['title'],
                    (string) $row['asset_tag'] . ' ' . (string) $row['asset_name'],
                    (string) $row['due_status'] === 'Overdue'
                        ? self::overdueWords((int) $row['days_until_due'])
                        : 'due ' . format_date((string) $row['next_due_date']),
                ]),
            ];

            if ((string) $row['due_status'] === 'Overdue') {
                $overdue[] = $item;
            } else {
                $due[] = $item;
            }
        }

        $recipients = self::recipientsFor('maintenance');

        $report['due_items']     = count($due);
        $report['overdue_items'] = count($overdue);

        // The person a job is assigned to gets their own jobs, whether or not
        // anybody put them on the notify list — that is the point of assigning
        // it to them. They still have to hold maintenance.view.
        $assignees = [];
        if (Setting::bool('reminder_maintenance_assignee', true)) {
            $ids = array_values(array_unique(array_filter(
                array_merge(array_column($due, 'assigned_to'), array_column($overdue, 'assigned_to')),
                static fn (?int $id): bool => $id !== null
            )));

            foreach (User::withPermission('maintenance.view', $ids) as $user) {
                $assignees[(int) $user['id']] = $user;
            }
        }

        $report['recipients'] = count($recipients) + count($assignees);

        if ($recipients === [] && $assignees === []) {
            $report['note'] = 'Nobody on the notify list can see maintenance, and no due job is assigned to anyone.';

            return $report;
        }

        foreach ([['maintenance_overdue', $overdue, []], ['maintenance_due', $due, ['days' => (string) $days]]] as [$templateKey, $items, $extra]) {
            $deliveries = self::asDeliveries($recipients, $items);

            foreach ($assignees as $userId => $user) {
                $mine = array_values(array_filter($items, static fn (array $i): bool => $i['assigned_to'] === $userId));

                if ($mine === []) {
                    continue;
                }

                // Already on the notify list: they would otherwise get their
                // own jobs twice, once in the full digest and once on their own.
                if (isset($deliveries[(string) $user['email']])) {
                    continue;
                }

                $deliveries[(string) $user['email']] = [
                    'email' => (string) $user['email'],
                    'name'  => (string) $user['name'],
                    'items' => $mine,
                ];
            }

            self::deliver($templateKey, 'maintenance_schedule', $templateKey, $items, $deliveries, $extra, $report, $dryRun, $force);
        }

        return $report;
    }

    // -- Hires --------------------------------------------------------------

    /** @return array<string,mixed> */
    private static function runHire(bool $dryRun, bool $force): array
    {
        $report = self::blankReport('hire');

        if (!self::isEnabled('hire')) {
            return $report;
        }

        $report['enabled'] = true;
        $days = $report['window_days'] = self::windowDays('hire');

        $rows = Hire::searchAll([
            'open_only'       => 1,
            'due_within_days' => $days,
        ]);

        $due = $overdue = [];

        foreach ($rows as $row) {
            $isOverdue = (string) $row['effective_status'] === 'Overdue';

            $item = [
                'id'   => (int) $row['id'],
                'row'  => $row,
                'line' => self::line([
                    (string) $row['reference'],
                    (string) $row['asset_tag'] . ' ' . (string) $row['asset_name'],
                    Hirer::label(['name' => $row['hirer_name'], 'company_name' => $row['company_name']]),
                    $isOverdue
                        ? self::overdueWords((int) $row['days_until_due'])
                        : 'due back ' . format_date((string) $row['due_back_date']),
                ]),
            ];

            if ($isOverdue) {
                $overdue[] = $item;
            } else {
                $due[] = $item;
            }
        }

        $recipients = self::recipientsFor('hire');

        $report['recipients']    = count($recipients);
        $report['due_items']     = count($due);
        $report['overdue_items'] = count($overdue);

        self::deliver('hire_overdue', 'hire', 'hire_overdue', $overdue, self::asDeliveries($recipients, $overdue), [], $report, $dryRun, $force);
        self::deliver('hire_due', 'hire', 'hire_due', $due, self::asDeliveries($recipients, $due), ['days' => (string) $days], $report, $dryRun, $force);

        // Optionally chase the hirer directly. One message per hire rather than
        // a digest: a customer wants to read about the item they have, not a
        // list of the workshop's outstanding hires — and the per-item template
        // is the same one the manual "Email reminder" button uses.
        if (Setting::bool('reminder_hire_notify_hirer', false)) {
            $report['hirer_notices'] = self::notifyHirers(array_merge($overdue, $due), $report, $dryRun, $force);
        }

        return $report;
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @param array<string,mixed>            $report
     */
    private static function notifyHirers(array $items, array &$report, bool $dryRun, bool $force): int
    {
        $sent       = 0;
        $repeatDays = self::repeatDays();

        foreach ($items as $item) {
            $hire  = $item['row'];
            $email = trim((string) ($hire['hirer_email'] ?? ''));

            if ($email === '') {
                $report['no_address']++;
                continue;
            }

            $key = (string) $hire['effective_status'] === 'Overdue' ? 'hirer_notice_overdue' : 'hirer_notice_due';

            if (!$force && EmailReminder::suppressed($key, 'hire', [(int) $hire['id']], $email, $repeatDays) !== []) {
                $report['suppressed']++;
                continue;
            }

            if ($dryRun) {
                $report['would_send']++;
                $sent++;
                continue;
            }

            $ok = Mailer::sendTemplate(
                'hirer_overdue_notice',
                $email,
                (string) $hire['hirer_name'],
                self::hireFields($hire),
                ['entity_type' => 'hire', 'entity_id' => (int) $hire['id'], 'trigger' => 'system']
            );

            if ($ok) {
                EmailReminder::markSent($key, 'hire', [(int) $hire['id']], $email);
                $report['sent']++;
                $sent++;
            } else {
                $report['failed']++;
            }
        }

        return $sent;
    }

    /**
     * Merge fields for a single hire — shared by the automated hirer notice and
     * the manual "Email reminder" button, so both say the same thing.
     *
     * @param array<string,mixed> $hire
     * @return array<string,string>
     */
    public static function hireFields(array $hire): array
    {
        $daysUntil  = (int) ($hire['days_until_due'] ?? 0);
        $isOverdue  = $daysUntil < 0;
        $daysOverdue = $isOverdue ? abs($daysUntil) : 0;

        if ($isOverdue) {
            $statusLine = sprintf('This is %d day%s overdue.', $daysOverdue, $daysOverdue === 1 ? '' : 's');
        } elseif ($daysUntil === 0) {
            $statusLine = 'This is due back today.';
        } else {
            $statusLine = sprintf('This is due back in %d day%s.', $daysUntil, $daysUntil === 1 ? '' : 's');
        }

        return [
            'hirer_name'       => (string) $hire['hirer_name'],
            'hirer_company'    => (string) ($hire['company_name'] ?? ''),
            'asset_tag'        => (string) $hire['asset_tag'],
            'asset_name'       => (string) $hire['asset_name'],
            'reference'        => (string) ($hire['reference'] ?? ''),
            'checked_out_date' => format_date((string) $hire['checked_out_at']),
            'due_date'         => format_date((string) $hire['due_back_date']),
            'days_overdue'     => (string) $daysOverdue,
            'status_line'      => $statusLine,
        ];
    }

    // -- Delivery -----------------------------------------------------------

    /**
     * Everyone gets the whole list.
     *
     * @param array<int,array<string,mixed>> $recipients
     * @param array<int,array<string,mixed>> $items
     * @return array<string,array<string,mixed>>
     */
    private static function asDeliveries(array $recipients, array $items): array
    {
        $deliveries = [];

        foreach ($recipients as $recipient) {
            $email = trim((string) $recipient['email']);

            if ($email === '') {
                continue;
            }

            $deliveries[$email] = [
                'email' => $email,
                'name'  => (string) $recipient['name'],
                'items' => $items,
            ];
        }

        return $deliveries;
    }

    /**
     * Send one digest per delivery, skipping items already reminded about.
     *
     * @param array<int,array<string,mixed>>    $allItems
     * @param array<string,array<string,mixed>> $deliveries
     * @param array<string,string>              $extraFields
     * @param array<string,mixed>               $report
     */
    private static function deliver(
        string $reminderKey,
        string $entityType,
        string $templateKey,
        array $allItems,
        array $deliveries,
        array $extraFields,
        array &$report,
        bool $dryRun,
        bool $force
    ): void {
        if ($allItems === [] || $deliveries === []) {
            return;
        }

        $repeatDays = self::repeatDays();

        foreach ($deliveries as $delivery) {
            $items = $delivery['items'];

            if ($items === []) {
                continue;
            }

            $ids = array_map(static fn (array $i): int => (int) $i['id'], $items);

            $suppressed = $force
                ? []
                : EmailReminder::suppressed($reminderKey, $entityType, $ids, (string) $delivery['email'], $repeatDays);

            $fresh = array_values(array_filter($items, static fn (array $i): bool => !isset($suppressed[(int) $i['id']])));

            $report['suppressed'] += count($items) - count($fresh);

            if ($fresh === []) {
                continue;
            }

            $freshIds = array_map(static fn (array $i): int => (int) $i['id'], $fresh);
            $fields   = array_merge($extraFields, [
                'count' => (string) count($fresh),
                'items' => implode("\n", array_map(static fn (array $i): string => (string) $i['line'], $fresh)),
            ]);

            if ($dryRun) {
                $report['would_send']++;
                continue;
            }

            $ok = Mailer::sendTemplate(
                $templateKey,
                (string) $delivery['email'],
                (string) $delivery['name'],
                $fields,
                ['trigger' => 'system', 'entity_type' => $entityType]
            );

            if ($ok) {
                EmailReminder::markSent($reminderKey, $entityType, $freshIds, (string) $delivery['email']);
                $report['sent']++;
            } else {
                $report['failed']++;
            }
        }
    }

    // -- Formatting ---------------------------------------------------------

    /** @return array<string,mixed> */
    private static function blankReport(string $type): array
    {
        return [
            'type'          => $type,
            'label'         => self::TYPES[$type],
            'enabled'       => false,
            'window_days'   => 0,
            'recipients'    => 0,
            'due_items'     => 0,
            'overdue_items' => 0,
            'sent'          => 0,
            'failed'        => 0,
            'suppressed'    => 0,
            'would_send'    => 0,
            'no_address'    => 0,
            'hirer_notices' => 0,
            'note'          => '',
        ];
    }

    /** Join the parts of a list line, dropping any that are empty. */
    private static function line(array $parts): string
    {
        $parts = array_values(array_filter(array_map('trim', $parts), static fn (string $p): bool => $p !== ''));

        return implode(' — ', $parts);
    }

    private static function overdueWords(int $daysUntilDue): string
    {
        $days = abs($daysUntilDue);

        return $days === 1 ? '1 day overdue' : $days . ' days overdue';
    }
}
