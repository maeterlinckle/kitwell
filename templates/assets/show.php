<?php

use App\Core\Barcode;
use App\Core\Upload;

/**
 * Asset detail page.
 *
 * @var array<string,mixed> $asset
 * @var array<int,array<string,mixed>> $children
 * @var array<int,array<string,mixed>> $manuals
 * @var array<int,array<string,mixed>> $photos
 * @var int $photoCount
 * @var array<int,array<string,mixed>> $schedules
 * @var array<int,array<string,mixed>> $maintenanceLogs
 * @var array<int,array<string,mixed>> $patRecords
 * @var array<string,mixed>|null $patStatus
 * @var array<string,mixed>|null $openHire
 * @var string|null $hireBlocked
 * @var array<int,array<string,mixed>> $hireHistory
 * @var array<int,array<string,mixed>> $history
 */
$id       = (int) $asset['id'];
$retired  = $asset['status'] === 'Retired';
$location = trim((string) ($asset['location_parent_name'] ?? '') . ' → ' . (string) ($asset['location_name'] ?? ''), ' →');
?>
<div class="page-head">
    <div>
        <p class="eyebrow mono"><?= e($asset['asset_tag']) ?></p>
        <h1><?= e($asset['name']) ?></h1>
        <p class="badge-row">
            <span class="badge status-<?= e(strtolower(str_replace(' ', '-', (string) $asset['status']))) ?>"><?= e($asset['status']) ?></span>
            <span class="badge condition-<?= e(strtolower(str_replace(' ', '-', (string) $asset['condition_rating']))) ?>"><?= e($asset['condition_rating']) ?></span>
            <?php if ((int) $asset['requires_pat'] === 1): ?>
                <?php /* Colour by the actual test state, the same way the PAT
                         list does. Yellow is for "do something soon"; an asset
                         whose test is in date has nothing needing attention. */ ?>
                <?php $patState = (string) ($patStatus['pat_status'] ?? 'Never tested'); ?>
                <span class="badge pat-status-<?= e(strtolower(str_replace(' ', '-', $patState))) ?>">Requires PAT</span>
            <?php endif; ?>
            <?php if ((int) $asset['is_hireable'] === 0): ?>
                <span class="badge badge-muted">Not hireable</span>
            <?php endif; ?>
            <?php if ($asset['parent_asset_id'] !== null): ?>
                <span class="badge badge-role"><?= e($asset['relationship_type'] ?? 'sub-asset') ?> of
                    <a href="<?= e(url('/assets/' . $asset['parent_asset_id'])) ?>"><?= e($asset['parent_tag']) ?></a>
                </span>
            <?php endif; ?>
        </p>
    </div>

    <div class="head-actions">
        <?php if ($openHire !== null && can('hires.return')): ?>
            <a class="btn btn-primary" href="<?= e(url('/hires/' . $openHire['id'] . '/return')) ?>">Book in</a>
        <?php elseif ($openHire === null && can('hires.create') && $hireBlocked === null): ?>
            <a class="btn btn-primary" href="<?= e(url('/hires/checkout?asset=' . $id)) ?>">Check out</a>
        <?php endif; ?>
        <?php if (can('assets.edit')): ?>
            <a class="btn" href="<?= e(url('/assets/' . $id . '/edit')) ?>">Edit</a>
        <?php endif; ?>
        <a class="btn" href="<?= e(url('/assets/' . $id . '/print')) ?>">Print</a>
        <a class="btn" href="<?= e(url('/assets/' . $id . '/label')) ?>">Print label</a>
        <?php if (can('assets.create')): ?>
            <a class="btn" href="<?= e(url('/assets/' . $id . '/copy')) ?>">Copy</a>
        <?php endif; ?>
        <?php if (can('assets.edit')): ?>
            <a class="btn" href="<?= e(url('/assets/' . $id . '/apply')) ?>">Copy details to…</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($openHire !== null && can('hires.view')): ?>
    <div class="hire-banner <?= $openHire['effective_status'] === 'Overdue' ? 'is-overdue' : '' ?>">
        <div class="hire-banner-main">
            <span class="hire-banner-label">On hire</span>
            <span class="hire-banner-headline">
                With <a href="<?= e(url('/hirers/' . $openHire['hirer_id'])) ?>"><?= e($openHire['hirer_name']) ?></a>
            </span>
            <span class="hire-banner-detail">
                Out since <?= e(format_date($openHire['checked_out_at'])) ?> ·
                due back <?= e(format_date($openHire['due_back_date'])) ?>
                <?php $d = (int) $openHire['days_until_due']; ?>
                <?php if ($d < 0): ?>
                    (<?= abs($d) ?> day<?= abs($d) === 1 ? '' : 's' ?> overdue)
                <?php elseif ($d === 0): ?>
                    (today)
                <?php else: ?>
                    (in <?= (int) $d ?> day<?= $d === 1 ? '' : 's' ?>)
                <?php endif; ?>
            </span>
        </div>
        <div class="hire-banner-actions">
            <a class="btn btn-sm" href="<?= e(url('/hires/' . $openHire['id'])) ?>">Hire details</a>
            <?php if (can('hires.return')): ?>
                <a class="btn btn-sm btn-primary" href="<?= e(url('/hires/' . $openHire['id'] . '/return')) ?>">Book in</a>
            <?php endif; ?>
        </div>
    </div>
<?php elseif ($hireBlocked !== null && can('hires.create') && !$retired): ?>
    <div class="flash flash-info"><span class="flash-text"><?= e($hireBlocked) ?></span></div>
<?php endif; ?>

<?php if (can('pat.view') && ((int) $asset['requires_pat'] === 1 || (int) ($patStatus['test_count'] ?? 0) > 0)): ?>
    <?php /* Only when it needs acting on. This page already has a PAT card in
             the sidebar and the badge in the heading, so a green "in date"
             banner across the top says nothing the page has not said twice. */ ?>
    <?= partial('partials/pat-status', ['asset' => $asset, 'status' => $patStatus, 'hideIfCurrent' => true]) ?>
<?php endif; ?>

<?php if ($retired): ?>
    <div class="flash flash-warning">
        <span class="flash-text">
            This asset was archived<?= $asset['retired_on'] ? ' on ' . e(format_date($asset['retired_on'])) : '' ?>.
            Its records are kept for audit purposes.
        </span>
    </div>
<?php endif; ?>

<div class="detail-grid">
    <div class="detail-main">
        <?php if (!empty($asset['description'])): ?>
            <div class="card">
                <h2>Description</h2>
                <p class="prewrap"><?= e($asset['description']) ?></p>
            </div>
        <?php endif; ?>

        <div class="card">
            <h2>Details</h2>
            <dl class="detail-list">
                <div><dt>Asset tag</dt><dd class="mono"><?= e($asset['asset_tag']) ?></dd></div>
                <?php if (!empty($asset['barcode'])): ?>
                    <div><dt>Other barcode</dt><dd class="mono"><?= e($asset['barcode']) ?></dd></div>
                <?php endif; ?>
                <div><dt>Category</dt><dd><?= e($asset['category_name'] ?? '—') ?></dd></div>
                <div><dt>Location</dt><dd><?= e($location !== '' ? $location : '—') ?></dd></div>
                <div><dt>Serial number</dt><dd class="mono"><?= e($asset['serial_number'] ?? '—') ?></dd></div>
                <div><dt>Manufacturer</dt><dd><?= e($asset['manufacturer'] ?? '—') ?></dd></div>
                <div><dt>Model</dt><dd><?= e($asset['model'] ?? '—') ?></dd></div>
                <div>
                    <dt>Manufacturer website</dt>
                    <dd>
                        <?php if (!empty($asset['manufacturer_url'])): ?>
                            <a href="<?= e($asset['manufacturer_url']) ?>" target="_blank" rel="noopener noreferrer nofollow">
                                <?= e(str_limit(preg_replace('#^https?://(www\.)?#', '', (string) $asset['manufacturer_url']) ?? '', 48)) ?>
                                <span class="external-hint" aria-hidden="true">↗</span>
                                <span class="sr-only">(opens in a new tab)</span>
                            </a>
                        <?php else: ?>—<?php endif; ?>
                    </dd>
                </div>
                <div><dt>Supplier</dt><dd><?= e($asset['supplier'] ?? '—') ?></dd></div>
                <div><dt>Purchase date</dt><dd><?= e(format_date($asset['purchase_date'])) ?></dd></div>
                <div><dt>Purchase cost</dt><dd><?= e(format_money($asset['purchase_cost'])) ?></dd></div>
                <div><dt>Current value</dt><dd><?= e(format_money($asset['current_value'])) ?></dd></div>
                <div><dt>Warranty expires</dt><dd><?= e(format_date($asset['warranty_expires_on'])) ?></dd></div>
            </dl>
        </div>

        <div class="card" id="pat">
            <div class="card-head">
                <h2>Electrical &amp; PAT</h2>
                <?php if (can('pat.view') && $patRecords !== []): ?>
                    <a class="btn btn-sm" href="<?= e(url('/assets/' . $id . '/pat')) ?>">
                        Full history (<?= (int) ($patStatus['test_count'] ?? 0) ?>)
                    </a>
                <?php endif; ?>
            </div>
            <dl class="detail-list">
                <div>
                    <dt>Requires PAT</dt>
                    <dd><?= (int) $asset['requires_pat'] === 1 ? 'Yes' : 'No' ?></dd>
                </div>
                <?php if ((int) $asset['requires_pat'] === 1): ?>
                    <div>
                        <dt>Retest interval</dt>
                        <dd><?= $asset['pat_interval_months'] !== null ? (int) $asset['pat_interval_months'] . ' months' : 'Site default' ?></dd>
                    </div>
                <?php endif; ?>
                <div>
                    <dt>Appliance class</dt>
                    <dd>
                        <?php if ($asset['appliance_class'] !== null): ?>
                            <?= e($asset['appliance_class']) ?>
                        <?php else: ?>
                            <span class="badge badge-warn">Not established</span>
                        <?php endif; ?>
                    </dd>
                </div>
                <div>
                    <dt>Load rating</dt>
                    <dd><?= $asset['load_rating_va'] !== null ? e(rtrim(rtrim(number_format((float) $asset['load_rating_va'], 2), '0'), '.')) . ' VA' : '—' ?></dd>
                </div>
                <div>
                    <dt>Fuse</dt>
                    <dd>
                        <?php if ((int) $asset['has_fuse'] === 1): ?>
                            <?= $asset['plug_fuse_rating_amps'] !== null
                                ? e(rtrim(rtrim(number_format((float) $asset['plug_fuse_rating_amps'], 2), '0'), '.')) . ' A'
                                : 'Fitted, rating not recorded' ?>
                        <?php else: ?>
                            None
                        <?php endif; ?>
                    </dd>
                </div>
                <div>
                    <dt>Cable CSA</dt>
                    <dd><?= $asset['cable_csa_mm2'] !== null ? e(rtrim(rtrim(number_format((float) $asset['cable_csa_mm2'], 2), '0'), '.')) . ' mm²' : '—' ?></dd>
                </div>
            </dl>

            <?php if (can('pat.view') && $patRecords !== []): ?>
                <h3 class="group-title">Recent PAT tests</h3>
                <ul class="pat-history pat-history-compact">
                    <?php foreach ($patRecords as $record): ?>
                        <?= partial('partials/pat-record', ['record' => $record, 'showActions' => false]) ?>
                    <?php endforeach; ?>
                </ul>
                <?php if ((int) ($patStatus['test_count'] ?? 0) > count($patRecords)): ?>
                    <p><a href="<?= e(url('/assets/' . $id . '/pat')) ?>">All <?= (int) $patStatus['test_count'] ?> PAT tests for this asset</a></p>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Sub-assets, accessories and related items -->
        <div class="card" id="children">
            <div class="card-head">
                <h2>Sub-assets &amp; accessories <span class="count-pill"><?= count($children) ?></span></h2>
                <?php if (can('assets.create') && $asset['parent_asset_id'] === null): ?>
                    <a class="btn btn-sm" href="<?= e(url('/assets/create?parent=' . $id)) ?>">Add item</a>
                <?php endif; ?>
            </div>

            <?php if ($children === []): ?>
                <p class="muted">
                    <?= $asset['parent_asset_id'] !== null
                        ? 'This is itself an attached item, so it cannot have its own sub-assets.'
                        : 'Nothing attached yet. Batteries, chargers, cases and spare parts can be registered against this asset while staying individually searchable.' ?>
                </p>
            <?php else: ?>
                <ul class="child-list">
                    <?php foreach ($children as $child): ?>
                        <li class="child-item <?= $child['status'] === 'Retired' ? 'is-retired' : '' ?>">
                            <a class="child-link" href="<?= e(url('/assets/' . $child['id'])) ?>">
                                <span class="mono asset-tag"><?= e($child['asset_tag']) ?></span>
                                <span class="child-name"><?= e($child['name']) ?></span>
                            </a>
                            <span class="child-meta">
                                <span class="badge badge-muted"><?= e($child['relationship_type'] ?? 'sub-asset') ?></span>
                                <span class="badge status-<?= e(strtolower(str_replace(' ', '-', (string) $child['status']))) ?>"><?= e($child['status']) ?></span>
                                <?php if ((int) $child['requires_pat'] === 1): ?>
                                    <span class="badge badge-warn">PAT</span>
                                <?php endif; ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <!-- Maintenance -->
        <?php if (can('maintenance.view')): ?>
            <div class="card" id="maintenance">
                <div class="card-head">
                    <h2>Maintenance <span class="count-pill"><?= count($schedules) ?></span></h2>
                    <div class="head-actions">
                        <?php if (can('maintenance.complete')): ?>
                            <a class="btn btn-sm" href="<?= e(url('/assets/' . $id . '/maintenance/log')) ?>">Record work</a>
                        <?php endif; ?>
                        <?php if (can('maintenance.manage')): ?>
                            <a class="btn btn-sm" href="<?= e(url('/maintenance/create?asset=' . $id)) ?>">Add schedule</a>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($schedules === []): ?>
                    <p class="muted">No maintenance scheduled for this asset.</p>
                <?php else: ?>
                    <ul class="schedule-list">
                        <?php foreach ($schedules as $schedule): ?>
                            <li class="schedule-item <?= (int) $schedule['is_active'] === 1 ? '' : 'is-closed' ?>">
                                <div class="schedule-main">
                                    <a href="<?= e(url('/maintenance/' . $schedule['id'])) ?>"><strong><?= e($schedule['title']) ?></strong></a>
                                    <span class="cell-sub muted">
                                        <?= e(\App\Models\MaintenanceSchedule::describeFrequency($schedule)) ?>
                                        <?php if (!empty($schedule['assigned_to_name'])): ?>
                                            · <?= partial('partials/assignee', ['schedule' => $schedule]) ?>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <div class="schedule-meta">
                                    <span class="badge due-<?= e(strtolower(str_replace(' ', '-', (string) $schedule['due_status']))) ?>">
                                        <?= e($schedule['due_status']) ?>
                                    </span>
                                    <span class="muted nowrap"><?= e(format_date($schedule['next_due_date'])) ?></span>
                                    <?php if (can('maintenance.complete') && (int) $schedule['is_active'] === 1): ?>
                                        <a class="btn btn-sm btn-primary" href="<?= e(url('/maintenance/' . $schedule['id'] . '/complete')) ?>">Complete</a>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <?php if ($maintenanceLogs !== []): ?>
                    <h3 class="group-title">Recent work</h3>
                    <ul class="log-list log-list-compact">
                        <?php foreach ($maintenanceLogs as $log): ?>
                            <li class="log-item">
                                <div class="log-head">
                                    <span class="log-date"><?= e(format_date($log['performed_on'])) ?></span>
                                    <span class="badge result-<?= e(strtolower((string) $log['result'])) ?>"><?= e($log['result']) ?></span>
                                    <span class="badge badge-muted"><?= e($log['maintenance_type']) ?></span>
                                </div>
                                <p class="log-work"><?= e(str_limit((string) $log['work_done'], 160)) ?></p>
                                <p class="cell-sub muted">
                                    <?= e($log['performed_by_user_name'] ?? $log['performed_by_name'] ?? 'Not recorded') ?>
                                    <?php if ($log['cost'] !== null): ?> · <?= e(format_money($log['cost'])) ?><?php endif; ?>
                                    <?php if (can('maintenance.manage')): ?>
                                        · <a href="<?= e(url('/maintenance/logs/' . $log['id'] . '/edit')) ?>">Edit</a>
                                    <?php endif; ?>
                                </p>
                                <?php if ((int) $log['photo_count'] > 0): ?>
                                    <?= partial('partials/maintenance-log-evidence', ['log' => $log]) ?>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <p><a href="<?= e(url('/maintenance/history?q=' . rawurlencode((string) $asset['asset_tag']))) ?>">All maintenance history for this asset</a></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Condition photos -->
        <div class="card" id="photos">
            <div class="card-head">
                <h2>Condition photos <span class="count-pill"><?= (int) $photoCount ?></span></h2>
                <?php if ($photoCount > 0): ?>
                    <a class="btn btn-sm" href="<?= e(url('/assets/' . $id . '/photos')) ?>">
                        Full history<?= $photoCount > count($photos) ? ' (' . (int) $photoCount . ')' : '' ?>
                    </a>
                <?php endif; ?>
            </div>

            <?php if ($photos === []): ?>
                <p class="muted">
                    No photos yet. Adding them over time builds a dated record of this item's
                    condition — worth doing before and after a hire.
                </p>
            <?php else: ?>
                <?= partial('partials/photo-gallery', [
                    'asset'       => $asset,
                    'photos'      => $photos,
                    'showActions' => true,
                ]) ?>
            <?php endif; ?>

            <?php if (can('media.photo.upload')): ?>
                <?= partial('partials/photo-upload', ['asset' => $asset]) ?>
            <?php endif; ?>
        </div>

        <!-- Manuals -->
        <div class="card" id="manuals">
            <div class="card-head">
                <h2>Manuals &amp; documents <span class="count-pill"><?= count($manuals) ?></span></h2>
            </div>

            <?php if ($manuals === []): ?>
                <p class="muted">No documents attached yet.</p>
            <?php else: ?>
                <ul class="file-list">
                    <?php foreach ($manuals as $manual): ?>
                        <li class="file-item">
                            <span class="file-icon" aria-hidden="true">PDF</span>
                            <span class="file-body">
                                <a class="file-title" href="<?= e(url('/assets/' . $id . '/manuals/' . $manual['id'])) ?>" target="_blank" rel="noopener">
                                    <?= e($manual['title']) ?>
                                </a>
                                <span class="file-meta muted">
                                    <?= e(Upload::formatBytes((int) $manual['file_size_bytes'])) ?>
                                    · uploaded <?= e(format_date($manual['created_at'])) ?>
                                    <?php if (!empty($manual['uploaded_by_name'])): ?>by <?= e($manual['uploaded_by_name']) ?><?php endif; ?>
                                </span>
                                <?php if (!empty($manual['notes'])): ?>
                                    <span class="file-meta muted"><?= e($manual['notes']) ?></span>
                                <?php endif; ?>
                            </span>
                            <span class="file-actions">
                                <a class="btn btn-sm" href="<?= e(url('/assets/' . $id . '/manuals/' . $manual['id'] . '?download=1')) ?>">Download</a>
                                <?php if (can('media.manual.delete')): ?>
                                    <form method="post" action="<?= e(url('/assets/' . $id . '/manuals/' . $manual['id'] . '/delete')) ?>" class="inline-form">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="btn btn-sm btn-ghost" data-confirm="Remove “<?= e($manual['title']) ?>”? The file is deleted from the server.">Remove</button>
                                    </form>
                                <?php endif; ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if (can('media.manual.upload')): ?>
                <form method="post" action="<?= e(url('/assets/' . $id . '/manuals')) ?>" enctype="multipart/form-data" class="upload-form">
                    <?= csrf_field() ?>
                    <div class="field-row">
                        <div class="field">
                            <label class="label" for="title">Title <span class="optional">(optional)</span></label>
                            <input class="input" type="text" id="title" name="title" maxlength="191" placeholder="e.g. User Manual">
                        </div>
                        <div class="field">
                            <label class="label" for="manuals">PDF file(s)</label>
                            <input class="input" type="file" id="manuals" name="manuals[]" accept="application/pdf,.pdf" multiple required>
                            <p class="field-hint">PDF only, up to <?= (int) (config('uploads.max_pdf_bytes') / 1048576) ?> MB each.</p>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">Upload</button>
                </form>
            <?php endif; ?>
        </div>

        <?php if (can('hires.view') && $hireHistory !== []): ?>
            <div class="card" id="hires">
                <div class="card-head">
                    <h2>Hire history <span class="count-pill"><?= count($hireHistory) ?></span></h2>
                </div>
                <div class="table-wrap">
                    <table class="table table-compact">
                        <thead>
                        <tr>
                            <th scope="col">Hirer</th>
                            <th scope="col">Out</th>
                            <th scope="col">Due</th>
                            <th scope="col">Returned</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($hireHistory as $entry): ?>
                            <tr>
                                <td>
                                    <a href="<?= e(url('/hires/' . $entry['id'])) ?>"><?= e($entry['hirer_name']) ?></a>
                                    <div class="cell-sub">
                                        <span class="badge hire-<?= e(strtolower((string) $entry['effective_status'])) ?>">
                                            <?= e($entry['effective_status']) ?>
                                        </span>
                                    </div>
                                </td>
                                <td class="nowrap"><?= e(format_date($entry['checked_out_at'])) ?></td>
                                <td class="nowrap"><?= e(format_date($entry['due_back_date'])) ?></td>
                                <td class="nowrap"><?= e(format_date($entry['returned_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($asset['notes'])): ?>
            <div class="card">
                <h2>Notes</h2>
                <p class="prewrap"><?= e($asset['notes']) ?></p>
            </div>
        <?php endif; ?>

        <?php if ($history !== []): ?>
            <div class="card">
                <h2>History</h2>
                <ul class="timeline">
                    <?php foreach ($history as $entry): ?>
                        <li>
                            <span class="timeline-when muted"><?= e(format_datetime($entry['created_at'])) ?></span>
                            <span class="timeline-what"><?= e($entry['description']) ?></span>
                            <span class="timeline-who muted"><?= e($entry['user_name']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>

    <aside class="detail-side detail-side-flow">
        <div class="card barcode-card">
            <?php if (Barcode::isEncodable((string) $asset['asset_tag'])): ?>
                <div class="barcode-holder"><?= Barcode::svg((string) $asset['asset_tag'], 0.4, 16.0) ?></div>
            <?php endif; ?>
            <a class="btn btn-block" href="<?= e(url('/assets/' . $id . '/label')) ?>">Print label</a>
        </div>

        <div class="card">
            <h2>Record</h2>
            <?php /* Stacked, not side by side: "Added" and "Last updated" carry a
                     date, a time and a person's name, which is far too much to
                     squeeze into the right-hand half of a 320px rail. */ ?>
            <dl class="detail-list detail-list-stacked">
                <div><dt>Added</dt><dd><?= e(format_date($asset['created_at'])) ?><?= !empty($asset['created_by_name']) ? ' by ' . e($asset['created_by_name']) : '' ?></dd></div>
                <div><dt>Last updated</dt><dd><?= e(format_datetime($asset['updated_at'])) ?><?= !empty($asset['updated_by_name']) ? ' by ' . e($asset['updated_by_name']) : '' ?></dd></div>
            </dl>
        </div>

        <?php if (can('assets.edit') || can('assets.delete')): ?>
            <div class="card danger-card">
                <h2>Manage</h2>

                <?php if ($retired): ?>
                    <?php if (can('assets.edit')): ?>
                        <form method="post" action="<?= e(url('/assets/' . $id . '/restore')) ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-block">Restore to stock</button>
                        </form>
                    <?php endif; ?>
                <?php elseif (can('assets.edit')): ?>
                    <p class="muted">Archiving keeps every record — history, PAT results and hires — but takes the asset out of day-to-day lists.</p>
                    <form method="post" action="<?= e(url('/assets/' . $id . '/archive')) ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-warning btn-block"
                                data-confirm="Archive <?= e($asset['asset_tag']) ?>?<?= count($children) > 0 ? ' Its ' . count($children) . ' attached item(s) will be archived too.' : '' ?>">
                            Archive asset
                        </button>
                    </form>
                <?php endif; ?>

                <?php if (can('assets.delete')): ?>
                    <form method="post" action="<?= e(url('/assets/' . $id . '/delete')) ?>" class="delete-form">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-danger btn-block"
                                data-confirm="Permanently delete <?= e($asset['asset_tag']) ?> and its files? This cannot be undone.">
                            Delete permanently
                        </button>
                        <p class="field-hint">Only possible while the asset has no hire, PAT or maintenance history.</p>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </aside>
</div>
