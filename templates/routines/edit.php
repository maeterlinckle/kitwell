<?php

use App\Models\MaintenanceRoutine;

/**
 * The routine editor.
 *
 * One form per page of the routine, holding that page's own fields and every
 * step in it. The buttons all post to the same place with a `do` value saying
 * which was pressed, and the controller saves the whole form before acting on
 * it — so adding a step never discards an edit made further up.
 *
 * $version is the version being edited, or null when the live one has been
 * used and nothing may be changed until a new version is started.
 *
 * @var array<string,mixed> $routine
 * @var array<string,mixed>|null $version
 * @var array<string,mixed>|null $current
 * @var array<int,array{id:int,path:string}> $categories
 * @var array<int,array<string,mixed>> $pages
 */
$routineId = (int) $routine['id'];
$isDraft   = $version !== null && $version['published_at'] === null;
?>
<div class="page-head">
    <div>
        <p class="eyebrow"><a href="<?= e(url('/maintenance/routines')) ?>">Maintenance routines</a></p>
        <h1><?= e($routine['name']) ?></h1>
        <p class="badge-row">
            <?php if ($current !== null): ?>
                <span class="badge">Live: v<?= (int) $current['version_number'] ?></span>
            <?php else: ?>
                <span class="badge badge-muted">Nothing published yet</span>
            <?php endif; ?>
            <?php if ($isDraft): ?>
                <span class="badge badge-warn">Editing draft v<?= (int) $version['version_number'] ?></span>
            <?php elseif ($version !== null): ?>
                <span class="badge badge-warn">Editing v<?= (int) $version['version_number'] ?> in place</span>
            <?php endif; ?>
        </p>
    </div>
    <div class="head-actions">
        <?php if ($version !== null): ?>
            <a class="btn" href="<?= e(url('/maintenance/routines/' . $routineId . '/preview?version=' . (int) $version['id'])) ?>">Preview</a>
        <?php endif; ?>
        <a class="btn btn-ghost" href="<?= e(url('/maintenance/routines/' . $routineId)) ?>">Done</a>
    </div>
</div>

<?php if ($version === null): ?>

    <?php /* Every completion points at the version it followed, and that
             version has to keep saying what it said. So there is nothing to
             edit here until somebody explicitly starts the next one. */ ?>
    <div class="card notice-card">
        <h2>Version <?= (int) $current['version_number'] ?> has been used</h2>
        <p>
            <?= (int) $current['completion_count'] ?>
            routine<?= (int) $current['completion_count'] === 1 ? ' has' : 's have' ?>
            been carried out against this version. Changing it now would rewrite what those records
            say was asked, so it is fixed.
        </p>
        <p class="muted">
            Starting version <?= (int) $current['version_number'] + 1 ?> copies everything as it stands
            into a draft. What is live carries on being used until you publish the draft, and the
            records already recorded keep version <?= (int) $current['version_number'] ?> for good.
        </p>

        <form method="post" action="<?= e(url('/maintenance/routines/' . $routineId . '/new-version')) ?>">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-primary">
                Start version <?= (int) $current['version_number'] + 1 ?>
            </button>
        </form>
    </div>

<?php else: ?>

    <?php if ($isDraft): ?>
        <div class="flash flash-info">
            <span class="flash-text">
                This is a draft. Nobody can run it and nothing has changed for anyone
                <?= $current === null ? 'yet' : '— v' . (int) $current['version_number'] . ' is still what gets used' ?>.
                Publish it when it is ready.
            </span>
        </div>
    <?php else: ?>
        <div class="flash flash-info">
            <span class="flash-text">
                Version <?= (int) $version['version_number'] ?> has never been run, so changes here take
                effect straight away. Once it has been used, editing will start a new version instead.
            </span>
        </div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('/maintenance/routines/' . $routineId)) ?>" class="form">
        <?= csrf_field() ?>
        <div class="card">
            <div class="card-head"><h2>Name and description</h2></div>

            <div class="field">
                <label class="label" for="name">Name</label>
                <input class="input" type="text" id="name" name="name" required maxlength="191"
                       value="<?= e($routine['name']) ?>">
            </div>

            <div class="field">
                <label class="label" for="description">Description <span class="optional">(optional)</span></label>
                <textarea class="input" id="description" name="description" rows="2" maxlength="1000"><?= e((string) ($routine['description'] ?? '')) ?></textarea>
            </div>

            <div class="field">
                <label class="label" for="category_id">Applies to</label>
                <select class="input" id="category_id" name="category_id">
                    <option value="">Any asset</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= (int) $category['id'] ?>"
                            <?= (int) ($routine['category_id'] ?? 0) === (int) $category['id'] ? 'selected' : '' ?>>
                            <?= e($category['path']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="field-hint">
                    The routine is offered for assets in this category and every category nested beneath
                    it, and refused for anything else.
                </p>
            </div>

            <div class="form-actions form-actions-inline">
                <button type="submit" class="btn">Save</button>
            </div>
        </div>
    </form>

    <?php /* Held on the version rather than on the routine: a run knows how it
             was meant to be worked through from the edition it followed. */ ?>
    <form method="post" action="<?= e(url('/maintenance/routines/' . $routineId . '/out-of-order')) ?>" class="form">
        <?= csrf_field() ?>
        <div class="card">
            <div class="card-head"><h2>How it is worked through</h2></div>

            <div class="field">
                <label class="checkbox">
                    <input type="checkbox" name="allow_out_of_order" value="1"
                        <?= (int) $version['allow_out_of_order'] === 1 ? 'checked' : '' ?>>
                    <span>
                        Allow steps to be completed out of order
                        <span class="field-hint">
                            A run then stays open as a checklist: any step on any page can be answered
                            at any time, by anybody, and the run is signed off once the required ones
                            are done. Suits work that passes between stations. Left off, the routine is
                            one form filled in from top to bottom in a single sitting.
                        </span>
                    </span>
                </label>
            </div>

            <div class="form-actions form-actions-inline">
                <button type="submit" class="btn">Save</button>
            </div>
        </div>
    </form>

    <?php foreach ($pages as $index => $page): ?>
        <?php $pageId = (int) $page['id']; ?>

        <form method="post" action="<?= e(url('/maintenance/routines/' . $routineId . '/pages/' . $pageId)) ?>"
              class="form routine-page-form" id="page-<?= (int) $pageId ?>">
            <?= csrf_field() ?>

            <div class="card routine-page-card">
                <div class="card-head">
                    <h2>Page <?= (int) $index + 1 ?></h2>
                    <div class="card-actions">
                        <button type="submit" class="btn btn-sm" name="do" value="move-page:-1"
                                <?= $index === 0 ? 'disabled' : '' ?> title="Move this page up">&uarr;</button>
                        <button type="submit" class="btn btn-sm" name="do" value="move-page:1"
                                <?= $index === count($pages) - 1 ? 'disabled' : '' ?> title="Move this page down">&darr;</button>
                        <button type="submit" class="btn btn-sm btn-danger" name="do" value="delete-page"
                                data-confirm="Remove this page and every step on it?">Remove page</button>
                    </div>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label class="label" for="page-title-<?= (int) $pageId ?>">Page title</label>
                        <input class="input" type="text" id="page-title-<?= (int) $pageId ?>" name="title"
                               maxlength="191" value="<?= e($page['title']) ?>">
                    </div>

                    <div class="field">
                        <label class="label" for="page-desc-<?= (int) $pageId ?>">Introduction <span class="optional">(optional)</span></label>
                        <input class="input" type="text" id="page-desc-<?= (int) $pageId ?>" name="description"
                               maxlength="1000" value="<?= e((string) ($page['description'] ?? '')) ?>"
                               placeholder="Shown under the heading while the routine is being filled in">
                    </div>
                </div>

                <?php if ($page['steps'] === []): ?>
                    <p class="muted">No steps on this page yet.</p>
                <?php endif; ?>

                <ol class="routine-step-editor">
                    <?php foreach ($page['steps'] as $stepIndex => $step): ?>
                        <?php
                        $stepId  = (int) $step['id'];
                        $type    = (string) $step['field_type'];
                        $isFirst = $stepIndex === 0;
                        $isLast  = $stepIndex === count($page['steps']) - 1;
                        ?>
                        <li class="routine-step-row" data-step-editor>
                            <div class="routine-step-main">
                                <div class="field">
                                    <label class="label" for="step-label-<?= (int) $stepId ?>">Question or instruction</label>
                                    <input class="input" type="text" id="step-label-<?= (int) $stepId ?>"
                                           name="steps[<?= (int) $stepId ?>][label]" maxlength="255"
                                           value="<?= e($step['label']) ?>">
                                </div>

                                <div class="field">
                                    <label class="label" for="step-help-<?= (int) $stepId ?>">Help text <span class="optional">(optional)</span></label>
                                    <input class="input" type="text" id="step-help-<?= (int) $stepId ?>"
                                           name="steps[<?= (int) $stepId ?>][help_text]" maxlength="1000"
                                           value="<?= e((string) ($step['help_text'] ?? '')) ?>"
                                           placeholder="How to do it, what to look for, what good looks like">
                                </div>

                                <div class="field-row">
                                    <div class="field">
                                        <label class="label" for="step-type-<?= (int) $stepId ?>">Answer type</label>
                                        <select class="input" id="step-type-<?= (int) $stepId ?>"
                                                name="steps[<?= (int) $stepId ?>][field_type]" data-step-type>
                                            <?php foreach (MaintenanceRoutine::FIELD_TYPES as $value => $label): ?>
                                                <option value="<?= e($value) ?>" <?= $type === $value ? 'selected' : '' ?>>
                                                    <?= e($label) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="field">
                                        <span class="label">Answering it</span>
                                        <label class="checkbox">
                                            <input type="checkbox" name="step_required[<?= (int) $stepId ?>]" value="1"
                                                <?= (int) $step['is_required'] === 1 ? 'checked' : '' ?>>
                                            <span>Required before moving on</span>
                                        </label>
                                    </div>
                                </div>

                                <?php /* Shown for the types that read them. Without
                                         JavaScript both are simply always visible,
                                         and the server ignores whichever the chosen
                                         type has no use for. */ ?>
                                <div class="field" data-when-type="number"<?= $type === 'number' ? '' : ' hidden' ?>>
                                    <label class="label" for="step-unit-<?= (int) $stepId ?>">Unit <span class="optional">(optional)</span></label>
                                    <input class="input" type="text" id="step-unit-<?= (int) $stepId ?>"
                                           name="steps[<?= (int) $stepId ?>][unit]" maxlength="30"
                                           value="<?= e((string) ($step['unit'] ?? '')) ?>"
                                           placeholder="bar, °C, mm">
                                </div>

                                <div class="field" data-when-type="single_choice multi_choice"<?= in_array($type, MaintenanceRoutine::CHOICE_TYPES, true) ? '' : ' hidden' ?>>
                                    <label class="label" for="step-options-<?= (int) $stepId ?>">Choices, one per line</label>
                                    <textarea class="input" id="step-options-<?= (int) $stepId ?>"
                                              name="steps[<?= (int) $stepId ?>][options]" rows="3"
                                              placeholder="Pass&#10;Advisory&#10;Fail"><?= e(MaintenanceRoutine::optionsText($step)) ?></textarea>
                                    <p class="field-hint">Up to <?= MaintenanceRoutine::MAX_OPTIONS ?>. Duplicates and blank lines are dropped.</p>
                                </div>
                            </div>

                            <div class="routine-step-tools">
                                <button type="submit" class="btn btn-sm" name="do" value="move-step:<?= (int) $stepId ?>:-1"
                                        <?= $isFirst ? 'disabled' : '' ?> title="Move this step up">&uarr;</button>
                                <button type="submit" class="btn btn-sm" name="do" value="move-step:<?= (int) $stepId ?>:1"
                                        <?= $isLast ? 'disabled' : '' ?> title="Move this step down">&darr;</button>
                                <button type="submit" class="btn btn-sm btn-danger" name="do" value="delete-step:<?= (int) $stepId ?>"
                                        data-confirm="Remove this step?">Remove</button>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ol>

                <div class="form-actions form-actions-inline">
                    <button type="submit" class="btn btn-primary" name="do" value="save">Save page</button>
                    <button type="submit" class="btn" name="do" value="add-step">Add a step</button>
                </div>
            </div>
        </form>
    <?php endforeach; ?>

    <form method="post" action="<?= e(url('/maintenance/routines/' . $routineId . '/pages')) ?>" class="form">
        <?= csrf_field() ?>
        <div class="card">
            <div class="card-head"><h2>Add a page</h2></div>
            <div class="field-row">
                <div class="field">
                    <label class="label" for="new-page-title">Page title</label>
                    <input class="input" type="text" id="new-page-title" name="title" maxlength="191"
                           placeholder="e.g. Before starting">
                </div>
                <div class="field field-aligned">
                    <button type="submit" class="btn btn-primary">Add page</button>
                </div>
            </div>
        </div>
    </form>

    <?php if ($isDraft): ?>
        <div class="card">
            <div class="card-head"><h2>Publish</h2></div>
            <p class="muted">
                Publishing makes version <?= (int) $version['version_number'] ?> the one every new run
                follows.
                <?php if ($current !== null): ?>
                    Records already carried out against v<?= (int) $current['version_number'] ?> keep it.
                <?php endif; ?>
            </p>

            <div class="form-actions form-actions-inline">
                <form method="post" action="<?= e(url('/maintenance/routines/' . $routineId . '/publish')) ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-primary">Publish version <?= (int) $version['version_number'] ?></button>
                </form>

                <form method="post" action="<?= e(url('/maintenance/routines/' . $routineId . '/discard')) ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-ghost"
                            data-confirm="Throw this draft away? Everything typed into it is lost.">Discard draft</button>
                </form>
            </div>
        </div>
    <?php endif; ?>

<?php endif; ?>

<script src="<?= e(asset_url('js/routine-editor.js')) ?>" defer></script>
