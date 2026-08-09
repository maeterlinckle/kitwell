<?php
/**
 * The borrower's own view: what they have out, and when it is due.
 *
 * @var array<string,mixed> $borrower
 * @var array<int,array<string,mixed>> $openLoans
 * @var array<int,array<string,mixed>> $pastLoans
 * @var array<int,array<string,mixed>> $photos
 */
$overdueCount = count(array_filter($openLoans, static fn (array $l): bool => $l['effective_status'] === 'Overdue'));
?>
<div class="page-head">
    <div>
        <h1>My loans</h1>
        <p class="muted">
            <?= e($borrower['name']) ?> ·
            <?= count($openLoans) ?> item<?= count($openLoans) === 1 ? '' : 's' ?> with you
        </p>
    </div>
</div>

<?php if ($overdueCount > 0): ?>
    <div class="flash flash-error">
        <span class="flash-text">
            <?= (int) $overdueCount ?> item<?= $overdueCount === 1 ? ' is' : 's are' ?> past the due-back date.
            Please return <?= $overdueCount === 1 ? 'it' : 'them' ?> as soon as you can.
        </span>
    </div>
<?php endif; ?>

<?php if ($openLoans === []): ?>
    <div class="card empty-state">
        <h2>Nothing out at the moment</h2>
        <p class="muted">When something is booked out to you it will appear here, with its due-back date.</p>
    </div>
<?php else: ?>
    <ul class="my-loans">
        <?php foreach ($openLoans as $loan): ?>
            <?php
            $overdue = $loan['effective_status'] === 'Overdue';
            $days    = (int) $loan['days_until_due'];
            $photo   = $photos[(int) $loan['asset_id']] ?? null;
            ?>
            <li class="my-loan <?= $overdue ? 'is-overdue' : '' ?>">
                <a class="my-loan-link" href="<?= e(url('/my-loans/' . $loan['id'])) ?>">
                    <span class="my-loan-media">
                        <?php if ($photo !== null): ?>
                            <img src="<?= e(url('/my-loans/' . $loan['id'] . '/photo?size=thumb')) ?>"
                                 alt="" loading="lazy" decoding="async">
                        <?php else: ?>
                            <span class="my-loan-media-empty" aria-hidden="true"></span>
                        <?php endif; ?>
                    </span>

                    <span class="my-loan-body">
                        <span class="my-loan-tag mono"><?= e($loan['asset_tag']) ?></span>
                        <span class="my-loan-name"><?= e($loan['asset_name']) ?></span>

                        <span class="my-loan-dates">
                            <span>Taken out <?= e(format_date($loan['checked_out_at'])) ?></span>
                            <span class="my-loan-due <?= $overdue ? 'is-overdue' : '' ?>">
                                Due back <?= e(format_date($loan['due_back_date'])) ?>
                                <?php if ($overdue): ?>
                                    — <?= abs($days) ?> day<?= abs($days) === 1 ? '' : 's' ?> overdue
                                <?php elseif ($days === 0): ?>
                                    — today
                                <?php else: ?>
                                    — in <?= (int) $days ?> day<?= $days === 1 ? '' : 's' ?>
                                <?php endif; ?>
                            </span>
                        </span>
                    </span>

                    <span class="my-loan-chevron" aria-hidden="true">›</span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php if ($pastLoans !== []): ?>
    <h2 class="section-title">Recently returned</h2>
    <div class="table-wrap">
        <table class="table table-compact">
            <thead>
            <tr>
                <th scope="col">Item</th>
                <th scope="col">Taken out</th>
                <th scope="col">Returned</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($pastLoans as $loan): ?>
                <tr>
                    <td>
                        <span class="mono"><?= e($loan['asset_tag']) ?></span>
                        <div class="cell-sub"><?= e(str_limit((string) $loan['asset_name'], 40)) ?></div>
                    </td>
                    <td class="nowrap"><?= e(format_date($loan['checked_out_at'])) ?></td>
                    <td class="nowrap"><?= e(format_date($loan['returned_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
