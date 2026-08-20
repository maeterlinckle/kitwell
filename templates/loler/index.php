<?php

use App\Models\LolerExamination;

/**
 * Every LOLER report of thorough examination on record.
 *
 * @var array{rows:array<int,array<string,mixed>>,total:int,page:int,pages:int,per_page:int} $result
 * @var array<string,mixed> $filters
 * @var array<int,array<string,mixed>> $examiners
 */
$rows = $result['rows'];
?>
<div class="page-head">
    <div>
        <h1>LOLER examinations</h1>
        <p class="muted">
            <?= number_format($result['total']) ?> report<?= $result['total'] === 1 ? '' : 's' ?> of thorough
            examination under LOLER 1998 regulation 9.
        </p>
    </div>
</div>

<form method="get" action="<?= e(url('/loler')) ?>" class="filter-bar">
    <div class="field">
        <label class="label" for="q">Search</label>
        <input class="input" type="search" id="q" name="q" value="<?= e((string) $filters['q']) ?>"
               placeholder="Tag, name, serial, examiner">
    </div>

    <div class="field">
        <label class="label" for="outcome">Outcome</label>
        <select class="input" id="outcome" name="outcome">
            <option value="">Any</option>
            <option value="none" <?= $filters['outcome'] === 'none' ? 'selected' : '' ?>>No defects</option>
            <option value="defects" <?= $filters['outcome'] === 'defects' ? 'selected' : '' ?>>Defects found</option>
        </select>
    </div>

    <div class="field">
        <label class="label" for="examiner">Examiner</label>
        <select class="input" id="examiner" name="examiner">
            <option value="">Anyone</option>
            <?php foreach ($examiners as $examiner): ?>
                <option value="<?= (int) $examiner['id'] ?>"
                    <?= (string) $filters['examiner'] === (string) $examiner['id'] ? 'selected' : '' ?>>
                    <?= e($examiner['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="field">
        <label class="label" for="from">From</label>
        <input class="input" type="date" id="from" name="from" value="<?= e((string) $filters['from']) ?>">
    </div>

    <div class="field">
        <label class="label" for="to">To</label>
        <input class="input" type="date" id="to" name="to" value="<?= e((string) $filters['to']) ?>">
    </div>

    <div class="field field-aligned">
        <button type="submit" class="btn btn-primary">Filter</button>
    </div>
</form>

<div class="card">
    <?php if ($rows === []): ?>
        <p class="empty muted">No examinations match.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Asset</th>
                        <th>Type</th>
                        <th>Examined</th>
                        <th>Outcome</th>
                        <th>Examiner</th>
                        <th>Next by</th>
                        <th class="actions"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $examination): ?>
                        <?php
                        $danger  = (int) $examination['danger_count'] > 0;
                        $overdue = (string) $examination['next_examination_date'] < date('Y-m-d');
                        ?>
                        <tr>
                            <td>
                                <a href="<?= e(url('/assets/' . (int) $examination['asset_id'])) ?>">
                                    <span class="mono"><?= e($examination['asset_tag']) ?></span>
                                </a>
                                <div class="cell-sub"><?= e(str_limit((string) $examination['asset_name'], 44)) ?></div>
                            </td>
                            <td><?= e(LolerExamination::typeLabel((string) $examination['loler_type'])) ?></td>
                            <td class="nowrap"><?= e(format_date((string) $examination['examined_on'])) ?></td>
                            <td>
                                <span class="badge <?= $danger ? 'badge-danger' : ((int) $examination['defect_count'] > 0 ? 'badge-warn' : 'badge-ok') ?>">
                                    <?= e(LolerExamination::verdict($examination)) ?>
                                </span>
                            </td>
                            <td><?= e($examination['examiner_name']) ?></td>
                            <td class="nowrap">
                                <?= e(format_date((string) $examination['next_examination_date'])) ?>
                                <?php if ($overdue): ?>
                                    <div class="cell-sub">Overdue</div>
                                <?php endif; ?>
                            </td>
                            <td class="actions">
                                <a class="btn btn-sm" href="<?= e(url('/loler/' . (int) $examination['id'])) ?>">View</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php if ($result['pages'] > 1): ?>
    <nav class="pagination" aria-label="Pages">
        <?php
        $params = array_filter([
            'q'        => $filters['q'],
            'outcome'  => $filters['outcome'],
            'examiner' => $filters['examiner'],
            'from'     => $filters['from'],
            'to'       => $filters['to'],
        ], static fn ($v): bool => $v !== '');
        $query = http_build_query($params);
        ?>
        <?php for ($page = 1; $page <= $result['pages']; $page++): ?>
            <a class="btn btn-sm <?= $page === $result['page'] ? 'btn-primary' : '' ?>"
               href="<?= e(url('/loler?' . ($query === '' ? '' : $query . '&') . 'page=' . $page)) ?>"><?= (int) $page ?></a>
        <?php endfor; ?>
    </nav>
<?php endif; ?>
