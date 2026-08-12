<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Mail\Mailer;
use App\Models\Asset;
use App\Models\FaultReport;
use App\Models\Setting;
use App\Models\Team;
use App\Models\User;

/**
 * Who hears about a fault, and what they are told.
 *
 * One class rather than two because the immediate notification and the nightly
 * digest have to agree about the recipient. If they did not, an asset could
 * email one person the moment it broke and a different person every morning
 * afterwards — which is worse than either alone, because each message makes the
 * other look like it must have been a mistake.
 *
 * Three rules, all deliberate:
 *
 * 1. **Nobody named means nobody emailed.** Not an error, not a fallback to an
 *    administrator or the notify list. Mail addressed to "whoever is around" is
 *    mail everybody learns to ignore, and once they have, the messages that
 *    were properly addressed go unread too.
 *
 * 2. **A team means every member of it.** The point of naming a team is that
 *    the news does not stop because one person is on holiday — the same
 *    argument migration 020 makes about maintenance.
 *
 * 3. **Recipients are re-checked against their permissions at send time.**
 *    Being named as responsible is not itself a grant: a fault report contains
 *    the asset's tag, location and condition, so somebody whose role no longer
 *    lets them see the register stops receiving it. Identical to the rule
 *    App\Mail\Reminders applies to the notify list.
 */
final class FaultNotifier
{
    /** Reading a fault report is reading asset data. */
    private const PERMISSION = 'assets.view';

    /**
     * The people to tell about one asset.
     *
     * @param array<string,mixed> $asset A row from Asset::find()/searchAll()
     * @return array<int,array<string,mixed>> Keyed by user id; empty is normal
     */
    public static function recipientsFor(array $asset): array
    {
        $teamId = (int) ($asset['responsible_team_id'] ?? 0);
        $userId = (int) ($asset['responsible_user_id'] ?? 0);

        if ($teamId > 0) {
            return Team::membersWithPermission([$teamId], self::PERMISSION);
        }

        if ($userId > 0) {
            $allowed = [];

            foreach (User::withPermission(self::PERMISSION, [$userId]) as $user) {
                $allowed[(int) $user['id']] = $user;
            }

            return $allowed;
        }

        return [];
    }

    public static function immediateEnabled(): bool
    {
        return Setting::bool('fault_notify_immediately', true);
    }

    /**
     * Tell the responsible party that an asset has just been reported faulty.
     *
     * Returns what happened, so the controller can say so on screen rather than
     * claiming a send that never occurred. `skipped` is not a failure: it is
     * the ordinary outcome for an asset nobody is responsible for.
     *
     * @param array<string,mixed> $asset
     * @param array<string,mixed> $report A row from fault_reports
     * @return array{sent:int,failed:int,skipped:bool,reason:string,recipients:array<int,string>}
     */
    public static function notify(array $asset, array $report, int $photoCount = 0): array
    {
        $result = ['sent' => 0, 'failed' => 0, 'skipped' => false, 'reason' => '', 'recipients' => []];

        if (!self::immediateEnabled()) {
            $result['skipped'] = true;
            $result['reason']  = 'Immediate fault notifications are switched off in Settings.';

            return $result;
        }

        // No responsible party is the documented no-op. Checked before the
        // permission filter so the two reasons can be told apart on screen —
        // "nobody is responsible for this" and "the person responsible can no
        // longer see assets" need different fixes.
        if (empty($asset['responsible_user_id']) && empty($asset['responsible_team_id'])) {
            $result['skipped'] = true;
            $result['reason']  = 'Nobody is set as responsible for this asset, so no notification was sent.';

            return $result;
        }

        $recipients = self::recipientsFor($asset);

        if ($recipients === []) {
            $result['skipped'] = true;
            $result['reason']  = sprintf(
                '%s could not be emailed: no active account there has permission to see assets.',
                Asset::responsibleLabel($asset, 'The responsible party')
            );

            return $result;
        }

        $fields = self::fields($asset, $report, $photoCount);

        foreach ($recipients as $user) {
            $ok = Mailer::sendTemplate(
                'asset_faulty',
                (string) $user['email'],
                (string) $user['name'],
                $fields,
                [
                    'entity_type' => 'asset',
                    'entity_id'   => (int) $asset['id'],
                    'trigger'     => 'user',
                ]
            );

            if ($ok) {
                $result['sent']++;
                $result['recipients'][] = (string) $user['name'];
            } else {
                $result['failed']++;
            }
        }

        return $result;
    }

    /**
     * Merge fields for one fault, shared by the immediate message and by the
     * preview on the templates screen, so both describe the same thing.
     *
     * @param array<string,mixed> $asset
     * @param array<string,mixed> $report
     * @return array<string,string>
     */
    public static function fields(array $asset, array $report, int $photoCount = 0): array
    {
        $base = rtrim((string) Config::get('app.url', ''), '/');

        $location = trim(
            (string) ($asset['location_parent_name'] ?? '') . ' → ' . (string) ($asset['location_name'] ?? ''),
            ' →'
        );

        return [
            'asset_tag'   => (string) $asset['asset_tag'],
            'asset_name'  => (string) $asset['name'],
            'asset_url'   => $base . '/assets/' . (int) $asset['id'],
            'urgency'     => (string) $report['urgency'],
            'faulty_date' => format_date((string) $report['faulty_on']),
            'condition'   => (string) $report['condition_rating'],
            'location'    => $location !== '' ? $location : 'not recorded',
            'description' => (string) $report['description'],
            'reported_by' => (string) $report['reported_by_name'],
            // A ready-made sentence rather than a bare number: a template that
            // says "{{photo_count}} photos" reads badly at 0 and at 1, and an
            // administrator editing the wording should not have to solve that.
            'photo_note'  => $photoCount > 0
                ? sprintf(' — %d photo%s attached to the report.', $photoCount, $photoCount === 1 ? '' : 's')
                : '',
            'responsible' => Asset::responsibleLabel($asset, 'nobody'),
        ];
    }

    /**
     * One line describing a faulty asset, for the digest.
     *
     * @param array<string,mixed> $row A row from FaultReport::currentFaults()
     */
    public static function digestLine(array $row): string
    {
        $days = $row['days_faulty'] === null ? null : (int) $row['days_faulty'];

        $age = match (true) {
            $days === null => 'no report on record',
            $days <= 0     => 'reported today',
            $days === 1    => 'faulty 1 day',
            default        => 'faulty ' . $days . ' days',
        };

        $parts = [
            strtoupper((string) ($row['urgency'] ?? 'unrated')),
            trim((string) $row['asset_tag'] . ' ' . (string) $row['asset_name']),
            (string) ($row['location_name'] ?? ''),
            $age,
            str_limit(trim((string) ($row['description'] ?? '')), 120),
        ];

        $parts = array_values(array_filter(array_map('trim', $parts), static fn (string $p): bool => $p !== ''));

        return implode(' — ', $parts);
    }

    /**
     * Faulty assets grouped by the person who should be told about them.
     *
     * This is what makes the digest one email per recipient rather than one per
     * asset: a fitter responsible for four faulty machines, two by name and two
     * through a team, gets a single message listing all four. An asset with no
     * responsible party appears in nobody's list, which is the same rule the
     * immediate notification follows.
     *
     * @return array<int,array{user:array<string,mixed>,items:array<int,array<string,mixed>>}>
     */
    public static function digestGroups(): array
    {
        $rows = FaultReport::currentFaults();

        if ($rows === []) {
            return [];
        }

        $userIds = [];
        $teamIds = [];

        foreach ($rows as $row) {
            if (!empty($row['responsible_team_id'])) {
                $teamIds[] = (int) $row['responsible_team_id'];
            } elseif (!empty($row['responsible_user_id'])) {
                $userIds[] = (int) $row['responsible_user_id'];
            }
        }

        // Two lookups for the whole run, not one per asset.
        $byUser = [];

        foreach (User::withPermission(self::PERMISSION, array_values(array_unique($userIds))) as $user) {
            $byUser[(int) $user['id']] = ['user' => $user, 'teams' => []];
        }

        foreach (Team::membersWithPermission(array_values(array_unique($teamIds)), self::PERMISSION) as $userId => $user) {
            $byUser[$userId]['user']  = $user;
            $byUser[$userId]['teams'] = $user['team_ids'];
        }

        $groups = [];

        foreach ($byUser as $userId => $entry) {
            $mine = array_values(array_filter($rows, static function (array $row) use ($userId, $entry): bool {
                if (!empty($row['responsible_team_id'])) {
                    return in_array((int) $row['responsible_team_id'], $entry['teams'], true);
                }

                return (int) ($row['responsible_user_id'] ?? 0) === $userId;
            }));

            if ($mine === []) {
                continue;
            }

            $groups[$userId] = ['user' => $entry['user'], 'items' => $mine];
        }

        return $groups;
    }
}
