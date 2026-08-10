<?php
/**
 * @var array<string,mixed> $user
 * @var string|null $feedUrl
 * @var array<int,string> $contents
 */
?>
<div class="page-head">
    <div>
        <h1>Calendar feed</h1>
        <p class="muted">
            Subscribe your own calendar to the dates that matter to you. It updates itself —
            there is nothing to import again when something changes.
        </p>
    </div>
    <div class="head-actions">
        <a class="btn btn-ghost" href="<?= e(url('/profile')) ?>">My account</a>
    </div>
</div>

<div class="card">
    <h2>What your feed contains</h2>

    <?php if ($contents === []): ?>
        <p class="empty">
            Your role does not currently give you access to any of the dates this feed can carry,
            so subscribing would show you an empty calendar.
        </p>
    <?php else: ?>
        <ul class="plain-list">
            <?php foreach ($contents as $line): ?>
                <li><?= e(ucfirst($line)) ?></li>
            <?php endforeach; ?>
        </ul>
        <p class="field-hint">
            This is decided by your role, exactly as the rest of the application is. If your
            permissions change, the feed changes with them the next time your calendar refreshes.
        </p>
    <?php endif; ?>
</div>

<?php if ($feedUrl === null): ?>
    <div class="card">
        <h2>Create your link</h2>
        <p>
            Your feed does not exist yet. Creating it gives you a private web address that your
            calendar app can subscribe to.
        </p>
        <form method="post" action="<?= e(url('/profile/calendar')) ?>">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-primary btn-lg">Create my calendar link</button>
        </form>
    </div>
<?php else: ?>
    <div class="card">
        <h2>Your link</h2>
        <p class="muted">
            Treat this like a password. Anyone who has it can see the same dates you can, without
            signing in — that is what lets a calendar app read it. Do not post it anywhere shared.
        </p>

        <div class="field">
            <label class="label" for="feed-url">Subscription address</label>
            <div class="input-with-button">
                <input class="input mono" type="text" id="feed-url" value="<?= e($feedUrl) ?>" readonly
                       data-select-on-focus spellcheck="false">
                <button type="button" class="btn btn-ghost btn-inline" data-copy="#feed-url">Copy</button>
            </div>
            <p class="field-hint">
                Created <?= e(format_datetime((string) $user['calendar_token_created_at'])) ?>.
            </p>
        </div>

        <div class="form-actions">
            <form method="post" action="<?= e(url('/profile/calendar')) ?>">
                <?= csrf_field() ?>
                <button type="submit" class="btn">Create a new link</button>
            </form>

            <form method="post" action="<?= e(url('/profile/calendar/revoke')) ?>">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-danger">Switch the feed off</button>
            </form>
        </div>

        <p class="field-hint">
            Creating a new link immediately stops the old one working. Use it if you think the
            address has been seen by someone it should not have been — then re-subscribe with the
            new one.
        </p>
    </div>

    <div class="card">
        <h2>Adding it to your calendar</h2>
        <p class="muted">
            Every common calendar app can subscribe to an address like this one. Look for
            “subscribe”, not “import” — importing takes a one-off copy that never updates.
        </p>

        <dl class="merge-fields">
            <dt>Apple Calendar (Mac)</dt>
            <dd>File → New Calendar Subscription, then paste the address.</dd>

            <dt>iPhone or iPad</dt>
            <dd>Settings → Apps → Calendar → Calendar Accounts → Add Account → Other → Add Subscribed Calendar.</dd>

            <dt>Google Calendar</dt>
            <dd>Other calendars → + → From URL, then paste the address. Google refreshes on its own schedule, often only every several hours.</dd>

            <dt>Outlook</dt>
            <dd>Add calendar → Subscribe from web, then paste the address.</dd>

            <dt>Thunderbird</dt>
            <dd>New Calendar → On the Network → iCalendar (ICS), then paste the address.</dd>
        </dl>

        <p class="field-hint">
            The feed is read-only. Editing an event in your calendar changes nothing here — dates
            come from the PAT records, maintenance schedules and hires in the application, and that
            is where they are changed.
        </p>
    </div>
<?php endif; ?>
