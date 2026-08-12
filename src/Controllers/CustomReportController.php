<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Models\ActivityLog;
use App\Models\CustomReport;
use App\Reports\DataSource;
use App\Reports\DataSourceRegistry;
use App\Reports\ReportRegistry;

/**
 * Defining a report.
 *
 * Only the definition lives here. Opening, filtering, printing and exporting a
 * saved report is `ReportController`'s job and needs no code of its own — the
 * saved definition arrives in the registry as an ordinary `Report`.
 *
 * Everything submitted is checked against the chosen data source before it is
 * stored: unknown filter keys are dropped, unknown columns are dropped, and the
 * sort column must be one of the columns actually chosen. A definition is
 * therefore never a way to reach a field the source does not declare, however
 * the form is submitted.
 */
final class CustomReportController extends Controller
{
    public function create(): void
    {
        $sources = DataSourceRegistry::available();

        if ($sources === []) {
            Flash::error('There is nothing you can build a report on — your role does not include access to any of the data sources.');
            Response::redirect('/reports');
        }

        $sourceKey = (string) Request::query('source', '');
        $source    = $sources[$sourceKey] ?? null;

        $this->view('reports/custom-form', [
            'pageTitle'  => 'New report',
            'definition' => null,
            'sources'    => $sources,
            'source'     => $source,
        ]);
    }

    public function store(): void
    {
        $source = $this->requireSource((string) Request::post('data_source', ''), '/reports/custom/create');
        $data   = $this->validateDefinition($source, null);

        $data['created_by'] = Auth::id();
        $data['updated_by'] = Auth::id();
        $data['report_key'] = CustomReport::makeKey((string) $data['name']);

        $id = CustomReport::create($data);
        ReportRegistry::forgetStored();

        ActivityLog::record('created', 'custom_report', $id, sprintf('Created the "%s" report', $data['name']));

        Flash::success('“' . $data['name'] . '” has been saved. It is on the Reports page now.');
        Response::redirect('/reports/' . $data['report_key']);
    }

    public function edit(string $id): void
    {
        $definition = CustomReport::find((int) $id);

        if ($definition === null) {
            $this->notFound('That report definition no longer exists.');
        }

        $sources = DataSourceRegistry::available();
        $source  = DataSourceRegistry::find((string) $definition['data_source']);

        $this->view('reports/custom-form', [
            'pageTitle'  => 'Edit ' . $definition['name'],
            'definition' => $definition,
            'sources'    => $sources,
            // The saved source, even if the editor could not create a new report
            // on it — otherwise opening the form would silently offer to move
            // the report to a different source.
            'source'     => $source,
        ]);
    }

    public function update(string $id): void
    {
        $reportId   = (int) $id;
        $definition = CustomReport::find($reportId);

        if ($definition === null) {
            $this->notFound('That report definition no longer exists.');
        }

        $redirect = '/reports/custom/' . $reportId . '/edit';
        $source   = $this->requireSource((string) Request::post('data_source', ''), $redirect);
        $data     = $this->validateDefinition($source, $definition);

        $data['updated_by'] = Auth::id();

        // The key follows the name, but only while nobody has bookmarked it —
        // which is to say, never after the first save. A report's URL is a
        // stable thing; renaming one should not break a link somebody pasted
        // into an email.
        $data['report_key'] = (string) $definition['report_key'];

        CustomReport::update($reportId, $data);
        ReportRegistry::forgetStored();

        ActivityLog::record(
            'updated',
            'custom_report',
            $reportId,
            sprintf('Updated the "%s" report', $data['name']),
            ActivityLog::diff(
                ['name' => $definition['name'], 'data_source' => $definition['data_source']],
                ['name' => $data['name'], 'data_source' => $data['data_source']]
            )
        );

        Flash::success('“' . $data['name'] . '” has been saved.');
        Response::redirect('/reports/' . $data['report_key']);
    }

    public function destroy(string $id): void
    {
        $reportId   = (int) $id;
        $definition = CustomReport::find($reportId);

        if ($definition === null) {
            $this->notFound('That report definition no longer exists.');
        }

        CustomReport::delete($reportId);
        ReportRegistry::forgetStored();

        ActivityLog::record('deleted', 'custom_report', $reportId, sprintf('Deleted the "%s" report', $definition['name']));

        Flash::success('“' . $definition['name'] . '” has been deleted. Nothing it reported on was touched.');
        Response::redirect('/reports');
    }

    /**
     * The chosen source, or a field error.
     *
     * Checked against `available()` rather than `all()`: a source the person
     * cannot read is not one they may build a report on, since the result would
     * be a definition they are refused at the moment they open it.
     */
    private function requireSource(string $key, string $redirect): DataSource
    {
        $source = DataSourceRegistry::available()[$key] ?? null;

        if ($source === null) {
            $this->failValidation(['data_source' => 'Choose something to report on.'], $redirect);
        }

        return $source;
    }

    /**
     * @param array<string,mixed>|null $existing
     * @return array<string,mixed>
     */
    private function validateDefinition(DataSource $source, ?array $existing): array
    {
        $redirect = $existing === null
            ? '/reports/custom/create?source=' . $source->key
            : '/reports/custom/' . (int) $existing['id'] . '/edit';

        $data = $this->validate([
            'name'        => 'required|max:120',
            'description' => 'max:500',
            'sort_direction' => 'in:asc,desc',
        ], [
            'name'        => 'Report name',
            'description' => 'Description',
        ], $redirect);

        // --- Columns ---------------------------------------------------------
        // Kept in the order the source declares them, not the order the browser
        // happened to post them: checkbox order is a property of the form, and a
        // report's column order should be the same every time it is saved.
        $submitted = Request::all()['columns'] ?? [];
        $submitted = is_array($submitted) ? array_map('strval', $submitted) : [];

        $columns = [];
        foreach (array_keys($source->columns()) as $columnKey) {
            if (in_array($columnKey, $submitted, true)) {
                $columns[] = $columnKey;
            }
        }

        if ($columns === []) {
            $this->failValidation(['columns' => 'Tick at least one column to show.'], $redirect);
        }

        // --- Filters ---------------------------------------------------------
        $posted  = Request::all()['filters'] ?? [];
        $posted  = is_array($posted) ? $posted : [];
        $filters = $source->cleanFilters($posted);

        // --- Sort ------------------------------------------------------------
        // Must be a column the report actually shows: sorting by something the
        // reader cannot see produces an order that looks arbitrary.
        $sortColumn = (string) Request::post('sort_column', '');

        if ($sortColumn !== '' && !in_array($sortColumn, $columns, true)) {
            $this->failValidation(
                ['sort_column' => 'Sort by one of the columns the report shows, or leave it unsorted.'],
                $redirect
            );
        }

        return [
            'name'           => $data['name'],
            'description'    => $data['description'] !== '' ? $data['description'] : null,
            'data_source'    => $source->key,
            'filters'        => $filters,
            'columns'        => $columns,
            'sort_column'    => $sortColumn !== '' ? $sortColumn : null,
            'sort_direction' => $data['sort_direction'] !== '' ? $data['sort_direction'] : 'asc',
            'is_active'      => Request::boolean('is_active') ? 1 : 0,
        ];
    }
}
