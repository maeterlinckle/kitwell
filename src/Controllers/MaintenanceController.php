<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Flash;
use App\Core\Image;
use App\Core\Request;
use App\Core\Response;
use App\Core\Upload;
use App\Models\ActivityLog;
use App\Models\Asset;
use App\Models\Category;
use App\Models\Location;
use App\Models\MaintenanceLog;
use App\Models\MaintenanceRoutine;
use App\Models\MaintenanceSchedule;
use App\Models\RoutineCompletion;
use App\Models\Team;
use App\Models\User;

final class MaintenanceController extends Controller
{
    /**
     * The follow-up check created by the last recordCompletion(), if any.
     *
     * A property rather than a widened return type: both callers already use
     * the returned log id to anchor their redirect, and only the flash message
     * cares whether a follow-up was scheduled.
     *
     * @var array{id:int,due:string}|null
     */
    private ?array $lastFollowUp = null;

    /** The work list: everything due, overdue or scheduled. */
    public function index(): void
    {
        $filters = self::filtersFromRequest();
        $page    = max(1, (int) Request::query('page', 1));

        $this->view('maintenance/index', [
            'pageTitle'   => 'Maintenance',
            'result'      => MaintenanceSchedule::search($filters, $page, 25),
            'filters'     => $filters,
            'summary'     => MaintenanceSchedule::summary(),
            'categories'  => Category::all(true),
            'locations'   => Location::forSelect(),
            'users'       => MaintenanceSchedule::assignableUsers(),
            'teams'       => MaintenanceSchedule::assignableTeams(),
            'queryString' => self::queryString($filters),
        ]);
    }

    /** Completion history across every asset. */
    public function history(): void
    {
        $filters = [
            'q'            => (string) Request::query('q', ''),
            'type'         => array_values(array_filter((array) (Request::query('type', []) ?? []), 'is_string')),
            'result'       => array_values(array_filter((array) (Request::query('result', []) ?? []), 'is_string')),
            'performed_by' => (string) Request::query('by', ''),
            'from'         => (string) Request::query('from', ''),
            'to'           => (string) Request::query('to', ''),
        ];

        $result = MaintenanceLog::search($filters, max(1, (int) Request::query('page', 1)), 25);

        $this->view('maintenance/history', [
            'pageTitle'   => 'Maintenance history',
            'result'      => $result,
            'filters'     => $filters,
            // Which of these entries came from a routine, in one query rather
            // than one per row.
            'completions' => RoutineCompletion::forLogs(array_map('intval', array_column($result['rows'], 'id'))),
            'users'     => MaintenanceSchedule::assignableUsers(),
            'totalCost' => MaintenanceLog::totalCost(
                $filters['from'] !== '' ? $filters['from'] : null,
                $filters['to'] !== '' ? $filters['to'] : null
            ),
        ]);
    }

    public function show(string $id): void
    {
        $schedule = MaintenanceSchedule::find((int) $id);

        if ($schedule === null) {
            $this->notFound();
        }

        $logs = MaintenanceLog::forSchedule((int) $schedule['id']);

        $this->view('maintenance/show', [
            'pageTitle'   => $schedule['title'] . ' · ' . $schedule['asset_tag'],
            'schedule'    => $schedule,
            'logs'        => $logs,
            'routine'     => $schedule['routine_id'] === null
                ? null
                : MaintenanceRoutine::find((int) $schedule['routine_id']),
            'completions' => RoutineCompletion::forLogs(array_map('intval', array_column($logs, 'id'))),
            'nextDue'     => MaintenanceSchedule::nextDueAfter($schedule, date('Y-m-d')),
        ]);
    }

    public function create(): void
    {
        $assetId = (int) Request::query('asset', 0);
        $asset   = $assetId > 0 ? Asset::find($assetId) : null;

        $this->view('maintenance/form', [
            'pageTitle' => $asset !== null ? 'New schedule for ' . $asset['asset_tag'] : 'New maintenance schedule',
            'schedule'  => null,
            'asset'     => $asset,
            'assets'    => $asset === null ? Asset::search(['type' => ''], 1, 500)['rows'] : [],
            'users'     => MaintenanceSchedule::assignableUsers(),
            'teams'     => MaintenanceSchedule::assignableTeams(),
            'routines'  => MaintenanceRoutine::runnable(),
        ]);
    }

    public function store(): void
    {
        $data = $this->validateSchedule();

        $data['created_by'] = Auth::id();

        $id    = MaintenanceSchedule::create($data);
        $asset = Asset::find((int) $data['asset_id']);

        ActivityLog::record(
            'created',
            'maintenance_schedule',
            $id,
            sprintf('Added maintenance schedule "%s" to %s', $data['title'], $asset['asset_tag'] ?? '')
        );

        Flash::success('Maintenance schedule created.');
        Response::redirect('/maintenance/' . $id);
    }

    public function edit(string $id): void
    {
        $schedule = MaintenanceSchedule::find((int) $id);

        if ($schedule === null) {
            $this->notFound();
        }

        $this->view('maintenance/form', [
            'pageTitle' => 'Edit ' . $schedule['title'],
            'schedule'  => $schedule,
            'asset'     => Asset::find((int) $schedule['asset_id']),
            'assets'    => [],
            'users'     => MaintenanceSchedule::assignableUsers(),
            'teams'     => MaintenanceSchedule::assignableTeams(),
            'routines'  => MaintenanceRoutine::runnable(),
        ]);
    }

    public function update(string $id): void
    {
        $scheduleId = (int) $id;
        $schedule   = MaintenanceSchedule::find($scheduleId);

        if ($schedule === null) {
            $this->notFound();
        }

        $data = $this->validateSchedule($schedule);
        unset($data['asset_id']); // an existing schedule stays with its asset

        $data['is_active'] = Request::boolean('is_active') ? 1 : 0;

        MaintenanceSchedule::update($scheduleId, $data);

        ActivityLog::record(
            'updated',
            'maintenance_schedule',
            $scheduleId,
            'Updated maintenance schedule "' . $data['title'] . '"',
            ActivityLog::diff($schedule, $data)
        );

        Flash::success('Schedule updated.');
        Response::redirect('/maintenance/' . $scheduleId);
    }

    public function destroy(string $id): void
    {
        $scheduleId = (int) $id;
        $schedule   = MaintenanceSchedule::find($scheduleId);

        if ($schedule === null) {
            $this->notFound();
        }

        // Completions are history and must survive; the schema keeps them by
        // setting maintenance_logs.schedule_id to NULL on delete.
        MaintenanceSchedule::delete($scheduleId);

        ActivityLog::record(
            'deleted',
            'maintenance_schedule',
            $scheduleId,
            sprintf('Deleted schedule "%s" from %s (its %d completion record(s) were kept)',
                $schedule['title'], $schedule['asset_tag'], (int) $schedule['completion_count'])
        );

        Flash::success('Schedule deleted. Its completion history has been kept against the asset.');
        Response::redirect('/assets/' . (int) $schedule['asset_id']);
    }

    /** The completion form for a scheduled job. */
    public function completeForm(string $id): void
    {
        $schedule = MaintenanceSchedule::find((int) $id);

        if ($schedule === null) {
            $this->notFound();
        }

        // A job that calls for a routine is completed by carrying the routine
        // out. The wizard writes the same maintenance log and rolls the
        // schedule forward through the same code, so nothing downstream can
        // tell which door was used.
        $routineId = (int) ($schedule['routine_id'] ?? 0);

        if ($routineId > 0 && MaintenanceRoutine::currentVersion($routineId) !== null) {
            Response::redirect(sprintf(
                '/assets/%d/routines/%d/run?schedule=%d',
                (int) $schedule['asset_id'],
                $routineId,
                (int) $schedule['id']
            ));
        }

        $this->view('maintenance/complete', [
            'pageTitle' => 'Complete: ' . $schedule['title'],
            'schedule'  => $schedule,
            'asset'     => Asset::find((int) $schedule['asset_id']),
            'users'     => MaintenanceSchedule::assignableUsers(),
            'nextDue'   => MaintenanceSchedule::nextDueAfter($schedule, date('Y-m-d')),
        ]);
    }

    /** Record a completion against a schedule. */
    public function complete(string $id): void
    {
        $scheduleId = (int) $id;
        $schedule   = MaintenanceSchedule::find($scheduleId);

        if ($schedule === null) {
            $this->notFound();
        }

        $assetId = (int) $schedule['asset_id'];
        $logId   = $this->recordCompletion($assetId, $schedule, '/maintenance/' . $scheduleId . '/complete');

        Flash::success('Maintenance logged against ' . $schedule['asset_tag'] . '.' . $this->followUpNote());
        Response::redirect('/maintenance/' . $scheduleId . '#log-' . $logId);
    }

    /** A sentence for the flash message when a follow-up was scheduled too. */
    private function followUpNote(): string
    {
        if ($this->lastFollowUp === null) {
            return '';
        }

        return ' A follow-up check is scheduled for ' . format_date($this->lastFollowUp['due']) . '.';
    }

    /**
     * The front door for unplanned work.
     *
     * Lookup first, the same shape the PAT flow uses: scan or search for the
     * asset, then straight into the form. Work that was never on a schedule is
     * recorded here rather than only from the asset's Maintenance card.
     */
    public function logChooser(): void
    {
        $assetId = (int) Request::query('asset', 0);

        if ($assetId > 0 && Asset::find($assetId) !== null) {
            Response::redirect('/assets/' . $assetId . '/maintenance/log');
        }

        $keywords = trim((string) Request::query('q', ''));

        $this->view('maintenance/choose-asset', [
            'pageTitle' => 'Record maintenance',
            'keywords'  => $keywords,
            'assets'    => $keywords === ''
                ? Asset::recentlyMaintained(15)
                : Asset::search(['q' => $keywords, 'status' => ['In Stock', 'On Hire', 'In Maintenance']], 1, 25)['rows'],
        ]);
    }

    /** Log unplanned work straight onto an asset, with no schedule involved. */
    public function logForm(string $assetId): void
    {
        $asset = Asset::find((int) $assetId);

        if ($asset === null) {
            $this->notFound();
        }

        $this->view('maintenance/complete', [
            'pageTitle' => 'Log maintenance · ' . $asset['asset_tag'],
            'schedule'  => null,
            'asset'     => $asset,
            'users'     => MaintenanceSchedule::assignableUsers(),
            'nextDue'   => null,
        ]);
    }

    public function log(string $assetId): void
    {
        $id    = (int) $assetId;
        $asset = Asset::find($id);

        if ($asset === null) {
            $this->notFound();
        }

        $logId = $this->recordCompletion($id, null, '/assets/' . $id . '/maintenance/log');

        Flash::success('Maintenance logged against ' . $asset['asset_tag'] . '.' . $this->followUpNote());
        Response::redirect('/assets/' . $id . '#maintenance');
    }

    /**
     * The fields a completed record may be corrected on, and what to call each
     * one in the audit trail. Deliberately excludes the asset and the schedule:
     * moving a record to a different machine is not a correction, it is a
     * different record.
     */
    private const EDITABLE_LOG_FIELDS = [
        'performed_on'         => 'Date performed',
        'maintenance_type'     => 'Type of work',
        'result'               => 'Result',
        'performed_by_user_id' => 'Performed by (staff)',
        'performed_by_name'    => 'Performed by (contractor)',
        'work_done'            => 'Work done',
        'parts_used'           => 'Parts used',
        'cost'                 => 'Cost',
        'downtime_minutes'     => 'Downtime (minutes)',
        'condition_after'      => 'Condition afterwards',
        'notes'                => 'Notes',
    ];

    /** Correct a completed record after the fact. */
    public function editLog(string $logId): void
    {
        $log = MaintenanceLog::find((int) $logId);

        if ($log === null) {
            $this->notFound();
        }

        $this->view('maintenance/edit-log', [
            'pageTitle' => 'Edit maintenance record · ' . $log['asset_tag'],
            'log'       => $log,
            'users'     => MaintenanceSchedule::assignableUsers(),
            'history'   => ActivityLog::recent(50, [
                'entity_type' => 'maintenance_log',
                'entity_id'   => (int) $log['id'],
            ]),
        ]);
    }

    public function updateLog(string $logId): void
    {
        $id  = (int) $logId;
        $log = MaintenanceLog::find($id);

        if ($log === null) {
            $this->notFound();
        }

        $redirectTo = '/maintenance/logs/' . $id . '/edit';

        $data = $this->validate([
            'performed_on'         => 'required|date',
            'maintenance_type'     => 'required|in:' . implode(',', MaintenanceLog::TYPES),
            'result'               => 'required|in:' . implode(',', MaintenanceLog::RESULTS),
            'performed_by_user_id' => 'integer',
            'performed_by_name'    => 'max:191',
            'work_done'            => 'required|max:5000',
            'parts_used'           => 'max:5000',
            'cost'                 => 'numeric|min_value:0|max_value:9999999',
            'downtime_minutes'     => 'integer|min_value:0|max_value:65535',
            'condition_after'      => 'in:' . implode(',', Asset::CONDITIONS),
            'notes'                => 'max:5000',
            'edit_reason'          => 'max:191',
        ], [
            'performed_on'     => 'Date performed',
            'maintenance_type' => 'Type of work',
            'work_done'        => 'Work done',
            'edit_reason'      => 'Reason for the correction',
        ], $redirectTo);

        if ($data['performed_on'] > date('Y-m-d')) {
            $this->failValidation(['performed_on' => 'The date performed cannot be in the future.'], $redirectTo);
        }

        $performedByUserId = (int) $data['performed_by_user_id'];

        $after = [
            'maintenance_type'     => $data['maintenance_type'],
            'performed_on'         => $data['performed_on'],
            'performed_by_user_id' => $performedByUserId > 0 ? $performedByUserId : null,
            'performed_by_name'    => $data['performed_by_name'] !== '' ? $data['performed_by_name'] : null,
            'work_done'            => $data['work_done'],
            'parts_used'           => $data['parts_used'] !== '' ? $data['parts_used'] : null,
            'cost'                 => $data['cost'] !== '' ? $data['cost'] : null,
            'downtime_minutes'     => $data['downtime_minutes'] !== '' ? (int) $data['downtime_minutes'] : null,
            'result'               => $data['result'],
            'condition_after'      => $data['condition_after'] !== '' ? $data['condition_after'] : null,
            'notes'                => $data['notes'] !== '' ? $data['notes'] : null,
        ];

        // Diff against the row as it stands, not against the form's own idea of
        // what was there — the record may have been edited by someone else
        // while this form was open.
        $changes = ActivityLog::diff($log, $after);

        if ($changes === []) {
            Flash::info('Nothing was changed.');
            Response::redirect('/maintenance/history?asset_id=' . (int) $log['asset_id']);
        }

        MaintenanceLog::update($id, $after);

        $reason = trim((string) $data['edit_reason']);

        // The audit entry is the point of the feature, so it carries the
        // field-level before and after, who and when (ActivityLog fills those
        // in), and a readable summary naming the fields that moved.
        ActivityLog::record(
            'updated',
            'maintenance_log',
            $id,
            sprintf(
                'Corrected the maintenance record of %s on %s (%s)%s',
                $log['asset_tag'],
                format_date((string) $log['performed_on']),
                $this->describeChangedFields($changes),
                $reason === '' ? '' : ' — ' . str_limit($reason, 200)
            ),
            $reason === '' ? $changes : ['reason' => $reason] + $changes
        );

        // Also against the asset, so the machine's own trail shows that its
        // maintenance history was rewritten.
        ActivityLog::record(
            'maintenance_record_edited',
            'asset',
            (int) $log['asset_id'],
            sprintf(
                'Maintenance record of %s was corrected (%s)',
                format_date((string) $log['performed_on']),
                $this->describeChangedFields($changes)
            )
        );

        Flash::success('Record updated. The change is in the activity log.');
        Response::redirect('/maintenance/logs/' . $id . '/edit');
    }

    /**
     * "Date performed, Cost and 2 more" — a summary a person can read without
     * opening the JSON payload.
     *
     * @param array<string,array{from:mixed,to:mixed}> $changes
     */
    private function describeChangedFields(array $changes): string
    {
        $labels = [];

        foreach (array_keys($changes) as $field) {
            $labels[] = self::EDITABLE_LOG_FIELDS[$field] ?? $field;
        }

        if (count($labels) <= 3) {
            return implode(', ', $labels);
        }

        return implode(', ', array_slice($labels, 0, 3)) . ' and ' . (count($labels) - 3) . ' more';
    }

    /**
     * Shared by both completion routes: validate, write the log, attach any
     * photos, then update the schedule and the asset.
     *
     * @param array<string,mixed>|null $schedule
     */
    private function recordCompletion(int $assetId, ?array $schedule, string $redirectTo): int
    {
        $data = $this->validate([
            'performed_on'      => 'required|date',
            'maintenance_type'  => 'required|in:' . implode(',', MaintenanceLog::TYPES),
            'result'            => 'required|in:' . implode(',', MaintenanceLog::RESULTS),
            'performed_by_user_id' => 'integer',
            'performed_by_name' => 'max:191',
            'work_done'         => 'required|max:5000',
            'parts_used'        => 'max:5000',
            'cost'              => 'numeric|min_value:0|max_value:9999999',
            'downtime_minutes'  => 'integer|min_value:0|max_value:65535',
            'condition_after'   => 'in:' . implode(',', Asset::CONDITIONS),
            'next_due_date'     => 'date',
            'notes'             => 'max:5000',
            'followup_interval' => 'integer|min_value:1|max_value:365',
            'followup_unit'     => 'in:' . implode(',', MaintenanceSchedule::UNITS),
            'followup_title'    => 'max:191',
        ], [
            'performed_on'      => 'Date performed',
            'maintenance_type'  => 'Type of work',
            'work_done'         => 'Work done',
            'next_due_date'     => 'Next due date',
            'followup_interval' => 'Follow-up interval',
            'followup_unit'     => 'Follow-up unit',
            'followup_title'    => 'Follow-up title',
        ], $redirectTo);

        // Worked out before anything is written, so an unusable interval is a
        // field error rather than a log row with no follow-up attached to it.
        $followUpDue = null;

        if (Request::boolean('schedule_followup')) {
            if ((string) $data['followup_interval'] === '') {
                $this->failValidation(
                    ['followup_interval' => 'Say how long until the follow-up check, or untick it.'],
                    $redirectTo
                );
            }

            $followUpDue = MaintenanceSchedule::dateAfter(
                (string) $data['performed_on'],
                (int) $data['followup_interval'],
                (string) ($data['followup_unit'] !== '' ? $data['followup_unit'] : 'weeks')
            );

            if ($followUpDue === null) {
                $this->failValidation(
                    ['followup_interval' => 'That follow-up interval could not be turned into a date.'],
                    $redirectTo
                );
            }
        }

        if ($data['performed_on'] > date('Y-m-d')) {
            $this->failValidation(['performed_on' => 'The date performed cannot be in the future.'], $redirectTo);
        }

        $performedByUserId = (int) $data['performed_by_user_id'];

        $logId = MaintenanceLog::create([
            'asset_id'             => $assetId,
            'schedule_id'          => $schedule === null ? null : (int) $schedule['id'],
            'maintenance_type'     => $data['maintenance_type'],
            'performed_on'         => $data['performed_on'],
            'performed_by_user_id' => $performedByUserId > 0 ? $performedByUserId : null,
            'performed_by_name'    => $data['performed_by_name'] !== '' ? $data['performed_by_name'] : null,
            'work_done'            => $data['work_done'],
            'parts_used'           => $data['parts_used'] !== '' ? $data['parts_used'] : null,
            'cost'                 => $data['cost'] !== '' ? $data['cost'] : null,
            'downtime_minutes'     => $data['downtime_minutes'] !== '' ? (int) $data['downtime_minutes'] : null,
            'result'               => $data['result'],
            'condition_after'      => $data['condition_after'] !== '' ? $data['condition_after'] : null,
            'next_due_date'        => $data['next_due_date'] !== '' ? $data['next_due_date'] : null,
            'notes'                => $data['notes'] !== '' ? $data['notes'] : null,
            'created_by'           => Auth::id(),
        ]);

        $this->attachPhotos($logId);
        $this->attachDocuments($logId);

        // Roll the schedule forward. The form carries the calculated next due
        // date so it can be overridden before saving.
        if ($schedule !== null) {
            $nextDue = $data['next_due_date'] !== ''
                ? $data['next_due_date']
                : MaintenanceSchedule::nextDueAfter($schedule, $data['performed_on']);

            MaintenanceSchedule::applyCompletion(
                (int) $schedule['id'],
                $data['performed_on'],
                $nextDue,
                $schedule['maintenance_type'] === 'ad-hoc'
            );
        }

        $asset = Asset::find($assetId);

        // "Check this again in three weeks." A one-off schedule, so it shows up
        // in the maintenance list and the reminders like any other job, and
        // closes itself once done rather than becoming a recurrence nobody
        // meant to create.
        $followUpId = null;

        if ($followUpDue !== null) {
            $title = (string) $data['followup_title'];

            if ($title === '') {
                $title = $schedule === null
                    ? 'Follow-up check'
                    : 'Follow-up check: ' . str_limit((string) $schedule['title'], 160);
            }

            $followUpId = MaintenanceSchedule::createFollowUp($assetId, [
                'title'               => $title,
                'due_date'            => $followUpDue,
                'assigned_to_team_id' => $schedule === null ? null : ($schedule['assigned_to_team_id'] ?? null),
                'assigned_to_user_id' => $performedByUserId > 0 ? $performedByUserId : null,
                'instructions'        => "Follow-up on the work recorded on " . format_date($data['performed_on'])
                    . ":\n\n" . str_limit((string) $data['work_done'], 1000),
            ]);

            ActivityLog::record(
                'created',
                'maintenance_schedule',
                $followUpId,
                sprintf('Scheduled a follow-up check on %s for %s', $asset['asset_tag'] ?? ('asset #' . $assetId), format_date($followUpDue))
            );
        }

        // Carry the recorded condition onto the asset, and put it back in
        // stock if it was sitting in maintenance.
        $assetChanges = [];

        // Belt and braces: condition_rating is NOT NULL, so only ever write a
        // value the schema actually allows.
        if (in_array($data['condition_after'], Asset::CONDITIONS, true)) {
            $assetChanges['condition_rating'] = $data['condition_after'];
        }

        if (Request::boolean('return_to_stock') && $asset !== null && $asset['status'] === 'In Maintenance') {
            $assetChanges['status'] = 'In Stock';
        }

        if ($assetChanges !== []) {
            $assetChanges['updated_by'] = Auth::id();
            Asset::update($assetId, $assetChanges);
        }

        $this->lastFollowUp = $followUpId === null ? null : ['id' => $followUpId, 'due' => $followUpDue];

        ActivityLog::record(
            'maintenance_logged',
            'asset',
            $assetId,
            sprintf(
                '%s on %s: %s',
                $schedule === null ? 'Logged unplanned maintenance' : 'Completed "' . $schedule['title'] . '"',
                format_date($data['performed_on']),
                str_limit($data['work_done'], 120)
            ),
            $assetChanges === [] ? null : ['asset_changes' => $assetChanges]
        );

        return $logId;
    }

    /** Optional photos attached to a completion (before/after, faults found). */
    private function attachPhotos(int $logId): void
    {
        $files = Upload::files('photos');

        if ($files === []) {
            return;
        }

        $maxBytes   = (int) Config::get('uploads.max_photo_bytes');
        $mimes      = (array) Config::get('uploads.photo_mimes');
        $extensions = (array) Config::get('uploads.photo_extensions');

        foreach ($files as $file) {
            $error = Upload::validate($file, $mimes, $extensions, $maxBytes);

            if ($error !== null) {
                Flash::error($error);
                continue;
            }

            $mime      = (string) Upload::detectMime($file['tmp_name']);
            $extension = match ($mime) {
                'image/png'  => 'png',
                'image/webp' => 'webp',
                'image/heic' => 'heic',
                'image/heif' => 'heif',
                default      => 'jpg',
            };

            $path     = Upload::store($file, 'maintenance/' . $logId, $extension);
            $absolute = Upload::absolutePath($path);

            if ($absolute !== null) {
                Image::normalise($absolute, $mime);
            }

            MaintenanceLog::addPhoto([
                'maintenance_log_id' => $logId,
                'file_path'          => $path,
                'original_filename'  => Upload::displayName($file['name']),
                'mime_type'          => $mime,
                'file_size_bytes'    => $absolute !== null ? (int) filesize($absolute) : (int) $file['size'],
                'caption'            => null,
                'uploaded_by'        => Auth::id(),
            ]);
        }
    }

    /**
     * Optional documents attached to a completion.
     *
     * The paperwork a visit produces — a contractor's service report, a
     * calibration certificate, an invoice. Validated exactly as an asset manual
     * is: the byte size, the extension and the sniffed MIME all against the
     * `pdf` limits in config/config.php, with the stored name generated rather
     * than taken from the client.
     */
    private function attachDocuments(int $logId): void
    {
        $files = Upload::files('documents');

        if ($files === []) {
            return;
        }

        $maxBytes   = (int) Config::get('uploads.max_pdf_bytes');
        $mimes      = (array) Config::get('uploads.pdf_mimes');
        $extensions = (array) Config::get('uploads.pdf_extensions');

        $title = trim((string) Request::post('document_title', ''));

        foreach ($files as $index => $file) {
            $error = Upload::validate($file, $mimes, $extensions, $maxBytes);

            if ($error !== null) {
                Flash::error($error);
                continue;
            }

            $displayName = Upload::displayName($file['name']);

            // One title for a single file; several at once fall back to each
            // file's own name so they stay distinguishable in the list.
            $documentTitle = ($title !== '' && count($files) === 1)
                ? $title
                : ($title !== '' ? $title . ' (' . ($index + 1) . ')' : pathinfo($displayName, PATHINFO_FILENAME));

            MaintenanceLog::addDocument([
                'maintenance_log_id' => $logId,
                'title'              => mb_substr($documentTitle, 0, 191),
                'file_path'          => Upload::store($file, 'maintenance/' . $logId . '/documents', 'pdf'),
                'original_filename'  => $displayName,
                'mime_type'          => 'application/pdf',
                'file_size_bytes'    => (int) $file['size'],
                'notes'              => null,
                'uploaded_by'        => Auth::id(),
            ]);
        }
    }

    /** Stream a document attached to a maintenance log. */
    public function document(string $logId, string $documentId): void
    {
        $document = MaintenanceLog::findDocument((int) $documentId);

        if ($document === null || (int) $document['maintenance_log_id'] !== (int) $logId) {
            $this->notFound('That document is no longer attached to this record.');
        }

        $path = Upload::absolutePath((string) $document['file_path']);

        if ($path === null) {
            $this->notFound('The file is missing from the server. It may need re-uploading.');
        }

        $download = Request::query('download') === '1';
        $filename = Upload::displayName((string) ($document['original_filename'] ?: $document['title'] . '.pdf'));

        if (!str_ends_with(strtolower($filename), '.pdf')) {
            $filename .= '.pdf';
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/pdf');
        header('Content-Length: ' . (string) filesize($path));
        header(sprintf(
            '%s; filename="%s"',
            'Content-Disposition: ' . ($download ? 'attachment' : 'inline'),
            str_replace('"', '', $filename)
        ));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=600');
        header_remove('Pragma');

        readfile($path);
        exit;
    }

    /** Stream a photo attached to a maintenance log. */
    public function photo(string $logId, string $photoId): void
    {
        $photo = MaintenanceLog::findPhoto((int) $photoId);

        if ($photo === null || (int) $photo['maintenance_log_id'] !== (int) $logId) {
            $this->notFound('That photo is no longer attached to this record.');
        }

        $path = Upload::absolutePath((string) $photo['file_path']);

        if ($path === null) {
            $this->notFound('The image file is missing from the server.');
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: ' . (string) $photo['mime_type']);
        header('Content-Length: ' . (string) filesize($path));
        header('Content-Disposition: inline; filename="' . str_replace('"', '', Upload::displayName((string) $photo['original_filename'])) . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=2592000, immutable');
        header_remove('Pragma');

        readfile($path);
        exit;
    }

    /**
     * Validate the schedule form.
     *
     * @param array<string,mixed>|null $existing
     * @return array<string,mixed>
     */
    private function validateSchedule(?array $existing = null): array
    {
        $redirect = $existing === null
            ? '/maintenance/create'
            : '/maintenance/' . (int) $existing['id'] . '/edit';

        $data = $this->validate([
            'asset_id'            => 'required|integer|exists:assets,id',
            'title'               => 'required|max:191',
            'maintenance_type'    => 'required|in:' . implode(',', MaintenanceSchedule::TYPES),
            'frequency_interval'  => 'integer|min_value:1|max_value:999',
            'frequency_unit'      => 'in:' . implode(',', MaintenanceSchedule::UNITS),
            'next_due_date'       => 'date',
            'last_completed_date' => 'date',
            'assigned_to'         => 'max:20',
            'estimated_minutes'   => 'integer|min_value:1|max_value:65535',
            'instructions'        => 'max:5000',
            'routine_id'          => 'integer',
        ], [
            'asset_id'            => 'Asset',
            'title'               => 'Title',
            'maintenance_type'    => 'Schedule type',
            'frequency_interval'  => 'Interval',
            'frequency_unit'      => 'Interval unit',
            'next_due_date'       => 'Next due date',
            'assigned_to'         => 'Assigned to',
        ], $redirect);

        $type = (string) $data['maintenance_type'];

        // A routine schedule can be picked from presets instead of typing an
        // interval; the preset wins when one is chosen.
        $preset = (string) Request::post('routine_preset', '');
        if ($type === 'routine' && isset(MaintenanceSchedule::ROUTINE_PRESETS[$preset])) {
            $data['frequency_interval'] = MaintenanceSchedule::ROUTINE_PRESETS[$preset]['interval'];
            $data['frequency_unit']     = MaintenanceSchedule::ROUTINE_PRESETS[$preset]['unit'];
        }

        if ($type === 'ad-hoc') {
            // One-off: no recurrence, but it still needs a date to be due on.
            $data['frequency_interval'] = null;
            $data['frequency_unit']     = null;

            if ($data['next_due_date'] === '') {
                $this->failValidation(['next_due_date' => 'A one-off job needs a date it is due on.'], $redirect);
            }
        } else {
            if ((int) $data['frequency_interval'] < 1 || $data['frequency_unit'] === '') {
                $this->failValidation(
                    ['frequency_interval' => 'Choose how often this recurs, or change the type to one-off.'],
                    $redirect
                );
            }

            // Without a first due date, work one out from the last completion
            // so the schedule starts life on the list rather than unscheduled.
            if ($data['next_due_date'] === '' && $data['last_completed_date'] !== '') {
                $data['next_due_date'] = MaintenanceSchedule::nextDueAfter(
                    ['maintenance_type' => $type, 'frequency_interval' => $data['frequency_interval'], 'frequency_unit' => $data['frequency_unit']],
                    (string) $data['last_completed_date']
                ) ?? '';
            }
        }

        // One control, one value, and exactly one of the two columns written.
        // Assigning to a team is not "also assign to a person", so the other
        // column is always cleared rather than left as it was.
        [$assigneeKind, $assigneeId] = MaintenanceSchedule::parseAssignee((string) $data['assigned_to']);

        if ($assigneeKind === 'user' && User::find($assigneeId) === null) {
            $this->failValidation(['assigned_to' => 'That person no longer has an account.'], $redirect);
        }

        if ($assigneeKind === 'team' && Team::find($assigneeId) === null) {
            $this->failValidation(['assigned_to' => 'That team no longer exists.'], $redirect);
        }

        // A routine can only be attached once it has something published: a
        // schedule pointing at a draft would send whoever picked the job up to
        // a form that does not exist yet.
        $routineId = (int) $data['routine_id'];

        if ($routineId > 0 && MaintenanceRoutine::currentVersion($routineId) === null) {
            $this->failValidation(
                ['routine_id' => 'That routine has no published version, so it cannot be attached to a job yet.'],
                $redirect
            );
        }

        return [
            'asset_id'            => (int) $data['asset_id'],
            'title'               => $data['title'],
            'maintenance_type'    => $type,
            'frequency_interval'  => $data['frequency_interval'] !== null && $data['frequency_interval'] !== ''
                ? (int) $data['frequency_interval'] : null,
            'frequency_unit'      => $data['frequency_unit'] !== null && $data['frequency_unit'] !== ''
                ? $data['frequency_unit'] : null,
            'next_due_date'       => $data['next_due_date'] !== '' ? $data['next_due_date'] : null,
            'last_completed_date' => $data['last_completed_date'] !== '' ? $data['last_completed_date'] : null,
            'assigned_to_user_id' => $assigneeKind === 'user' ? $assigneeId : null,
            'assigned_to_team_id' => $assigneeKind === 'team' ? $assigneeId : null,
            'estimated_minutes'   => $data['estimated_minutes'] !== '' ? (int) $data['estimated_minutes'] : null,
            'instructions'        => $data['instructions'] !== '' ? $data['instructions'] : null,
            'routine_id'          => $routineId > 0 ? $routineId : null,
        ];
    }

    /** @return array<string,mixed> */
    public static function filtersFromRequest(): array
    {
        return [
            'q'                => (string) Request::query('q', ''),
            'status'           => array_values(array_filter((array) (Request::query('status', []) ?? []), 'is_string')),
            'type'             => array_values(array_filter((array) (Request::query('type', []) ?? []), 'is_string')),
            'assigned_to'      => (string) Request::query('assignee', ''),
            'category_id'      => (string) Request::query('category', ''),
            'location_id'      => (string) Request::query('location', ''),
            'include_inactive' => Request::query('inactive') === '1',
            'sort'             => (string) Request::query('sort', 'due'),
        ];
    }

    /** @param array<string,mixed> $filters */
    public static function queryString(array $filters): string
    {
        $params = array_filter([
            'q'        => $filters['q'] ?? '',
            'assignee' => $filters['assigned_to'] ?? '',
            'category' => $filters['category_id'] ?? '',
            'location' => $filters['location_id'] ?? '',
            'inactive' => !empty($filters['include_inactive']) ? '1' : '',
            'sort'     => ($filters['sort'] ?? 'due') !== 'due' ? $filters['sort'] : '',
        ], static fn ($v): bool => $v !== '' && $v !== null);

        $query = http_build_query($params);

        foreach ((array) ($filters['status'] ?? []) as $status) {
            $query .= '&status%5B%5D=' . rawurlencode((string) $status);
        }

        foreach ((array) ($filters['type'] ?? []) as $type) {
            $query .= '&type%5B%5D=' . rawurlencode((string) $type);
        }

        return trim($query, '&');
    }
}
