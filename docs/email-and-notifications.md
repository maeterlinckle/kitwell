# Email and notifications

The four kinds of scheduled reminder, the message templates, the send log, and the personal calendar feeds — all configured from Settings in the browser.

**On this page**

- [Settings → Email](#settings--email)
- [Reminders](#reminders)
- [Templates](#templates)
- [One-click sends](#one-click-sends)
- [The log](#the-log)
- [Calendar feeds](#calendar-feeds)

---

> Connecting the site to a mail server, and scheduling the run that actually
> sends the reminders, are server tasks. See
> [Administration](administration.md#outgoing-mail-and-the-reminder-schedule).

## Settings → Email

Everything here lives under **Settings → Email**, which has four tabs:
Connection, Reminders, Templates and Log. All of it needs `email.manage`, which
only the Administrator role holds by default.

**Nothing is sent until you switch it on.** A new site starts with email
disabled, no host configured and every reminder type off.

On the **Connection** tab, fill in the host, port, encryption, sign-in details
and the "from" address, tick *Send email from this application* and save. Then
press **Send test email**: if it fails, the page shows the mail server's own
error message rather than a shrug.

Most providers want STARTTLS on port 587. Port 465 is the older implicit-TLS
style. Choose *None* only for a relay on the same machine.

The SMTP password is encrypted before it is stored, so a copy of the database on
its own does not carry it. A site can keep the password out of the database
altogether, in which case this field shows as locked and says where the value is
coming from.

## Reminders

Four kinds, each switched on independently: **PAT**, **maintenance**, **hire
returns** and **faulty equipment**. The first three have a "remind this many
days before due" window; leave it at `0` to use the same window the register and
dashboard already show, so the numbers agree without being written down twice.

On this site the windows are currently set to PAT **{{setting:reminder_pat_days}}**,
maintenance **{{setting:reminder_maintenance_days}}**, and hire
returns **{{setting:reminder_hire_days}}**.

Faulty equipment has no window, because a fault has no due date — it is open
until somebody changes the asset's status. It has a repeat interval instead,
currently **{{setting:reminder_faulty_repeat_days}}**, and it goes to each
asset's responsible party rather than to the notify list. See
[Faults and the responsible party](faults.md).

- **One digest per person, not one email per item.** Forty overdue PAT items
  produce one message listing forty items.
- **An item already mentioned is skipped** until *Remind again after* has
  passed — currently **{{setting:reminder_repeat_days}}**. Crossing from "due
  soon" to "overdue" sends straight away, because that is a different message
  rather than a repeat.
- **PAT reminders include failures and never-tested items**, matching the
  "Assets needing PAT" report. An appliance that failed its last test is not
  fine merely because no retest date has arrived.
- **Recipients are re-checked against their permissions every run.** The notify
  list is a list of people, but somebody's role can change after they are added
  to it; a user who does not hold `pat.view` receives no PAT reminders. Ticking
  a box here grants nothing.
- Maintenance can also go to the person a job is **assigned to** — their own
  jobs only. Hire reminders can optionally chase the **hirer** directly.

## Templates

**Settings → Email → Templates** holds the wording of every message. Editing one
takes effect immediately; there is nothing to deploy.

Each template documents its own merge fields — `{{asset_tag}}`, `{{due_date}}`,
`{{items}}` and so on — beside the editor, with a live preview filled in with
example values. A placeholder a template does not supply is flagged when you
save and comes out blank when sent.

The shipped wording lives in the application itself and the database stores
**only what you have edited**, so *Reset to the default wording* puts a template
back exactly as it came. A single template can also be switched off without
disabling email or the reminder it belongs to.

### What a message looks like

Messages go out as HTML in a fixed layout: your logo across the top, the
content, and a footer. What you edit is the *content* — ordinary HTML, so `<p>`,
`<strong>`, `<ul>` and links all work, and the shipped wording shows the shape.
The surrounding layout is not editable: it is one design to keep right rather
than nine, and improving it improves every message at once.

**A plain-text version is always sent alongside**, generated from your content,
so a client that shows no HTML still gets a readable message — links included,
with their addresses. To write a template as plain text, untick *HTML* and it is
sent exactly as typed.

## One-click sends

| Where | Button | What it sends |
|---|---|---|
| A hirer's page | **Email hire list** | Everything currently on hire to them, to the address on their record |
| An open hire | **Email reminder** | A return reminder for that one item |

Both need `email.send` (Administrator and Manager / Staff). Neither asks for
confirmation — the address is already on file and the wording is already a
template. What you get back is the result: sent, or the exact reason not. A
manual reminder also counts against the automated schedule, so the scheduled run
does not chase the same person again tomorrow.

## The log

**Settings → Email → Log** records every message: recipient, subject, template,
the record it relates to, whether it was sent or failed, the failure reason, and
whether it came from a person or the scheduled run. A bad address or an SMTP
outage is invisible otherwise, and "the reminders stopped working three weeks
ago" is exactly the sort of thing nobody notices until it matters.

## Calendar feeds

Every user can subscribe their own calendar app to their dates: **account
menu → Calendar feed**. This is personal rather than administrative, so it is
under the user's own menu and not in Settings.

The feed carries PAT retest dates, maintenance due dates and hire due-back
dates — **filtered by what that user's role permits**, using the same permission
rules as the rest of the application. A Hirer sees only the due-back dates of
equipment they hold; nothing else appears.

Outlook, Google Calendar, Apple Calendar and Thunderbird all subscribe to an
HTTPS `.ics` address and keep it up to date on their own. Instructions for each
are on the page. Nothing in a calendar is editable — these dates come from PAT
records, maintenance schedules and hires, and the place to change one is the
application.

### The link is a credential

The address contains a 64-character random token unique to one user. A calendar
app cannot complete an interactive sign-in, so a secret in the address is the
mechanism they all support — which means anyone holding the link can read those
dates without signing in.

- **Create a new link** immediately stops the old one working. Use it if the
  address may have been seen by someone it should not have been.
- **Switch the feed off** removes it entirely.

Handing one person another person's feed address is not an administrative task,
so there is no button for it in the admin area.

---

**See also:** [Documentation index](README.md) · [Faults](faults.md) · [Maintenance](maintenance.md) · [Administration](administration.md)
