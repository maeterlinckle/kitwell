<?php

/**
 * The API, readable and runnable.
 *
 * The page is a shell; everything in it is drawn from the generated OpenAPI
 * document by public/js/api-docs.js. That is deliberate — the spec and the
 * endpoints are built from the same declarations, so a page rendered from the
 * spec cannot describe an API that is not there.
 *
 * Swagger UI and Redoc were the obvious answers and both were rejected: the
 * Content-Security-Policy here is `default-src 'self'` with no off-origin
 * scripts, so a CDN build simply would not load, and vendoring a megabyte of
 * somebody else's JavaScript into a repository that hand-writes its own barcode
 * and QR encoders would be out of character. This is about two hundred lines.
 *
 * @var string $specUrl
 * @var string $baseUrl
 * @var bool   $canManage
 */
?>
<div class="page-head">
    <div>
        <h1>API</h1>
        <p class="muted">
            Generated from the running application, so it describes what is actually there.
            Base address <span class="mono"><?= e($baseUrl) ?></span>.
        </p>
    </div>
    <div class="head-actions">
        <a class="btn" href="<?= e($specUrl) ?>">openapi.json</a>
        <?php if ($canManage): ?>
            <a class="btn btn-primary" href="<?= e(url('/admin/api')) ?>">Manage keys</a>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <h2>Trying it out</h2>
    <p>
        <strong>You are signed in, so the “Send” buttons below work straight away</strong> — a browser
        session may read from the API without a key. It may only read: anything that writes needs a key,
        so a stray page cannot be made to change your register.
    </p>
    <p>
        For a script, issue a key<?= $canManage ? ' under <a href="' . e(url('/admin/api')) . '">Settings → API keys</a>' : '' ?>
        and send it as a bearer token:
    </p>
    <pre class="mono code-block">curl -H "Authorization: Bearer ark_your_key_here" \
     "<?= e($baseUrl) ?>/assets?status[]=In+Stock&per_page=10"</pre>
    <div class="field">
        <label class="label" for="api-key-input">Use a key for the requests on this page <span class="optional">(optional)</span></label>
        <input class="input mono" type="password" id="api-key-input" autocomplete="off" spellcheck="false"
               placeholder="ark_…  — leave blank to use your signed-in session">
        <p class="field-hint">
            Kept in this tab only, never sent anywhere but this application, and gone when you close it.
        </p>
    </div>
</div>

<div id="api-docs" data-spec-url="<?= e($specUrl) ?>">
    <div class="card">
        <p class="muted">Loading the specification…</p>
    </div>
</div>

<script src="<?= e(asset_url('js/api-docs.js')) ?>" defer></script>
