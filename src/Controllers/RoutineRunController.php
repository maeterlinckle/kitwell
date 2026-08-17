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
            'routines'  => MaintenanceRoutine::runnable(),
        ]);
    }

    /** The guided form itself. */
    public function run(string $assetId, string $routineId): void
    {
        [$asset, $routine, $version] = $this->target((int) $assetId, (int) $routineId);

        $schedule = $this->scheduleFromQuery((int) $asset['id'], (int) $routine['id']);

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

        $steps  = MaintenanceRoutine::allSteps((int) $version['id']);
        $answers = $this->readAnswers($steps, $redirect);

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

        $performedBy = (int) $log['performed_by_user_id'];

        // The routine's own name is what the history should read, so the log's
        // "work done" says which procedure was followed and which edition of
        // it, and the notes box adds whatever the technician wants to say.
        $workDone = sprintf('%s (v%d)', $routine['name'], (int) $version['version_number']);

        Database::beginTransaction();

        try {
            $logId = MaintenanceLog::create([
                'asset_id'             => (int) $asset['id'],
                'schedule_id'          => $scheduleId,
                'maintenance_type'     => $schedule === null ? 'inspection' : 'routine',
                'performed_on'         => $log['performed_on'],
                'performed_by_user_id' => $performedBy > 0 ? $performedBy : Auth::id(),
                'work_done'            => $workDone,
                'result'               => $log['result'],
                'condition_after'      => $log['condition_after'] !== '' ? $log['condition_after'] : null,
                'notes'                => $log['notes'] !== '' ? $log['notes'] : null,
                'created_by'           => Auth::id(),
            ]);

            $startedAt = (string) Request::post('started_at', '');

            $completionId = RoutineCompletion::create([
                'routine_id'         => (int) $routine['id'],
                'version_id'         => (int) $version['id'],
                'asset_id'           => (int) $asset['id'],
                'schedule_id'        => $scheduleId,
                'maintenance_log_id' => $logId,
                'completed_by'       => Auth::id(),
                'started_at'         => self::timestamp($startedAt),
                'completed_at'       => date('Y-m-d H:i:s'),
            ]);

            foreach ($answers as $stepId => $values) {
                RoutineCompletion::addResponse(['completion_id' => $completionId, 'step_id' => $stepId] + $values);
            }

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();

            throw $e;
        }

        // Files are stored outside the transaction: a rolled-back write leaves
        // nothing behind, but a rolled-back file does not delete itself.
        $this->storeFiles($completionId, $steps);

        if ($schedule !== null) {
            $nextDue = $log['next_due_date'] !== ''
                ? $log['next_due_date']
                : MaintenanceSchedule::nextDueAfter($schedule, (string) $log['performed_on']);

            MaintenanceSchedule::applyCompletion(
                $scheduleId,
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
                $routine['name'],
                (int) $version['version_number'],
                format_date((string) $log['performed_on'])
            ),
            ['routine_completion_id' => $completionId]
        );

        Flash::success(sprintf('Routine recorded against %s.', $asset['asset_tag']));
        Response::redirect('/maintenance/completions/' . $completionId);
    }

    /** A completed routine, laid out as it was filled in. */
    public function show(string $id): void
    {
        $completion = RoutineCompletion::find((int) $id);

        if ($completion === null) {
            $this->notFound();
        }

        $this->view('routines/completion', [
            'pageTitle'  => $completion['routine_name'] . ' · ' . $completion['asset_tag'],
            'completion' => $completion,
            'pages'      => MaintenanceRoutine::structure((int) $completion['version_id']),
            'responses'  => RoutineCompletion::responses((int) $completion['id']),
            'files'      => RoutineCompletion::files((int) $completion['id']),
        ]);
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

    /** A posted timestamp, or null — never a value the client invented a shape for. */
    private static function timestamp(string $value): ?string
    {
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);

        return $parsed === false ? null : $parsed->format('Y-m-d H:i:s');
    }
}
