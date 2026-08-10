<?php
/**
 * @var array<string,mixed> $emailTemplate
 * @var string $previewSubject
 * @var string $previewBody
 * @var array<int,string> $unknownFields
 * @var array<string,string> $errors
 * @var array<string,mixed>  $old
 * @var string $section
 */
?>
<div class="page-head">
    <div>
        <h1><?= e($emailTemplate['name']) ?></h1>
        <p class="muted"><?= e($emailTemplate['description']) ?></p>
    </div>
    <div class="head-actions">
        <a class="btn btn-ghost" href="<?= e(url('/admin/email/templates')) ?>">All templates</a>
    </div>
</div>

<?= partial('partials/email-nav', ['section' => $section]) ?>

<?php if ($emailTemplate['is_customised'] === true): ?>
    <div class="card notice-card">
        <p>
            Edited<?= $emailTemplate['updated_by_name'] !== null ? ' by ' . e((string) $emailTemplate['updated_by_name']) : '' ?><?php
            ?><?= $emailTemplate['updated_at'] !== null ? ' on ' . e(format_datetime((string) $emailTemplate['updated_at'])) : '' ?>.
            Resetting puts back the wording the application ships with.
        </p>
        <form method="post" action="<?= e(url('/admin/email/templates/' . $emailTemplate['key'] . '/reset')) ?>">
            <?= csrf_field() ?>
            <button type="submit" class="btn">Reset to the default wording</button>
        </form>
    </div>
<?php endif; ?>

<?php if ($unknownFields !== []): ?>
    <div class="card card-warn">
        <p>
            <strong>These merge fields are not available in this template and will come out blank:</strong>
            <?php foreach ($unknownFields as $field): ?>
                <span class="mono">{{<?= e($field) ?>}}</span>
            <?php endforeach; ?>
        </p>
    </div>
<?php endif; ?>

<div class="detail-grid detail-grid-wide">
    <div>
        <form method="post" action="<?= e(url('/admin/email/templates/' . $emailTemplate['key'])) ?>" class="form" novalidate>
            <?= csrf_field() ?>

            <div class="card">
                <div class="field">
                    <label class="label" for="subject">Subject</label>
                    <input class="input<?= isset($errors['subject']) ? ' has-error' : '' ?>" type="text"
                           id="subject" name="subject" maxlength="255" required
                           value="<?= e(old($old, 'subject', (string) $emailTemplate['subject'])) ?>">
                    <?php if (isset($errors['subject'])): ?><p class="field-error"><?= e($errors['subject']) ?></p><?php endif; ?>
                </div>

                <div class="field">
                    <label class="label" for="body">Message</label>
                    <textarea class="input textarea-tall<?= isset($errors['body']) ? ' has-error' : '' ?>"
                              id="body" name="body" rows="18" required spellcheck="true"><?= e(old($old, 'body', (string) $emailTemplate['body'])) ?></textarea>
                    <?php if (isset($errors['body'])): ?><p class="field-error"><?= e($errors['body']) ?></p><?php endif; ?>
                </div>

                <div class="field">
                    <label class="checkbox">
                        <input type="checkbox" name="is_html" value="1" <?= $emailTemplate['is_html'] === true ? 'checked' : '' ?>>
                        <span>This message is written in HTML</span>
                    </label>
                    <p class="field-hint">
                        Leave this off unless you have written HTML above. A plain-text version is generated
                        automatically and sent alongside, so the message stays readable in any mail client.
                        Merged values are escaped, so an apostrophe in a name cannot break the markup.
                    </p>
                </div>

                <div class="field">
                    <label class="checkbox">
                        <input type="checkbox" name="is_active" value="1" <?= $emailTemplate['is_active'] === true ? 'checked' : '' ?>>
                        <span>Send this message</span>
                    </label>
                    <p class="field-hint">
                        Untick to suppress this one message without switching off email or the reminder
                        it belongs to.
                    </p>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary btn-lg">Save template</button>
                <a class="btn btn-ghost" href="<?= e(url('/admin/email/templates')) ?>">Cancel</a>
            </div>
        </form>
    </div>

    <div>
        <div class="card">
            <h2>Merge fields</h2>
            <p class="muted">
                Type these anywhere in the subject or the message. They are replaced when the message is
                sent. Anything not in this list comes out blank.
            </p>

            <dl class="merge-fields">
                <?php foreach ($emailTemplate['fields'] as $field => $description): ?>
                    <dt class="mono">{{<?= e($field) ?>}}</dt>
                    <dd><?= e($description) ?></dd>
                <?php endforeach; ?>
            </dl>
        </div>

        <div class="card">
            <h2>Preview</h2>
            <p class="muted">The wording as saved, filled in with example values.</p>

            <p><strong>Subject:</strong> <?= e($previewSubject) ?></p>

            <?php if ($emailTemplate['is_html'] === true): ?>
                <p class="field-hint">Shown as source, because this template is HTML.</p>
            <?php endif; ?>

            <pre class="email-preview"><?= e($previewBody) ?></pre>
        </div>
    </div>
</div>
