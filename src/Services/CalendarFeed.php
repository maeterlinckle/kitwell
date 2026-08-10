<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Models\Hire;
use App\Models\Hirer;
use App\Models\MaintenanceSchedule;
use App\Models\PatRecord;
use App\Models\User;

/**
 * A personal, read-only calendar feed of the dates one user is allowed to see.
 *
 * **Why iCalendar and not CalDAV.** CalDAV is a WebDAV extension: PROPFIND,
 * REPORT, ctag/etag synchronisation, collection discovery, and a write path
 * with scheduling semantics. All of that exists so that clients can *change*
 * events and sync two ways. Nothing here is editable from a calendar — these
 * dates are derived from PAT records, maintenance schedules and hires, and the
 * only sensible way to change one is in the application. What the brief
 * actually asks for is "add it to your calendar and see it update", and every
 * client named in it — Outlook, Google Calendar, Apple Calendar — does that by
 * subscribing to an HTTPS .ics URL and re-fetching it. So this is a subscribed
 * iCalendar feed: a few hundred lines instead of a WebDAV server, no write
 * surface to secure, and identical results for the user.
 *
 * **Authentication.** The feed URL carries a 64-character random token unique
 * to one user, which the user can regenerate from their own profile page if it
 * leaks. Calendar clients cannot complete an interactive login, so a bearer
 * secret in the URL is the mechanism they all support.
 *
 * **Scope.** Which events appear is decided by the token owner's permissions,
 * evaluated with the same rule Auth::can() uses (User::holdsPermission). A
 * hirer's feed contains their own hire return dates and nothing else. There is
 * no second access model here to fall out of step with the first.
 */
final class CalendarFeed
{
    /** How far back and forward the feed reaches. */
    private const PAST_DAYS   = 90;
    private const FUTURE_DAYS = 400;

    /**
     * Build the .ics document for a user.
     *
     * @param array<string,mixed> $user
     */
    public static function build(array $user): string
    {
        $userId = (int) $user['id'];
        $events = [];

        if (User::holdsPermission($userId, 'pat.view')) {
            $events = array_merge($events, self::patEvents());
        }

        if (User::holdsPermission($userId, 'maintenance.view')) {
            $events = array_merge($events, self::maintenanceEvents());
        }

        if (User::holdsPermission($userId, 'hires.view')) {
            $events = array_merge($events, self::hireEvents(null));
        } elseif (User::holdsPermission($userId, 'hires.view_own')) {
            // A hirer sees only what is out in their own name. The link is the
            // hirer record attached to their login; without one there is
            // nothing to show, which is exactly how the hirer portal behaves.
            $hirer = Hirer::findByUserId($userId);

            if ($hirer !== null) {
                $events = array_merge($events, self::hireEvents((int) $hirer['id']));
            }
        }

        return self::document($events, (string) $user['name']);
    }

    /**
     * A one-line description of what a user's feed will contain, for the
     * profile page. Same permission checks, so the page cannot promise
     * something the feed does not deliver.
     *
     * @return array<int,string>
     */
    public static function describe(int $userId): array
    {
        $parts = [];

        if (User::holdsPermission($userId, 'pat.view')) {
            $parts[] = 'PAT retest dates';
        }

        if (User::holdsPermission($userId, 'maintenance.view')) {
            $parts[] = 'maintenance due dates';
        }

        if (User::holdsPermission($userId, 'hires.view')) {
            $parts[] = 'due-back dates for every open hire';
        } elseif (User::holdsPermission($userId, 'hires.view_own')) {
            $parts[] = 'due-back dates for equipment on hire to you';
        }

        return $parts;
    }

    // -- Event sources ------------------------------------------------------

    /** @return array<int,array<string,mixed>> */
    private static function patEvents(): array
    {
        $rows = PatRecord::assetSearchAll([
            'status' => ['Overdue', 'Due soon', 'Current'],
        ]);

        $events = [];

        foreach ($rows as $row) {
            $due = (string) ($row['retest_due_date'] ?? '');

            if (!self::inWindow($due)) {
                continue;
            }

            $events[] = [
                'uid'         => 'pat-asset-' . (int) $row['id'],
                'date'        => $due,
                'summary'     => sprintf('PAT due: %s %s', $row['asset_tag'], $row['name']),
                'description' => self::describeLines([
                    'Asset'      => (string) $row['asset_tag'] . ' — ' . (string) $row['name'],
                    'Status'     => (string) $row['pat_status'],
                    'Last test'  => $row['test_date'] === null ? 'never' : format_date((string) $row['test_date']),
                    'Last result'=> (string) ($row['overall_result'] ?? ''),
                ]),
                'location'    => (string) ($row['location_name'] ?? ''),
                'path'        => '/assets/' . (int) $row['id'] . '/pat',
                'category'    => 'PAT',
            ];
        }

        return $events;
    }

    /** @return array<int,array<string,mixed>> */
    private static function maintenanceEvents(): array
    {
        $rows = MaintenanceSchedule::searchAll([]);

        $events = [];

        foreach ($rows as $row) {
            $due = (string) ($row['next_due_date'] ?? '');

            if (!self::inWindow($due)) {
                continue;
            }

            $events[] = [
                'uid'         => 'maintenance-' . (int) $row['id'],
                'date'        => $due,
                'summary'     => sprintf('Maintenance: %s (%s)', $row['title'], $row['asset_tag']),
                'description' => self::describeLines([
                    'Asset'       => (string) $row['asset_tag'] . ' — ' . (string) $row['asset_name'],
                    'Status'      => (string) $row['due_status'],
                    'Frequency'   => MaintenanceSchedule::describeFrequency($row),
                    'Assigned to' => (string) ($row['assigned_to_name'] ?? 'nobody'),
                ]),
                'location'    => (string) ($row['location_name'] ?? ''),
                'path'        => '/maintenance/' . (int) $row['id'],
                'category'    => 'Maintenance',
            ];
        }

        return $events;
    }

    /** @return array<int,array<string,mixed>> */
    private static function hireEvents(?int $hirerId): array
    {
        $rows = $hirerId === null
            ? Hire::searchAll(['open_only' => 1])
            : Hire::forHirer($hirerId, true);

        $events = [];

        foreach ($rows as $row) {
            $due = (string) ($row['due_back_date'] ?? '');

            if (!self::inWindow($due)) {
                continue;
            }

            $events[] = [
                'uid'         => 'hire-' . (int) $row['id'],
                'date'        => $due,
                'summary'     => sprintf('Due back: %s %s', $row['asset_tag'], $row['asset_name']),
                'description' => self::describeLines([
                    'Reference' => (string) ($row['reference'] ?? ''),
                    'Hirer'     => Hirer::label(['name' => $row['hirer_name'], 'company_name' => $row['company_name']]),
                    'Taken out' => format_date((string) $row['checked_out_at']),
                    'Status'    => (string) $row['effective_status'],
                ]),
                'location'    => '',
                'path'        => $hirerId === null ? '/hires/' . (int) $row['id'] : '/my-hires/' . (int) $row['id'],
                'category'    => 'Hire',
            ];
        }

        return $events;
    }

    // -- iCalendar assembly -------------------------------------------------

    /**
     * @param array<int,array<string,mixed>> $events
     */
    private static function document(array $events, string $userName): string
    {
        $appName = (string) Config::get('app.name', 'Asset Register');
        $stamp   = gmdate('Ymd\THis\Z');

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Asset Register//Calendar Feed//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:' . self::escape($appName . ' — ' . $userName),
            'X-WR-TIMEZONE:' . self::escape((string) Config::get('app.timezone', 'Europe/London')),
            // Both spellings: REFRESH-INTERVAL is the standard one (RFC 7986),
            // X-PUBLISHED-TTL is what Outlook has always read.
            'REFRESH-INTERVAL;VALUE=DURATION:PT6H',
            'X-PUBLISHED-TTL:PT6H',
        ];

        foreach ($events as $event) {
            $start = str_replace('-', '', (string) $event['date']);
            $end   = date('Ymd', strtotime((string) $event['date'] . ' +1 day'));

            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:' . self::escape((string) $event['uid'] . '@' . self::host());
            $lines[] = 'DTSTAMP:' . $stamp;
            // All-day events: a due date is a day, not a time of day, and
            // pinning it to a clock time makes it move for anyone in another
            // timezone.
            $lines[] = 'DTSTART;VALUE=DATE:' . $start;
            $lines[] = 'DTEND;VALUE=DATE:' . $end;
            $lines[] = 'SUMMARY:' . self::escape((string) $event['summary']);

            if ((string) $event['description'] !== '') {
                $lines[] = 'DESCRIPTION:' . self::escape((string) $event['description']);
            }

            if ((string) $event['location'] !== '') {
                $lines[] = 'LOCATION:' . self::escape((string) $event['location']);
            }

            $url = self::absoluteUrl((string) $event['path']);
            if ($url !== '') {
                $lines[] = 'URL;VALUE=URI:' . self::escape($url);
            }

            $lines[] = 'CATEGORIES:' . self::escape((string) $event['category']);
            $lines[] = 'TRANSP:TRANSPARENT';
            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';

        $folded = array_map([self::class, 'fold'], $lines);

        // RFC 5545 wants CRLF, and Outlook is the client that actually minds.
        return implode("\r\n", $folded) . "\r\n";
    }

    /**
     * Escape a text value: backslash, semicolon and comma are delimiters in
     * iCalendar, and a literal newline would end the property.
     */
    private static function escape(string $value): string
    {
        return str_replace(
            ['\\', "\r\n", "\n", "\r", ';', ','],
            ['\\\\', '\\n', '\\n', '\\n', '\\;', '\\,'],
            $value
        );
    }

    /**
     * Fold to 75 octets per line, continuations prefixed with a space.
     *
     * Counted in octets rather than characters, and split on character
     * boundaries, so a multi-byte character is never cut in half — which some
     * parsers survive and others do not.
     */
    private static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $out       = '';
        $lineBytes = 0;
        $limit     = 75;

        foreach (preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            $width = strlen($char);

            if ($lineBytes + $width > $limit) {
                $out      .= "\r\n ";
                $lineBytes = 1;   // the leading space counts
                $limit     = 75;
            }

            $out      .= $char;
            $lineBytes += $width;
        }

        return $out;
    }

    /** @param array<string,string> $pairs */
    private static function describeLines(array $pairs): string
    {
        $lines = [];

        foreach ($pairs as $label => $value) {
            $value = trim($value);

            if ($value !== '' && $value !== '—') {
                $lines[] = $label . ': ' . $value;
            }
        }

        return implode("\n", $lines);
    }

    private static function absoluteUrl(string $path): string
    {
        $base = rtrim((string) Config::get('app.url', ''), '/');

        return $base === '' ? '' : $base . '/' . ltrim($path, '/');
    }

    /** A stable domain for UIDs, so re-subscribing does not duplicate events. */
    private static function host(): string
    {
        $base = (string) Config::get('app.url', '');
        $host = $base === '' ? '' : (string) (parse_url($base, PHP_URL_HOST) ?? '');

        return $host === '' ? 'asset-register.local' : $host;
    }

    private static function inWindow(string $date): bool
    {
        if ($date === '' || str_starts_with($date, '0000')) {
            return false;
        }

        $timestamp = strtotime($date);

        if ($timestamp === false) {
            return false;
        }

        return $timestamp >= strtotime('-' . self::PAST_DAYS . ' days')
            && $timestamp <= strtotime('+' . self::FUTURE_DAYS . ' days');
    }
}
