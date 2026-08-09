<?php

use App\Core\Flash;

$messages = Flash::messages();

if ($messages === []) {
    return;
}
?>
<div class="flash-stack" role="status" aria-live="polite">
    <?php foreach ($messages as $message): ?>
        <div class="flash flash-<?= e($message['type']) ?>">
            <span class="flash-text"><?= e($message['message']) ?></span>
            <button type="button" class="flash-close" data-dismiss aria-label="Dismiss">&times;</button>
        </div>
    <?php endforeach; ?>
</div>
