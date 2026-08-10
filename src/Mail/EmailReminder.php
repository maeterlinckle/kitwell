<?php

declare(strict_types=1);

namespace App\Mail;

use App\Core\Database;

/**
 * What has already been reminded about, and when.
 *
 * Without this, an item that has been overdue for three weeks generates
 * twenty-one identical emails and everybody starts filtering them to a folder —
 * at which point the reminder is worse than useless, because it looks like it
 * is working.
 *
 * One row per (reminder, record, recipient). `reminder_key` is part of the key
 * on purpose: when an item crosses from "due soon" to "overdue" that is a
 * different reminder, so it goes out at once rather than waiting for the repeat
 * window belonging to the earlier, gentler message to expire.
 */
final class EmailReminder
{
    /**
     * Entity ids already reminded about within the repeat window, for one
     * recipient. Returned as a lookup so the caller can filter a list cheaply.
     *
     * @param array<int,int> $entityIds
     * @return array<int,true>
     */
    public static function suppressed(string $reminderKey, string $entityType, array $entityIds, string $recipient, int $repeatDays): array
    {
        if ($entityIds === []) {
            return [];
        }

        $ids    = array_values(array_unique(array_map('intval', $entityIds)));
        $params = [$reminderKey, $entityType, mb_substr($recipient, 0, 190), max(1, $repeatDays)];

        foreach ($ids as $id) {
            $params[] = $id;
        }

        $rows = Database::select(
            'SELECT entity_id
               FROM email_reminders
              WHERE reminder_key = ?
                AND entity_type = ?
                AND recipient = ?
                AND last_sent_at > DATE_SUB(NOW(), INTERVAL ? DAY)
                AND entity_id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')',
            $params
        );

        $suppressed = [];
        foreach ($rows as $row) {
            $suppressed[(int) $row['entity_id']] = true;
        }

        return $suppressed;
    }

    /**
     * Note that a reminder has gone out.
     *
     * @param array<int,int> $entityIds
     */
    public static function markSent(string $reminderKey, string $entityType, array $entityIds, string $recipient): void
    {
        $recipient = mb_substr($recipient, 0, 190);
        $now       = date('Y-m-d H:i:s');

        foreach (array_unique(array_map('intval', $entityIds)) as $entityId) {
            Database::run(
                'INSERT INTO email_reminders (reminder_key, entity_type, entity_id, recipient, last_sent_at, send_count)
                      VALUES (:k, :t, :i, :r, :s, 1)
                 ON DUPLICATE KEY UPDATE
                      last_sent_at = VALUES(last_sent_at),
                      send_count = send_count + 1',
                ['k' => $reminderKey, 't' => $entityType, 'i' => $entityId, 'r' => $recipient, 's' => $now]
            );
        }
    }

    /**
     * Forget a record entirely — used when an item stops needing reminders, so
     * that if it comes back around later the first reminder is not silently
     * suppressed by a year-old row.
     */
    public static function forget(string $entityType, int $entityId): void
    {
        Database::run(
            'DELETE FROM email_reminders WHERE entity_type = ? AND entity_id = ?',
            [$entityType, $entityId]
        );
    }

    /** Housekeeping: rows older than the window can never suppress anything. */
    public static function prune(int $days = 180): int
    {
        return Database::run(
            'DELETE FROM email_reminders WHERE last_sent_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
            [max(30, $days)]
        )->rowCount();
    }

    /** @return array{tracked:int,last_sent_at:?string} */
    public static function summary(): array
    {
        $row = Database::selectOne('SELECT COUNT(*) AS tracked, MAX(last_sent_at) AS last_sent_at FROM email_reminders');

        return [
            'tracked'      => (int) ($row['tracked'] ?? 0),
            'last_sent_at' => $row['last_sent_at'] ?? null,
        ];
    }
}
