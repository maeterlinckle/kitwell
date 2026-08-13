<?php
/**
 * The shared media library.
 *
 * @var array{rows:array<int,array<string,mixed>>,total:int,page:int,pages:int} $result
 * @var string|null $type
 * @var string      $keyword
 */
$canUploadPhoto = can('media.photo.upload');
$canUploadDoc   = can('media.manual.upload');
$canDelete      = can('media.manual.delete');
?>
<div class="page-head">
    <div>
        <h1>Media library</h1>
        <p class="muted">
            Photos and documents that describe a <em>model</em> rather than one
            item — a manufacturer's photo, a manual. Each is stored once and
            attached to as many assets as need it.
        </p>
    </div>
</div>

<div class="card">
    <form method="get" action="<?= e(url('/media')) ?>" class="field-row">
        <div class="field">
            <label class="label" for="q">Search</label>
            <input class="input" type="search" id="q" name="q" value="<?= e($keyword) ?>"
                   placeholder="Title, description or file name">
        </div>

        <div class="field">
            <label class="label" for="type">Type</label>
            <select class="input" id="type" name="type">
                <option value="">Everything</option>
                <option value="photo" <?= $type === 'photo' ? 'selected' : '' ?>>Photos</option>
                <option value="document" <?= $type === 'document' ? 'selected' : '' ?>>Documents</option>
            </select>
        </div>

        <div class="field field">
            <button class="btn btn-primary" type="submit">Search</button>
            <?php if ($keyword !== '' || $type !== null): ?>
                <a class="btn" href="<?= e(url('/media')) ?>">Clear</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="card">
    <div class="card-head">
        <h2>
            <?= $keyword !== '' || $type !== null ? 'Matching files' : 'Everything in the library' ?>
            <span class="count-pill"><?= (int) $result['total'] ?></span>
        </h2>
    </div>

    <?php if ($result['rows'] === []): ?>
        <p class="empty muted">
            <?= $keyword !== '' ? 'Nothing matches that search.' : 'The library is empty. Add a file below, or upload one against an asset and choose to share it.' ?>
        </p>
    <?php else: ?>
        <div class="media-grid media-grid-wide">
            <?php foreach ($result['rows'] as $item): ?>
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
                        <a class="media-title" href="<?= e(url('/media/' . $mediaId)) ?>" target="_blank" rel="noopener"><?= e($item['title']) ?></a>

                        <?php if (!empty($item['description'])): ?>
                            <span class="media-sub"><?= e(str_limit((string) $item['description'], 90)) ?></span>
                        <?php endif; ?>

                        <span class="muted media-sub">
                            <?= e((string) ($item['original_filename'] ?? '')) ?>
                            <?php if ((int) $item['file_size_bytes'] > 0): ?>
                                · <?= e(\App\Core\Upload::formatBytes((int) $item['file_size_bytes'])) ?>
                            <?php endif; ?>
                        </span>

                        <span class="muted media-sub">
                            <?php $used = (int) ($item['asset_count'] ?? 0); ?>
                            <?= e($used === 0 ? 'Not attached to anything' : 'On ' . $used . ' asset' . ($used === 1 ? '' : 's')) ?>
                            <?php if (!empty($item['uploaded_by_name'])): ?>
                                · added by <?= e((string) $item['uploaded_by_name']) ?>
                            <?php endif; ?>
                        </span>

                        <?php if ($canDelete && (int) ($item['asset_count'] ?? 0) === 0): ?>
                            <form method="post" action="<?= e(url('/media/' . $mediaId . '/delete')) ?>"
                                  onsubmit="return confirm('Delete this file from the library? Nothing is using it.');">
                                <?= csrf_field() ?>
                                <button class="btn btn-sm btn-danger" type="submit">Delete</button>
                            </form>
                        <?php endif; ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ((int) $result['pages'] > 1): ?>
            <nav class="pagination" aria-label="Pages">
                <?php for ($p = 1; $p <= (int) $result['pages']; $p++): ?>
                    <a class="btn btn-sm<?= $p === (int) $result['page'] ? ' btn-primary' : '' ?>"
                       href="<?= e(url('/media?' . http_build_query(array_filter(['q' => $keyword, 'type' => $type, 'page' => $p])))) ?>"><?= (int) $p ?></a>
                <?php endfor; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php if ($canUploadPhoto || $canUploadDoc): ?>
    <div class="card">
        <div class="card-head">
            <h2>Add to the library</h2>
        </div>

        <p class="field-hint">
            Only put something here if it is the same for every unit of the
            model. A photo of the condition of one particular item belongs on
            that asset's own Photos card, not in the library.
        </p>

        <form method="post" action="<?= e(url('/media')) ?>" enctype="multipart/form-data"
              data-photo-form data-max-bytes="<?= (int) config('uploads.max_photo_bytes') ?>">
            <?= csrf_field() ?>

            <div class="field-row">
                <div class="field">
                    <label class="label" for="media_type">Kind</label>
                    <select class="input" id="media_type" name="media_type">
                        <?php if ($canUploadDoc): ?><option value="document">Document (PDF)</option><?php endif; ?>
                        <?php if ($canUploadPhoto): ?><option value="photo">Photo</option><?php endif; ?>
                    </select>
                </div>

                <div class="field">
                    <label class="label" for="title">Title</label>
                    <input class="input" type="text" id="title" name="title" maxlength="191"
                           placeholder="Left blank, the file name is used">
                </div>

                <div class="field field-full">
                    <label class="label" for="description">Description</label>
                    <input class="input" type="text" id="description" name="description" maxlength="500">
                </div>

                <div class="field field-full">
                    <label class="label" for="files">File(s)</label>
                    <input class="input" type="file" id="files" name="files[]" multiple
                           accept="application/pdf,image/jpeg,image/png,image/webp,image/heic,image/heif">
                    <p class="field-hint">
                        Uploading a file that is already in the library attaches
                        the copy that is there rather than storing it twice.
                    </p>
                </div>
            </div>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Add to library</button>
            </div>
        </form>
    </div>
<?php endif; ?>
