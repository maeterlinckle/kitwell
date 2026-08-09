<?php

use App\Models\PatRecord;

/**
 * One PAT test, rendered as a card in a chronological history.
 *
 * @var array<string,mixed> $record
 * @var bool $showActions
 */
$showActions = $showActions ?? false;
$passed      = $record['overall_result'] === 'Pass';
?>
<li class="pat-record <?= $passed ? 'is-pass' : 'is-fail' ?>" id="pat-<?= (int) $record['id'] ?>">
    <div class="pat-record-head">
        <span class="pat-date"><?= e(format_date($record['test_date'])) ?></span>
        <span class="badge pat-<?= $passed ? 'pass' : 'fail' ?>"><?= e($record['overall_result']) ?></span>
        <span class="badge badge-muted"><?= e($record['appliance_class']) ?></span>
        <?php if (!empty($record['pat_label_serial'])): ?>
            <span class="badge mono"><?= e($record['pat_label_serial']) ?></span>
        <?php endif; ?>
        <?php if (!empty($record['retest_due_date'])): ?>
            <span class="muted pat-retest">Retest due <?= e(format_date($record['retest_due_date'])) ?></span>
        <?php endif; ?>
    </div>

    <dl class="pat-readings">
        <div>
            <dt>Visual inspection</dt>
            <dd class="<?= (int) $record['visual_inspection_pass'] === 1 ? 'reading-pass' : 'reading-fail' ?>">
                <?= (int) $record['visual_inspection_pass'] === 1 ? 'Pass' : 'Fail' ?>
            </dd>
        </div>

        <div>
            <dt>Earth continuity</dt>
            <dd><?= e(PatRecord::measurement($record['earth_continuity_ohms'], 'Ω')) ?></dd>
        </div>

        <div>
            <dt>Insulation resistance</dt>
            <dd><?= e(PatRecord::measurement($record['insulation_resistance_mohms'], 'MΩ')) ?></dd>
        </div>

        <div>
            <dt>Leakage current</dt>
            <dd><?= e(PatRecord::measurement($record['leakage_current_ma'], 'mA')) ?></dd>
        </div>

        <?php if ($record['load_test_va'] !== null): ?>
            <div><dt>Load</dt><dd><?= e(PatRecord::measurement($record['load_test_va'], 'VA')) ?></dd></div>
        <?php endif; ?>

        <?php if ($record['fuse_fitted_amps'] !== null): ?>
            <div><dt>Fuse fitted</dt><dd><?= e(PatRecord::measurement($record['fuse_fitted_amps'], 'A')) ?></dd></div>
        <?php endif; ?>

        <div>
            <dt>Functional check</dt>
            <dd class="<?= $record['functional_check_pass'] === null ? '' : ((int) $record['functional_check_pass'] === 1 ? 'reading-pass' : 'reading-fail') ?>">
                <?= $record['functional_check_pass'] === null
                    ? 'Not performed'
                    : ((int) $record['functional_check_pass'] === 1 ? 'Pass' : 'Fail') ?>
            </dd>
        </div>

        <?php if ($record['polarity_pass'] !== null): ?>
            <div>
                <dt>Polarity</dt>
                <dd class="<?= (int) $record['polarity_pass'] === 1 ? 'reading-pass' : 'reading-fail' ?>">
                    <?= (int) $record['polarity_pass'] === 1 ? 'Pass' : 'Fail' ?>
                </dd>
            </div>
        <?php endif; ?>

        <div>
            <dt>Tester</dt>
            <dd>
                <?= e($record['tester_user_name'] ?? $record['tester_name'] ?? 'Not recorded') ?>
                <?php if (!empty($record['tester_reference'])): ?>
                    <span class="muted">(<?= e($record['tester_reference']) ?>)</span>
                <?php endif; ?>
            </dd>
        </div>

        <?php if (!empty($record['test_equipment'])): ?>
            <div><dt>Test equipment</dt><dd><?= e($record['test_equipment']) ?></dd></div>
        <?php endif; ?>
    </dl>

    <?php if (!empty($record['remedial_action'])): ?>
        <div class="pat-remedial">
            <strong>Remedial action</strong>
            <p class="prewrap"><?= e($record['remedial_action']) ?></p>
        </div>
    <?php endif; ?>

    <?php if (!empty($record['notes'])): ?>
        <p class="muted prewrap"><?= e($record['notes']) ?></p>
    <?php endif; ?>

    <?php if ($showActions && (can('pat.manage') || can('pat.delete'))): ?>
        <div class="pat-actions">
            <?php if (can('pat.manage')): ?>
                <a class="btn btn-sm" href="<?= e(url('/pat/' . $record['id'] . '/edit')) ?>">Correct</a>
            <?php endif; ?>
            <?php if (can('pat.delete')): ?>
                <form method="post" action="<?= e(url('/pat/' . $record['id'] . '/delete')) ?>" class="inline-form">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-sm btn-ghost"
                            data-confirm="Delete the PAT test dated <?= e(format_date($record['test_date'])) ?>? Only do this for a record entered in error.">
                        Delete
                    </button>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</li>
