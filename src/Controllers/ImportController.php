<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Csv;
use App\Core\CsvReader;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Upload;
use App\Imports\Importer;
use App\Imports\ImportRegistry;
use App\Models\ActivityLog;

/**
 * CSV import: upload, preview, commit.
 *
 * Nothing is written until the preview has been seen and confirmed, and a bad
 * row never stops the rest of the batch — it is skipped and reported.
 *
 * The uploaded file is kept on disk between the two steps and re-parsed and
 * re-validated at commit time rather than trusting anything held in the
 * session. That keeps the session small and, more importantly, means a row
 * that became invalid between preview and commit (someone else created the
 * same asset tag in the meantime) is caught rather than written.
 */
final class ImportController extends Controller
{
    private const SESSION_KEY = '__import_uploads';

    public function index(): void
    {
        $importers = ImportRegistry::available();

        if ($importers === []) {
            Auth::authorize('assets.create');
        }

        $this->view('import/index', [
            'pageTitle' => 'Import data',
            'importers' => $importers,
        ]);
    }

    public function show(string $key): void
    {
        $importer = $this->resolve($key);

        $this->view('import/show', [
            'pageTitle' => 'Import ' . strtolower($importer->name()),
            'importer'  => $importer,
            'maxMb'     => (int) ((int) Config::get('uploads.max_csv_bytes') / 1048576),
            'maxRows'   => CsvReader::MAX_ROWS,
        ]);
    }

    /** The blank template, with one example row. */
    public function template(string $key): void
    {
        $importer = $this->resolve($key);

        Csv::download(
            $importer->key() . '-import-template',
            $importer->templateHeadings(),
            $importer->templateRows()
        );
    }

    /** Step 1: take the upload, validate it, and show what would happen. */
    public function preview(string $key): void
    {
        $importer = $this->resolve($key);
        $files    = Upload::files('file');

        if ($files === []) {
            Flash::error('Choose a CSV file to upload.');
            Response::redirect('/import/' . $importer->key());
        }

        $error = Upload::validate(
            $files[0],
            (array) Config::get('uploads.csv_mimes'),
            (array) Config::get('uploads.csv_extensions'),
            (int) Config::get('uploads.max_csv_bytes')
        );

        if ($error !== null) {
            Flash::error($error);
            Response::redirect('/import/' . $importer->key());
        }

        $options = $importer->normaliseOptions(Request::all());
        $path    = Upload::store($files[0], 'imports', 'csv');

        // Remember the upload for the commit step, and tidy up any earlier one.
        $uploads = (array) Session::get(self::SESSION_KEY, []);

        if (isset($uploads[$importer->key()]['path'])) {
            Upload::delete((string) $uploads[$importer->key()]['path']);
        }

        $uploads[$importer->key()] = [
            'path'     => $path,
            'name'     => Upload::displayName($files[0]['name']),
            'options'  => $options,
            'uploaded' => time(),
        ];

        Session::put(self::SESSION_KEY, $uploads);

        $this->renderPreview($importer, $path, Upload::displayName($files[0]['name']), $options, false);
    }

    /** Step 2: write the rows that are still valid. */
    public function commit(string $key): void
    {
        $importer = $this->resolve($key);

        $uploads = (array) Session::get(self::SESSION_KEY, []);
        $upload  = $uploads[$importer->key()] ?? null;

        if ($upload === null || Upload::absolutePath((string) $upload['path']) === null) {
            Flash::error('That upload is no longer available. Please upload the file again.');
            Response::redirect('/import/' . $importer->key());
        }

        $options = (array) $upload['options'];
        $result  = $this->parseAndValidate($importer, (string) $upload['path'], $options);

        if ($result['fatal'] !== null) {
            Flash::error($result['fatal']);
            Response::redirect('/import/' . $importer->key());
        }

        $created = 0;
        $skipped = 0;
        $failed  = [];
        $samples = [];

        Database::beginTransaction();

        try {
            foreach ($result['rows'] as $row) {
                if ($row['status'] === Importer::STATUS_ERROR) {
                    $skipped++;
                    continue;
                }

                try {
                    $description = $importer->commitRow($row['data'], $options);
                    $created++;

                    if (count($samples) < 5) {
                        $samples[] = $description;
                    }
                } catch (\Throwable $e) {
                    // One bad row must not cost the whole batch.
                    $skipped++;
                    $failed[] = 'Line ' . $row['line'] . ': ' . $e->getMessage();
                }
            }

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();

            Flash::error('The import could not be completed: ' . $e->getMessage());
            Response::redirect('/import/' . $importer->key());
        }

        ActivityLog::record(
            'imported',
            'import',
            null,
            sprintf(
                'Imported %d %s from "%s" (%d row(s) skipped)',
                $created,
                strtolower($importer->name()),
                $upload['name'],
                $skipped
            ),
            [
                'importer' => $importer->key(),
                'file'     => $upload['name'],
                'created'  => $created,
                'skipped'  => $skipped,
                'options'  => $options,
                'examples' => $samples,
            ]
        );

        // The file has done its job.
        Upload::delete((string) $upload['path']);
        unset($uploads[$importer->key()]);
        Session::put(self::SESSION_KEY, $uploads);

        if ($created > 0) {
            Flash::success(sprintf(
                '%d %s imported%s.',
                $created,
                strtolower($importer->name()),
                $skipped > 0 ? ', ' . $skipped . ' row(s) skipped' : ''
            ));
        } else {
            Flash::warning('Nothing was imported — every row had an error.');
        }

        foreach (array_slice($failed, 0, 5) as $message) {
            Flash::error($message);
        }

        Response::redirect('/import/' . $importer->key());
    }

    /**
     * @param array<string,mixed> $options
     */
    private function renderPreview(Importer $importer, string $path, string $filename, array $options, bool $stale): void
    {
        $result = $this->parseAndValidate($importer, $path, $options);

        if ($result['fatal'] !== null) {
            Flash::error($result['fatal']);
            Response::redirect('/import/' . $importer->key());
        }

        $this->view('import/preview', [
            'pageTitle' => 'Preview import · ' . $importer->name(),
            'importer'  => $importer,
            'filename'  => $filename,
            'options'   => $options,
            'rows'      => $result['rows'],
            'counts'    => $result['counts'],
            'reader'    => $result['reader'],
        ]);
    }

    /**
     * Parse the file and run every row through the importer's validation.
     *
     * @param array<string,mixed> $options
     * @return array{rows:array<int,array<string,mixed>>,counts:array<string,int>,reader:CsvReader|null,fatal:string|null}
     */
    private function parseAndValidate(Importer $importer, string $relativePath, array $options): array
    {
        $absolute = Upload::absolutePath($relativePath);

        if ($absolute === null) {
            return ['rows' => [], 'counts' => [], 'reader' => null, 'fatal' => 'The uploaded file could not be found.'];
        }

        try {
            $reader = new CsvReader($absolute, $importer->columns());
        } catch (\Throwable $e) {
            return ['rows' => [], 'counts' => [], 'reader' => null, 'fatal' => 'That file could not be read as CSV: ' . $e->getMessage()];
        }

        if ($reader->missingRequiredColumns() !== []) {
            return [
                'rows' => [], 'counts' => [], 'reader' => $reader,
                'fatal' => 'The file is missing required column(s): ' . implode(', ', $reader->missingRequiredColumns())
                    . '. Download the template to see the expected headings.',
            ];
        }

        if ($reader->count() === 0) {
            return ['rows' => [], 'counts' => [], 'reader' => $reader, 'fatal' => 'That file has no data rows.'];
        }

        $context = $importer->newContext();
        $rows    = [];
        $counts  = [Importer::STATUS_OK => 0, Importer::STATUS_WARNING => 0, Importer::STATUS_ERROR => 0];

        foreach ($reader->rows() as $raw) {
            $checked = $importer->validateRow($raw, $context, $options);

            $counts[$checked['status']]++;

            $rows[] = [
                'line'     => $raw['__line'] ?? '?',
                'raw'      => $raw,
                'status'   => $checked['status'],
                'errors'   => $checked['errors'],
                'warnings' => $checked['warnings'],
                'data'     => $checked['data'],
                'summary'  => $checked['summary'],
            ];
        }

        return ['rows' => $rows, 'counts' => $counts, 'reader' => $reader, 'fatal' => null];
    }

    private function resolve(string $key): Importer
    {
        $importer = ImportRegistry::find($key);

        if ($importer === null) {
            $this->notFound('That import does not exist.');
        }

        Auth::authorize($importer->permission());

        return $importer;
    }
}
