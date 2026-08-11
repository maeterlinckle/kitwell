<?php
/**
 * Quick scan.
 *
 * @var string $mode  view | checkout | return | maintenance
 */
$titles = [
    'view'        => 'Scan an asset',
    'checkout'    => 'Scan to check out',
    'return'      => 'Scan to book in',
    'maintenance' => 'Scan to record maintenance',
];

$blurbs = [
    'view'        => 'Scan or type an asset tag to jump straight to it.',
    'checkout'    => 'Scan the item going out. You will be taken straight to the checkout form.',
    'return'      => 'Scan the item coming back. You will be taken straight to its return form.',
    'maintenance' => 'Scan the item you have worked on. You will be taken straight to the record form.',
];
?>
<div class="page-head">
    <div>
        <h1><?= e($titles[$mode]) ?></h1>
        <p class="muted"><?= e($blurbs[$mode]) ?></p>
    </div>
    <div class="head-actions">
        <?php foreach (['view' => 'Look up', 'checkout' => 'Check out', 'return' => 'Book in', 'maintenance' => 'Record work'] as $option => $label): ?>
            <?php
            if ($option === 'checkout' && !can('hires.create')) {
                continue;
            }
            if ($option === 'return' && !can('hires.return')) {
                continue;
            }
            if ($option === 'maintenance' && !can('maintenance.complete')) {
                continue;
            }
            ?>
            <a class="btn btn-sm <?= $mode === $option ? 'btn-primary' : '' ?>"
               href="<?= e(url('/scan?mode=' . $option)) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>
</div>

<div class="scan-page" data-scanner data-scan-mode="<?= e($mode) ?>" data-lookup-url="<?= e(url('/scan/lookup')) ?>">
    <div class="card scan-entry">
        <form method="post" action="<?= e(url('/scan')) ?>" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="mode" value="<?= e($mode) ?>">

            <div class="field">
                <label class="label" for="code">Asset tag</label>
                <div class="input-with-button">
                    <input class="input input-scan mono" type="text" id="code" name="code"
                           autocomplete="off" autocapitalize="characters" spellcheck="false"
                           enterkeyhint="go" placeholder="Scan, or type e.g. AST-0001"
                           data-scan-input>
                    <button type="submit" class="btn btn-primary">Go</button>
                </div>
                <p class="field-hint">
                    A USB barcode scanner works here with no setup — it types the code and presses Enter.
                    This box keeps the focus so you can scan one item after another.
                </p>
            </div>
        </form>
    </div>

    <div class="card scan-camera">
        <div class="card-head">
            <h2>Camera</h2>
            <div class="head-actions">
                <button type="button" class="btn btn-primary" data-scan-start>Start camera</button>
                <button type="button" class="btn" data-scan-stop hidden>Stop</button>
            </div>
        </div>

        <div class="scan-viewport">
            <video data-scan-video muted playsinline></video>
            <div class="scan-reticle" aria-hidden="true"></div>
        </div>

        <p class="scan-status" data-scan-status>The camera is off. Start it, or use the box above.</p>

        <p class="field-hint">
            Reads Code 128 — the labels this system prints — as well as Code 39 and QR codes, so a
            tag that came with the equipment usually works too. Uses your browser's built-in reader
            where it has one, and its own where it does not (Safari and iPhones). If a code will not
            read, type it instead; nothing is lost.
        </p>
    </div>

    <div class="card scan-result" data-scan-result hidden></div>
</div>

<?php /* barcode.js first: scanner.js uses its decoder, and deferred scripts run
         in document order. */ ?>
<script src="<?= e(asset_url('js/barcode.js')) ?>" defer></script>
<script src="<?= e(asset_url('js/scanner.js')) ?>" defer></script>
