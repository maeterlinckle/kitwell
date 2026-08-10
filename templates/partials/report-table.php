<?php

use App\Reports\Report;

/**
 * Generic report table, driven entirely by the report's column declarations.
 *
 * @var Report $report
 * @var array<int,array<string,mixed>> $rows
 * @var bool $linked  Render links (off for the print view)
 */
$columns = $report->columns();
$linked  = $linked ?? true;

/** Build the href for a linked cell, if the row carries the id it needs. */
$hrefFor = static function (string $link, array $row): ?string {
    $id = match ($link) {
        'asset'       => $row['asset_id'] ?? $row['id'] ?? null,
        'hire'        => $row['hire_id'] ?? $row['id'] ?? null,
        'maintenance' => $row['schedule_id'] ?? $row['id'] ?? null,
        'hirer'    => $row['hirer_id'] ?? null,
        default       => null,
    };

    if ($id === null || (int) $id <= 0) {
        return null;
    }

    return match ($link) {
        'asset'       => url('/assets/' . (int) $id),
        'hire'        => url('/hires/' . (int) $id),
        'maintenance' => url('/maintenance/' . (int) $id),
        'hirer'    => url('/hirers/' . (int) $id),
        default       => null,
    };
};

/** Format one cell according to its declared type. */
$formatCell = static function (mixed $value, array $definition): string {
    $type = (string) ($definition['type'] ?? 'text');

    if ($type === 'bool') {
        return ((int) $value === 1) ? 'Yes' : 'No';
    }

    if ($value === null || $value === '') {
        return '—';
    }

    return match ($type) {
        'date'     => format_date((string) $value),
        'datetime' => format_datetime((string) $value),
        'money'    => format_money($value),
        'number'   => number_format((float) $value),
        default    => (string) $value,
    };
};
?>
<div class="table-wrap">
    <table class="table table-report">
        <thead>
        <tr>
            <?php foreach ($columns as $definition): ?>
                <th scope="col" class="<?= ($definition['align'] ?? '') === 'right' ? 'align-right' : '' ?>">
                    <?= e($definition['label']) ?>
                </th>
            <?php endforeach; ?>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <?php foreach ($columns as $columnKey => $definition): ?>
                    <?php
                    $raw     = $row[$columnKey] ?? null;
                    $display = $formatCell($raw, $definition);
                    $href    = ($linked && isset($definition['link'])) ? $hrefFor((string) $definition['link'], $row) : null;
                    $sub     = isset($definition['sub']) ? trim((string) ($row[$definition['sub']] ?? '')) : '';
                    ?>
                    <td class="<?= ($definition['align'] ?? '') === 'right' ? 'align-right' : '' ?>">
                        <?php if (isset($definition['badge'])): ?>
                            <span class="badge <?= e($definition['badge'] . strtolower(str_replace(' ', '-', (string) $raw))) ?>">
                                <?= e($display) ?>
                            </span>
                        <?php elseif ($href !== null): ?>
                            <a href="<?= e($href) ?>" class="<?= $columnKey === 'asset_tag' || $columnKey === 'reference' ? 'mono' : '' ?>">
                                <?= e($display) ?>
                            </a>
                        <?php else: ?>
                            <?= e($display) ?>
                        <?php endif; ?>

                        <?php if ($sub !== ''): ?>
                            <div class="cell-sub"><?= e($sub) ?></div>
                        <?php endif; ?>
                    </td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
