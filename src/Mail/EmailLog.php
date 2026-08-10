<?php

declare(strict_types=1);

namespace App\Mail;

use App\Core\Auth;
use App\Core\Database;

/**
 * The record of every message the application has tried to send.
 *
 * Failures are logged as loudly as successes: a bad address or an SMTP outage
 * is invisible otherwise, and "the reminders stopped working three weeks ago"
 * is the kind of thing nobody notices until it matters.
 *
 * Like `activity_log`, `entity_id` carries no foreign key and `user_name` is a
 * snapshot, so the log survives deletion of whatever it refers to.
 */
final class EmailLog
{
    /**
     * @param array<string,mixed> $context template_key, entity_type, entity_id, trigger
     */
    public static function record(
        string $recipient,
        ?string $recipientName,
        string $subject,
        string $status,
        ?string $error,
        array $context = []
    ): void {
        $user    = Auth::user();
        $trigger = ($context['trigger'] ?? 'system') === 'user' ? 'user' : 'system';

        Database::insert('email_log', [
            'recipient'      => mb_substr($recipient, 0, 190),
            'recipient_name' => $recipientName === null ? null : mb_substr($recipientName, 0, 191),
            'subject'        => mb_substr($subject, 0, 255),
            'template_key'   => isset($context['template_key']) ? mb_substr((string) $context['template_key'], 0, 60) : null,
            'entity_type'    => isset($context['entity_type']) ? mb_substr((string) $context['entity_type'], 0, 64) : null,
            'entity_id'      => isset($context['entity_id']) ? (int) $context['entity_id'] : null,
            'status'         => $status === 'sent' ? 'sent' : 'failed',
            'error'          => $error === null ? null : mb_substr($error, 0, 500),
            'trigger_source' => $trigger,
            'user_id'        => $trigger === 'user' ? ($user['id'] ?? null) : null,
            'user_name'      => $trigger === 'user' ? (string) ($user['name'] ?? 'System') : 'System',
        ]);
    }

    /**
     * @param array<string,mixed> $filters status, template_key, q, entity_type, entity_id
     * @return array{rows:array<int,array<string,mixed>>,total:int,page:int,pages:int,per_page:int}
     */
    public static function search(array $filters = [], int $page = 1, int $perPage = 50): array
    {
        [$where, $params] = self::buildFilters($filters);

        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $total = (int) Database::scalar('SELECT COUNT(*) FROM email_log' . $whereSql, $params);

        $perPage = max(10, min(200, $perPage));
        $pages   = max(1, (int) ceil($total / $perPage));
        $page    = max(1, min($page, $pages));
        $offset  = ($page - 1) * $perPage;

        $rows = Database::select(
            'SELECT * FROM email_log' . $whereSql
            . ' ORDER BY created_at DESC, id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params
        );

        return [
            'rows'     => $rows,
            'total'    => $total,
            'page'     => $page,
            'pages'    => $pages,
            'per_page' => $perPage,
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{0:array<int,string>,1:array<int,mixed>}
     */
    private static function buildFilters(array $filters): array
    {
        $where  = [];
        $params = [];

        if (!empty($filters['status']) && in_array($filters['status'], ['sent', 'failed'], true)) {
            $where[]  = 'status = ?';
            $params[] = (string) $filters['status'];
        }

        if (!empty($filters['template_key'])) {
            $where[]  = 'template_key = ?';
            $params[] = (string) $filters['template_key'];
        }

        if (!empty($filters['entity_type'])) {
            $where[]  = 'entity_type = ?';
            $params[] = (string) $filters['entity_type'];
        }

        if (!empty($filters['entity_id'])) {
            $where[]  = 'entity_id = ?';
            $params[] = (int) $filters['entity_id'];
        }

        $keywords = trim((string) ($filters['q'] ?? ''));
        if ($keywords !== '') {
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $keywords) . '%';

            $clauses = [];
            foreach (['recipient', 'recipient_name', 'subject', 'error'] as $column) {
                $clauses[] = $column . " LIKE ? ESCAPE '!'";
                $params[]  = $like;
            }

            $where[] = '(' . implode(' OR ', $clauses) . ')';
        }

        return [$where, $params];
    }

    /**
     * Headline counts for the email settings page.
     *
     * @return array{total:int,sent:int,failed:int,failed_7:int,last_sent_at:?string}
     */
    public static function summary(): array
    {
        $row = Database::selectOne(
            "SELECT COUNT(*) AS total,
                    SUM(status = 'sent')   AS sent,
                    SUM(status = 'failed') AS failed,
                    SUM(status = 'failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS failed_7,
                    MAX(CASE WHEN status = 'sent' THEN created_at END) AS last_sent_at
               FROM email_log"
        );

        return [
            'total'        => (int) ($row['total'] ?? 0),
            'sent'         => (int) ($row['sent'] ?? 0),
            'failed'       => (int) ($row['failed'] ?? 0),
            'failed_7'     => (int) ($row['failed_7'] ?? 0),
            'last_sent_at' => $row['last_sent_at'] ?? null,
        ];
    }

    /**
     * Recent messages about one record — shown on a hirer's page so staff can
     * see what has already been sent before sending it again.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function forEntity(string $entityType, int $entityId, int $limit = 5): array
    {
        return Database::select(
            'SELECT * FROM email_log
              WHERE entity_type = ? AND entity_id = ?
              ORDER BY created_at DESC, id DESC
              LIMIT ' . max(1, min(50, $limit)),
            [$entityType, $entityId]
        );
    }

    /** Trim the log. Returns the number of rows removed. */
    public static function prune(int $days): int
    {
        return Database::run(
            'DELETE FROM email_log WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
            [max(1, $days)]
        )->rowCount();
    }
}
