<?php
/**
 * Product branding, on every page.
 *
 * The *product* name rather than `app.name`: an instance can call itself
 * whatever the workshop likes, but the thing in the footer is what the software
 * is and who made it.
 */
?>
<footer class="site-footer">
    <div class="container footer-inner">
        <div class="footer-brand">
            <span><?= e(config('app.full_name', 'Kitwell by Junction')) ?></span>
            <span class="muted">
                <?= e(config('app.product_tagline', 'Asset Management')) ?> ·
                <a href="<?= e(config('app.vendor_url', 'https://www.junctioninc.co.uk/')) ?>"
                   target="_blank" rel="noopener noreferrer"><?= e(config('app.vendor', 'Junction Inc Ltd')) ?></a>
            </span>
        </div>

        <?php if (auth_user() !== null): ?>
            <span class="muted">Signed in as <?= e(auth_user()['name']) ?> · <?= e(auth_user()['role_name']) ?></span>
        <?php endif; ?>
    </div>
</footer>
