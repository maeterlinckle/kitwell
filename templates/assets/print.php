<?php

use App\Core\Barcode;
use App\Models\MaintenanceSchedule;

/**
 * One asset as a document: everything someone would want on paper when the
 * machine is in front of them and the system is not.
 *
 * @var array<string,mixed> $asset
 * @var array<int,array<string,mixed>> $children
 * @var array<int,array<string,mixed>> $schedules
 * @var array<int,array<string,mixed>> $maintenanceLogs
 * @var array<string,mixed>|null $patStatus
 * @var array<int,array<string,mixed>> $patRecords
 * @var array<string,mixed>|null $openHire
 */
$dash = static fn ($value): string => ($value === null || $value === '') ? '—' : (string) $value;
?>
<article class="print-doc">
    <?= partial('partials/print-header', [
        'title'    => (string) $asset['asset_tag'],
        'subtitle' => (string) $asset['name'],
    ]) ?>

    <?php if (Barcode::isEncodable((string) $asset['asset_tag'])): ?>
        <div class="print-barcode"><?= Barcode::svg((string) $asset['asset_tag'], 0.34, 12.0) ?></div>
    <?php endif; ?>

    <section class="print-section">
        <h2>Identification</h2>
        <dl class="print-dl">
            <div><dt>Asset tag</dt><dd class="mono"><?= e($asset['asset_tag']) ?></dd></div>
            <div><dt>Name</dt><dd><?= e($asset['name']) ?></dd></div>
            <div><dt>Category</dt><dd><?= e($dash($asset['category_name'] ?? null)) ?></dd></div>
            <div><dt>Location</dt><dd><?= e($dash($asset['location_name'] ?? null)) ?></dd></div>
            <div><dt>Status</dt><dd><?= e($asset['status']) ?></dd></div>
            <div><dt>Condition</dt><dd><?= e($asset['condition_rating']) ?></dd></div>
            <div>
                <dt>Responsible party</dt>
                <dd><?= e(\App\Models\Asset::responsibleLabel($asset, 'Unassigned')) ?></dd>
            </div>
            <div><dt>Manufacturer</dt><dd><?= e($dash($asset['manufacturer'] ?? null)) ?></dd></div>
            <div><dt>Model</dt><dd><?= e($dash($asset['model'] ?? null)) ?></dd></div>
            <div><dt>Serial number</dt><dd class="mono"><?= e($dash($asset['serial_number'] ?? null)) ?></dd></div>
            <div><dt>Secondary barcode</dt><dd class="mono"><?= e($dash($asset['barcode'] ?? null)) ?></dd></div>
            <?php if (!empty($asset['parent_tag'])): ?>
                <div><dt>Part of</dt><dd><?= e($asset['parent_tag']) ?> (<?= e($dash($asset['relationship_type'] ?? null)) ?>)</dd></div>
            <?php endif; ?>
        </dl>

        <?php if (!empty($asset['description'])): ?>
            <p class="print-note prewrap"><?= e($asset['description']) ?></p>
        <?php endif; ?>
    </section>

    <?php if ($asset['status'] === 'Faulty'): ?>
        <?php /* A printed record that says "Faulty" and then explains nothing
                 is the paperwork equivalent of a shrug. This is the same
                 information the screen's banner carries, without the photos —
                 they are on the record itself, and this is a document. */ ?>
        <section class="print-section">
            <h2>Reported fault</h2>
            <?php if ($currentFault === null): ?>
                <p class="print-note">
                    This asset is marked faulty, but no fault report has been filed against it.
                </p>
            <?php else: ?>
                <dl class="print-dl">
                    <div><dt>Urgency</dt><dd><?= e((string) $currentFault['urgency']) ?></dd></div>
                    <div><dt>Noticed</dt><dd><?= e(format_date((string) $currentFault['faulty_on'])) ?></dd></div>
                    <div><dt>Reported by</dt><dd><?= e((string) $currentFault['reported_by_name']) ?></dd></div>
                    <div><dt>Reported on</dt><dd><?= e(format_datetime((string) $currentFault['created_at'])) ?></dd></div>
                    <div><dt>Photos on file</dt><dd><?= (int) $currentFault['photo_count'] ?></dd></div>
                </dl>
                <p class="print-note prewrap"><?= e((string) $currentFault['description']) ?></p>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <section class="print-section">
        <h2>Purchase and value</h2>
        <dl class="print-dl">
            <div><dt>Purchased</dt><dd><?= e($dash($asset['purchase_date'] ? format_date($asset['purchase_date']) : null)) ?></dd></div>
            <div><dt>Purchase cost</dt><dd><?= e($asset['purchase_cost'] !== null ? format_money($asset['purchase_cost']) : '—') ?></dd></div>
            <div><dt>Current value</dt><dd><?= e($asset['current_value'] !== null ? format_money($asset['current_value']) : '—') ?></dd></div>
            <div><dt>Supplier</dt><dd><?= e($dash($asset['supplier'] ?? null)) ?></dd></div>
            <div><dt>Warranty expires</dt><dd><?= e($dash($asset['warranty_expires_on'] ? format_date($asset['warranty_expires_on']) : null)) ?></dd></div>
            <div><dt>Available for hire</dt><dd><?= (int) $asset['is_hireable'] === 1 ? 'Yes' : 'No' ?></dd></div>
        </dl>
    </section>

    <section class="print-section">
        <h2>Portable appliance testing</h2>
        <?php if ((int) $asset['requires_pat'] !== 1 && $patRecords === []): ?>
            <p class="muted">This asset is not flagged as needing PAT.</p>
        <?php else: ?>
            <dl class="print-dl">
                <div><dt>Status</dt><dd><?= e($dash($patStatus['pat_status'] ?? 'Never tested')) ?></dd></div>
                <div><dt>Last tested</dt><dd><?= e($dash(!empty($patStatus['test_date']) ? format_date($patStatus['test_date']) : null)) ?></dd></div>
                <div><dt>Result</dt><dd><?= e($dash($patStatus['overall_result'] ?? null)) ?></dd></div>
                <div><dt>Retest due</dt><dd><?= e($dash(!empty($patStatus['retest_due_date']) ? format_date($patStatus['retest_due_date']) : null)) ?></dd></div>
                <div><dt>Appliance class</dt><dd><?= e($dash($patStatus['appliance_class'] ?? $asset['appliance_class'] ?? null)) ?></dd></div>
                <div><dt>Label serial</dt><dd class="mono"><?= e($dash($patStatus['pat_label_serial'] ?? null)) ?></dd></div>
                <div><dt>Plug fuse</dt><dd><?= e($dash($asset['plug_fuse_rating_amps'] !== null ? $asset['plug_fuse_rating_amps'] . ' A' : null)) ?></dd></div>
                <div><dt>Cable CSA</dt><dd><?= e($dash($asset['cable_csa_mm2'] !== null ? $asset['cable_csa_mm2'] . ' mm²' : null)) ?></dd></div>
            </dl>

            <?php if ($patRecords !== []): ?>
                <table class="print-table">
                    <thead><tr><th>Date</th><th>Result</th><th>Tester</th><th>Retest due</th></tr></thead>
                    <tbody>
                    <?php foreach ($patRecords as $record): ?>
                        <tr>
                            <td><?= e(format_date($record['test_date'])) ?></td>
                            <td><?= e($record['overall_result']) ?></td>
                            <td><?= e($dash($record['tester_user_name'] ?? $record['tester_name'] ?? null)) ?></td>
                            <td><?= e($dash($record['retest_due_date'] ? format_date($record['retest_due_date']) : null)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <section class="print-section">
        <h2>Maintenance</h2>
        <?php if ($schedules === [] && $maintenanceLogs === []): ?>
            <p class="muted">No maintenance scheduled and none recorded.</p>
        <?php else: ?>
            <?php if ($schedules !== []): ?>
                <h3>Scheduled</h3>
                <table class="print-table">
                    <thead><tr><th>Job</th><th>Frequency</th><th>Next due</th><th>Last done</th></tr></thead>
                    <tbody>
                    <?php foreach ($schedules as $schedule): ?>
                        <tr>
                            <td><?= e($schedule['title']) ?></td>
                            <td><?= e(MaintenanceSchedule::describeFrequency($schedule)) ?></td>
                            <td><?= e($dash($schedule['next_due_date'] ? format_date($schedule['next_due_date']) : null)) ?></td>
                            <td><?= e($dash($schedule['last_completed_date'] ? format_date($schedule['last_completed_date']) : null)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if ($maintenanceLogs !== []): ?>
                <h3>Recent work</h3>
                <table class="print-table">
                    <thead><tr><th>Date</th><th>Work done</th><th>By</th><th>Result</th></tr></thead>
                    <tbody>
                    <?php foreach ($maintenanceLogs as $log): ?>
                        <tr>
                            <td><?= e(format_date($log['performed_on'])) ?></td>
                            <td><?= e(str_limit((string) $log['work_done'], 120)) ?></td>
                            <td><?= e($dash($log['performed_by_user_name'] ?? $log['performed_by_name'] ?? null)) ?></td>
                            <td><?= e($log['result']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <section class="print-section">
        <h2>Hire</h2>
        <?php if ($openHire === null): ?>
            <p class="muted">Not currently out on hire.</p>
        <?php else: ?>
            <dl class="print-dl">
                <div><dt>Reference</dt><dd class="mono"><?= e($openHire['reference']) ?></dd></div>
                <div><dt>Out with</dt><dd><?= e($openHire['hirer_name']) ?></dd></div>
                <div><dt>Taken out</dt><dd><?= e(format_date($openHire['checked_out_at'] ?? null)) ?></dd></div>
                <div><dt>Due back</dt><dd><?= e(format_date($openHire['due_back_date'])) ?></dd></div>
                <div><dt>Status</dt><dd><?= e($openHire['effective_status']) ?></dd></div>
            </dl>
        <?php endif; ?>
    </section>

    <?php if ($children !== []): ?>
        <section class="print-section">
            <h2>Sub-assets (<?= count($children) ?>)</h2>
            <table class="print-table">
                <thead><tr><th>Tag</th><th>Name</th><th>Relationship</th><th>Status</th><th>Condition</th></tr></thead>
                <tbody>
                <?php foreach ($children as $child): ?>
                    <tr>
                        <td class="mono"><?= e($child['asset_tag']) ?></td>
                        <td><?= e($child['name']) ?></td>
                        <td><?= e($dash($child['relationship_type'] ?? null)) ?></td>
                        <td><?= e($child['status']) ?></td>
                        <td><?= e($child['condition_rating']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    <?php endif; ?>

    <?php if (!empty($asset['notes'])): ?>
        <section class="print-section">
            <h2>Notes</h2>
            <p class="print-note prewrap"><?= e($asset['notes']) ?></p>
        </section>
    <?php endif; ?>

    <footer class="print-foot muted">
        <?= e(config('app.product', 'Kitwell')) ?> — <?= e(config('app.product_tagline', 'Asset Management')) ?>
        · by <?= e(config('app.vendor', 'Junction Inc Ltd')) ?>
        · record last updated <?= e(format_datetime($asset['updated_at'])) ?>
    </footer>
</article>
