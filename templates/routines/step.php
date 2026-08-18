<?php

use App\Core\Upload;
use App\Models\MaintenanceRoutine;

/**
 * One step of an open run, on its own.
 *
 * The control is the same partial the wizard and the preview use, so what is
 * answered here is literally the field the routine's author configured.
 *
 * @var array<string,mixed> $completion
 * @var array<string,mixed> $step
 * @var string $position
 * @var array<string,mixed>|null $response
 * @var array<int,array<string,mixed>> $stepFiles
 * @var array{name:?string,at:?string}|null $attribution
 * @var array<string,string> $errors
 * @var array<string,mixed>  $old
 */
$id     = (int) $completion['id'];
$stepId = (int) $step['id'];
$isFile = in_array((string) $step['field_type'], MaintenanceRoutine::FILE_TYPES, true);

/**
 * What is already recorded, in the shape the field partial expects.
 *
 * A rejected submission takes precedence, so a mistyped number is corrected
 * rather than retyped.
 */
$answers = [];

if (is_array($old['step'] ?? null)) {
    $answers = $old['step'];
} elseif ($response !== null) {
    $answers[$stepId] = match ((string) $step['field_type']) {
        'boolean'      => $response['value_boolean'] === null ? '' : (string) (int) $response['value_boolean'],
        'number'       => $response['value_number'] === null
            ? ''
            : rtrim(rtrim(number_format((float) $response['value_number'], 4, '.', ''), '0'), '.'),
        'date'         => (string) ($response['value_date'] ?? ''),
        'multi_choice' => array_values(array_filter(array_map('trim', preg_split('/\R/', (string) ($response['value_text'] ?? '')) ?: []))),
        default        => (string) ($response['value_text'] ?? ''),
    };
}
?>
<div class="page-head">
    <div>
        <p class="eyebrow">
            <a href="<?= e(url('/maintenance/completions/' . $id)) ?>"><?= e($completion['routine_name']) ?></a>
            &middot; <span class="mono"><?= e($completion['asset_tag']) ?></span>
        </p>
        <h1><?= e($step['label']) ?></h1>
        <p class="badge-row">
            <span class="badge badge-muted"><?= e($position) ?></span>
            <span class="badge badge-muted"><?= e(MaintenanceRoutine::typeLabel((string) $step['field_type'])) ?></span>
            <?php if ((int) $step['is_required'] === 1): ?>
                <span class="badge badge-warn">Required</span>
            <?php endif; ?>
        </p>
    </div>
    <a class="btn btn-ghost" href="<?= e(url('/maintenance/completions/' . $id)) ?>">Back to the run</a>
</div>

<?php if ($attribution !== null && $attribution['name'] !== null): ?>
    <div class="flash flash-info">
        <span class="flash-text">
            Answered by <?= e($attribution['name']) ?><?php
                if ($attribution['at'] !== null): ?> on <?= e(format_datetime((string) $attribution['at'])) ?><?php endif; ?>.
            Changing it now records your name against it instead.
        </span>
    </div>
<?php endif; ?>

<form method="post" action="<?= e(url('/maintenance/completions/' . $id . '/steps/' . $stepId)) ?>"
      enctype="multipart/form-data" class="form" novalidate>
    <?= csrf_field() ?>

    <div class="card">
        <?= partial('partials/routine-field', [
            'step'     => $step,
            'disabled' => false,
            'answers'  => $answers,
            'errors'   => $errors,
        ]) ?>

        <?php if ($isFile && $stepFiles !== []): ?>
            <h3 class="group-title">Already attached</h3>
            <ul class="file-list file-list-compact">
                <?php foreach ($stepFiles as $file): ?>
                    <li class="file-item">
                        <span class="file-icon" aria-hidden="true"><?= $file['file_kind'] === 'photo' ? 'IMG' : 'PDF' ?></span>
                        <span class="file-body">
                            <a class="file-title" target="_blank" rel="noopener"
                               href="<?= e(url('/maintenance/completions/' . $id . '/files/' . (int) $file['id'])) ?>">
                                <?= e(Upload::displayName((string) ($file['original_filename'] ?: 'attachment'))) ?>
                            </a>
                            <span class="file-meta muted"><?= e(Upload::formatBytes((int) $file['file_size_bytes'])) ?></span>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="field-hint">Attaching again replaces what is here.</p>
        <?php endif; ?>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary btn-lg">Save this step</button>
        <a class="btn btn-ghost" href="<?= e(url('/maintenance/completions/' . $id)) ?>">Cancel</a>
    </div>
</form>
