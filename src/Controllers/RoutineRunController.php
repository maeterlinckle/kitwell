<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Image;
use App\Core\Request;
use App\Core\Response;
use App\Core\Upload;
use App\Models\ActivityLog;
use App\Models\Asset;
use App\Models\MaintenanceLog;
use App\Models\MaintenanceRoutine;
use App\Models\MaintenanceSchedule;
use App\Models\RoutineCompletion;
use App\Services\RoutineDocument;

/**
 * Carrying out a routine, and reading one that was carried out.
 *
 * Running needs `maintenance.complete` — the same permission as logging any
 * other work — and never `routines.manage`. Designing the procedure and
 * following it are separate jobs and separate rights.
 *
 * A completion always produces a maintenance log entry, so routine work lands
 * in the asset's existing history rather than in a second history beside it.
 * When the routine was reached from a scheduled job, that schedule is rolled
 * forward by exactly the code that rolls it forward from the free-text form.
 */
final class RoutineRunController extends Controller
{
    /** Which routine to run against this asset. */
    public function choose(string $assetId): void
    {
        $asset = Asset::find((int) $assetId);

        if ($asset === null) {
            $this->notFound();
        }

        $this->view('routines/choose', [
            'pageTitle' => 'Run a routine · ' . $asset['asset_tag'],
            'asset'     => $asset,
            'routines'  => MaintenanceRoutine::runnableFor(self::categoryOf($asset)),
            'openRun'   => RoutineCompletion::openForAsset((int) $asset['id']),
        ]);
    }

    /** The guided form itself. */
    public function run(string $assetId, string $routineId): void
    {
        [$asset, $routine, $version] = $this->target((int) $assetId, (int) $routineId);

        $schedule = $this->scheduleFromQuery((int) $asset['id'], (int) $routine['id']);

        // A version worked through as a checklist is not a form to fill in and
        // post; it is a run that gets opened and then visited. Join the one
        // already open on this asset rather than starting a rival to it.
        if ((int) $version['allow_out_of_order'] === 1) {
            $open = RoutineCompletion::openForAsset((int) $asset['id']);

            if ($open !== null) {
                Response::redirect('/maintenance/completions/' . (int) $open['id']);
            }

            $this->view('routines/start', [
                'pageTitle' => 'Start ' . $routine['name'],
                'asset'     => $asset,
                'routine'   => $routine,
                'version'   => $version,
                'schedule'  => $schedule,
                'pages'     => MaintenanceRoutine::structure((int) $version['id']),
            ]);

            // view() writes the page; it does not end the request. Without this
            // the wizard below is rendered underneath the start card.
            return;
        }

        $this->view('routines/run', [
            'pageTitle' => $routine['name'] . ' · ' . $asset['asset_tag'],
            'asset'     => $asset,
            'routine'   => $routine,
            'version'   => $version,
            'schedule'  => $schedule,
            'pages'     => MaintenanceRoutine::structure((int) $version['id']),
            'users'     => MaintenanceSchedule::assignableUsers(),
            'startedAt' => date('Y-m-d H:i:s'),
            'nextDue'   => $schedule === null ? null : MaintenanceSchedule::nextDueAfter($schedule, date('Y-m-d')),
        ]);
    }

    /** Record what was filled in. */
    public function store(string $assetId, string $routineId): void
    {
        [$asset, $routine, $version] = $this->target((int) $assetId, (int) $routineId);

        $schedule   = $this->scheduleFromQuery((int) $asset['id'], (int) $routine['id']);
        $scheduleId = $schedule === null ? null : (int) $schedule['id'];

        $redirect = '/assets/' . (int) $asset['id'] . '/routines/' . (int) $routine['id'] . '/run'
            . ($scheduleId === null ? '' : '?schedule=' . $scheduleId);

        $steps   = MaintenanceRoutine::allSteps((int) $version['id']);
        $answers = $this->readAnswers($steps, $redirect);
        $log     = $this->validateLog($redirect);

        Database::beginTransaction();

        try {
            $logId = $this->writeLog($asset, $routine, $version, $schedule, $log);

            $completionId = RoutineCompletion::create([
                'routine_id'         => (int) $routine['id'],
                'version_id'         => (int) $version['id'],
                'asset_id'           => (int) $asset['id'],
                'schedule_id'        => $scheduleId,
                'status'             => 'submitted',
                'maintenance_log_id' => $logId,
                'completed_by'       => Auth::id(),
                'started_at'         => self::timestamp((string) Request::post('started_at', '')),
                'started_by'         => Auth::id(),
                'completed_at'       => date('Y-m-d H:i:s'),
            ]);

            $answeredAt = date('Y-m-d H:i:s');

            foreach ($answers as $stepId => $values) {
                RoutineCompletion::addResponse([
                    'completion_id' => $completionId,
                    'step_id'       => $stepId,
                    'answered_by'   => Auth::id(),
                    'answered_at'   => $answeredAt,
                ] + $values);
            }

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();

            throw $e;
        }

        // Files are stored outside the transaction: a rolled-back write leaves
        // nothing behind, but a rolled-back file does not delete itself.
        $this->storeFiles($completionId, $steps);

        $this->afterCompletion($asset, $routine, $version, $schedule, $log, $completionId);

        Flash::success(sprintf('Routine recorded against %s.', (string) $asset['asset_tag']));
        Response::redirect('/maintenance/completions/' . $completionId);
    }

    /** Open a run of a checklist routine, and go straight to its contents. */
    public function start(string $assetId, string $routineId): void
    {
        [$asset, $routine, $version] = $this->target((int) $assetId, (int) $routineId);

        if ((int) $version['allow_out_of_order'] !== 1) {
            $this->notFound('That routine is filled in as one form, not opened as a run.');
        }

        $open = RoutineCompletion::openForAsset((int) $asset['id']);

        if ($open !== null) {
            Flash::info('There is already a run open on ' . $asset['asset_tag'] . '.');
            Response::redirect('/maintenance/completions/' . (int) $open['id']);
        }

        $schedule = $this->scheduleFromQuery((int) $asset['id'], (int) $routine['id']);

        $completionId = RoutineCompletion::create([
            'routine_id'   => (int) $routine['id'],
            'version_id'   => (int) $version['id'],
            'asset_id'     => (int) $asset['id'],
            'schedule_id'  => $schedule === null ? null : (int) $schedule['id'],
            'status'       => 'open',
            'started_at'   => date('Y-m-d H:i:s'),
            'started_by'   => Auth::id(),
            'completed_at' => null,
        ]);

        ActivityLog::record(
            'routine_started',
            'asset',
            (int) $asset['id'],
            sprintf('Started the routine "%s" (v%d)', (string) $routine['name'], (int) $version['version_number'])
        );

        Flash::success('Run started. Its steps can be answered in any order, by anybody.');
        Response::redirect('/maintenance/completions/' . $completionId);
    }

    /**
     * A run, or a record.
     *
     * One address either way. While a run is open this is its contents — every
     * page and step, what it says now and who put it there — and once it has
     * been signed off the same address is the record of what was done.
     */
    public function show(string $id): void
    {
        $completion = RoutineCompletion::find((int) $id);

        if ($completion === null) {
            $this->notFound();
        }

        $pages     = MaintenanceRoutine::structure((int) $completion['version_id']);
        $responses = RoutineCompletion::responses((int) $completion['id']);
        $files     = RoutineCompletion::files((int) $completion['id']);

        $batched = MaintenanceRoutine::isPageBatched($completion);
        $done    = RoutineCompletion::pageCompletions((int) $completion['id']);

        if ($completion['status'] === 'open') {
            $this->view('routines/contents', [
                'pageTitle'       => $completion['routine_name'] . ' · ' . $completion['asset_tag'],
                'completion'      => $completion,
                'pages'           => $pages,
                'responses'       => $responses,
                'files'           => $files,
                'batched'         => $batched,
                'attribution'     => RoutineCompletion::attribution((int) $completion['id']),
                'pageCompletions' => $done,
                'outstanding'     => self::outstanding($completion, $pages, $responses, $files, $done),
            ]);

            // Same reason: one of these two pages, never both.
            return;
        }

        $this->view('routines/completion', [
            'pageTitle'       => $completion['routine_name'] . ' · ' . $completion['asset_tag'],
            'completion'      => $completion,
            'pages'           => $pages,
            'responses'       => $responses,
            'files'           => $files,
            'batched'         => $batched,
            'attribution'     => RoutineCompletion::attribution((int) $completion['id']),
            'pageCompletions' => $done,
        ]);
    }

    /** One step of an open run, on its own. */
    public function step(string $id, string $stepId): void
    {
        [$completion, $step] = $this->openStep((int) $id, (int) $stepId);

        $this->refuseIfBatched($completion, (int) $step['page_id']);

        $pages = MaintenanceRoutine::structure((int) $completion['version_id']);

        $this->view('routines/step', [
            'pageTitle'   => $step['label'] . ' · ' . $completion['asset_tag'],
            'completion'  => $completion,
            'step'        => $step,
            'position'    => self::positionOf($pages, (int) $step['id']),
            'response'    => RoutineCompletion::responses((int) $completion['id'])[(int) $step['id']] ?? null,
            'stepFiles'   => RoutineCompletion::files((int) $completion['id'])[(int) $step['id']] ?? [],
            'attribution' => RoutineCompletion::attribution((int) $completion['id'])[(int) $step['id']] ?? null,
        ]);
    }

    /** Record the answer to one step, whoever happens to be at the machine. */
    public function saveStep(string $id, string $stepId): void
    {
        [$completion, $step] = $this->openStep((int) $id, (int) $stepId);

        $this->refuseIfBatched($completion, (int) $step['page_id']);

        $completionId = (int) $completion['id'];
        $stepKey      = (int) $step['id'];
        $redirect     = '/maintenance/completions/' . $completionId . '/steps/' . $stepKey;

        // Steps are answered one at a time here, so "required" cannot be
        // enforced yet — that is what signing off is for. The type checks
        // still apply, which is why the step is passed through as optional.
        $optional = $step;
        $optional['is_required'] = 0;

        $answers = $this->readAnswers([$optional], $redirect);

        if (in_array((string) $step['field_type'], MaintenanceRoutine::FILE_TYPES, true)) {
            if (Upload::files('step_file_' . $stepKey) === []) {
                Flash::info('Nothing was attached, so nothing changed.');
                Response::redirect($redirect);
            }

            // Attaching again replaces what was there, and the files that go
            // are removed from disk with it: nothing else refers to them.
            foreach (RoutineCompletion::forgetFiles($completionId, $stepKey) as $old) {
                Upload::delete((string) $old['file_path']);
            }

            $this->storeFiles($completionId, [$step]);

            RoutineCompletion::saveResponse($completionId, $stepKey, [
                'value_text'    => null,
                'value_number'  => null,
                'value_date'    => null,
                'value_boolean' => null,
            ], Auth::id());
        } elseif (isset($answers[$stepKey])) {
            RoutineCompletion::saveResponse($completionId, $stepKey, $answers[$stepKey], Auth::id());
        } else {
            // Cleared on purpose. An answer taken away has to stop being an
            // answer, or the contents page carries on calling the step done.
            RoutineCompletion::forgetResponse($completionId, $stepKey);
        }

        Flash::success('Saved.');
        Response::redirect('/maintenance/completions/' . $completionId . '#step-' . $stepKey);
    }

    /** One page of a batched run: its steps, and only its steps. */
    public function page(string $id, string $pageId): void
    {
        [$completion, $page] = $this->openPage((int) $id, (int) $pageId);

        $completionId = (int) $completion['id'];
        $steps        = MaintenanceRoutine::steps((int) $page['id']);
        $structure    = MaintenanceRoutine::structure((int) $completion['version_id']);

        $this->view('routines/page', [
            'pageTitle'  => $page['title'] . ' · ' . $completion['asset_tag'],
            'completion' => $completion,
            'page'       => $page,
            'steps'      => $steps,
            'position'   => self::pagePositionOf($structure, (int) $page['id']),
            'responses'  => RoutineCompletion::responses($completionId),
            'files'      => RoutineCompletion::files($completionId),
            'done'       => RoutineCompletion::pageCompletions($completionId)[(int) $page['id']] ?? null,
        ]);
    }

    /**
     * Record a whole page in one go.
     *
     * Required is enforced across this page and no further: a page is an
     * independently completable unit, so what is outstanding elsewhere is
     * nobody's business here. The run as a whole is checked at sign-off.
     */
    public function savePage(string $id, string $pageId): void
    {
        [$completion, $page] = $this->openPage((int) $id, (int) $pageId);

        $completionId = (int) $completion['id'];
        $pageKey      = (int) $page['id'];
        $redirect     = '/maintenance/completions/' . $completionId . '/pages/' . $pageKey;

        $steps   = MaintenanceRoutine::steps($pageKey);
        $stored  = RoutineCompletion::files($completionId);
        $answers = $this->readAnswers(self::withFilesAnswered($steps, $stored), $redirect);

        foreach ($steps as $step) {
            $stepKey = (int) $step['id'];

            if (in_array((string) $step['field_type'], MaintenanceRoutine::FILE_TYPES, true)) {
                if (Upload::files('step_file_' . $stepKey) === []) {
                    continue;
                }

                foreach (RoutineCompletion::forgetFiles($completionId, $stepKey) as $old) {
                    Upload::delete((string) $old['file_path']);
                }

                $this->storeFiles($completionId, [$step]);

                RoutineCompletion::saveResponse($completionId, $stepKey, [
                    'value_text'    => null,
                    'value_number'  => null,
                    'value_date'    => null,
                    'value_boolean' => null,
                ], Auth::id());

                continue;
            }

            if (isset($answers[$stepKey])) {
                RoutineCompletion::saveResponse($completionId, $stepKey, $answers[$stepKey], Auth::id());
                continue;
            }

            RoutineCompletion::forgetResponse($completionId, $stepKey);
        }

        RoutineCompletion::completePage($completionId, $pageKey, Auth::id());

        Flash::success(sprintf('“%s” recorded.', (string) $page['title']));
        Response::redirect('/maintenance/completions/' . $completionId . '#page-' . $pageKey);
    }

    /** The form that signs an open run off. */
    public function submitForm(string $id): void
    {
        $completion = $this->openRun((int) $id);

        $pages     = MaintenanceRoutine::structure((int) $completion['version_id']);
        $responses = RoutineCompletion::responses((int) $completion['id']);
        $files     = RoutineCompletion::files((int) $completion['id']);

        $schedule = $completion['schedule_id'] === null
            ? null
            : MaintenanceSchedule::find((int) $completion['schedule_id']);

        $this->view('routines/submit', [
            'pageTitle'   => 'Sign off ' . $completion['routine_name'],
            'completion'  => $completion,
            'asset'       => Asset::find((int) $completion['asset_id']),
            'schedule'    => $schedule,
            'users'       => MaintenanceSchedule::assignableUsers(),
            'batched'     => MaintenanceRoutine::isPageBatched($completion),
            'outstanding' => self::outstanding(
                $completion,
                $pages,
                $responses,
                $files,
                RoutineCompletion::pageCompletions((int) $completion['id'])
            ),
            'nextDue'     => $schedule === null ? null : MaintenanceSchedule::nextDueAfter($schedule, date('Y-m-d')),
        ]);
    }

    /**
     * Sign an open run off.
     *
     * The required steps are checked here rather than on the way in, which is
     * the whole point of a checklist: they can be answered in any order, but
     * the run is not finished until they all are.
     */
    public function submit(string $id): void
    {
        $completion   = $this->openRun((int) $id);
        $completionId = (int) $completion['id'];
        $redirect     = '/maintenance/completions/' . $completionId . '/submit';

        $pages       = MaintenanceRoutine::structure((int) $completion['version_id']);
        $outstanding = self::outstanding(
            $completion,
            $pages,
            RoutineCompletion::responses($completionId),
            RoutineCompletion::files($completionId),
            RoutineCompletion::pageCompletions($completionId)
        );

        if ($outstanding !== []) {
            $unit = MaintenanceRoutine::isPageBatched($completion) ? 'page' : 'step';

            Flash::error(sprintf(
                '%d required %s%s still to complete. A routine cannot be signed off part-finished.',
                count($outstanding),
                $unit,
                count($outstanding) === 1 ? '' : 's'
            ));
            Response::redirect('/maintenance/completions/' . $completionId);
        }

        $asset    = Asset::find((int) $completion['asset_id']);
        $routine  = MaintenanceRoutine::find((int) $completion['routine_id']);
        $version  = MaintenanceRoutine::findVersion((int) $completion['version_id']);
        $schedule = $completion['schedule_id'] === null
            ? null
            : MaintenanceSchedule::find((int) $completion['schedule_id']);

        if ($asset === null || $routine === null || $version === null) {
            $this->notFound();
        }

        $log   = $this->validateLog($redirect);
        $logId = $this->writeLog($asset, $routine, $version, $schedule, $log);

        RoutineCompletion::update($completionId, [
            'status'             => 'submitted',
            'maintenance_log_id' => $logId,
            'completed_by'       => Auth::id(),
            'completed_at'       => date('Y-m-d H:i:s'),
        ]);

        $this->afterCompletion($asset, $routine, $version, $schedule, $log, $completionId);

        Flash::success(sprintf('Routine signed off against %s.', (string) $asset['asset_tag']));
        Response::redirect('/maintenance/completions/' . $completionId);
    }

    /** Abandon an open run, taking its answers and files with it. */
    public function discard(string $id): void
    {
        $completion   = $this->openRun((int) $id);
        $completionId = (int) $completion['id'];

        foreach (RoutineCompletion::files($completionId) as $stepFiles) {
            foreach ($stepFiles as $file) {
                Upload::delete((string) $file['file_path']);
            }
        }

        ActivityLog::record(
            'routine_discarded',
            'asset',
            (int) $completion['asset_id'],
            sprintf('Discarded the open run of "%s"', (string) $completion['routine_name'])
        );

        RoutineCompletion::delete($completionId);

        Flash::success('Run discarded. Nothing was recorded against the asset.');
        Response::redirect('/assets/' . (int) $completion['asset_id']);
    }

    /** The same record as a document, for filing or sending on. */
    public function pdf(string $id): void
    {
        $completion = RoutineCompletion::find((int) $id);

        if ($completion === null) {
            $this->notFound();
        }

        $document = RoutineDocument::build($completion);
        $filename = RoutineDocument::filename($completion);

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/pdf');
        header('Content-Length: ' . (string) strlen($document));
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=0, must-revalidate');
        header_remove('Pragma');

        echo $document;
        exit;
    }

    /** Stream a photo or document captured while the routine was carried out. */
    public function file(string $id, string $fileId): void
    {
        $file = RoutineCompletion::findFile((int) $fileId);

        if ($file === null || (int) $file['completion_id'] !== (int) $id) {
            $this->notFound('That file is no longer attached to this record.');
        }

        $path = Upload::absolutePath((string) $file['file_path']);

        if ($path === null) {
            $this->notFound('The file is missing from the server.');
        }

        $download = Request::query('download') === '1';
        $name     = Upload::displayName((string) ($file['original_filename'] ?: 'attachment'));

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: ' . (string) $file['mime_type']);
        header('Content-Length: ' . (string) filesize($path));
        header(sprintf(
            'Content-Disposition: %s; filename="%s"',
            $download ? 'attachment' : 'inline',
            str_replace('"', '', $name)
        ));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=2592000, immutable');
        header_remove('Pragma');

        readfile($path);
        exit;
    }

    // -- Closing a run out ---------------------------------------------------

    /**
     * The maintenance record's own fields, which every routine produces
     * whichever way it was filled in.
     *
     * @return array<string,mixed>
     */
    private function validateLog(string $redirect): array
    {
        $log = $this->validate([
            'performed_on'         => 'required|date',
            'result'               => 'required|in:' . implode(',', MaintenanceLog::RESULTS),
            'performed_by_user_id' => 'integer',
            'condition_after'      => 'in:' . implode(',', Asset::CONDITIONS),
            'notes'                => 'max:5000',
            'next_due_date'        => 'date',
        ], [
            'performed_on'    => 'Date performed',
            'result'          => 'Result',
            'condition_after' => 'Condition afterwards',
            'next_due_date'   => 'Next due date',
        ], $redirect);

        if ($log['performed_on'] > date('Y-m-d')) {
            $this->failValidation(['performed_on' => 'The date performed cannot be in the future.'], $redirect);
        }

        return $log;
    }

    /**
     * Write the maintenance log entry a completion produces.
     *
     * The routine's own name is what the history should read, so "work done"
     * says which procedure was followed and which edition of it; the notes box
     * adds whatever the technician wants to say.
     *
     * @param array<string,mixed> $asset
     * @param array<string,mixed> $routine
     * @param array<string,mixed> $version
     * @param array<string,mixed>|null $schedule
     * @param array<string,mixed> $log
     */
    private function writeLog(array $asset, array $routine, array $version, ?array $schedule, array $log): int
    {
        $performedBy = (int) $log['performed_by_user_id'];

        return MaintenanceLog::create([
            'asset_id'             => (int) $asset['id'],
            'schedule_id'          => $schedule === null ? null : (int) $schedule['id'],
            'maintenance_type'     => $schedule === null ? 'inspection' : 'routine',
            'performed_on'         => $log['performed_on'],
            'performed_by_user_id' => $performedBy > 0 ? $performedBy : Auth::id(),
            'work_done'            => sprintf('%s (v%d)', (string) $routine['name'], (int) $version['version_number']),
            'result'               => $log['result'],
            'condition_after'      => $log['condition_after'] !== '' ? $log['condition_after'] : null,
            'notes'                => $log['notes'] !== '' ? $log['notes'] : null,
            'created_by'           => Auth::id(),
        ]);
    }

    /**
     * Everything that follows a completion: the schedule rolls forward, the
     * asset takes its recorded condition, and the activity log says so.
     *
     * @param array<string,mixed> $asset
     * @param array<string,mixed> $routine
     * @param array<string,mixed> $version
     * @param array<string,mixed>|null $schedule
     * @param array<string,mixed> $log
     */
    private function afterCompletion(array $asset, array $routine, array $version, ?array $schedule, array $log, int $completionId): void
    {
        if ($schedule !== null) {
            $nextDue = $log['next_due_date'] !== ''
                ? $log['next_due_date']
                : MaintenanceSchedule::nextDueAfter($schedule, (string) $log['performed_on']);

            MaintenanceSchedule::applyCompletion(
                (int) $schedule['id'],
                (string) $log['performed_on'],
                $nextDue,
                $schedule['maintenance_type'] === 'ad-hoc'
            );
        }

        $assetChanges = [];

        if (in_array($log['condition_after'], Asset::CONDITIONS, true)) {
            $assetChanges['condition_rating'] = $log['condition_after'];
        }

        if (Request::boolean('return_to_stock') && $asset['status'] === 'In Maintenance') {
            $assetChanges['status'] = 'In Stock';
        }

        if ($assetChanges !== []) {
            $assetChanges['updated_by'] = Auth::id();
            Asset::update((int) $asset['id'], $assetChanges);
        }

        ActivityLog::record(
            'maintenance_logged',
            'asset',
            (int) $asset['id'],
            sprintf(
                'Completed the routine "%s" (v%d) on %s',
                (string) $routine['name'],
                (int) $version['version_number'],
                format_date((string) $log['performed_on'])
            ),
            ['routine_completion_id' => $completionId]
        );
    }

    // -- Open runs -----------------------------------------------------------

    /**
     * An open run, or a refusal.
     *
     * A run that has already been signed off is history: it is read at its own
     * address and changed nowhere.
     *
     * @return array<string,mixed>
     */
    private function openRun(int $id): array
    {
        $completion = RoutineCompletion::find($id);

        if ($completion === null) {
            $this->notFound();
        }

        if ($completion['status'] !== 'open') {
            Flash::info('That routine has already been signed off.');
            Response::redirect('/maintenance/completions/' . $id);
        }

        return $completion;
    }

    /**
     * An open run and one step of the version it is following.
     *
     * The step is checked against the run's own version, so a step id
     * belonging to a different routine — or to a different edition of this one
     * — cannot be answered into it.
     *
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    private function openStep(int $id, int $stepId): array
    {
        $completion = $this->openRun($id);
        $step       = MaintenanceRoutine::findStep($stepId);

        if ($step === null) {
            $this->notFound('That step is not part of this routine.');
        }

        $page = MaintenanceRoutine::findPage((int) $step['page_id']);

        if ($page === null || (int) $page['version_id'] !== (int) $completion['version_id']) {
            $this->notFound('That step is not part of this routine.');
        }

        return [$completion, $step];
    }

    /**
     * What still stands between a run and its sign-off.
     *
     * The unit depends on how the run is worked through. A step-at-a-time run
     * is blocked by its unanswered **required steps**, wherever they are. A
     * page-batched one is blocked by its incomplete **pages flagged as
     * required** — and only those, because a page nobody needed to fill in is
     * exactly what an optional page is for.
     *
     * Worked out from what is stored rather than from what was posted: the
     * answers arrive separately, and from different people.
     *
     * @param array<string,mixed> $completion
     * @param array<int,array<string,mixed>> $pages
     * @param array<int,array<string,mixed>> $responses keyed by step id
     * @param array<int,array<int,array<string,mixed>>> $files keyed by step id
     * @param array<int,array{name:?string,at:string}> $pageCompletions keyed by page id
     * @return array<int,array<string,mixed>>
     */
    private static function outstanding(
        array $completion,
        array $pages,
        array $responses,
        array $files,
        array $pageCompletions = []
    ): array {
        if (MaintenanceRoutine::isPageBatched($completion)) {
            $missing = [];

            foreach ($pages as $page) {
                if ((int) $page['required_for_signoff'] !== 1) {
                    continue;
                }

                if (!isset($pageCompletions[(int) $page['id']])) {
                    $missing[] = $page + ['label' => $page['title'], 'page_title' => $page['title']];
                }
            }

            return $missing;
        }

        $missing = [];

        foreach ($pages as $page) {
            foreach ((array) $page['steps'] as $step) {
                if ((int) $step['is_required'] !== 1) {
                    continue;
                }

                if (!self::isAnswered($step, $responses, $files)) {
                    $missing[] = $step + ['page_title' => $page['title']];
                }
            }
        }

        return $missing;
    }

    /** Every required step on one page that still has no answer. */
    public static function outstandingOnPage(array $steps, array $responses, array $files): array
    {
        $missing = [];

        foreach ($steps as $step) {
            if ((int) $step['is_required'] === 1 && !self::isAnswered($step, $responses, $files)) {
                $missing[] = $step;
            }
        }

        return $missing;
    }

    /**
     * A file step that already has files behind it counts as answered, so a
     * page re-submitted without re-attaching them is not refused for a
     * requirement it has already met.
     *
     * @param array<int,array<string,mixed>> $steps
     * @param array<int,array<int,array<string,mixed>>> $stored keyed by step id
     * @return array<int,array<string,mixed>>
     */
    private static function withFilesAnswered(array $steps, array $stored): array
    {
        foreach ($steps as $index => $step) {
            if (!in_array((string) $step['field_type'], MaintenanceRoutine::FILE_TYPES, true)) {
                continue;
            }

            if (($stored[(int) $step['id']] ?? []) !== [] && Upload::files('step_file_' . (int) $step['id']) === []) {
                $steps[$index]['is_required'] = 0;
            }
        }

        return $steps;
    }

    /**
     * Has this step been answered?
     *
     * A file step counts once a file is attached; everything else counts once
     * a response row exists, which is only written for a value that is
     * actually there.
     *
     * @param array<string,mixed> $step
     * @param array<int,array<string,mixed>> $responses
     * @param array<int,array<int,array<string,mixed>>> $files
     */
    public static function isAnswered(array $step, array $responses, array $files): bool
    {
        $stepId = (int) $step['id'];

        if (in_array((string) $step['field_type'], MaintenanceRoutine::FILE_TYPES, true)) {
            return ($files[$stepId] ?? []) !== [];
        }

        return RoutineCompletion::answer($step, $responses[$stepId] ?? null) !== null;
    }

    /**
     * An open run and one page of the version it is following.
     *
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    private function openPage(int $id, int $pageId): array
    {
        $completion = $this->openRun($id);

        if (!MaintenanceRoutine::isPageBatched($completion)) {
            Flash::info('This routine is answered a step at a time.');
            Response::redirect('/maintenance/completions/' . $id);
        }

        $page = MaintenanceRoutine::findPage($pageId);

        if ($page === null || (int) $page['version_id'] !== (int) $completion['version_id']) {
            $this->notFound('That page is not part of this routine.');
        }

        return [$completion, $page];
    }

    /**
     * A step of a batched run is not answered on its own.
     *
     * @param array<string,mixed> $completion
     */
    private function refuseIfBatched(array $completion, int $pageId): void
    {
        if (!MaintenanceRoutine::isPageBatched($completion)) {
            return;
        }

        Flash::info('This routine is answered a page at a time.');
        Response::redirect('/maintenance/completions/' . (int) $completion['id'] . '/pages/' . $pageId);
    }

    /** "Page 2 of 4" — where a page sits, for a view that shows only it. */
    private static function pagePositionOf(array $pages, int $pageId): string
    {
        foreach ($pages as $index => $page) {
            if ((int) $page['id'] === $pageId) {
                return sprintf('Page %d of %d', $index + 1, count($pages));
            }
        }

        return '';
    }

    /**
     * "Page 2, step 3" — where a step sits, for a page that shows only it.
     *
     * @param array<int,array<string,mixed>> $pages
     */
    private static function positionOf(array $pages, int $stepId): string
    {
        foreach ($pages as $pageIndex => $page) {
            foreach ((array) $page['steps'] as $stepIndex => $step) {
                if ((int) $step['id'] === $stepId) {
                    return sprintf('Page %d, step %d', $pageIndex + 1, $stepIndex + 1);
                }
            }
        }

        return '';
    }

    // -- Reading a submission ------------------------------------------------

    /**
     * Every answer, checked against the step that asked for it.
     *
     * Required is enforced here and not only in the browser: the wizard's
     * gating is a convenience, and a form posted without it has to be refused
     * by the server or the flag means nothing.
     *
     * @param array<int,array<string,mixed>> $steps
     * @return array<int,array<string,mixed>>
     */
    private function readAnswers(array $steps, string $redirect): array
    {
        $submitted = Request::post('step');
        $submitted = is_array($submitted) ? $submitted : [];

        $answers = [];
        $errors  = [];

        foreach ($steps as $step) {
            $stepId   = (int) $step['id'];
            $type     = (string) $step['field_type'];
            $required = (int) $step['is_required'] === 1;
            $raw      = $submitted[$stepId] ?? null;

            if (in_array($type, MaintenanceRoutine::FILE_TYPES, true)) {
                if ($required && Upload::files('step_file_' . $stepId) === []) {
                    $errors['step.' . $stepId] = $step['label'] . ' needs a file.';
                }

                continue;
            }

            $row  = ['value_text' => null, 'value_number' => null, 'value_date' => null, 'value_boolean' => null];
            $blank = true;

            switch ($type) {
                case 'boolean':
                    $value = is_string($raw) ? trim($raw) : '';

                    if ($value === '1' || $value === '0') {
                        $row['value_boolean'] = (int) $value;
                        $blank = false;
                    }
                    break;

                case 'number':
                    $value = is_string($raw) ? trim($raw) : '';

                    if ($value !== '') {
                        if (!is_numeric($value)) {
                            $errors['step.' . $stepId] = $step['label'] . ' has to be a number.';
                            break;
                        }

                        $row['value_number'] = (float) $value;
                        $blank = false;
                    }
                    break;

                case 'date':
                    $value = is_string($raw) ? trim($raw) : '';

                    if ($value !== '') {
                        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $value);

                        if ($parsed === false) {
                            $errors['step.' . $stepId] = $step['label'] . ' has to be a date.';
                            break;
                        }

                        $row['value_date'] = $parsed->format('Y-m-d');
                        $blank = false;
                    }
                    break;

                case 'single_choice':
                    $value   = is_string($raw) ? trim($raw) : '';
                    $options = MaintenanceRoutine::options($step);

                    if ($value !== '') {
                        if (!in_array($value, $options, true)) {
                            $errors['step.' . $stepId] = $step['label'] . ' was answered with something it does not offer.';
                            break;
                        }

                        $row['value_text'] = $value;
                        $blank = false;
                    }
                    break;

                case 'multi_choice':
                    $options = MaintenanceRoutine::options($step);
                    $chosen  = [];

                    foreach (is_array($raw) ? $raw : [] as $value) {
                        if (is_string($value) && in_array($value, $options, true) && !in_array($value, $chosen, true)) {
                            $chosen[] = $value;
                        }
                    }

                    if ($chosen !== []) {
                        // One label per line, which is exactly how the choices
                        // were typed in the editor and why a label may not
                        // contain a line break.
                        $row['value_text'] = implode("\n", $chosen);
                        $blank = false;
                    }
                    break;

                default:
                    $value = is_string($raw) ? trim($raw) : '';
                    $limit = $type === 'long_text' ? 5000 : 1000;

                    if ($value !== '') {
                        $row['value_text'] = mb_substr($value, 0, $limit);
                        $blank = false;
                    }
            }

            if ($blank) {
                if ($required && !isset($errors['step.' . $stepId])) {
                    $errors['step.' . $stepId] = $step['label'] . ' has to be answered.';
                }

                continue;
            }

            $answers[$stepId] = $row;
        }

        if ($errors !== []) {
            $this->failValidation($errors, $redirect);
        }

        return $answers;
    }

    /**
     * Photographs and paperwork captured against a step.
     *
     * Validated in this file rather than handed to something else: a
     * controller that takes a file and leaves the checking elsewhere is the
     * shape that hides a missing check, and `tests/security-audit.php` looks
     * for it per file.
     *
     * @param array<int,array<string,mixed>> $steps
     */
    private function storeFiles(int $completionId, array $steps): void
    {
        foreach ($steps as $step) {
            $type = (string) $step['field_type'];

            if (!in_array($type, MaintenanceRoutine::FILE_TYPES, true)) {
                continue;
            }

            $stepId = (int) $step['id'];
            $files  = Upload::files('step_file_' . $stepId);

            if ($files === []) {
                continue;
            }

            $isPhoto    = $type === 'photo';
            $maxBytes   = (int) Config::get($isPhoto ? 'uploads.max_photo_bytes' : 'uploads.max_pdf_bytes');
            $mimes      = (array) Config::get($isPhoto ? 'uploads.photo_mimes' : 'uploads.pdf_mimes');
            $extensions = (array) Config::get($isPhoto ? 'uploads.photo_extensions' : 'uploads.pdf_extensions');

            foreach ($files as $file) {
                $error = Upload::validate($file, $mimes, $extensions, $maxBytes);

                if ($error !== null) {
                    Flash::error($error);
                    continue;
                }

                $mime = (string) Upload::detectMime($file['tmp_name']);

                $extension = $isPhoto
                    ? match ($mime) {
                        'image/png'  => 'png',
                        'image/webp' => 'webp',
                        'image/heic' => 'heic',
                        'image/heif' => 'heif',
                        default      => 'jpg',
                    }
                    : 'pdf';

                $path     = Upload::store($file, 'routines/' . $completionId, $extension);
                $absolute = Upload::absolutePath($path);

                if ($isPhoto && $absolute !== null) {
                    Image::normalise($absolute, $mime);
                }

                RoutineCompletion::addFile([
                    'completion_id'     => $completionId,
                    'step_id'           => $stepId,
                    'file_kind'         => $isPhoto ? 'photo' : 'document',
                    'file_path'         => $path,
                    'original_filename' => Upload::displayName($file['name']),
                    'mime_type'         => $isPhoto ? $mime : 'application/pdf',
                    'file_size_bytes'   => $absolute !== null ? (int) filesize($absolute) : (int) $file['size'],
                    'uploaded_by'       => Auth::id(),
                ]);
            }
        }
    }

    // -- Shared lookups ------------------------------------------------------

    /**
     * The asset, the routine and the version to follow, or a 404.
     *
     * The version is always the routine's current one. A draft is not
     * runnable, and an archived routine cannot be started even by URL — the
     * completions it already has are what keep it visible.
     *
     * @return array{0:array<string,mixed>,1:array<string,mixed>,2:array<string,mixed>}
     */
    private function target(int $assetId, int $routineId): array
    {
        $asset = Asset::find($assetId);

        if ($asset === null) {
            $this->notFound();
        }

        $routine = MaintenanceRoutine::find($routineId);

        if ($routine === null || $routine['status'] !== 'active') {
            $this->notFound('That routine is not available to run.');
        }

        // The picker only offers applicable routines; this is what makes that
        // a rule rather than a courtesy, since a URL can be typed.
        if (!MaintenanceRoutine::appliesTo($routine, self::categoryOf($asset))) {
            $this->notFound(sprintf(
                'The routine "%s" is for %s equipment, and %s is not in that category.',
                $routine['name'],
                (string) ($routine['category_name'] ?? 'another category'),
                (string) $asset['asset_tag']
            ));
        }

        $version = MaintenanceRoutine::currentVersion($routineId);

        if ($version === null) {
            $this->notFound('That routine has no published version yet.');
        }

        return [$asset, $routine, $version];
    }

    /**
     * The schedule this run satisfies, when there is one.
     *
     * Checked against both the asset and the routine, so a schedule id cannot
     * be swapped for one belonging to a different machine or a different job.
     *
     * @return array<string,mixed>|null
     */
    private function scheduleFromQuery(int $assetId, int $routineId): ?array
    {
        $scheduleId = (int) (Request::query('schedule', 0) ?: Request::post('schedule_id', 0));

        if ($scheduleId < 1) {
            return null;
        }

        $schedule = MaintenanceSchedule::find($scheduleId);

        if ($schedule === null
            || (int) $schedule['asset_id'] !== $assetId
            || (int) ($schedule['routine_id'] ?? 0) !== $routineId) {
            return null;
        }

        return $schedule;
    }

    /** An asset's category id, or null when it has none. */
    private static function categoryOf(array $asset): ?int
    {
        $categoryId = (int) ($asset['category_id'] ?? 0);

        return $categoryId > 0 ? $categoryId : null;
    }

    /** A posted timestamp, or null — never a value the client invented a shape for. */
    private static function timestamp(string $value): ?string
    {
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);

        return $parsed === false ? null : $parsed->format('Y-m-d H:i:s');
    }
}
