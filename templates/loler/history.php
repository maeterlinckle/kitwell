<?php

use App\Models\LolerExamination;

/**
 * One asset's LOLER examination history.
 *
 * @var array<string,mixed> $asset
 * @var array<int,array<string,mixed>> $examinations
 * @var array{state:string,due:?string,latest:?array<string,mixed>}|null $status
 */
$assetId = (int) $asset['id'];
?>
<div class="page-head">
    <div>
        <p class="eyebrow">
            <a href="<?= e(url('/assets/' . $assetId)) ?>"><span class="mono"><?= e($asset['asset_tag']) ?></span></a>
            <?= e(str_limit((string) $asset['name'], 60)) ?>
        </p>
        <h1>LOLER examination history</h1>
        <p class="badge-row">
            <?php if ($status !== null && $status['state'] === 'Overdue'): ?>
                <span class="badge badge-danger">Overdue since <?= e(format_date((string) $status['due'])) ?></span>
            <?php elseif ($status !== null && $status['due'] !== null): ?>
                <span class="badge badge-ok">Next by <?= e(format_date((string) $status['due'])) ?></span>
            <?php else: ?>
                <span class="badge badge-muted">Never examined</span>
            <?php endif; ?>
        </p>
    </div>
    <div class="head-actions">
        <?php if (can('loler.inspect') && (int) $asset['requires_loler'] === 1): ?>
            <a class="btn btn-primary" href="<?= e(url('/assets/' . $assetId . '/loler/examine')) ?>">New examination</a>
        <?php endif; ?>
        <a class="btn btn-ghost" href="<?= e(url('/loler')) ?>">All examinations</a>
    </div>
</div>

<div class="card">
    <?php if ($examinations === []): ?>
        <p class="empty muted">
            No thorough examination has been recorded against this item here.
        </p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Examined</th>
                        <th>Outcome</th>
                        <th>Examiner</th>
                        <th>Next by</th>
                        <th class="actions"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($examinations as $examination): ?>
                        <?php $danger = (int) $examination['danger_count'] > 0; ?>
                        <tr>
                            <td class="nowrap"><?= e(format_date((string) $examination['examined_on'])) ?></td>
                            <td>
                                <span class="badge <?= $danger ? 'badge-danger' : ((int) $examination['defect_count'] > 0 ? 'badge-warn' : 'badge-ok') ?>">
                                    <?= e(LolerExamination::verdict($examination)) ?>
                                </span>
                                <?php if ((int) $examination['serious_count'] > 0): ?>
                                    <div class="cell-sub">Serious personal injury risk reported</div>
                                <?php endif; ?>
                            </td>
                            <td><?= e($examination['examiner_name']) ?></td>
                            <td class="nowrap"><?= e(format_date((string) $examination['next_examination_date'])) ?></td>
                            <td class="actions">
                                <a class="btn btn-sm" href="<?= e(url('/loler/' . (int) $examination['id'])) ?>">View</a>
                                <a class="btn btn-sm" href="<?= e(url('/loler/' . (int) $examination['id'] . '/pdf')) ?>">PDF</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
