<?php

use App\Models\Borrower;

/**
 * @var array<string,mixed>|null $borrower
 * @var array<int,array<string,mixed>> $users
 * @var array<string,string> $errors
 * @var array<string,mixed>  $old
 */
$isEdit = $borrower !== null;
$action = $isEdit ? url('/borrowers/' . $borrower['id']) : url('/borrowers');

$value = static function (string $field, mixed $default = '') use ($old, $borrower): string {
    if (array_key_exists($field, $old)) {
        return (string) $old[$field];
    }

    if ($borrower !== null && array_key_exists($field, $borrower) && $borrower[$field] !== null) {
        return (string) $borrower[$field];
    }

    return (string) $default;
};
?>
<div class="page-head">
    <div>
        <h1><?= $isEdit ? 'Edit borrower' : 'Add borrower' ?></h1>
        <?php if ($isEdit): ?>
            <p class="muted"><?= (int) $borrower['total_loans'] ?> loan<?= (int) $borrower['total_loans'] === 1 ? '' : 's' ?> on record</p>
        <?php endif; ?>
    </div>
    <a class="btn btn-ghost" href="<?= e($isEdit ? url('/borrowers/' . $borrower['id']) : url('/borrowers')) ?>">Cancel</a>
</div>

<form method="post" action="<?= e($action) ?>" class="form form-wide" novalidate>
    <?= csrf_field() ?>

    <div class="card">
        <h2>Who</h2>

        <div class="field">
            <span class="label">Type</span>
            <div class="radio-cards radio-cards-inline">
                <?php foreach (Borrower::TYPES as $type): ?>
                    <label class="radio-card">
                        <input type="radio" name="borrower_type" value="<?= e($type) ?>"
                            <?= $value('borrower_type', 'Person') === $type ? 'checked' : '' ?>>
                        <span>
                            <strong><?= e($type) ?></strong>
                            <span class="muted"><?= $type === 'Person' ? 'An individual who takes items out.' : 'An organisation hiring items.' ?></span>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="field-row">
            <div class="field">
                <label class="label" for="name">Name</label>
                <input class="input<?= isset($errors['name']) ? ' has-error' : '' ?>" type="text" id="name" name="name"
                       maxlength="191" required value="<?= e($value('name')) ?>">
                <p class="field-hint">The person's name, or the company's trading name.</p>
                <?php if (isset($errors['name'])): ?><p class="field-error"><?= e($errors['name']) ?></p><?php endif; ?>
            </div>

            <div class="field">
                <label class="label" for="company_name">Company <span class="optional">(optional)</span></label>
                <input class="input" type="text" id="company_name" name="company_name" maxlength="191"
                       value="<?= e($value('company_name')) ?>">
                <p class="field-hint">For a person, who they work for.</p>
            </div>
        </div>

        <div class="field-row">
            <div class="field">
                <label class="label" for="reference">Reference <span class="optional">(optional)</span></label>
                <input class="input mono" type="text" id="reference" name="reference" maxlength="64"
                       placeholder="Staff number, account number…" value="<?= e($value('reference')) ?>">
            </div>

            <div class="field">
                <label class="label" for="email">Email <span class="optional">(optional)</span></label>
                <input class="input<?= isset($errors['email']) ? ' has-error' : '' ?>" type="email" id="email"
                       name="email" maxlength="190" autocapitalize="none" spellcheck="false"
                       value="<?= e($value('email')) ?>">
                <?php if (isset($errors['email'])): ?><p class="field-error"><?= e($errors['email']) ?></p><?php endif; ?>
            </div>
        </div>

        <div class="field-row">
            <div class="field">
                <label class="label" for="phone">Phone <span class="optional">(optional)</span></label>
                <input class="input" type="tel" id="phone" name="phone" maxlength="50" value="<?= e($value('phone')) ?>">
            </div>

            <div class="field">
                <label class="label" for="address">Address <span class="optional">(optional)</span></label>
                <textarea class="input" id="address" name="address" rows="3" maxlength="5000"><?= e($value('address')) ?></textarea>
            </div>
        </div>
    </div>

    <div class="card">
        <h2>Self-service login <span class="optional">(optional)</span></h2>
        <p class="muted">
            Linking a login lets this borrower sign in and see what they have out — and nothing else.
            Create the user first under <a href="<?= e(url('/admin/users')) ?>">Users</a> with the
            <strong>Borrower</strong> role, then pick them here.
        </p>

        <div class="field">
            <label class="label" for="user_id">Linked login</label>
            <select class="input<?= isset($errors['user_id']) ? ' has-error' : '' ?>" id="user_id" name="user_id">
                <option value="">Not linked</option>
                <?php foreach ($users as $user): ?>
                    <option value="<?= (int) $user['id'] ?>" <?= $value('user_id') === (string) $user['id'] ? 'selected' : '' ?>>
                        <?= e($user['name']) ?> — <?= e($user['email']) ?> (<?= e($user['role_name']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="field-hint">Each login can be linked to one borrower record.</p>
            <?php if (isset($errors['user_id'])): ?><p class="field-error"><?= e($errors['user_id']) ?></p><?php endif; ?>
        </div>

        <?php if ($isEdit): ?>
            <div class="field">
                <label class="checkbox">
                    <input type="checkbox" name="is_active" value="1" <?= (int) $borrower['is_active'] === 1 ? 'checked' : '' ?>>
                    <span>Active<span class="field-hint">Inactive borrowers stay in the history but drop out of the checkout list.</span></span>
                </label>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Notes <span class="optional">(optional)</span></h2>
        <div class="field">
            <label class="sr-only" for="notes">Notes</label>
            <textarea class="input" id="notes" name="notes" rows="3" maxlength="5000"><?= e($value('notes')) ?></textarea>
        </div>
    </div>

    <div class="form-actions sticky-actions">
        <button type="submit" class="btn btn-primary btn-lg"><?= $isEdit ? 'Save borrower' : 'Add borrower' ?></button>
        <a class="btn btn-ghost" href="<?= e($isEdit ? url('/borrowers/' . $borrower['id']) : url('/borrowers')) ?>">Cancel</a>
    </div>
</form>
