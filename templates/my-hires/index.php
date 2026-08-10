<?php
/**
 * The hirer's own view: what they have out, and when it is due.
 *
 * @var array<string,mixed> $hirer
 * @var array<int,array<string,mixed>> $openHires
 * @var array<int,array<string,mixed>> $pastHires
 * @var array<int,array<string,mixed>> $photos
 */
$overdueCount = count(array_filter($openHires, static fn (array $l): bool => $l['effective_status'] === 'Overdue'));
?>
<div class="page-head">
    <div>
        <h1>My hires</h1>
        <p class="muted">
            <?= e($hirer['name']) ?> ·
            <?= count($openHires) ?> item<?= count($openHires) === 1 ? '' : 's' ?> with you
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

<?php if ($openHires === []): ?>
    <div class="card empty-state">
        <h2>Nothing out at the moment</h2>
        <p class="muted">When something is booked out to you it will appear here, with its due-back date.</p>
    </div>
<?php else: ?>
    <ul class="my-hires">
        <?php foreach ($openHires as $hire): ?>
            <?php
            $overdue = $hire['effective_status'] === 'Overdue';
            $days    = (int) $hire['days_until_due'];
            $photo   = $photos[(int) $hire['asset_id']] ?? null;
            ?>
            <li class="my-hire <?= $overdue ? 'is-overdue' : '' ?>">
                <a class="my-hire-link" href="<?= e(url('/my-hires/' . $hire['id'])) ?>">
                    <span class="my-hire-media">
                        <?php if ($photo !== null): ?>
                            <img src="<?= e(url('/my-hires/' . $hire['id'] . '/photo?size=thumb')) ?>"
                                 alt="" loading="lazy" decoding="async">
                        <?php else: ?>
                            <span class="my-hire-media-empty" aria-hidden="true"></span>
                        <?php endif; ?>
                    </span>

                    <span class="my-hire-body">
                        <span class="my-hire-tag mono"><?= e($hire['asset_tag']) ?></span>
                        <span class="my-hire-name"><?= e($hire['asset_name']) ?></span>

                        <span class="my-hire-dates">
                            <span>Taken out <?= e(format_date($hire['checked_out_at'])) ?></span>
                            <span class="my-hire-due <?= $overdue ? 'is-overdue' : '' ?>">
                                Due back <?= e(format_date($hire['due_back_date'])) ?>
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

                    <span class="my-hire-chevron" aria-hidden="true">›</span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php if ($pastHires !== []): ?>
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
            <?php foreach ($pastHires as $hire): ?>
                <tr>
                    <td>
                        <span class="mono"><?= e($hire['asset_tag']) ?></span>
                        <div class="cell-sub"><?= e(str_limit((string) $hire['asset_name'], 40)) ?></div>
                    </td>
                    <td class="nowrap"><?= e(format_date($hire['checked_out_at'])) ?></td>
                    <td class="nowrap"><?= e(format_date($hire['returned_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
