<?php

declare(strict_types=1);

namespace App\Imports;

use App\Core\Auth;
use App\Models\Asset;
use App\Models\PatRecord;

/**
 * Bulk import of PAT test results — typically a testing contractor's
 * spreadsheet, or historical records from a previous system.
 *
 * Rows are matched to assets by tag. A row that matches nothing is reported
 * clearly rather than silently dropped.
 */
final class PatImporter extends Importer
{
    public function key(): string
    {
        return 'pat';
    }

    public function name(): string
    {
        return 'PAT records';
    }

    public function description(): string
    {
        return 'Load test results against existing assets, matched by asset tag. Ideal for a contractor’s results sheet.';
    }

    public function permission(): string
    {
        return 'pat.manage';
    }

    public function columns(): array
    {
        return [
            'asset_tag' => [
                'label' => 'Asset tag', 'required' => true,
                'aliases' => ['tag', 'asset id', 'asset number', 'barcode', 'appliance id'],
                'description' => 'Required. Must match an asset already in the system (its tag or secondary barcode).',
                'example' => 'AST-0004',
            ],
            'test_date' => [
                'label' => 'Test date', 'required' => true,
                'aliases' => ['date tested', 'date', 'tested on'],
                'description' => 'Required. Accepts 2026-03-04, 04/03/2026 or 4 Mar 2026.',
                'example' => '2026-03-04',
            ],
            'retest_due_date' => [
                'label' => 'Retest due', 'aliases' => ['next test', 'retest date', 'due'],
                'description' => 'Left blank, it is worked out from the asset’s retest interval.',
                'example' => '2027-03-04',
            ],
            'tester_name' => [
                'label' => 'Tester name', 'aliases' => ['tester', 'tested by', 'engineer'],
                'description' => 'Free text — the person or contractor who did the test.',
                'example' => 'Sam Staff',
            ],
            'tester_reference' => [
                'label' => 'Tester ID', 'aliases' => ['tester reference', 'competency', 'tester id'],
                'description' => 'Optional competency or ID reference.',
                'example' => 'C&G 2377-22',
            ],
            'test_equipment' => [
                'label' => 'Test equipment', 'aliases' => ['tester model', 'instrument'],
                'description' => 'Make, model and serial of the PAT tester.',
                'example' => 'Seaward PrimeTest 250+',
            ],
            'appliance_class' => [
                'label' => 'Appliance class', 'aliases' => ['class'],
                'description' => 'Class I, Class II, Class III or Not Applicable. “1” and “2” are understood.',
                'example' => 'Class I',
            ],
            'visual_inspection' => [
                'label' => 'Visual inspection', 'aliases' => ['visual', 'visual check', 'visual pass'],
                'description' => 'Pass/Fail. Defaults to Pass.',
                'example' => 'Pass',
            ],
            'earth_continuity_ohms' => [
                'label' => 'Earth continuity (ohms)',
                'aliases' => ['earth continuity', 'earth', 'continuity', 'earth resistance', 'r ohms'],
                'description' => 'Ohms. Class I only — ignored for other classes.',
                'example' => '0.08',
            ],
            'insulation_resistance_mohms' => [
                'label' => 'Insulation resistance (Mohms)',
                'aliases' => ['insulation resistance', 'insulation', 'ir', 'insulation mohms'],
                'description' => 'Megohms.',
                'example' => '99.99',
            ],
            'leakage_current_ma' => [
                'label' => 'Leakage current (mA)',
                'aliases' => ['leakage', 'leakage current', 'leakage ma'],
                'description' => 'Milliamps.',
                'example' => '0.21',
            ],
            'load_test_va' => [
                'label' => 'Load (VA)', 'aliases' => ['load', 'load test', 'power'],
                'description' => 'Volt-amps. Optional.',
                'example' => '1200',
            ],
            'functional_check' => [
                'label' => 'Functional check', 'aliases' => ['function', 'functional', 'operation'],
                'description' => 'Pass/Fail, or blank if not performed.',
                'example' => 'Pass',
            ],
            'overall_result' => [
                'label' => 'Overall result', 'required' => true,
                'aliases' => ['result', 'pass fail', 'overall', 'outcome'],
                'description' => 'Required. Pass or Fail.',
                'example' => 'Pass',
            ],
            'pat_label_serial' => [
                'label' => 'PAT label', 'aliases' => ['label', 'label serial', 'sticker'],
                'description' => 'The serial printed on the label applied to the item.',
                'example' => 'PAT-2026-0141',
            ],
            'fuse_fitted_amps' => [
                'label' => 'Fuse fitted (A)', 'aliases' => ['fuse', 'fuse rating'],
                'description' => 'Amps found or fitted at the time of test.',
                'example' => '13',
            ],
            'remedial_action' => [
                'label' => 'Remedial action', 'aliases' => ['remedial', 'action taken', 'repairs'],
                'description' => 'What was done when the item failed.',
                'example' => '',
            ],
            'notes' => [
                'label' => 'Notes', 'aliases' => ['comments', 'remarks'],
                'description' => 'Free text.',
                'example' => 'Cable in good order',
            ],
        ];
    }

    public function optionDefinitions(): array
    {
        return [
            'flag_requires_pat' => [
                'label'       => 'Flag matched assets as requiring PAT',
                'description' => 'Recording a test on an asset implies it needs testing. Leave on unless you have a reason not to.',
                'default'     => true,
            ],
            'withdraw_failures' => [
                'label'       => 'Move failed items to “In Maintenance”',
                'description' => 'Keeps anything that failed out of the available stock.',
                'default'     => false,
            ],
        ];
    }

    public function previewColumns(): array
    {
        return ['asset_tag', 'test_date', 'retest_due_date', 'appliance_class', 'overall_result', 'pat_label_serial'];
    }

    public function notes(): array
    {
        return [
            'Asset tag, Test date and Overall result are required; everything else is optional.',
            'Rows are matched to assets by tag (or secondary barcode). A tag that matches nothing is reported and skipped — no asset is created.',
            'Earth continuity is only kept for Class I appliances; a reading against a Class II item is ignored, since there is no earth to test.',
            'A row that fails its visual inspection but claims an overall Pass is rejected, the same as the on-screen form.',
        ];
    }

    public function newContext(): array
    {
        return ['assets' => [], 'seen' => []];
    }

    public function validateRow(array $row, array &$context, array $options): array
    {
        $errors   = [];
        $warnings = [];
        $data     = [];

        // --- Match the asset ---
        $tag = trim($row['asset_tag'] ?? '');

        if ($tag === '') {
            $errors[] = 'Asset tag is missing.';
            $asset    = null;
        } else {
            $cacheKey = mb_strtolower($tag);

            if (!array_key_exists($cacheKey, $context['assets'])) {
                $context['assets'][$cacheKey] = Asset::findByTag($tag);
            }

            $asset = $context['assets'][$cacheKey];

            if ($asset === null) {
                $errors[] = 'No asset matches the tag "' . $tag . '". Import the asset first, or correct the tag.';
            }
        }

        $data['asset_id']  = $asset === null ? null : (int) $asset['id'];
        $data['asset_tag'] = $tag;

        // --- Test date ---
        $testDateRaw = trim($row['test_date'] ?? '');

        if ($testDateRaw === '') {
            $errors[] = 'Test date is missing.';
            $testDate = null;
        } else {
            $testDate = self::parseDate($testDateRaw);

            if ($testDate === null) {
                $errors[] = 'Test date "' . $testDateRaw . '" was not understood.';
            } elseif ($testDate > date('Y-m-d')) {
                $errors[] = 'Test date is in the future.';
            }
        }

        $data['test_date'] = $testDate;

        // A duplicate test for the same asset on the same day is almost
        // certainly the sheet being imported twice.
        if ($asset !== null && $testDate !== null) {
            $seenKey = $asset['id'] . '|' . $testDate;

            if (isset($context['seen'][$seenKey])) {
                $errors[] = 'This asset already has a test dated ' . format_date($testDate) . ' earlier in this file.';
            } else {
                $existing = \App\Core\Database::selectOne(
                    'SELECT id FROM pat_records WHERE asset_id = ? AND test_date = ? LIMIT 1',
                    [(int) $asset['id'], $testDate]
                );

                if ($existing !== null) {
                    $errors[] = 'A test for this asset dated ' . format_date($testDate) . ' is already recorded.';
                }
            }

            $context['seen'][$seenKey] = true;
        }

        // --- Appliance class ---
        $classRaw = trim($row['appliance_class'] ?? '');
        $class    = 'Class I';

        if ($classRaw !== '') {
            $normalised = self::matchOption($classRaw, PatRecord::CLASSES)
                ?? match (preg_replace('/[^0-9iv]/i', '', strtolower($classRaw))) {
                    '1', 'i'   => 'Class I',
                    '2', 'ii'  => 'Class II',
                    '3', 'iii' => 'Class III',
                    default    => null,
                };

            if ($normalised === null) {
                $warnings[] = 'Appliance class "' . $classRaw . '" not recognised — using Class I.';
            } else {
                $class = $normalised;
            }
        } else {
            $warnings[] = 'No appliance class given — assuming Class I.';
        }

        $data['appliance_class'] = $class;

        // --- Results ---
        $visual = self::parseBool(trim($row['visual_inspection'] ?? ''));
        $data['visual_inspection_pass'] = $visual === false ? 0 : 1;

        $overallRaw = trim($row['overall_result'] ?? '');

        if ($overallRaw === '') {
            $errors[] = 'Overall result is missing.';
            $overall  = null;
        } else {
            $overall = self::matchOption($overallRaw, PatRecord::RESULTS);

            if ($overall === null) {
                $parsed  = self::parseBool($overallRaw);
                $overall = $parsed === null ? null : ($parsed ? 'Pass' : 'Fail');
            }

            if ($overall === null) {
                $errors[] = 'Overall result "' . $overallRaw . '" is not Pass or Fail.';
            }
        }

        $data['overall_result'] = $overall;

        // The same rule the on-screen form enforces.
        if ($data['visual_inspection_pass'] === 0 && $overall === 'Pass') {
            $errors[] = 'Visual inspection failed but the overall result says Pass — one of the two is wrong.';
        }

        $functional = self::parseBool(trim($row['functional_check'] ?? ''));
        $data['functional_check_pass'] = $functional === null ? null : ($functional ? 1 : 0);
        $data['polarity_pass'] = null;

        // --- Readings ---
        $readings = [
            'earth_continuity_ohms'       => ['label' => 'Earth continuity', 'max' => 9999],
            'insulation_resistance_mohms' => ['label' => 'Insulation resistance', 'max' => 999999],
            'leakage_current_ma'          => ['label' => 'Leakage current', 'max' => 9999],
            'load_test_va'                => ['label' => 'Load', 'max' => 9999999],
            'fuse_fitted_amps'            => ['label' => 'Fuse fitted', 'max' => 999],
        ];

        foreach ($readings as $field => $meta) {
            $raw = trim($row[$field] ?? '');

            if ($raw === '') {
                $data[$field] = null;
                continue;
            }

            // Testers often print ">299" or "OL" for an over-range reading.
            $overRange = preg_match('/^\s*>/', $raw) === 1;
            $number    = self::parseNumber($raw);

            if ($number === null) {
                if (in_array(strtolower($raw), ['ol', 'over', 'over range', 'n/a', '-'], true)) {
                    $warnings[] = $meta['label'] . ' "' . $raw . '" is an over-range or not-applicable reading and has been left blank.';
                } else {
                    $warnings[] = $meta['label'] . ' "' . $raw . '" was not understood and has been left blank.';
                }

                $data[$field] = null;
                continue;
            }

            if ($number < 0 || $number > $meta['max']) {
                $warnings[] = $meta['label'] . ' "' . $raw . '" is out of range and has been left blank.';
                $data[$field] = null;
                continue;
            }

            if ($overRange) {
                $warnings[] = $meta['label'] . ' was recorded as over-range; the figure given has been stored.';
            }

            $data[$field] = $number;
        }

        // Earth continuity is meaningless on anything but Class I.
        if ($class !== 'Class I' && $data['earth_continuity_ohms'] !== null) {
            $warnings[] = 'Earth continuity reading ignored: it does not apply to ' . $class . '.';
            $data['earth_continuity_ohms'] = null;
        }

        // --- Retest date ---
        $retestRaw = trim($row['retest_due_date'] ?? '');

        if ($retestRaw !== '') {
            $retest = self::parseDate($retestRaw);

            if ($retest === null) {
                $warnings[] = 'Retest due "' . $retestRaw . '" was not understood — it will be calculated instead.';
            } elseif ($testDate !== null && $retest < $testDate) {
                $warnings[] = 'Retest due is before the test date — it will be calculated instead.';
                $retest = null;
            }
        } else {
            $retest = null;
        }

        if ($retest === null && $testDate !== null && $asset !== null) {
            $retest = PatRecord::suggestRetestDate($testDate, $asset);

            if ($retestRaw === '') {
                $warnings[] = 'Retest due calculated as ' . format_date($retest) . '.';
            }
        }

        $data['retest_due_date'] = $retest;

        // --- Text fields ---
        foreach (['tester_name', 'tester_reference', 'test_equipment', 'pat_label_serial', 'remedial_action', 'notes'] as $field) {
            $value = trim($row[$field] ?? '');
            $data[$field] = $value !== '' ? $value : null;
        }

        if ($asset !== null && (int) $asset['requires_pat'] !== 1 && !empty($options['flag_requires_pat'])) {
            $warnings[] = $asset['asset_tag'] . ' is not currently flagged as requiring PAT — it will be.';
        }

        $status = $errors !== [] ? self::STATUS_ERROR : ($warnings !== [] ? self::STATUS_WARNING : self::STATUS_OK);

        return [
            'status'   => $status,
            'errors'   => $errors,
            'warnings' => $warnings,
            'data'     => $data,
            'summary'  => trim($tag . ' — ' . ($overall ?? '?') . ' on ' . ($testDate !== null ? format_date($testDate) : '?')),
        ];
    }

    public function commitRow(array $data, array $options): string
    {
        $assetId = (int) $data['asset_id'];
        $tag     = (string) $data['asset_tag'];

        unset($data['asset_tag'], $data['__line']);

        $data['created_by'] = Auth::id();

        PatRecord::create($data);

        // Mirror the on-screen form: recording a test implies the asset needs
        // testing, and a failure can take it out of service.
        $changes = [];
        $asset   = Asset::find($assetId);

        if ($asset !== null) {
            if ((int) $asset['requires_pat'] !== 1 && !empty($options['flag_requires_pat'])) {
                $changes['requires_pat'] = 1;
            }

            if ($data['overall_result'] === 'Fail'
                && !empty($options['withdraw_failures'])
                && $asset['status'] !== 'Retired'
                && $asset['status'] !== 'On Loan') {
                $changes['status'] = 'In Maintenance';
            }

            if ($changes !== []) {
                $changes['updated_by'] = Auth::id();
                Asset::update($assetId, $changes);
            }
        }

        return sprintf('%s — %s on %s', $tag, $data['overall_result'], format_date($data['test_date']));
    }
}
