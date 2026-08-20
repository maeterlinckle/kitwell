<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Models\ActivityLog;
use App\Models\Asset;
use App\Models\LolerExamination;
use App\Models\Setting;
use App\Models\User;
use App\Services\LolerDocument;

/**
 * In-house LOLER reports of thorough examination.
 *
 * Reading a report needs `maintenance.view`. Making one needs `loler.inspect`,
 * which is granted to nobody on a fresh install: LOLER regulation 9 requires a
 * competent person, and L113 paragraph 297 requires an in-house examiner to
 * have genuine authority and independence, so a site has to decide who holds
 * it rather than inheriting it from a role they already had.
 *
 * The examination is a three-page guided form in the same shape as the PAT
 * wizard (§4.x): the static characteristics confirmed, then what the
 * examination found, then who examined it and for whom. Every page is checked
 * on the server; the one-page-at-a-time flow is a convenience.
 *
 * What this class does *not* do is decide anything. Whether equipment is safe
 * to operate, whether a defect is a danger, and whether the regulation 10(1)(c)
 * duty to notify the enforcing authority is engaged are the competent person's
 * judgements. The application records them, checks them against each other for
 * contradictions, and prints them.
 */
final class LolerController extends Controller
{
    /** Every examination on record. */
    public function index(): void
    {
        $filters = [
            'q'        => (string) Request::query('q', ''),
            'outcome'  => (string) Request::query('outcome', ''),
            'examiner' => (string) Request::query('examiner', ''),
            'from'     => (string) Request::query('from', ''),
            'to'       => (string) Request::query('to', ''),
        ];

        $this->view('loler/index', [
            'pageTitle' => 'LOLER examinations',
            'result'    => LolerExamination::search($filters, max(1, (int) Request::query('page', 1)), 25),
            'filters'   => $filters,
            'examiners' => User::withPermission('loler.inspect'),
        ]);
    }

    /** One asset's examination history. */
    public function history(string $assetId): void
    {
        $asset = Asset::find((int) $assetId);

        if ($asset === null) {
            $this->notFound();
        }

        $this->view('loler/history', [
            'pageTitle'    => 'LOLER history · ' . $asset['asset_tag'],
            'asset'        => $asset,
            'examinations' => LolerExamination::forAsset((int) $asset['id']),
            'status'       => LolerExamination::statusForAsset((int) $asset['id']),
        ]);
    }

    /** The guided examination. */
    public function create(string $assetId): void
    {
        $asset = Asset::find((int) $assetId);

        if ($asset === null) {
            $this->notFound();
        }

        if ((int) $asset['requires_loler'] !== 1) {
            Flash::error(sprintf(
                '%s is not marked as requiring a LOLER thorough examination. Set that on the asset first.',
                (string) $asset['asset_tag']
            ));
            Response::redirect('/assets/' . (int) $asset['id'] . '/edit');
        }

        $previous = LolerExamination::latestForAsset((int) $asset['id']);
        $type     = (string) ($asset['loler_type'] ?? '');
        $interval = $asset['loler_interval_months'] === null
            ? LolerExamination::statutoryInterval($type)
            : (int) $asset['loler_interval_months'];

        $this->view('loler/examine', [
            'pageTitle'    => 'LOLER examination · ' . $asset['asset_tag'],
            'asset'        => $asset,
            'previous'     => $previous,
            'interval'     => $interval,
            'suggestedNext'=> LolerExamination::nextExaminationDate(date('Y-m-d'), $interval),
            'basis'        => LolerExamination::basisFor($type, $interval),
            'examiners'    => User::withPermission('loler.inspect'),
            'organisation' => [
                'name'    => (string) (Setting::get('organisation_name') ?? ''),
                'address' => (string) (Setting::get('organisation_address') ?? ''),
            ],
        ]);
    }

    /** Record the examination, and the report it produces. */
    public function store(string $assetId): void
    {
        $asset = Asset::find((int) $assetId);

        if ($asset === null) {
            $this->notFound();
        }

        $id       = (int) $asset['id'];
        $redirect = '/assets/' . $id . '/loler/examine';

        $data    = $this->validateExamination($redirect);
        $defects = $this->readDefects($redirect, $data['outcome']);

        // Schedule 1(6)(b) and (7)(b) against Schedule 1(8)(a): an examiner
        // cannot report equipment as safe to operate while also reporting a
        // defect that is a danger to persons. Regulation 10(3)(a) forbids its
        // use until that defect is rectified.
        $hasDanger = false;

        foreach ($defects as $defect) {
            if ($defect['category'] === 'danger') {
                $hasDanger = true;
            }
        }

        if ($hasDanger && $data['safe_to_operate'] === 1) {
            $this->failValidation([
                'safe_to_operate' => 'A defect that is a danger to persons has been recorded, so the'
                    . ' equipment cannot also be reported as safe to operate. Regulation 10(3)(a)'
                    . ' forbids its use until the defect is rectified.',
            ], $redirect);
        }

        $examinerId = (int) $data['examiner_user_id'];
        $examiner   = User::find($examinerId);

        // The picker only offers competent persons; this is what makes that a
        // rule rather than a courtesy, since a form can be posted by hand.
        if ($examiner === null || !User::holdsPermission($examinerId, 'loler.inspect')) {
            $this->failValidation([
                'examiner_user_id' => 'That person does not hold the LOLER examination permission.',
            ], $redirect);
        }

        $me = Auth::user();

        Database::beginTransaction();

        try {
            $examinationId = LolerExamination::create([
                'asset_id'                  => $id,

                // Schedule 1(3) and (5).
                'loler_type'                => $data['loler_type'],
                'serial_number'             => $data['serial_number'] !== '' ? $data['serial_number'] : null,
                'date_of_manufacture'       => $data['manufacture_unknown'] === 1 ? null : ($data['date_of_manufacture'] !== '' ? $data['date_of_manufacture'] : null),
                'manufacture_unknown'       => $data['manufacture_unknown'],
                'swl'                       => $data['swl'] !== '' ? $data['swl'] : null,
                'swl_unit'                  => $data['swl_unit'] !== '' ? $data['swl_unit'] : null,
                'swl_configuration'         => $data['swl_configuration'] !== '' ? $data['swl_configuration'] : null,

                // Schedule 1(1) and (2).
                'employer_name'             => $data['employer_name'],
                'employer_address'          => $data['employer_address'],
                'examination_address'       => $data['examination_address'],
                'owner_name'                => $data['owner_name'] !== '' ? $data['owner_name'] : null,
                'owner_address'             => $data['owner_address'] !== '' ? $data['owner_address'] : null,

                // Schedule 1(4), (6) and (7).
                'previous_examination_date' => $data['previous_examination_date'] !== '' ? $data['previous_examination_date'] : null,
                'is_first_examination'      => $data['is_first_examination'],
                'installed_correctly'       => $data['is_first_examination'] === 1 ? $data['installed_correctly'] : null,
                'examination_basis'         => $data['examination_basis'],
                'interval_months'           => $data['interval_months'] !== '' ? (int) $data['interval_months'] : null,
                'safe_to_operate'           => $data['safe_to_operate'],

                // Schedule 1(8)(d), (e) and (f), and (11).
                'testing_carried_out'       => $data['testing_carried_out'],
                'test_particulars'          => $data['testing_carried_out'] === 1 && $data['test_particulars'] !== ''
                    ? $data['test_particulars'] : null,
                'examined_on'               => $data['examined_on'],
                'next_examination_date'     => $data['next_examination_date'],
                'reported_on'               => $data['reported_on'],

                // Schedule 1(9).
                'examiner_user_id'          => $examinerId,
                'examiner_name'             => (string) $examiner['name'],
                'examiner_qualifications'   => $data['examiner_qualifications'] !== '' ? $data['examiner_qualifications'] : null,
                'examiner_self_employed'    => $data['examiner_self_employed'],
                'examiner_employer_name'    => $data['examiner_self_employed'] === 1 ? null
                    : ($data['examiner_employer_name'] !== '' ? $data['examiner_employer_name'] : null),
                'examiner_employer_address' => $data['examiner_self_employed'] === 1 ? null
                    : ($data['examiner_employer_address'] !== '' ? $data['examiner_employer_address'] : null),

                // Schedule 1(10) and regulation 10(1)(b). The signed-in account
                // is the "equally secure means"; the name is copied in so a
                // later rename cannot rewrite an authenticated report.
                'authenticated_by'          => Auth::id(),
                'authenticated_name'        => (string) ($me['name'] ?? 'Unknown'),
                'authenticated_at'          => date('Y-m-d H:i:s'),

                'outcome'                   => $data['outcome'],
                'notes'                     => $data['notes'] !== '' ? $data['notes'] : null,
            ]);

            foreach ($defects as $position => $defect) {
                LolerExamination::addDefect([
                    'examination_id'      => $examinationId,
                    'position'            => $position + 1,
                    'category'            => $defect['category'],
                    'part_identified'     => $defect['part_identified'],
                    'description'         => $defect['description'],
                    'remedy'              => $defect['remedy'] !== '' ? $defect['remedy'] : null,
                    'becomes_danger_by'   => $defect['becomes_danger_by'] !== '' ? $defect['becomes_danger_by'] : null,
                    'serious_injury_risk' => $defect['serious_injury_risk'],
                ]);
            }

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();

            throw $e;
        }

        $this->applyToAsset($asset, $data, $hasDanger);

        ActivityLog::record(
            'loler_examined',
            'asset',
            $id,
            sprintf(
                'LOLER thorough examination on %s: %s. Next due %s.',
                format_date($data['examined_on']),
                $defects === []
                    ? 'no defects'
                    : count($defects) . ' defect(s) recorded' . ($hasDanger ? ', including a danger to persons' : ''),
                format_date($data['next_examination_date'])
            )
        );

        Flash::success($hasDanger
            ? 'Report recorded. A defect that is a danger to persons was reported: the employer must be'
              . ' notified forthwith, and the equipment must not be used until it is rectified.'
            : 'Report of thorough examination recorded.');

        Response::redirect('/loler/' . $examinationId);
    }

    /** The completed report. */
    public function show(string $id): void
    {
        $examination = LolerExamination::find((int) $id);

        if ($examination === null) {
            $this->notFound();
        }

        $this->view('loler/show', [
            'pageTitle'   => 'LOLER report · ' . $examination['asset_tag'],
            'examination' => $examination,
            'defects'     => LolerExamination::defects((int) $examination['id']),
        ]);
    }

    /** The same report as a document, for filing or sending on. */
    public function pdf(string $id): void
    {
        $examination = LolerExamination::find((int) $id);

        if ($examination === null) {
            $this->notFound();
        }

        $document = LolerDocument::build($examination, LolerExamination::defects((int) $examination['id']));
        $filename = LolerDocument::filename($examination);

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

    // -- Reading the form ----------------------------------------------------

    /**
     * Page 1's corrections go back to the asset.
     *
     * The asset is the source of truth for the equipment's fixed
     * characteristics, so an examiner who corrects one during the confirmation
     * step corrects it everywhere rather than only on this report. A defect
     * that is a danger also takes the item out of service, which is what
     * regulation 10(3)(a) requires of the employer.
     *
     * @param array<string,mixed> $asset
     * @param array<string,mixed> $data
     */
    private function applyToAsset(array $asset, array $data, bool $hasDanger): void
    {
        $changes = [
            'loler_type'               => $data['loler_type'],
            'loler_interval_months'    => $data['interval_months'] !== '' ? (int) $data['interval_months'] : null,
            'loler_swl'                => $data['swl'] !== '' ? $data['swl'] : null,
            'loler_swl_unit'           => $data['swl_unit'] !== '' ? $data['swl_unit'] : null,
            'loler_date_of_manufacture'=> $data['manufacture_unknown'] === 1 ? null
                : ($data['date_of_manufacture'] !== '' ? $data['date_of_manufacture'] : null),
            'loler_manufacture_unknown'=> $data['manufacture_unknown'],
            'serial_number'            => $data['serial_number'] !== '' ? $data['serial_number'] : null,
        ];

        if ($hasDanger && Request::boolean('take_out_of_service') && $asset['status'] !== 'Retired') {
            $changes['status'] = 'Faulty';
        }

        $changes['updated_by'] = Auth::id();

        Asset::update((int) $asset['id'], $changes);

        $corrected = ActivityLog::diff($asset, $changes);

        if ($corrected !== []) {
            ActivityLog::record(
                'updated',
                'asset',
                (int) $asset['id'],
                'Equipment details corrected during a LOLER thorough examination',
                $corrected
            );
        }
    }

    /**
     * The defects recorded on page 2 — Schedule 1(8).
     *
     * @return array<int,array<string,mixed>>
     */
    private function readDefects(string $redirect, string $outcome): array
    {
        $submitted = Request::post('defect');
        $submitted = is_array($submitted) ? $submitted : [];

        $defects = [];
        $errors  = [];

        foreach ($submitted as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $category    = (string) ($row['category'] ?? '');
            $part        = trim((string) ($row['part_identified'] ?? ''));
            $description = trim((string) ($row['description'] ?? ''));
            $remedy      = trim((string) ($row['remedy'] ?? ''));
            $by          = trim((string) ($row['becomes_danger_by'] ?? ''));

            // A row nobody filled in is a row nobody meant to add.
            if ($category === '' && $part === '' && $description === '') {
                continue;
            }

            $key = 'defect.' . $index;

            if (!array_key_exists($category, LolerExamination::DEFECT_CATEGORIES)) {
                $errors[$key] = 'Choose whether this defect is a danger now, or could become one.';
                continue;
            }

            if ($part === '') {
                $errors[$key] = 'Schedule 1(8)(a) needs the part with the defect identified.';
                continue;
            }

            if ($description === '') {
                $errors[$key] = 'Schedule 1(8)(a) needs a description of the defect.';
                continue;
            }

            // Schedule 1(8)(b): particulars of the repair, renewal or
            // alteration required to remedy a defect found to be a danger.
            if ($category === 'danger' && $remedy === '') {
                $errors[$key] = 'Schedule 1(8)(b) needs the repair, renewal or alteration required to'
                    . ' remedy a defect that is a danger to persons.';
                continue;
            }

            // Schedule 1(8)(c)(i): the time by which it could become a danger.
            if ($category === 'becoming_danger' && $by === '') {
                $errors[$key] = 'Schedule 1(8)(c)(i) needs the date by which this could become a danger.';
                continue;
            }

            // Schedule 1(8)(c)(ii): the same particulars of a remedy are
            // required for a defect that could become a danger as (8)(b)
            // requires for one that already is. Easy to overlook, because the
            // date is the conspicuous half of (8)(c).
            if ($category === 'becoming_danger' && $remedy === '') {
                $errors[$key] = 'Schedule 1(8)(c)(ii) needs the repair, renewal or alteration required to'
                    . ' remedy a defect that could become a danger to persons.';
                continue;
            }

            if ($by !== '' && \DateTimeImmutable::createFromFormat('Y-m-d', $by) === false) {
                $errors[$key] = 'That is not a date this could become a danger by.';
                continue;
            }

            $defects[] = [
                'category'            => $category,
                'part_identified'     => mb_substr($part, 0, 255),
                'description'         => mb_substr($description, 0, 5000),
                'remedy'              => mb_substr($remedy, 0, 5000),
                'becomes_danger_by'   => $category === 'becoming_danger' ? $by : '',
                'serious_injury_risk' => !empty($row['serious_injury_risk']) ? 1 : 0,
            ];
        }

        if ($errors !== []) {
            $this->failValidation($errors, $redirect);
        }

        if ($outcome === 'defects' && $defects === []) {
            $this->failValidation(
                ['outcome' => 'Describe at least one defect, or record the examination as finding none.'],
                $redirect
            );
        }

        return $outcome === 'none' ? [] : $defects;
    }

    /** @return array<string,mixed> */
    private function validateExamination(string $redirect): array
    {
        $data = $this->validate([
            // Page 1 — the equipment.
            'loler_type'                => 'required|max:60',
            'serial_number'             => 'max:191',
            'date_of_manufacture'       => 'date',
            'swl'                       => 'numeric|min_value:0|max_value:999999999',
            'swl_unit'                  => 'max:12',
            'swl_configuration'         => 'max:255',
            'interval_months'           => 'required|integer|min_value:1|max_value:120',

            // Page 2 — the examination.
            'outcome'                   => 'required|in:none,defects',
            'test_particulars'          => 'max:5000',
            'notes'                     => 'max:5000',

            // Page 3 — the parties and the dates.
            'examiner_user_id'          => 'required|integer',
            'examiner_qualifications'   => 'max:500',
            'examiner_employer_name'    => 'max:191',
            'examiner_employer_address' => 'max:500',
            'employer_name'             => 'required|max:191',
            'employer_address'          => 'required|max:500',
            'examination_address'       => 'required|max:500',
            'owner_name'                => 'max:191',
            'owner_address'             => 'max:500',
            'previous_examination_date' => 'date',
            'examination_basis'         => 'required|in:' . implode(',', array_keys(LolerExamination::BASES)),
            'examined_on'               => 'required|date',
            'next_examination_date'     => 'required|date',
            'reported_on'               => 'required|date',
        ], [
            'loler_type'            => 'Type of lifting equipment',
            'interval_months'       => 'Examination interval',
            'outcome'               => 'Examination outcome',
            'examiner_user_id'      => 'Examiner',
            'employer_name'         => 'Employer name',
            'employer_address'      => 'Employer address',
            'examination_address'   => 'Address of examination',
            'examination_basis'     => 'Basis for this examination',
            'examined_on'           => 'Date of examination',
            'next_examination_date' => 'Latest date of next examination',
            'reported_on'           => 'Date of this report',
        ], $redirect);

        if (!array_key_exists($data['loler_type'], LolerExamination::TYPES)) {
            $this->failValidation(['loler_type' => 'Choose the type of lifting equipment or accessory.'], $redirect);
        }

        if ($data['swl_unit'] !== '' && !in_array($data['swl_unit'], LolerExamination::SWL_UNITS, true)) {
            $this->failValidation(['swl_unit' => 'That is not a unit an SWL can be given in.'], $redirect);
        }

        $data['manufacture_unknown']  = Request::boolean('manufacture_unknown') ? 1 : 0;
        $data['is_first_examination'] = Request::boolean('is_first_examination') ? 1 : 0;
        $data['installed_correctly']  = Request::boolean('installed_correctly') ? 1 : 0;
        $data['safe_to_operate']      = Request::boolean('safe_to_operate') ? 1 : 0;
        $data['testing_carried_out']  = Request::boolean('testing_carried_out') ? 1 : 0;
        $data['examiner_self_employed'] = Request::boolean('examiner_self_employed') ? 1 : 0;

        // Schedule 1(3) asks for the date of manufacture "where known", which is
        // why there is a way to say it is not.
        if ($data['manufacture_unknown'] === 0 && $data['date_of_manufacture'] === '') {
            $this->failValidation([
                'date_of_manufacture' => 'Give the date of manufacture, or tick that it is not known or not marked.',
            ], $redirect);
        }

        if ($data['date_of_manufacture'] !== '' && $data['date_of_manufacture'] > date('Y-m-d')) {
            $this->failValidation(['date_of_manufacture' => 'The date of manufacture cannot be in the future.'], $redirect);
        }

        if ($data['examined_on'] > date('Y-m-d')) {
            $this->failValidation(['examined_on' => 'The date of examination cannot be in the future.'], $redirect);
        }

        if ($data['next_examination_date'] <= $data['examined_on']) {
            $this->failValidation([
                'next_examination_date' => 'The next examination must fall after this one.',
            ], $redirect);
        }

        if ($data['previous_examination_date'] !== '' && $data['previous_examination_date'] > $data['examined_on']) {
            $this->failValidation([
                'previous_examination_date' => 'The last examination cannot be after this one.',
            ], $redirect);
        }

        // Schedule 1(8)(e): where the examination included testing, the report
        // has to say what the test was.
        if ($data['testing_carried_out'] === 1 && $data['test_particulars'] === '') {
            $this->failValidation([
                'test_particulars' => 'Schedule 1(8)(e) needs particulars of the test that was carried out.',
            ], $redirect);
        }

        return $data;
    }
}
