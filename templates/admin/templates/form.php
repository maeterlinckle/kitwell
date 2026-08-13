<?php

use App\Models\Asset;

/**
 * Create or edit an asset template.
 *
 * Everything on the left is a default the Add asset form will fill in. The
 * three flags are three-way — "leave it alone" is not the same instruction as
 * "switch it off" — so a template that says nothing about PAT does not quietly
 * turn it off on every asset started from it.
 *
 * There is no asset tag or barcode here on purpose: those identify one physical
 * item and are generated or typed per asset however it was started.
 *
 * @var array<string,mixed>|null       $assetTemplate
 * @var array<int,array<string,mixed>> $categories
 * @var array<int,array<string,mixed>> $locations
 * @var array<int,array<string,mixed>> $media
 */

$isNew    = $assetTemplate === null;
$id       = $isNew ? 0 : (int) $assetTemplate['id'];
$action   = $isNew ? url('/admin/templates') : url('/admin/templates/' . $id);

/** A saved value, an old submission, or nothing. */
$value = static function (string $field, mixed $default = '') use ($old, $assetTemplate): string {
    if (array_key_exists($field, $old)) {
        return (string) $old[$field];
    }

    return (string) ($assetTemplate[$field] ?? $default);
};

/** The three-way flags. '' means the template says nothing about the field. */
$flag = static function (string $field) use ($old, $assetTemplate): string {
    if (array_key_exists($field, $old)) {
        return (string) $old[$field];
    }

    $stored = $assetTemplate[$field] ?? null;

    return $stored === null ? '' : ((int) $stored === 1 ? '1' : '0');
};

$photos    = array_values(array_filter($media, static fn (array $m): bool => $m['media_type'] === 'photo'));
$documents = array_values(array_filter($media, static fn (array $m): bool => $m['media_type'] === 'document'));
?>
<div class="page-head">
    <div>
        <h1><?= $isNew ? 'Add asset template' : e((string) $assetTemplate['name']) ?></h1>
        <p class="muted">
            <?= $isNew
                ? 'Give it a name, then set whatever should be filled in automatically. Leave anything else blank.'
                : 'Everything here is a default the Add asset form fills in and the person creating the asset can change.' ?>
        </p>
    </div>
    <div class="head-actions">
        <a class="btn" href="<?= e(url('/admin/templates')) ?>">Back to templates</a>
        <?php if (!$isNew && can('assets.create')): ?>
            <a class="btn btn-primary" href="<?= e(url('/assets/create?template=' . $id)) ?>">Use this template</a>
        <?php endif; ?>
    </div>
</div>

<form method="post" action="<?= e($action) ?>">
    <?= csrf_field() ?>

    <div class="card">
        <div class="card-head"><h2>The template</h2></div>

        <div class="field-row">
            <div class="field">
                <label class="label" for="name">Template name <span aria-hidden="true">*</span></label>
                <input class="input<?= isset($errors['name']) ? ' has-error' : '' ?>" type="text" id="name" name="name"
                       value="<?= e($value('name')) ?>" maxlength="120" required>
                <?php if (isset($errors['name'])): ?><p class="field-error"><?= e($errors['name']) ?></p><?php endif; ?>
                <p class="field-hint">What it is called in the picker, e.g. “Makita 18V combi drill”.</p>
            </div>

            <div class="field">
                <label class="label" for="description">Description</label>
                <input class="input" type="text" id="description" name="description"
                       value="<?= e($value('description')) ?>" maxlength="500">
            </div>

            <div class="field field-full">
                <label class="checkbox">
                    <input type="checkbox" name="is_active" value="1"
                        <?= $isNew || (int) ($assetTemplate['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
                    <span>Offer this template on the Add asset form</span>
                </label>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h2>What it fills in</h2></div>

        <div class="field-row">
            <div class="field">
                <label class="label" for="asset_name">Asset name</label>
                <input class="input" type="text" id="asset_name" name="asset_name"
                       value="<?= e($value('asset_name')) ?>" maxlength="191">
            </div>

            <div class="field">
                <label class="label" for="category_id">Category</label>
                <select class="input" id="category_id" name="category_id">
                    <option value="">Leave blank</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= (int) $category['id'] ?>" <?= $value('category_id') === (string) $category['id'] ? 'selected' : '' ?>>
                            <?= e((string) $category['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label class="label" for="location_id">Default location</label>
                <select class="input" id="location_id" name="location_id">
                    <option value="">Leave blank</option>
                    <?php foreach ($locations as $location): ?>
                        <option value="<?= (int) $location['id'] ?>" <?= $value('location_id') === (string) $location['id'] ? 'selected' : '' ?>>
                            <?= e((string) $location['display_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label class="label" for="condition_rating">Condition</label>
                <select class="input" id="condition_rating" name="condition_rating">
                    <option value="">Leave blank</option>
                    <?php foreach (Asset::CONDITIONS as $condition): ?>
                        <option value="<?= e($condition) ?>" <?= $value('condition_rating') === $condition ? 'selected' : '' ?>><?= e($condition) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label class="label" for="manufacturer">Manufacturer</label>
                <input class="input" type="text" id="manufacturer" name="manufacturer"
                       value="<?= e($value('manufacturer')) ?>" maxlength="191">
            </div>

            <div class="field">
                <label class="label" for="model">Model</label>
                <input class="input" type="text" id="model" name="model"
                       value="<?= e($value('model')) ?>" maxlength="191">
            </div>

            <div class="field">
                <label class="label" for="supplier">Supplier</label>
                <input class="input" type="text" id="supplier" name="supplier"
                       value="<?= e($value('supplier')) ?>" maxlength="191">
            </div>

            <div class="field">
                <label class="label" for="manufacturer_url">Manufacturer website</label>
                <input class="input<?= isset($errors['manufacturer_url']) ? ' has-error' : '' ?>" type="url"
                       id="manufacturer_url" name="manufacturer_url"
                       value="<?= e($value('manufacturer_url')) ?>" maxlength="500">
                <?php if (isset($errors['manufacturer_url'])): ?><p class="field-error"><?= e($errors['manufacturer_url']) ?></p><?php endif; ?>
            </div>

            <div class="field field-full">
                <label class="label" for="asset_description">Asset description</label>
                <textarea class="input" id="asset_description" name="asset_description" rows="3" maxlength="5000"><?= e($value('asset_description')) ?></textarea>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h2>Electrical &amp; PAT</h2></div>

        <div class="field-row">
            <div class="field">
                <label class="label" for="requires_pat">Requires PAT</label>
                <select class="input" id="requires_pat" name="requires_pat">
                    <option value="" <?= $flag('requires_pat') === '' ? 'selected' : '' ?>>Say nothing — leave the form's own default</option>
                    <option value="1" <?= $flag('requires_pat') === '1' ? 'selected' : '' ?>>Yes</option>
                    <option value="0" <?= $flag('requires_pat') === '0' ? 'selected' : '' ?>>No</option>
                </select>
            </div>

            <div class="field">
                <label class="label" for="pat_interval_months">PAT interval (months)</label>
                <input class="input<?= isset($errors['pat_interval_months']) ? ' has-error' : '' ?>" type="number"
                       id="pat_interval_months" name="pat_interval_months" min="1" max="120"
                       value="<?= e($value('pat_interval_months')) ?>">
                <?php if (isset($errors['pat_interval_months'])): ?><p class="field-error"><?= e($errors['pat_interval_months']) ?></p><?php endif; ?>
                <p class="field-hint">Blank uses the site default.</p>
            </div>

            <div class="field">
                <label class="label" for="appliance_class">Appliance class</label>
                <select class="input" id="appliance_class" name="appliance_class">
                    <option value="">Leave blank</option>
                    <?php foreach (Asset::APPLIANCE_CLASSES as $class): ?>
                        <option value="<?= e($class) ?>" <?= $value('appliance_class') === $class ? 'selected' : '' ?>><?= e($class) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label class="label" for="load_rating_va">Load rating (VA)</label>
                <input class="input<?= isset($errors['load_rating_va']) ? ' has-error' : '' ?>" type="number" step="0.01" min="0"
                       id="load_rating_va" name="load_rating_va" value="<?= e($value('load_rating_va')) ?>">
                <?php if (isset($errors['load_rating_va'])): ?><p class="field-error"><?= e($errors['load_rating_va']) ?></p><?php endif; ?>
            </div>

            <div class="field">
                <label class="label" for="has_fuse">Plug carries a fuse</label>
                <select class="input" id="has_fuse" name="has_fuse">
                    <option value="" <?= $flag('has_fuse') === '' ? 'selected' : '' ?>>Say nothing</option>
                    <option value="1" <?= $flag('has_fuse') === '1' ? 'selected' : '' ?>>Yes</option>
                    <option value="0" <?= $flag('has_fuse') === '0' ? 'selected' : '' ?>>No</option>
                </select>
            </div>

            <div class="field">
                <label class="label" for="plug_fuse_rating_amps">Plug fuse rating (A)</label>
                <select class="input<?= isset($errors['plug_fuse_rating_amps']) ? ' has-error' : '' ?>"
                        id="plug_fuse_rating_amps" name="plug_fuse_rating_amps">
                    <option value="">Leave blank</option>
                    <?php foreach (Asset::FUSE_RATINGS as $rating): ?>
                        <option value="<?= e($rating) ?>" <?= (string) (float) $value('plug_fuse_rating_amps') === $rating ? 'selected' : '' ?>><?= e($rating) ?> A</option>
                    <?php endforeach; ?>
                </select>
                <?php if (isset($errors['plug_fuse_rating_amps'])): ?><p class="field-error"><?= e($errors['plug_fuse_rating_amps']) ?></p><?php endif; ?>
            </div>

            <div class="field">
                <label class="label" for="cable_csa_mm2">Cable CSA (mm²)</label>
                <input class="input<?= isset($errors['cable_csa_mm2']) ? ' has-error' : '' ?>" type="number" step="0.01" min="0"
                       id="cable_csa_mm2" name="cable_csa_mm2" value="<?= e($value('cable_csa_mm2')) ?>">
                <?php if (isset($errors['cable_csa_mm2'])): ?><p class="field-error"><?= e($errors['cable_csa_mm2']) ?></p><?php endif; ?>
            </div>

            <div class="field">
                <label class="label" for="is_hireable">Available for hire</label>
                <select class="input" id="is_hireable" name="is_hireable">
                    <option value="" <?= $flag('is_hireable') === '' ? 'selected' : '' ?>>Say nothing</option>
                    <option value="1" <?= $flag('is_hireable') === '1' ? 'selected' : '' ?>>Yes</option>
                    <option value="0" <?= $flag('is_hireable') === '0' ? 'selected' : '' ?>>No</option>
                </select>
            </div>

            <div class="field field-full">
                <label class="label" for="notes">Notes</label>
                <textarea class="input" id="notes" name="notes" rows="3" maxlength="5000"><?= e($value('notes')) ?></textarea>
            </div>
        </div>
    </div>

    <div class="form-actions">
        <button class="btn btn-primary" type="submit"><?= $isNew ? 'Create template' : 'Save template' ?></button>
        <a class="btn" href="<?= e(url('/admin/templates')) ?>">Cancel</a>
    </div>
</form>

<?php if (!$isNew): ?>
    <div class="card" id="media">
        <div class="card-head">
            <h2>Photos &amp; documents <span class="count-pill"><?= count($media) ?></span></h2>
        </div>

        <p class="field-hint">
            These come with the template. An asset created from it is attached
            to the same files — nothing is copied, so ten drills built from this
            template share one manual.
        </p>

        <?php if ($media === []): ?>
            <p class="empty muted">Nothing attached yet.</p>
        <?php else: ?>
            <div class="media-grid media-grid-wide">
                <?php foreach ($media as $item): ?>
                    <?php $mediaId = (int) $item['id']; ?>
                    <div class="media-card media-card-static">
                        <a href="<?= e(url('/media/' . $mediaId)) ?>" target="_blank" rel="noopener">
                            <?php if ($item['media_type'] === 'photo'): ?>
                                <img class="media-thumb" src="<?= e(url('/media/' . $mediaId . '/thumbnail')) ?>" alt="" loading="lazy">
                            <?php else: ?>
                                <span class="media-thumb media-thumb-doc" aria-hidden="true">PDF</span>
                            <?php endif; ?>
                        </a>
                        <span class="media-meta">
                            <a class="media-title" href="<?= e(url('/media/' . $mediaId)) ?>" target="_blank" rel="noopener"><?= e((string) $item['title']) ?></a>
                            <span class="muted media-sub"><?= e((string) ($item['original_filename'] ?? '')) ?></span>
                            <form method="post" action="<?= e(url('/admin/templates/' . $id . '/media/' . $mediaId . '/detach')) ?>">
                                <?= csrf_field() ?>
                                <button class="btn btn-sm" type="submit">Remove</button>
                            </form>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-head"><h2>Attach from the library</h2></div>

        <form method="post" action="<?= e(url('/admin/templates/' . $id . '/media')) ?>">
            <?= csrf_field() ?>
            <?= partial('partials/media-picker', [
                'type'     => 'document',
                'recent'   => $libraryDocuments ?? [],
                'selected' => array_map(static fn (array $m): int => (int) $m['id'], $documents),
                'label'    => 'Documents',
            ]) ?>
            <?= partial('partials/media-picker', [
                'type'     => 'photo',
                'recent'   => $libraryPhotos ?? [],
                'selected' => array_map(static fn (array $m): int => (int) $m['id'], $photos),
                'label'    => 'Photos',
            ]) ?>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Attach the ticked files</button>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="card-head"><h2>Upload a new file</h2></div>

        <p class="field-hint">
            Anything uploaded here goes into the shared library, because a
            template is by definition about a model rather than one item.
        </p>

        <form method="post" action="<?= e(url('/admin/templates/' . $id . '/media/upload')) ?>" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <div class="field-row">
                <div class="field">
                    <label class="label" for="upload_type">Kind</label>
                    <select class="input" id="upload_type" name="media_type">
                        <option value="document">Document (PDF)</option>
                        <option value="photo">Photo</option>
                    </select>
                </div>

                <div class="field">
                    <label class="label" for="upload_title">Title</label>
                    <input class="input" type="text" id="upload_title" name="title" maxlength="191">
                </div>

                <div class="field field-full">
                    <label class="label" for="upload_files">File(s)</label>
                    <input class="input" type="file" id="upload_files" name="files[]" multiple
                           accept="application/pdf,image/jpeg,image/png,image/webp,image/heic,image/heif">
                </div>
            </div>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Upload and attach</button>
            </div>
        </form>
    </div>
<?php endif; ?>
