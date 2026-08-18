<?php

use App\Core\Upload;
use App\Models\MaintenanceRoutine;
use App\Models\RoutineCompletion;

/**
 * One page of a batched run: its steps, and only its steps.
 *
 * The controls are the same partial the wizard, the preview and the single-step
 * view use, so what is answered here is literally the field the routine's
 * author configured.
 *
 * @var array<string,mixed> $completion
 * @var array<string,mixed> $page
 * @var array<int,array<string,mixed>> $steps
 * @var string $position
 * @var array<int,array<string,mixed>> $responses keyed by step id
 * @var array<int,array<int,array<string,mixed>>> $files keyed by step id
 * @var array{name:?string,at:string}|null $done
 * @var array<string,string> $errors
 * @var array<string,mixed>  $old
 */
$id     = (int) $completion['id'];
$pageId = (int) $page['id'];

/**
 * What is already recorded, in the shape the field partial expects.
 *
 * A rejected submission takes precedence, so a page refused for one blank
 * answer is corrected rather than retyped.
 */
$answers = is_array($old['step'] ?? null) ? $old['step'] : [];

if ($answers === []) {
    foreach ($steps as $step) {
        $stepId   = (int) $step['id'];
        $response = $responses[$stepId] ?? null;

        if ($response === null) {
            continue;
        }

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
}
?>
<div class="page-head">
    <div>
        <p class="eyebrow">
            <a href="<?= e(url('/maintenance/completions/' . $id)) ?>"><?= e($completion['routine_name']) ?></a>
            &middot; <span class="mono"><?= e($completion['asset_tag']) ?></span>
        </p>
        <h1><?= e($page['title']) ?></h1>
        <p class="badge-row">
            <span class="badge badge-muted"><?= e($position) ?></span>
            <span class="badge badge-muted">
                <?= count($steps) ?> step<?= count($steps) === 1 ? '' : 's' ?>
            </span>
            <?php if ((int) $page['required_for_signoff'] === 1): ?>
                <span class="badge badge-warn">Required for sign-off</span>
            <?php endif; ?>
        </p>
    </div>
    <a class="btn btn-ghost" href="<?= e(url('/maintenance/completions/' . $id)) ?>">Back to the run</a>
</div>

<?php if ($done !== null && $done['name'] !== null): ?>
    <div class="flash flash-info">
        <span class="flash-text">
            This page was completed by <?= e($done['name']) ?>
            on <?= e(format_datetime($done['at'])) ?>.
            Submitting it again records your name against it instead.
        </span>
    </div>
<?php endif; ?>

<?php if ($errors !== []): ?>
    <div class="flash flash-error">
        <span class="flash-text">
            <strong>Nothing was saved.</strong> Every required step on this page has to be answered
            before the page can be recorded &mdash; the ones outstanding are marked below.
        </span>
    </div>
<?php endif; ?>

<form method="post" action="<?= e(url('/maintenance/completions/' . $id . '/pages/' . $pageId)) ?>"
      enctype="multipart/form-data" class="form" novalidate>
    <?= csrf_field() ?>

    <section class="card routine-run-page">
        <?php if (!empty($page['description'])): ?>
            <p class="muted"><?= e($page['description']) ?></p>
        <?php endif; ?>

        <?php if ($steps === []): ?>
            <p class="muted">This page has no steps.</p>
        <?php endif; ?>

        <?php foreach ($steps as $step): ?>
            <?= partial('partials/routine-field', [
                'step'     => $step,
                'disabled' => false,
                'answers'  => $answers,
                'errors'   => $errors,
            ]) ?>

            <?php $stepFiles = $files[(int) $step['id']] ?? []; ?>
            <?php if ($stepFiles !== [] && in_array((string) $step['field_type'], MaintenanceRoutine::FILE_TYPES, true)): ?>
                <ul class="file-list file-list-compact">
                    <?php foreach ($stepFiles as $file): ?>
                        <li class="file-item">
                            <span class="file-icon" aria-hidden="true"><?= $file['file_kind'] === 'photo' ? 'IMG' : 'PDF' ?></span>
                            <span class="file-body">
                                <a class="file-title" target="_blank" rel="noopener"
                                   href="<?= e(url('/maintenance/completions/' . $id . '/files/' . (int) $file['id'])) ?>">
                                    <?= e(Upload::displayName((string) ($file['original_filename'] ?: 'attachment'))) ?>
                                </a>
                                <span class="file-meta muted">
                                    <?= e(Upload::formatBytes((int) $file['file_size_bytes'])) ?>
                                    &middot; already attached; choosing again replaces it
                                </span>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        <?php endforeach; ?>
    </section>

    <div class="form-actions sticky-actions">
        <button type="submit" class="btn btn-primary btn-lg">
            <?= $done === null ? 'Record this page' : 'Save this page again' ?>
        </button>
        <a class="btn btn-ghost" href="<?= e(url('/maintenance/completions/' . $id)) ?>">Cancel</a>
    </div>
</form>
