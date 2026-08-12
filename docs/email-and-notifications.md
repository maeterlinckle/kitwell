# Email and notifications

The SMTP connection, the four kinds of scheduled reminder, editable message templates, the send log, and the personal calendar feeds.

**On this page**

- [Email and reminders](#email-and-reminders)
- [Calendar feeds](#calendar-feeds)

---

## Email and reminders


Everything lives under **Settings → Email**, which has four tabs: Connection,
Reminders, Templates and Log. All of it needs `email.manage`, which only the
Administrator role holds by default.

**Nothing is sent until you switch it on.** A fresh install has email disabled,
no host configured and every reminder type off. That is deliberate: an install
that starts trying to send on day one, to a server that does not exist, just
fills the log with failures nobody asked for.

### Setting it up

1. **Settings → Email → Connection**: host, port, encryption, sign-in details
   and the "from" address. Tick *Send email from this application* and save.
2. Press **Send test email**. If it fails you get the mail server's own error
   message, not a shrug.

`install.sh` installs PHPMailer for you. If the page says it is missing — an
older install, or a machine that had no Composer at the time:

```bash
sudo /var/www/asset-register/manage.sh composer-install
```

That installs Composer first if the machine has none, then fetches PHPMailer
and fixes the file ownership. It tries the distribution's own `composer`
package before anything else; only if that does not exist does it fall back to
the official installer from getcomposer.org, checked against the SHA-384
Composer publishes. If the signature does not match, it refuses to run it.

Most providers want STARTTLS on port 587. Port 465 is the older implicit-TLS
style. Choose *None* only for a relay on the same machine.

### Where the SMTP password lives

Typed into the Settings page, it is encrypted with AES-256-GCM before being
stored, using the `APP_KEY` in `.env` (the installer generates one). A database
dump on its own is therefore useless — dumps get emailed about and copied to
laptops, and a password sitting in a `settings` row in the clear would travel
with them.

Two consequences worth knowing:

- **Back up `.env` alongside the database.** Restoring a dump without the
  matching `APP_KEY` leaves a password that cannot be decrypted. The application
  says so plainly and asks for it again rather than failing mysteriously.
- If you would rather the password never touched the database, set
  `MAIL_PASSWORD` in `.env` instead. It takes precedence, and the Settings page
  shows the field as locked and says where the value is coming from.

### Reminders

Four kinds, each switched on independently: **PAT**, **maintenance**, **hire
returns** and **faulty equipment**. The first three have a "remind this many
days before due" window; leave it at `0` to use the same window the register and
dashboard already show, so the numbers agree without being written down twice.

Faulty equipment has no window, because a fault has no due date — it is open
until somebody changes the asset's status. It has a repeat interval instead, and
it goes to each asset's responsible party rather than to the notify list. See
[Faults and the responsible party](faults.md).

- **One digest per person, not one email per item.** Forty overdue PAT items
  produce one message listing forty items. Volume is what makes people filter
  reminders into a folder, and a filtered reminder is worse than none because it
  still looks like it is working.
- **An item already mentioned is skipped** until *Remind again after* has
  passed (7 days by default). Crossing from "due soon" to "overdue" sends
  straight away, because that is a different message rather than a repeat.
- **PAT reminders include failures and never-tested items**, matching the
  "Assets needing PAT" report. An appliance that failed its last test is not
  fine merely because no retest date has arrived.
- **Recipients are re-checked against their permissions every run.** The notify
  list is a list of people, but somebody's role can change after they are added
  to it; a user who no longer holds `pat.view` stops receiving PAT reminders.
  Ticking a box here grants nothing.
- Maintenance can also go to the person a job is **assigned to** — their own
  jobs only. Hire reminders can optionally chase the **hirer** directly.

They are sent by cron, not by anyone having the site open:

```bash
sudo /var/www/asset-register/manage.sh cron-install
```

That installs a daily 08:00 run. To try it first:

```bash
sudo /var/www/asset-register/manage.sh send-reminders --dry-run
```

### Templates

**Settings → Email → Templates** holds the wording of every message. Editing one
takes effect immediately; there is nothing to deploy.

Each template documents its own merge fields — `{{asset_tag}}`, `{{due_date}}`,
`{{items}}` and so on — beside the editor, with a live preview filled in with
example values. A placeholder that a template does not supply is flagged when
you save and comes out blank when sent.

Defaults ship in the code, and the database stores **only what you have
edited**. So a fresh install sends properly worded mail with an empty table, and
*Reset to the default wording* is a deletion rather than a re-seed — it cannot
go stale. A single template can also be switched off without disabling email or
the reminder it belongs to.

#### What a message looks like

Messages go out as HTML in a fixed layout: your logo across the top, the
content, and a footer. What you edit is the *content* — ordinary HTML, so
`<p>`, `<strong>`, `<ul>` and links all work, and the shipped wording shows the
shape. The surrounding layout is not editable on purpose: it is one design to
keep right rather than nine, and improving it improves every message at once.

**A plain-text version is always sent alongside**, generated from your content,
so a client that shows no HTML still gets a readable message — links included,
with their addresses. If you rewrite a template as plain text, untick *HTML* and
it is sent exactly as typed.

### One-click sends

| Where | Button | What it sends |
|---|---|---|
| A hirer's page | **Email hire list** | Everything currently on hire to them, to the address on their record |
| An open hire | **Email reminder** | A return reminder for that one item |

Both need `email.send` (Administrator and Manager/Staff). Neither asks for
confirmation — the address is already on file and the wording is already a
template, so a confirmation step would only be asking you to agree with
yourself. What you get back is the result: sent, or the exact reason not. A
manual reminder also counts against the automated schedule, so cron will not
chase the same person again tomorrow.

### The log

**Settings → Email → Log** records every message: recipient, subject, template,
the record it relates to, whether it was sent or failed, the failure reason, and
whether it came from a person or the scheduled run. A bad address or an SMTP
outage is invisible otherwise, and "the reminders stopped working three weeks
ago" is exactly the sort of thing nobody notices until it matters.

Trim it with `manage.sh` when it gets long:

```bash
sudo /var/www/asset-register/manage.sh mail-status
```

---

## Calendar feeds


Every user can subscribe their own calendar app to their dates: **account menu →
Calendar feed**. This is personal rather than administrative, so it is under the
user's own menu and not in Settings.

The feed carries PAT retest dates, maintenance due dates and hire due-back
dates — **filtered by what that user's role permits**, using the same permission
rules as the rest of the application rather than a second access model. A Hirer
sees only the due-back dates of equipment they hold; nothing else appears.

### Why iCalendar and not CalDAV

CalDAV is a WebDAV extension with `PROPFIND`, `REPORT`, ctag/etag
synchronisation and a two-way write path. All of that exists so clients can
*change* events. Nothing here is editable from a calendar: these dates are
derived from PAT records, maintenance schedules and hires, and the only sensible
place to change one is in the application.

What is actually wanted is "add it to my calendar and let it keep up", and
Outlook, Google Calendar, Apple Calendar and Thunderbird all do that by
subscribing to an HTTPS `.ics` URL. So that is what this is: a few hundred lines
instead of a WebDAV server, no write surface to secure, and the same result for
the user. Instructions for each client are on the page.

### The link is a credential

The URL contains a 64-character random token unique to one user. A calendar app
cannot complete an interactive sign-in, so a secret in the URL is the mechanism
they all support — which means anyone holding the link can read those dates
without signing in.

- **Create a new link** immediately stops the old one working. Use it if the
  address may have been seen by someone it should not have been.
- **Switch the feed off** removes it entirely.
- An administrator can look up a link for support purposes with
  `manage.sh calendar-url jo@example.com` — a shell command that writes an audit
  entry, not a button in the admin area. Handing one person another's feed URL
  is not an administrative task.

Set `APP_URL` in `.env`. The address a user pastes into their phone has to work
from outside, and the host header on the request they happen to be looking at
may be an internal name.

---

---

**See also:** [Documentation index](README.md) · [Faults](faults.md) · [Maintenance](maintenance.md) · [Administration](administration.md)
