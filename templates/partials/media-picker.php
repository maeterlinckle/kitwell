<?php
/**
 * The library picker: search the shared media library and tick what to attach.
 *
 * Used on the Add asset form and in the template editor, so both are literally
 * the same control. Results come from /media/search as JSON; with JavaScript
 * unavailable the panel shows the recent items rendered server-side, which is
 * enough to attach something without leaving the page.
 *
 * Every ticked box posts `media_ids[]`, which is all the receiving controller
 * needs — attaching is one join row.
 *
 * @var string                             $type      'photo' or 'document'
 * @var array<int,array<string,mixed>>     $recent    Items shown before a search
 * @var array<int,int>                     $selected  Already ticked
 * @var string                             $label
 */
$type     = ($type ?? 'document') === 'photo' ? 'photo' : 'document';
$recent   = $recent ?? [];
$selected = array_map('intval', $selected ?? []);
$label    = (string) ($label ?? ($type === 'photo' ? 'Photos from the library' : 'Documents from the library'));
$listId   = 'picker-' . $type;
?>
<div class="media-picker" data-media-picker data-type="<?= e($type) ?>" data-search="<?= e(url('/media/search')) ?>">
    <div class="media-picker-head">
        <label class="label" for="<?= e($listId) ?>-q"><?= e($label) ?></label>
        <input class="input" type="search" id="<?= e($listId) ?>-q" data-media-search
               placeholder="Search by title, description or file name"
               autocomplete="off">
    </div>

    <p class="hint">
        These are shared. Attaching one here links the file that is already
        stored — it is not copied, and editing it later changes it everywhere
        it is used.
    </p>

    <div class="media-grid" id="<?= e($listId) ?>" data-media-results>
        <?php if ($recent === []): ?>
            <p class="muted media-empty">The library has no <?= $type === 'photo' ? 'photos' : 'documents' ?> yet. Upload one below and it will be here next time.</p>
        <?php else: ?>
            <?php foreach ($recent as $item): ?>
                <?php $id = (int) $item['id']; ?>
                <label class="media-card<?= in_array($id, $selected, true) ? ' is-selected' : '' ?>">
                    <input type="checkbox" name="media_ids[]" value="<?= (int) $id ?>"
                           <?= in_array($id, $selected, true) ? 'checked' : '' ?>>
                    <?php if ($type === 'photo'): ?>
                        <img class="media-thumb" src="<?= e(url('/media/' . $id . '/thumbnail')) ?>" alt="" loading="lazy">
                    <?php else: ?>
                        <span class="media-thumb media-thumb-doc" aria-hidden="true">PDF</span>
                    <?php endif; ?>
                    <span class="media-meta">
                        <span class="media-title"><?= e($item['title']) ?></span>
                        <span class="muted media-sub">
                            <?= e((string) ($item['original_filename'] ?? '')) ?>
                            <?php if ((int) ($item['asset_count'] ?? 0) > 0): ?>
                                · on <?= (int) $item['asset_count'] ?> asset<?= (int) $item['asset_count'] === 1 ? '' : 's' ?>
                            <?php endif; ?>
                        </span>
                    </span>
                </label>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <p class="muted media-picker-status" data-media-status hidden></p>
</div>
