<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Models\ActivityLog;
use App\Models\Asset;
use App\Models\Category;
use App\Models\Location;
use App\Models\MaintenanceSchedule;
use App\Models\PatRecord;

/**
 * Portable Appliance Testing.
 *
 * Every test is kept, so an asset builds a full history. The current position
 * (in date / due soon / overdue / failed / never tested) is derived from the
 * most recent test.
 */
final class PatController extends Controller
{
    /** The PAT register: every asset that needs testing. */
    public function index(): void
    {
        $filters = self::filtersFromRequest();
        $page    = max(1, (int) Request::query('page', 1));

        $this->view('pat/index', [
            'pageTitle'   => 'PAT testing',
            'result'      => PatRecord::assetSearch($filters, $page, 25),
            'filters'     => $filters,
            'summary'     => PatRecord::summary(),
            'categories'  => Category::all(true),
            'locations'   => Location::forSelect(),
            'queryString' => self::queryString($filters),
        ]);
    }

    /** Full PAT history for one asset. */
    public function history(string $assetId): void
    {
        $id    = (int) $assetId;
        $asset = Asset::find($id);

        if ($asset === null) {
            $this->notFound();
        }

        $this->view('pat/history', [
            'pageTitle' => 'PAT history · ' . $asset['asset_tag'],
            'asset'     => $asset,
            'records'   => PatRecord::forAsset($id),
            'status'    => PatRecord::statusForAsset($id),
        ]);
    }

    /** One test record in full. */
    public function show(string $id): void
    {
        $record = PatRecord::find((int) $id);

        if ($record === null) {
            $this->notFound();
        }

        $this->view('pat/show', [
            'pageTitle' => 'PAT test ' . format_date($record['test_date']) . ' · ' . $record['asset_tag'],
            'record'    => $record,
        ]);
    }

    public function create(): void
    {
        $assetId = (int) Request::query('asset', 0);
        $asset   = $assetId > 0 ? Asset::find($assetId) : null;

        if ($asset !== null && (int) $asset['requires_pat'] !== 1) {
            Flash::warning($asset['asset_tag'] . ' is not currently flagged as requiring PAT. Recording a test will flag it.');
        }

        // Without an asset there is nothing to show fixed values for, so the
        // flow starts with a lookup rather than a half-populated wizard.
        if ($asset === null) {
            $this->view('pat/choose-asset', [
                'pageTitle' => 'Record a PAT test',
                'assets'    => PatRecord::testableAssets(),
            ]);

            return;
        }

        $this->view('pat/wizard', [
            'pageTitle'    => 'Record PAT test · ' . $asset['asset_tag'],
            'asset'        => $asset,
            'users'        => MaintenanceSchedule::assignableUsers(),
            'suggestedDue' => PatRecord::suggestRetestDate(date('Y-m-d'), $asset),
            'interval'     => PatRecord::intervalForAsset($asset),
            'tests'        => Asset::testsFor($asset),
            'lastTest'     => PatRecord::forAsset((int) $asset['id'], 1)[0] ?? null,
        ]);
    }

    public function store(): void
    {
        $assetId = (int) Request::post('asset_id', 0);
        $asset   = Asset::find($assetId);

        if ($asset === null) {
            $this->notFound();
        }

        $data = $this->validateGuided($asset);

        $data['asset_id']   = $assetId;
        $data['created_by'] = Auth::id();

        $recordId = PatRecord::create($data);

        $this->applyOutcomeToAsset($asset, $data);

        ActivityLog::record(
            'pat_recorded',
            'asset',
            $assetId,
            sprintf(
                'PAT test %s on %s (%s), retest due %s',
                $data['overall_result'],
                format_date($data['test_date']),
                $data['appliance_class'],
                $data['retest_due_date'] !== null ? format_date($data['retest_due_date']) : 'not set'
            )
        );

        Flash::success(sprintf(
            'PAT test recorded: %s. %s',
            $data['overall_result'],
            $data['retest_due_date'] !== null ? 'Next test due ' . format_date($data['retest_due_date']) . '.' : ''
        ));

        Response::redirect('/assets/' . $assetId . '/pat');
    }

    public function edit(string $id): void
    {
        $record = PatRecord::find((int) $id);

        if ($record === null) {
            $this->notFound();
        }

        $asset = Asset::find((int) $record['asset_id']);

        $this->view('pat/form', [
            'pageTitle'    => 'Edit PAT test · ' . $record['asset_tag'],
            'record'       => $record,
            'asset'        => $asset,
            'assets'       => [],
            'users'        => MaintenanceSchedule::assignableUsers(),
            'suggestedDue' => PatRecord::suggestRetestDate((string) $record['test_date'], $asset),
            'interval'     => PatRecord::intervalForAsset($asset),
        ]);
    }

    public function update(string $id): void
    {
        $recordId = (int) $id;
        $record   = PatRecord::find($recordId);

        if ($record === null) {
            $this->notFound();
        }

        $data = $this->validateRecord($record);
        unset($data['asset_id']); // a test stays with the asset it was performed on

        PatRecord::update($recordId, $data);

        ActivityLog::record(
            'pat_updated',
            'asset',
            (int) $record['asset_id'],
            'Corrected the PAT test dated ' . format_date($record['test_date']),
            ActivityLog::diff($record, $data)
        );

        Flash::success('PAT record updated.');
        Response::redirect('/assets/' . (int) $record['asset_id'] . '/pat');
    }

    public function destroy(string $id): void
    {
        $recordId = (int) $id;
        $record   = PatRecord::find($recordId);

        if ($record === null) {
            $this->notFound();
        }

        $assetId = (int) $record['asset_id'];
        PatRecord::delete($recordId);

        ActivityLog::record(
            'pat_deleted',
            'asset',
            $assetId,
            'Deleted the PAT test dated ' . format_date($record['test_date'])
        );

        Flash::success('PAT record deleted. The asset now shows its previous test, if it has one.');
        Response::redirect('/assets/' . $assetId . '/pat');
    }

    /** One-click flip of the "requires PAT" flag from the asset page. */
    public function toggleRequirement(string $assetId): void
    {
        $id    = (int) $assetId;
        $asset = Asset::find($id);

        if ($asset === null) {
            $this->notFound();
        }

        $required = (int) $asset['requires_pat'] !== 1;

        Asset::update($id, [
            'requires_pat' => $required ? 1 : 0,
            'updated_by'   => Auth::id(),
        ]);

        ActivityLog::record(
            'updated',
            'asset',
            $id,
            $required
                ? 'Flagged ' . $asset['asset_tag'] . ' as requiring PAT'
                : 'Removed the PAT requirement from ' . $asset['asset_tag']
        );

        Flash::success($required
            ? $asset['asset_tag'] . ' now requires PAT testing.'
            : $asset['asset_tag'] . ' no longer requires PAT testing. Any existing test history is kept.');

        Response::back('/assets/' . $id);
    }

    /**
     * A failed test should not leave the item quietly available for use.
     *
     * @param array<string,mixed> $asset
     * @param array<string,mixed> $data
     */
    private function applyOutcomeToAsset(array $asset, array $data): void
    {
        $changes = [];

        // Recording a test on an unflagged asset implies it does need testing.
        if ((int) $asset['requires_pat'] !== 1) {
            $changes['requires_pat'] = 1;
        }

        if ($data['overall_result'] === 'Fail') {
            if (Request::boolean('withdraw_from_use') && $asset['status'] !== 'Retired') {
                $changes['status'] = 'In Maintenance';
            }

            if (Request::boolean('mark_out_of_service')) {
                $changes['condition_rating'] = 'Out of Service';
            }
        }

        if ($changes !== []) {
            $changes['updated_by'] = Auth::id();
            Asset::update((int) $asset['id'], $changes);
        }
    }

    /**
     * Validate the guided test flow.
     *
     * The tester answers each check separately; the overall result is derived
     * here rather than declared. One failed check fails the test, and a Pass is
     * only possible once every applicable check has passed. The browser gates
     * the Pass button too, but this is the check that counts — the wizard is
     * ordinary HTML and can be posted without it.
     *
     * Fixed values (appliance class, fuse arrangement) come from the asset, not
     * from the form, so a tester cannot contradict the register by hand.
     *
     * @param array<string,mixed> $asset
     * @return array<string,mixed>
     */
    private function validateGuided(array $asset): array
    {
        $assetId  = (int) $asset['id'];
        $redirect = '/pat/create?asset=' . $assetId;

        $data = $this->validate([
            'test_date'                   => 'required|date',
            'retest_due_date'             => 'date',
            'tester_user_id'              => 'integer',
            'tester_name'                 => 'max:191',
            'tester_reference'            => 'max:100',
            'test_equipment'              => 'max:191',
            'earth_continuity_ohms'       => 'numeric|min_value:0|max_value:9999',
            'insulation_resistance_mohms' => 'numeric|min_value:0|max_value:999999',
            'leakage_current_ma'          => 'numeric|min_value:0|max_value:9999',
            'extension_lead_metres'       => 'numeric|min_value:0|max_value:500',
            'pat_label_serial'            => 'max:100',
            'remedial_action'             => 'max:5000',
            'notes'                       => 'max:5000',
        ], [
            'test_date'                   => 'Test date',
            'retest_due_date'             => 'Retest due date',
            'earth_continuity_ohms'       => 'Earth continuity (Ω)',
            'insulation_resistance_mohms' => 'Insulation resistance (MΩ)',
            'leakage_current_ma'          => 'Leakage current (mA)',
            'extension_lead_metres'       => 'Extension lead length (m)',
            'pat_label_serial'            => 'PAT label serial',
        ], $redirect);

        if ($data['test_date'] > date('Y-m-d')) {
            $this->failValidation(['test_date' => 'The test date cannot be in the future.'], $redirect);
        }

        if ($data['retest_due_date'] !== '' && $data['retest_due_date'] < $data['test_date']) {
            $this->failValidation(['retest_due_date' => 'The retest date must be after the test date.'], $redirect);
        }

        // Fixed values are the asset's, snapshotted onto the record so the
        // history stays readable even if the asset is corrected later.
        $applianceClass = (string) ($asset['appliance_class'] ?? '');
        $hasFuse        = (int) ($asset['has_fuse'] ?? 0) === 1;

        if ($applianceClass === '') {
            $this->failValidation(
                ['appliance_class' => 'This asset has no appliance class recorded, so the flow cannot tell which electrical tests apply. Set it on the asset first.'],
                '/assets/' . $assetId . '/edit'
            );
        }

        $errors  = [];
        $answers = [];   // column => 1 | 0 | null
        $failed  = [];   // labels of everything that failed, for the summary

        // --- Step 2: the visual checks ------------------------------------
        foreach (PatRecord::VISUAL_CHECKS as $column => [$label]) {
            if ($column === 'visual_fuse_pass' && !$hasFuse) {
                $answers[$column] = null;   // unfused: the check does not exist
                continue;
            }

            $answer = (string) Request::post($column, '');

            if ($answer !== '1' && $answer !== '0') {
                $errors[$column] = $label . ' has not been answered.';
                continue;
            }

            $answers[$column] = (int) $answer;
            if ($answer === '0') {
                $failed[] = $label;
            }
        }

        // --- Step 3: only the electrical tests this class calls for --------
        $applicable = Asset::testsFor($asset);

        foreach (PatRecord::ELECTRICAL_TESTS as $key => [$valueColumn, $verdictColumn, $label]) {
            if (!in_array($key, $applicable, true)) {
                $answers[$valueColumn]   = null;
                $answers[$verdictColumn] = null;
                continue;
            }

            if ($data[$valueColumn] === '') {
                $errors[$valueColumn] = $label . ' has no reading.';
            }

            $verdict = (string) Request::post($verdictColumn, '');

            if ($verdict !== '1' && $verdict !== '0') {
                $errors[$verdictColumn] = $label . ' has not been marked pass or fail.';
                continue;
            }

            $answers[$valueColumn]   = $data[$valueColumn] !== '' ? $data[$valueColumn] : null;
            $answers[$verdictColumn] = (int) $verdict;

            if ($verdict === '0') {
                $failed[] = $label;
            }
        }

        // --- Step 4: functional check -------------------------------------
        $functional = (string) Request::post('functional_check_pass', '');

        if ($functional !== '1' && $functional !== '0') {
            $errors['functional_check_pass'] = 'The functional check has not been answered.';
        } else {
            $answers['functional_check_pass'] = (int) $functional;
            if ($functional === '0') {
                $failed[] = 'Functional check';
            }
        }

        if ($errors !== []) {
            $this->failValidation($errors, $redirect);
        }

        // --- Step 5: the result is derived, never declared -----------------
        $overall = $failed === [] ? 'Pass' : 'Fail';

        // A failure needs saying what failed; the flow asks for it, so an empty
        // note here means the form was bypassed.
        $notes = $data['notes'];
        if ($overall === 'Fail') {
            $summary = 'Failed: ' . implode(', ', $failed) . '.';
            $notes   = $notes !== '' ? $summary . ' ' . $notes : $summary;
        }

        $visualColumns = ['visual_plug_pass', 'visual_cable_pass', 'visual_case_pass', 'visual_fuse_pass'];
        $visualPass    = 1;
        foreach ($visualColumns as $column) {
            if (($answers[$column] ?? null) === 0) {
                $visualPass = 0;
            }
        }

        return array_merge($answers, [
            'test_date'              => $data['test_date'],
            'retest_due_date'        => $data['retest_due_date'] !== ''
                ? $data['retest_due_date']
                : PatRecord::suggestRetestDate($data['test_date'], $asset),
            'tester_user_id'         => (int) $data['tester_user_id'] > 0 ? (int) $data['tester_user_id'] : Auth::id(),
            'tester_name'            => $data['tester_name'] !== '' ? $data['tester_name'] : null,
            'tester_reference'       => $data['tester_reference'] !== '' ? $data['tester_reference'] : null,
            'test_equipment'         => $data['test_equipment'] !== '' ? $data['test_equipment'] : null,
            'appliance_class'        => $applianceClass,
            'visual_inspection_pass' => $visualPass,
            'extension_lead_metres'  => $data['extension_lead_metres'] !== '' ? $data['extension_lead_metres'] : null,
            'load_test_va'           => $asset['load_rating_va'] ?? null,
            'fuse_fitted_amps'       => $hasFuse ? ($asset['plug_fuse_rating_amps'] ?? null) : null,
            'overall_result'         => $overall,
            'pat_label_serial'       => $data['pat_label_serial'] !== '' ? $data['pat_label_serial'] : null,
            'remedial_action'        => $data['remedial_action'] !== '' ? $data['remedial_action'] : null,
            'notes'                  => $notes !== '' ? $notes : null,
        ]);
    }

    /**
     * Validate the correction form.
     *
     * Editing an existing record stays a flat form: a correction is a different
     * job from performing a test, and walking six steps to fix a typo in the
     * tester's name would be worse, not better.
     *
     * @param array<string,mixed>|null $existing
     * @return array<string,mixed>
     */
    private function validateRecord(?array $existing = null): array
    {
        $redirect = $existing === null
            ? '/pat/create' . (Request::post('asset_id') !== null ? '?asset=' . (int) Request::post('asset_id') : '')
            : '/pat/' . (int) $existing['id'] . '/edit';

        $data = $this->validate([
            'asset_id'                    => 'required|integer|exists:assets,id',
            'test_date'                   => 'required|date',
            'retest_due_date'             => 'date',
            'tester_user_id'              => 'integer',
            'tester_name'                 => 'max:191',
            'tester_reference'            => 'max:100',
            'test_equipment'              => 'max:191',
            'appliance_class'             => 'required|in:' . implode(',', PatRecord::CLASSES),
            'earth_continuity_ohms'       => 'numeric|min_value:0|max_value:9999',
            'insulation_resistance_mohms' => 'numeric|min_value:0|max_value:999999',
            'leakage_current_ma'          => 'numeric|min_value:0|max_value:9999',
            'load_test_va'                => 'numeric|min_value:0|max_value:9999999',
            'overall_result'              => 'required|in:' . implode(',', PatRecord::RESULTS),
            'pat_label_serial'            => 'max:100',
            'fuse_fitted_amps'            => 'numeric|min_value:0|max_value:999',
            'remedial_action'             => 'max:5000',
            'notes'                       => 'max:5000',
        ], [
            'asset_id'                    => 'Asset',
            'test_date'                   => 'Test date',
            'retest_due_date'             => 'Retest due date',
            'appliance_class'             => 'Appliance class',
            'earth_continuity_ohms'       => 'Earth continuity (Ω)',
            'insulation_resistance_mohms' => 'Insulation resistance (MΩ)',
            'leakage_current_ma'          => 'Leakage current (mA)',
            'load_test_va'                => 'Load test (VA)',
            'overall_result'              => 'Overall result',
            'pat_label_serial'            => 'PAT label serial',
            'fuse_fitted_amps'            => 'Fuse fitted (A)',
        ], $redirect);

        if ($data['test_date'] > date('Y-m-d')) {
            $this->failValidation(['test_date' => 'The test date cannot be in the future.'], $redirect);
        }

        if ($data['retest_due_date'] !== '' && $data['retest_due_date'] < $data['test_date']) {
            $this->failValidation(['retest_due_date' => 'The retest date must be after the test date.'], $redirect);
        }

        $visualPass     = Request::boolean('visual_inspection_pass');
        $functionalRaw  = (string) Request::post('functional_check_pass', '');
        $polarityRaw    = (string) Request::post('polarity_pass', '');

        // A failed visual inspection means the appliance fails, and testing
        // should stop there. Recording "visual fail, overall pass" is almost
        // always a slip, so it is rejected rather than silently stored.
        if (!$visualPass && $data['overall_result'] === 'Pass') {
            $this->failValidation(
                ['overall_result' => 'An item that fails its visual inspection cannot pass overall. Set the overall result to Fail, or mark the visual inspection as a pass.'],
                $redirect
            );
        }

        $applianceClass = (string) $data['appliance_class'];

        // Earth continuity only means anything on a Class I appliance.
        if ($applianceClass !== 'Class I') {
            $data['earth_continuity_ohms'] = '';
        }

        return [
            'asset_id'                    => (int) $data['asset_id'],
            'test_date'                   => $data['test_date'],
            'retest_due_date'             => $data['retest_due_date'] !== '' ? $data['retest_due_date'] : null,
            'tester_user_id'              => (int) $data['tester_user_id'] > 0 ? (int) $data['tester_user_id'] : null,
            'tester_name'                 => $data['tester_name'] !== '' ? $data['tester_name'] : null,
            'tester_reference'            => $data['tester_reference'] !== '' ? $data['tester_reference'] : null,
            'test_equipment'              => $data['test_equipment'] !== '' ? $data['test_equipment'] : null,
            'appliance_class'             => $applianceClass,
            'visual_inspection_pass'      => $visualPass ? 1 : 0,
            'earth_continuity_ohms'       => $data['earth_continuity_ohms'] !== '' ? $data['earth_continuity_ohms'] : null,
            'insulation_resistance_mohms' => $data['insulation_resistance_mohms'] !== '' ? $data['insulation_resistance_mohms'] : null,
            'leakage_current_ma'          => $data['leakage_current_ma'] !== '' ? $data['leakage_current_ma'] : null,
            'load_test_va'                => $data['load_test_va'] !== '' ? $data['load_test_va'] : null,
            'polarity_pass'               => $polarityRaw === '' ? null : ($polarityRaw === '1' ? 1 : 0),
            'functional_check_pass'       => $functionalRaw === '' ? null : ($functionalRaw === '1' ? 1 : 0),
            'overall_result'              => $data['overall_result'],
            'pat_label_serial'            => $data['pat_label_serial'] !== '' ? $data['pat_label_serial'] : null,
            'fuse_fitted_amps'            => $data['fuse_fitted_amps'] !== '' ? $data['fuse_fitted_amps'] : null,
            'remedial_action'             => $data['remedial_action'] !== '' ? $data['remedial_action'] : null,
            'notes'                       => $data['notes'] !== '' ? $data['notes'] : null,
        ];
    }

    /** @return array<string,mixed> */
    public static function filtersFromRequest(): array
    {
        return [
            'q'               => (string) Request::query('q', ''),
            'status'          => array_values(array_filter((array) (Request::query('status', []) ?? []), 'is_string')),
            'category_id'     => (string) Request::query('category', ''),
            'location_id'     => (string) Request::query('location', ''),
            'include_retired' => Request::query('retired') === '1',
            'sort'            => (string) Request::query('sort', 'due'),
        ];
    }

    /** @param array<string,mixed> $filters */
    public static function queryString(array $filters): string
    {
        $params = array_filter([
            'q'        => $filters['q'] ?? '',
            'category' => $filters['category_id'] ?? '',
            'location' => $filters['location_id'] ?? '',
            'retired'  => !empty($filters['include_retired']) ? '1' : '',
            'sort'     => ($filters['sort'] ?? 'due') !== 'due' ? $filters['sort'] : '',
        ], static fn ($v): bool => $v !== '' && $v !== null);

        $query = http_build_query($params);

        foreach ((array) ($filters['status'] ?? []) as $status) {
            $query .= '&status%5B%5D=' . rawurlencode((string) $status);
        }

        return trim($query, '&');
    }
}
