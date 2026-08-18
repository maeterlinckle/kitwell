<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * A routine actually carried out, and the answers it produced.
 *
 * The completion is the detail behind a maintenance log entry rather than a
 * record beside it: the log is what puts the work in the asset's history, and
 * this is what was asked and answered while doing it.
 *
 * Every completion names the version it followed, and the version it names can
 * no longer be edited, so opening a two-year-old record shows the questions of
 * the day rather than today's.
 */
final class RoutineCompletion
{
    /**
     * How recently a routine counts as "just done" for the Routine scan
     * target. A fixed constant, not a setting: it exists to stop somebody
     * repeating work that was finished this week, and a site that wants a
     * different number wants a different feature.
     */
    public const RECENT_DAYS = 7;

    private const SELECT = 'SELECT c.*,
                                   r.name AS routine_name,
                                   r.description AS routine_description,
                                   v.version_number,
                                   v.published_at AS version_published_at,
                                   v.allow_out_of_order,
                                   v.page_batched,
                                   a.asset_tag, a.name AS asset_name,
                                   a.serial_number, a.manufacturer, a.model,
                                   cat.name AS category_name,
                                   loc.name AS location_name,
                                   u.name AS completed_by_name,
                                   su.name AS started_by_name,
                                   s.title AS schedule_title,
                                   ml.performed_on, ml.result, ml.work_done, ml.notes AS log_notes,
                                   (SELECT COUNT(*) FROM routine_response_files f WHERE f.completion_id = c.id) AS file_count
                              FROM routine_completions c
                              INNER JOIN maintenance_routines r ON r.id = c.routine_id
                              INNER JOIN routine_versions v ON v.id = c.version_id
                              INNER JOIN assets a ON a.id = c.asset_id
                              LEFT JOIN categories cat ON cat.id = a.category_id
                              LEFT JOIN locations loc ON loc.id = a.location_id
                              LEFT JOIN users u ON u.id = c.completed_by
                              LEFT JOIN users su ON su.id = c.started_by
                              LEFT JOIN maintenance_schedules s ON s.id = c.schedule_id
                              LEFT JOIN maintenance_logs ml ON ml.id = c.maintenance_log_id';

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::selectOne(self::SELECT . ' WHERE c.id = ?', [$id]);
    }

    /** The completion behind a maintenance log entry, if the work was a routine. */
    public static function forLog(int $logId): ?array
    {
        return Database::selectOne(self::SELECT . ' WHERE c.maintenance_log_id = ?', [$logId]);
    }

    /**
     * Which of these maintenance logs came from a routine, keyed by log id.
     *
     * One query for a whole history list rather than one per row.
     *
     * @param array<int,int> $logIds
     * @return array<int,array<string,mixed>>
     */
    public static function forLogs(array $logIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $logIds)));

        if ($ids === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));

        $rows = Database::select(
            'SELECT c.id, c.maintenance_log_id, c.routine_id, v.version_number, r.name AS routine_name
               FROM routine_completions c
               INNER JOIN routine_versions v ON v.id = c.version_id
               INNER JOIN maintenance_routines r ON r.id = c.routine_id
              WHERE c.maintenance_log_id IN (' . $placeholders . ')',
            $ids
        );

        $byLog = [];

        foreach ($rows as $row) {
            $byLog[(int) $row['maintenance_log_id']] = $row;
        }

        return $byLog;
    }

    /** @return array<int,array<string,mixed>> */
    public static function forAsset(int $assetId, int $limit = 50): array
    {
        return Database::select(
            self::SELECT . " WHERE c.asset_id = ? AND c.status = 'submitted'
                             ORDER BY c.completed_at DESC, c.id DESC LIMIT " . max(1, min(200, $limit)),
            [$assetId]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public static function forRoutine(int $routineId, int $limit = 100): array
    {
        return Database::select(
            self::SELECT . " WHERE c.routine_id = ? AND c.status = 'submitted'
                             ORDER BY c.completed_at DESC, c.id DESC LIMIT " . max(1, min(500, $limit)),
            [$routineId]
        );
    }

    /**
     * The run somebody has started on this asset and not yet closed out.
     *
     * At most one is open per asset at a time — a second station scanning the
     * same item should join the run in progress, not begin a rival one.
     *
     * @return array<string,mixed>|null
     */
    public static function openForAsset(int $assetId): ?array
    {
        return Database::selectOne(
            self::SELECT . " WHERE c.asset_id = ? AND c.status = 'open' ORDER BY c.id DESC",
            [$assetId]
        );
    }

    /** The most recent finished run on an asset, however long ago. */
    public static function latestSubmittedForAsset(int $assetId): ?array
    {
        return Database::selectOne(
            self::SELECT . " WHERE c.asset_id = ? AND c.status = 'submitted'
                             ORDER BY c.completed_at DESC, c.id DESC",
            [$assetId]
        );
    }

    /**
     * The most recent finished run on an asset, if it was within RECENT_DAYS.
     *
     * @return array<string,mixed>|null
     */
    public static function recentForAsset(int $assetId): ?array
    {
        $latest = self::latestSubmittedForAsset($assetId);

        if ($latest === null || $latest['completed_at'] === null) {
            return null;
        }

        $cutoff = date('Y-m-d H:i:s', strtotime('-' . self::RECENT_DAYS . ' days'));

        return (string) $latest['completed_at'] >= $cutoff ? $latest : null;
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        return Database::insert('routine_completions', $data);
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        Database::update('routine_completions', $data, $id);
    }

    /** @param array<string,mixed> $data */
    public static function addResponse(array $data): int
    {
        return Database::insert('routine_responses', $data);
    }

    /**
     * Record one answer, replacing whatever was there.
     *
     * A step answered again — a reading corrected before the run is closed —
     * takes the new answerer's name with it, because the record has to say who
     * stands behind what it now says.
     *
     * @param array<string,mixed> $values
     */
    public static function saveResponse(int $completionId, int $stepId, array $values, ?int $userId): void
    {
        Database::run(
            'DELETE FROM routine_responses WHERE completion_id = ? AND step_id = ?',
            [$completionId, $stepId]
        );

        Database::insert('routine_responses', $values + [
            'completion_id' => $completionId,
            'step_id'       => $stepId,
            'answered_by'   => $userId,
            'answered_at'   => date('Y-m-d H:i:s'),
        ]);
    }

    /** Take the answer to one step away, so the step reads as unanswered. */
    public static function forgetResponse(int $completionId, int $stepId): void
    {
        Database::run(
            "DELETE FROM routine_responses WHERE completion_id = ? AND step_id = ?",
            [$completionId, $stepId]
        );
    }

    /** Discard an open run outright. Its answers and files go with it. */
    public static function delete(int $id): void
    {
        Database::run("DELETE FROM routine_completions WHERE id = ? AND status = 'open'", [$id]);
    }

    /** Remove every file recorded against one step of one run. */
    public static function forgetFiles(int $completionId, int $stepId): array
    {
        $files = Database::select(
            'SELECT * FROM routine_response_files WHERE completion_id = ? AND step_id = ?',
            [$completionId, $stepId]
        );

        Database::run(
            'DELETE FROM routine_response_files WHERE completion_id = ? AND step_id = ?',
            [$completionId, $stepId]
        );

        return $files;
    }

    /**
     * Record a page of a batched run as finished, by whoever finished it.
     *
     * Re-submitting a page moves the name to whoever submitted it last, for
     * the same reason a re-answered step does: the record says who stands
     * behind what it now holds.
     */
    public static function completePage(int $completionId, int $pageId, ?int $userId): void
    {
        Database::run(
            'DELETE FROM routine_page_completions WHERE completion_id = ? AND page_id = ?',
            [$completionId, $pageId]
        );

        Database::insert('routine_page_completions', [
            'completion_id' => $completionId,
            'page_id'       => $pageId,
            'completed_by'  => $userId,
            'completed_at'  => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Which pages of a run are finished, with who finished them.
     *
     * @return array<int,array{name:?string,at:string}>
     */
    public static function pageCompletions(int $completionId): array
    {
        $rows = Database::select(
            'SELECT p.page_id, p.completed_at, u.name
               FROM routine_page_completions p
               LEFT JOIN users u ON u.id = p.completed_by
              WHERE p.completion_id = ?',
            [$completionId]
        );

        $byPage = [];

        foreach ($rows as $row) {
            $byPage[(int) $row['page_id']] = [
                'name' => $row['name'],
                'at'   => (string) $row['completed_at'],
            ];
        }

        return $byPage;
    }

    /**
     * Who answered each step, and when.
     *
     * @return array<int,array{name:?string,at:?string}>
     */
    public static function attribution(int $completionId): array
    {
        $rows = Database::select(
            'SELECT p.step_id, p.answered_at, u.name
               FROM routine_responses p
               LEFT JOIN users u ON u.id = p.answered_by
              WHERE p.completion_id = ?',
            [$completionId]
        );

        $byStep = [];

        foreach ($rows as $row) {
            $byStep[(int) $row['step_id']] = ['name' => $row['name'], 'at' => $row['answered_at']];
        }

        return $byStep;
    }

    /**
     * Every answer for one completion, keyed by the step it answers.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function responses(int $completionId): array
    {
        $rows = Database::select(
            'SELECT * FROM routine_responses WHERE completion_id = ?',
            [$completionId]
        );

        $byStep = [];

        foreach ($rows as $row) {
            $byStep[(int) $row['step_id']] = $row;
        }

        return $byStep;
    }

    /** @param array<string,mixed> $data */
    public static function addFile(array $data): int
    {
        return Database::insert('routine_response_files', $data);
    }

    /**
     * Files for one completion, grouped by the step they answer.
     *
     * @return array<int,array<int,array<string,mixed>>>
     */
    public static function files(int $completionId): array
    {
        $rows = Database::select(
            'SELECT * FROM routine_response_files WHERE completion_id = ? ORDER BY step_id, id',
            [$completionId]
        );

        $byStep = [];

        foreach ($rows as $row) {
            $byStep[(int) $row['step_id']][] = $row;
        }

        return $byStep;
    }

    /** @return array<string,mixed>|null */
    public static function findFile(int $fileId): ?array
    {
        return Database::selectOne('SELECT * FROM routine_response_files WHERE id = ?', [$fileId]);
    }

    /**
     * One answer as text, for the completion view, the PDF and anything else
     * that has to print it.
     *
     * Returns null when the step was left blank, so callers can say "not
     * answered" in their own voice rather than printing an empty line.
     *
     * @param array<string,mixed> $step
     * @param array<string,mixed>|null $response
     * @return array<int,string>|string|null
     */
    public static function answer(array $step, ?array $response): array|string|null
    {
        if ($response === null) {
            return null;
        }

        switch ((string) $step['field_type']) {
            case 'boolean':
                if ($response['value_boolean'] === null) {
                    return null;
                }

                return (int) $response['value_boolean'] === 1 ? 'Yes' : 'No';

            case 'number':
                if ($response['value_number'] === null) {
                    return null;
                }

                $number = rtrim(rtrim(number_format((float) $response['value_number'], 4, '.', ','), '0'), '.');
                $unit   = trim((string) ($step['unit'] ?? ''));

                return $unit === '' ? $number : $number . ' ' . $unit;

            case 'date':
                return $response['value_date'] === null ? null : format_date((string) $response['value_date']);

            case 'multi_choice':
                $text = trim((string) ($response['value_text'] ?? ''));

                if ($text === '') {
                    return null;
                }

                return array_values(array_filter(array_map('trim', preg_split('/\R/', $text) ?: [])));

            default:
                $text = trim((string) ($response['value_text'] ?? ''));

                return $text === '' ? null : $text;
        }
    }
}
