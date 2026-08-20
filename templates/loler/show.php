<?php

use App\Models\LolerExamination;

/**
 * A completed report of thorough examination.
 *
 * Laid out as one coherent record rather than as the form that produced it:
 * what the equipment is, what the examination found, who made it and for whom,
 * and when the next one falls due. Every Schedule 1 paragraph is on the page.
 *
 * @var array<string,mixed> $examination
 * @var array<int,array<string,mixed>> $defects
 */
$id      = (int) $examination['id'];
$assetId = (int) $examination['asset_id'];
$verdict = LolerExamination::verdict($examination);
$danger  = (int) $examination['danger_count'] > 0;
$serious = (int) $examination['serious_count'] > 0;

$swl = $examination['swl'] === null
    ? null
    : rtrim(rtrim(number_format((float) $examination['swl'], 3, '.', ','), '0'), '.')
        . ' ' . (string) $examination['swl_unit'];
?>
<div class="page-head">
    <div>
        <p class="eyebrow">
            <a href="<?= e(url('/assets/' . $assetId)) ?>"><span class="mono"><?= e($examination['asset_tag']) ?></span></a>
            <?= e(str_limit((string) $examination['asset_name'], 60)) ?>
        </p>
        <h1>Report of thorough examination</h1>
        <p class="badge-row">
            <span class="badge <?= $danger ? 'badge-danger' : ((int) $examination['defect_count'] > 0 ? 'badge-warn' : 'badge-ok') ?>">
                <?= e($verdict) ?>
            </span>
            <span class="badge badge-muted"><?= e(format_date((string) $examination['examined_on'])) ?></span>
            <span class="badge badge-muted">Next by <?= e(format_date((string) $examination['next_examination_date'])) ?></span>
        </p>
    </div>
    <div class="head-actions">
        <a class="btn btn-primary" href="<?= e(url('/loler/' . $id . '/pdf')) ?>">Download PDF</a>
        <a class="btn btn-ghost" href="<?= e(url('/assets/' . $assetId . '/loler')) ?>">History</a>
    </div>
</div>

<?php if ($danger): ?>
    <div class="flash flash-error">
        <span class="flash-text">
            <strong>A defect that is a danger to persons was reported.</strong>
            The employer must have been notified forthwith (regulation 10(1)(a)), and the equipment
            must not be used before the defect is rectified (regulation 10(3)(a)).
        </span>
    </div>
<?php endif; ?>

<?php if ($serious): ?>
    <div class="flash flash-error">
        <span class="flash-text">
            <strong>Regulation 10(1)(c) applies to this report.</strong>
            A defect involving an existing or imminent risk of serious personal injury was recorded,
            so a copy of this report must be sent to the relevant enforcing authority as soon as is
            practicable. This system does not send it.
        </span>
    </div>
<?php endif; ?>

<div class="detail-grid">
    <div class="detail-main">
        <div class="card">
            <div class="card-head"><h2>The equipment</h2></div>
            <dl class="answer-list">
                <div class="answer-row">
                    <dt class="answer-question">Equipment</dt>
                    <dd class="answer-value">
                        <span class="mono"><?= e($examination['asset_tag']) ?></span>
                        &mdash; <?= e($examination['asset_name']) ?>
                        <?php if (!empty($examination['manufacturer']) || !empty($examination['model'])): ?>
                            <span class="cell-sub"><?= e(trim((string) $examination['manufacturer'] . ' ' . (string) $examination['model'])) ?></span>
                        <?php endif; ?>
                    </dd>
                </div>
                <div class="answer-row">
                    <dt class="answer-question">Type</dt>
                    <dd class="answer-value"><?= e(LolerExamination::typeLabel((string) $examination['loler_type'])) ?></dd>
                </div>
                <div class="answer-row">
                    <dt class="answer-question">Serial number</dt>
                    <dd class="answer-value">
                        <?= $examination['serial_number'] === null
                            ? '<span class="muted">Not recorded</span>'
                            : '<span class="mono">' . e((string) $examination['serial_number']) . '</span>' ?>
                    </dd>
                </div>
                <div class="answer-row">
                    <dt class="answer-question">Date of manufacture</dt>
                    <dd class="answer-value">
                        <?php if ((int) $examination['manufacture_unknown'] === 1): ?>
                            <span class="muted">Not known or not marked</span>
                        <?php elseif ($examination['date_of_manufacture'] !== null): ?>
                            <?= e(format_date((string) $examination['date_of_manufacture'])) ?>
                        <?php else: ?>
                            <span class="muted">Not recorded</span>
                        <?php endif; ?>
                    </dd>
                </div>
                <div class="answer-row">
                    <dt class="answer-question">Safe working load</dt>
                    <dd class="answer-value">
                        <?= $swl === null ? '<span class="muted">Not recorded</span>' : e($swl) ?>
                        <?php if (!empty($examination['swl_configuration'])): ?>
                            <span class="cell-sub">In the configuration: <?= e($examination['swl_configuration']) ?></span>
                        <?php endif; ?>
                    </dd>
                </div>
                <div class="answer-row">
                    <dt class="answer-question">Examination interval</dt>
                    <dd class="answer-value">
                        <?= $examination['interval_months'] === null
                            ? '<span class="muted">Not recorded</span>'
                            : e((string) (int) $examination['interval_months'] . ' months') ?>
                    </dd>
                </div>
            </dl>
        </div>

        <div class="card">
            <div class="card-head"><h2>The examination</h2></div>
            <dl class="answer-list">
                <div class="answer-row">
                    <dt class="answer-question">Carried out</dt>
                    <dd class="answer-value"><?= e(LolerExamination::BASES[(string) $examination['examination_basis']] ?? (string) $examination['examination_basis']) ?></dd>
                </div>
                <?php if ((int) $examination['is_first_examination'] === 1): ?>
                    <div class="answer-row">
                        <dt class="answer-question">First examination</dt>
                        <dd class="answer-value">
                            This is the first thorough examination after installation, or after assembly
                            at a new site or in a new location.
                            <span class="cell-sub">
                                <?= (int) $examination['installed_correctly'] === 1
                                    ? 'It has been installed correctly.'
                                    : 'Not reported as correctly installed.' ?>
                            </span>
                        </dd>
                    </div>
                <?php endif; ?>
                <div class="answer-row">
                    <dt class="answer-question">Testing</dt>
                    <dd class="answer-value">
                        <?php if ((int) $examination['testing_carried_out'] === 1): ?>
                            <span class="prewrap"><?= e((string) $examination['test_particulars']) ?></span>
                        <?php else: ?>
                            <span class="muted">This examination did not include testing</span>
                        <?php endif; ?>
                    </dd>
                </div>
                <div class="answer-row">
                    <dt class="answer-question">Safe to operate</dt>
                    <dd class="answer-value">
                        <?php if ((int) $examination['safe_to_operate'] === 1): ?>
                            <span class="badge badge-ok">Yes</span>
                            <span class="cell-sub">In the opinion of the competent person named below.</span>
                        <?php else: ?>
                            <span class="badge badge-danger">Not reported as safe to operate</span>
                        <?php endif; ?>
                    </dd>
                </div>
            </dl>
        </div>

        <div class="card">
            <div class="card-head">
                <h2>Defects</h2>
                <span class="count-pill"><?= count($defects) ?></span>
            </div>

            <?php if ($defects === []): ?>
                <p class="muted">
                    <strong>None.</strong> No defect was found at this examination.
                </p>
            <?php else: ?>
                <ul class="defect-list">
                    <?php foreach ($defects as $defect): ?>
                        <?php $meta = LolerExamination::DEFECT_CATEGORIES[(string) $defect['category']] ?? null; ?>
                        <li class="defect-item">
                            <div class="defect-head">
                                <span class="badge <?= $defect['category'] === 'danger' ? 'badge-danger' : 'badge-warn' ?>">
                                    <?= e($meta['short'] ?? (string) $defect['category']) ?>
                                </span>
                                <strong><?= e($defect['part_identified']) ?></strong>
                                <?php if ((int) $defect['serious_injury_risk'] === 1): ?>
                                    <span class="badge badge-danger">Serious personal injury risk</span>
                                <?php endif; ?>
                            </div>

                            <p class="prewrap"><?= e($defect['description']) ?></p>

                            <dl class="detail-list detail-list-tight detail-list-stacked">
                                <?php if ($defect['becomes_danger_by'] !== null): ?>
                                    <div>
                                        <dt>Could become a danger by</dt>
                                        <dd>
                                            <?= e(format_date((string) $defect['becomes_danger_by'])) ?>
                                            <span class="cell-sub">
                                                The equipment must not be used after this date until the defect
                                                is rectified.
                                            </span>
                                        </dd>
                                    </div>
                                <?php endif; ?>
                                <?php if ($defect['remedy'] !== null): ?>
                                    <div>
                                        <dt>Repair, renewal or alteration required</dt>
                                        <dd class="prewrap"><?= e($defect['remedy']) ?></dd>
                                    </div>
                                <?php endif; ?>
                            </dl>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <?php if (!empty($examination['notes'])): ?>
            <div class="card">
                <h2>Notes</h2>
                <p class="prewrap"><?= e($examination['notes']) ?></p>
            </div>
        <?php endif; ?>
    </div>

    <aside class="detail-side">
        <div class="card">
            <h2>Dates</h2>
            <dl class="detail-list detail-list-tight detail-list-stacked">
                <div>
                    <dt>Last thorough examination</dt>
                    <dd><?= $examination['previous_examination_date'] === null
                        ? '<span class="muted">None on record</span>'
                        : e(format_date((string) $examination['previous_examination_date'])) ?></dd>
                </div>
                <div>
                    <dt>This examination</dt>
                    <dd><?= e(format_date((string) $examination['examined_on'])) ?></dd>
                </div>
                <div>
                    <dt>Next examination by</dt>
                    <dd><strong><?= e(format_date((string) $examination['next_examination_date'])) ?></strong></dd>
                </div>
                <div>
                    <dt>Date of this report</dt>
                    <dd><?= e(format_date((string) $examination['reported_on'])) ?></dd>
                </div>
            </dl>
        </div>

        <div class="card">
            <h2>Parties</h2>
            <dl class="detail-list detail-list-tight detail-list-stacked">
                <div>
                    <dt>Employer the examination was made for</dt>
                    <dd>
                        <?= e($examination['employer_name']) ?>
                        <span class="cell-sub prewrap"><?= e($examination['employer_address']) ?></span>
                    </dd>
                </div>
                <div>
                    <dt>Premises examined at</dt>
                    <dd class="prewrap"><?= e($examination['examination_address']) ?></dd>
                </div>
                <?php if (!empty($examination['owner_name']) || !empty($examination['owner_address'])): ?>
                    <div>
                        <dt>Owner, or hired/leased from</dt>
                        <dd>
                            <?= e((string) $examination['owner_name']) ?>
                            <span class="cell-sub prewrap"><?= e((string) $examination['owner_address']) ?></span>
                        </dd>
                    </div>
                <?php endif; ?>
            </dl>
        </div>

        <div class="card">
            <h2>Competent person</h2>
            <dl class="detail-list detail-list-tight detail-list-stacked">
                <div>
                    <dt>Examiner</dt>
                    <dd><?= e($examination['examiner_name']) ?></dd>
                </div>
                <?php if (!empty($examination['examiner_qualifications'])): ?>
                    <div>
                        <dt>Qualifications</dt>
                        <dd class="prewrap"><?= e($examination['examiner_qualifications']) ?></dd>
                    </div>
                <?php endif; ?>
                <div>
                    <dt>Employment</dt>
                    <dd>
                        <?php if ((int) $examination['examiner_self_employed'] === 1): ?>
                            Self-employed
                        <?php else: ?>
                            <?= e((string) ($examination['examiner_employer_name'] ?? 'Not recorded')) ?>
                            <?php if (!empty($examination['examiner_employer_address'])): ?>
                                <span class="cell-sub prewrap"><?= e((string) $examination['examiner_employer_address']) ?></span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </dd>
                </div>
                <div>
                    <dt>Authenticated by</dt>
                    <dd>
                        <?= e($examination['authenticated_name']) ?>
                        <span class="cell-sub"><?= e(format_datetime((string) $examination['authenticated_at'])) ?></span>
                    </dd>
                </div>
            </dl>
            <p class="field-hint">
                Authenticated by the signed-in account that submitted it, which regulation 10(1)(b)
                permits as an equally secure means in place of a signature.
            </p>
            <p class="field-hint">
                The examination, the conclusions in this report and the duties that follow from them
                under regulations 10(1) and 10(3) are those of the competent person named above.
                This software records and presents that report; it does not carry out an examination,
                exercise professional judgement, or certify compliance with LOLER.
            </p>
        </div>
    </aside>
</div>
