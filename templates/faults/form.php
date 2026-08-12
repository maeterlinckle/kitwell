<?php

use App\Models\Asset;
use App\Models\FaultReport;

/**
 * Report a fault on one asset.
 *
 * @var array<string,mixed>      $asset
 * @var array<string,mixed>|null $previous   The last fault on this asset, if any
 * @var int                      $faultCount
 * @var array<string,string>     $errors
 * @var array<string,mixed>      $old
 */
$id     = (int) $asset['id'];
$action = url('/assets/' . $id . '/faults');

$value = static fn (string $field, string $default = ''): string =>
    array_key_exists($field, $old) ? (string) $old[$field] : $default;

$location = trim(
    (string) ($asset['location_parent_name'] ?? '') . ' → ' . (string) ($asset['location_name'] ?? ''),
    ' →'
);
?>
<div class="page-head">
    <div>
        <p class="eyebrow mono"><?= e($asset['asset_tag']) ?></p>
        <h1>Report a fault</h1>
        <p class="muted"><?= e($asset['name']) ?><?= $location !== '' ? ' — ' . e($location) : '' ?></p>
    </div>
    <a class="btn btn-ghost" href="<?= e(url('/assets/' . $id)) ?>">Cancel</a>
</div>

<?php if ($asset['status'] === 'Faulty' && $previous !== null): ?>
    <?php /* Already faulty. Not an error — a second thing can go wrong with a
             machine that is already broken — but worth showing what is on
             record, so the same fault is not filed twice by two people. */ ?>
    <div class="flash flash-warning">
        <span class="flash-text">
            This asset is already marked faulty. Reported
            <?= e(format_date((string) $previous['faulty_on'])) ?>
            by <?= e((string) $previous['reported_by_name']) ?>:
            “<?= e(str_limit((string) $previous['description'], 160)) ?>”
            <a href="<?= e(url('/assets/' . $id . '/faults')) ?>">See the history</a>.
        </span>
    </div>
<?php endif; ?>

<?php /* data-photo-form and data-max-bytes are what the photo-input handler in
         app.js looks for: preview thumbnails, per-file size checked against the
         server's own limit, and clearing whichever of the two inputs was not
         used. The same control the asset condition photos and the maintenance
         evidence use — see partials/photo-inputs. */ ?>
<form method="post" action="<?= e($action) ?>" enctype="multipart/form-data" class="form form-wide" novalidate
      data-photo-form data-max-bytes="<?= (int) config('uploads.max_photo_bytes') ?>">
    <?= csrf_field() ?>

    <div class="card">
        <h2>What is wrong</h2>

        <div class="field">
            <label class="label" for="description">Fault description</label>
            <textarea class="input<?= isset($errors['description']) ? ' has-error' : '' ?>" id="description"
                      name="description" rows="4" maxlength="5000" required
                      placeholder="What it does, what it should do, and anything that makes it worse."><?= e($value('description')) ?></textarea>
            <p class="field-hint">
                Write it for the person who will pick the job up, not for the record. “Pressure
                switch will not cut out” beats “broken”.
            </p>
            <?php if (isset($errors['description'])): ?><p class="field-error"><?= e($errors['description']) ?></p><?php endif; ?>
        </div>

        <div class="field-row">
            <div class="field">
                <label class="label" for="faulty_on">Faulty date</label>
                <input class="input<?= isset($errors['faulty_on']) ? ' has-error' : '' ?>" type="date"
                       id="faulty_on" name="faulty_on" required max="<?= e(date('Y-m-d')) ?>"
                       value="<?= e($value('faulty_on', date('Y-m-d'))) ?>">
                <p class="field-hint">When it was noticed. Change it if the fault has been there a while.</p>
                <?php if (isset($errors['faulty_on'])): ?><p class="field-error"><?= e($errors['faulty_on']) ?></p><?php endif; ?>
            </div>

            <div class="field">
                <label class="label" for="condition_rating">Condition</label>
                <select class="input" id="condition_rating" name="condition_rating" required>
                    <?php $currentCondition = $value('condition_rating', (string) $asset['condition_rating']); ?>
                    <?php foreach (Asset::CONDITIONS as $condition): ?>
                        <option value="<?= e($condition) ?>" <?= $currentCondition === $condition ? 'selected' : '' ?>>
                            <?= e($condition) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="field-hint">
                    The asset's own condition scale. Currently <strong><?= e((string) $asset['condition_rating']) ?></strong>;
                    saving this updates it.
                </p>
            </div>
        </div>
    </div>

    <div class="card">
        <h2>How urgent</h2>
        <p class="muted">
            How badly this one fault needs fixing. It belongs to the report, not to the asset —
            the same machine can break badly one month and trivially the next.
        </p>

        <div class="field">
            <?php $urgency = $value('urgency', 'Medium'); ?>
            <div class="radio-stack">
                <?php foreach (FaultReport::URGENCIES as $level): ?>
                    <label class="checkbox">
                        <input type="radio" name="urgency" value="<?= e($level) ?>"
                               <?= $urgency === $level ? 'checked' : '' ?> required>
                        <span>
                            <strong><?= e($level) ?></strong>
                            <span class="field-hint"><?= e(FaultReport::URGENCY_HINTS[$level]) ?></span>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
            <?php if (isset($errors['urgency'])): ?><p class="field-error"><?= e($errors['urgency']) ?></p><?php endif; ?>
        </div>
    </div>

    <div class="card">
        <h2>Photo</h2>

        <div class="field">
            <span class="label">Photo of the fault</span>
            <?= partial('partials/photo-inputs', ['name' => 'photos[]', 'primary' => true]) ?>
            <p class="field-hint">
                At least one. “Take photo” opens the camera on a phone or tablet; “Choose files”
                picks one taken earlier. Up to <?= (int) (config('uploads.max_photo_bytes') / 1048576) ?> MB each,
                rotated the right way up and scaled down automatically.
            </p>
            <div class="photo-preview" data-photo-preview hidden></div>
            <?php if (isset($errors['photos'])): ?><p class="field-error"><?= e($errors['photos']) ?></p><?php endif; ?>
        </div>
    </div>

    <div class="card">
        <h2>Who will be told</h2>
        <p>
            <?php if (empty($asset['responsible_user_id']) && empty($asset['responsible_team_id'])): ?>
                <strong>Nobody.</strong> No responsible party is set on this asset, so the fault will
                be recorded but no email will go out.
                <?php if (can('assets.edit')): ?>
                    <a href="<?= e(url('/assets/' . $id . '/edit')) ?>">Set one on the asset</a>.
                <?php endif; ?>
            <?php else: ?>
                <?= partial('partials/assignee', Asset::responsibleParts($asset)) ?>
                will be emailed as soon as this is submitted.
                <?php if (($asset['responsible_kind'] ?? '') === 'team'): ?>
                    Every member of the team is told.
                <?php endif; ?>
            <?php endif; ?>
        </p>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary btn-lg">Mark as faulty</button>
        <a class="btn btn-ghost" href="<?= e(url('/assets/' . $id)) ?>">Cancel</a>
    </div>
</form>
