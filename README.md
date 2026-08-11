# Asset Register

Self-hosted asset register for a workshop/office: assets and sub-assets, condition
photos, PDF manuals, maintenance scheduling, PAT (Portable Appliance Testing)
records, and hire tracking — usable on a phone in the workshop as well as on
a desktop.

PHP 8.1+ and MariaDB 10.4+, no framework. Server-rendered PHP templates
and vanilla JS: no build step, nothing to compile, deployable by copying files.

## What it does

- **Assets** — register with tags/barcodes, sub-assets and accessories,
  categories, locations, condition and status, purchase and electrical detail
  (plug fuse rating in A, cable CSA in mm²), PDF manuals, and printable Code 128
  labels. Copy an asset into a batch, or push chosen fields across a group.
- **Condition photos** — a dated visual history per asset, straightened and
  resized on upload.
- **Maintenance** — routine, periodic and one-off schedules, completions with
  parts, cost and photos, and a clear view of what is overdue or coming up.
- **PAT testing** — full test history per asset with every reading and its
  unit, and an at-a-glance status that treats a failure as a failure.
- **Hires** — check out by barcode scan or by hand, due dates, returns
  with condition notes and photos, and no way to double-book an item.
- **Hirer self-service** — a hirer signs in and sees only what they hold.
- **Reports** — five built in, each filterable, printable and exportable.
- **CSV** — import an existing register or a contractor's PAT results with a
  preview before anything is written; export what you are looking at.
- **Email** — reminders for PAT, maintenance and hire returns on a schedule you
  choose, sent through your own SMTP server; one-click "email this hirer their
  hire list"; editable templates; and a log of every message, sent or failed.
- **Calendar** — each user can subscribe their own calendar app to the dates
  their role lets them see.

---

## Contents

- [Requirements](#requirements)
- [Installation](#installation) — [scripted](#the-scripted-install) or [by hand](#installing-by-hand)
- [Administration: manage.sh](#administration-managesh)
- [Web server configuration](#web-server-configuration)
- [Upgrading](#upgrading)
- [Backups](#backups)
- [Roles and permissions](#roles-and-permissions)
- [Email and reminders](#email-and-reminders)
- [Calendar feeds](#calendar-feeds)
- [Using the asset register](#using-the-asset-register)
- [CSV formats](#csv-import-and-export)
- [Security notes](#security-notes)
- [Troubleshooting](#troubleshooting)
- [Development](#development)

---

## Requirements

| Component | Version | Notes |
|-----------|---------|-------|
| PHP       | 8.1 or newer | Required: `pdo_mysql`, `mbstring`, `fileinfo`, `json`. Recommended: `gd` and `exif` for photo resizing and orientation |
| MariaDB   | 10.4 or newer | InnoDB, `utf8mb4`. Developed and tested against MariaDB 12.3 |
| Web server| Apache with `mod_rewrite`, or nginx | Document root must point at `public/` |
| Composer  | Installed for you | `install.sh` installs it (distribution package first, otherwise the official signature-checked installer) and uses it to fetch PHPMailer. Without it everything works except sending mail |
| PHP `openssl` | Needed for email | Encrypts the stored SMTP password. Present in almost every PHP build |
| HTTPS     | Required in production | TLS is expected to terminate at a reverse proxy |
| SMTP server | Optional | Any host you can send through. Email is off until it is configured |

> **On the `mysql` naming:** the PHP extension is `pdo_mysql` and the PDO DSN
> starts `mysql:`. Those are the names of PHP's extension and PDO's driver, and
> they are correct for MariaDB — there is no `pdo_mariadb` driver. Everything
> else in this project targets MariaDB.
---

## Installation

There are two routes. They produce the same thing.

### The scripted install

On a Linux server, `install.sh` does the whole of the next section for you:
finds or installs Apache, PHP 8.1+ and MariaDB, creates the database and its
user, writes `.env` with a generated password, sets the file permissions,
configures the web server, runs the migrations and creates your administrator.

```bash
sudo ./install.sh
```

Add `--dry-run` to see the plan without changing anything, or
`--answers=FILE --non-interactive` to run it unattended. Full detail — the
HTTPS choice, unattended installs, what to do when a step fails — is in
**[INSTALL.md](INSTALL.md)**.

Re-running it over an existing install is an upgrade: it refreshes the files
and re-runs the migrations, and leaves `.env`, `storage/` and the database
alone.

### Installing by hand

Follow these eight steps on any platform, or when you want to know exactly what
the script did.

### 1. Get the files onto the server

```bash
git clone https://github.com/maeterlinckle/kitwell.git /var/www/asset-register
cd /var/www/asset-register
```

If the server has no outbound git access, `manage.sh package` on an existing
install (or a plain tarball of a checkout) works just as well — see
[INSTALL.md](INSTALL.md).

Composer is optional — the app ships with a fallback autoloader — but running it
gives you a faster classmap:

```bash
composer install --no-dev --optimize-autoloader
```

### 2. Create the database and a dedicated user

Connect as an administrative user (`mariadb -u root -p`, or `mysql -u root -p`
on older installs — same client) and run:

```sql
CREATE DATABASE asset_register CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'asset_register'@'localhost' IDENTIFIED BY 'a-long-random-password';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, REFERENCES ON asset_register.* TO 'asset_register'@'localhost';
FLUSH PRIVILEGES;
```

`DROP` is needed by the **migrations**, not by the running application: MariaDB
requires `ALTER` *and* `DROP` on the source table of a `RENAME TABLE`, which
migration 017 uses. Without it an upgrade stops with
`ERROR 1142: DROP command denied`.

Withholding it bought less than it appeared to — the same user already holds
`DELETE` (empty every table) and `ALTER` (drop any column or index), so it
blocked one verb while leaving equivalent damage available two other ways.

What is still withheld is the part that matters: **no `GRANT OPTION`, no
`CREATE USER`, no `FILE`, no `SUPER`, no `PROCESS`, and no rights on any other
database.** A compromise stays inside this one schema.

> **Upgrading an install made before 2026-08-11?** It has the older grant.
> `sudo ./manage.sh db-grant` repairs it, or run the `GRANT` above again.
> `manage.sh doctor` now checks for this.

### 3. Configure the environment

Credentials live in `.env`, never in the code:

```bash
cp .env.example .env
composer install --no-dev --optimize-autoloader   # installs PHPMailer
php bin/console.php key:generate                  # prints the APP_KEY line
```

Composer is only needed for outbound email. Skip it and everything else still
works — the application falls back to its own autoloader, and Settings → Email
tells you what to run.

Then edit `.env`. The settings that matter most:

| Key | Purpose |
|-----|---------|
| `APP_URL` | Full public URL, e.g. `https://assets.example.com` |
| `APP_ENV` / `APP_DEBUG` | Use `production` / `false` on a live server |
| `APP_KEY` | Encrypts the SMTP password in the database. Generate with `php bin/console.php key:generate`. Back it up with the database |
| `DB_*` | Database host, name, user, password |
| `MAIL_PASSWORD` | Optional. Set it to keep the SMTP password out of the database entirely; it then overrides the Settings page |
| `FORCE_HTTPS` | `true` redirects HTTP → HTTPS and sets the `Secure` cookie flag |
| `TRUST_PROXY` | `true` when behind a reverse proxy, so `X-Forwarded-Proto`/`-For` are honoured |
| `LOGIN_MAX_ATTEMPTS`, `LOGIN_DECAY_MINUTES`, `LOGIN_LOCKOUT_MINUTES` | Failed-login throttling |
| `UPLOAD_MAX_PHOTO_MB`, `UPLOAD_MAX_PDF_MB` | Upload ceilings (also raise `upload_max_filesize`/`post_max_size` in PHP if you increase these) |

`.env` should be readable only by the web server user:

```bash
chmod 640 .env && chown root:www-data .env
```

### 4. Run the migrations

Migrations are plain `.sql` files in `database/migrations/`, applied in filename
order and recorded in a `migrations` table, so re-running is safe.

```bash
php bin/migrate.php
php bin/migrate.php --status   # see what is applied and what is pending
```

To add a migration later, drop a new numbered file into `database/migrations/`
(e.g. `009_add_something.sql`) and run `php bin/migrate.php` again.

### 5. Create the first administrator

```bash
php bin/create-admin.php
```

It prompts for name, email and password (minimum 12 characters; the password is
never passed on the command line so it stays out of shell history). To script it
non-interactively, or to create a user with a different role:

```bash
php bin/create-admin.php --name="Jo Bloggs" --email=jo@example.com --role=manager
```

Re-running it for an existing email offers to reset that user's password —
that's the recovery route if an admin locks themselves out.

### 6. Optional: load demo data

```bash
php bin/seed.php
```

Adds sample categories, locations, ten assets (including a sub-assembly and an
accessory), maintenance schedules, PAT history, hirers and hires, plus one
user per role. Demo accounts all use the password `Workshop!Demo2026`:

| Email | Role |
|-------|------|
| `admin@example.com` | Administrator |
| `manager@example.com` | Manager / Staff |
| `viewer@example.com` | Read-only |
| `hirer@example.com` | Hirer |

The script refuses to run when `APP_ENV=production` unless you pass `--force`.
**Delete or deactivate these accounts before real use.**

### 7. Permissions on disk

```bash
chown -R www-data:www-data storage
chmod -R 775 storage
```

`storage/` holds uploads and logs and sits outside the document root.

### 8. Check it over

Visit `/health` — it returns JSON and a 200 (or 503 if the database is
unreachable), which is also what to point an uptime monitor at.

Then sign in and confirm:

- **Settings** — set the organisation name (it prints on labels), the asset tag
  prefix, and the "due soon" windows for maintenance and PAT.
- **Categories** and **Locations** — add the ones you use, or let the CSV
  import create them.
- Print one barcode label at 100% scale and check it scans before doing a batch.

---

## Administration: manage.sh

Once installed, `manage.sh` is the front door for everything an administrator
does from the server. The installer links it onto `PATH`, so this works from
anywhere:

```bash
sudo asset-register help
```

> **Run the installed copy, not the one in your checkout.** `manage.sh` travels
> with the source, so `~/kitwell/manage.sh` exists too — but it manages nothing,
> because a checkout has no `.env`, and the web server user usually cannot even
> read a directory under `/root`. Run it from the install directory (or use the
> `asset-register` link above). It will tell you, and point at the real install,
> if you get this wrong.

The full path always works too:

```bash
sudo /var/www/asset-register/manage.sh help
```

| Task | Command |
|------|---------|
| Reset a password and lift the lockout | `manage.sh reset-password jo@example.com` |
| Clear a lockout on its own | `manage.sh unlock jo@example.com` |
| Add a user | `manage.sh create-user manager` |
| Disable a leaver | `manage.sh deactivate jo@example.com` |
| Check the install over | `manage.sh doctor` |
| Services, versions, disk, migrations | `manage.sh status` |
| Back up the database and uploads | `manage.sh backup` |
| Restore from a backup | `manage.sh restore dump.sql.gz uploads.tar.gz` |
| Apply a new version | `manage.sh update /path/to/new` |
| Re-apply file ownership and modes | `manage.sh permissions` |
| Read the application log | `manage.sh logs -n 100` |
| Change one value in `.env` | `manage.sh config FORCE_HTTPS false` |
| Trim the audit trail | `manage.sh prune-activity 730` |
| Schedule backups and reminder emails | `manage.sh cron-install` |
| Check the mail setup | `manage.sh mail-status` |
| Send a test message | `manage.sh mail-test you@example.com` |
| Run the reminders now | `manage.sh send-reminders --dry-run` |
| Find a user's calendar link | `manage.sh calendar-url jo@example.com` |
| Re-run the shipped audits | `manage.sh audit` |

Anything that touches the database goes through `bin/console.php`, which uses
the application's own models — the same prepared statements, the same
"the last active administrator cannot be removed" rule, and the same audit-log
entries as the web interface. Passwords are typed twice with the terminal echo
off and are never accepted as a command-line argument.

`bin/console.php` can be used on its own and does not need root:

```bash
cd /var/www/asset-register && sudo -u www-data php bin/console.php doctor
```

`doctor` is worth knowing: it checks the PHP version and extensions, whether
`.env` is readable by anyone it should not be, PHP's upload limits against the
application's, the storage directories, the database connection and collation,
pending migrations, and whether an active administrator still exists.

---

## Web server configuration

The document root **must** be `public/`. Everything else — `src/`, `.env`,
`storage/`, `database/` — then sits outside the web-reachable tree.

### Apache

`public/.htaccess` handles rewriting and the HTTPS redirect. The vhost needs:

```apache
<VirtualHost *:80>
    ServerName assets.example.com
    DocumentRoot /var/www/asset-register/public

    <Directory /var/www/asset-register/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

### nginx

```nginx
server {
    listen 443 ssl http2;
    server_name assets.example.com;
    root /var/www/asset-register/public;
    index index.php;

    client_max_body_size 25m;   # match UPLOAD_MAX_PDF_MB

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param HTTPS on;
    }

    location ~ /\. { deny all; }
}

server {
    listen 80;
    server_name assets.example.com;
    return 301 https://$host$request_uri;
}
```

### Behind a reverse proxy

This is the expected deployment: TLS terminates at the proxy and the
application speaks plain HTTP behind it.

The proxy must forward:

| Header | Why |
|--------|-----|
| `X-Forwarded-Proto: https` | Without it the app thinks the request is plain HTTP, drops the `Secure` cookie flag and can redirect in a loop |
| `X-Forwarded-For` | So the audit log and login throttling record the real client IP, not the proxy's |
| `Host` | So generated URLs point at the public hostname |

Keep `TRUST_PROXY=true` in `.env` when — and only when — the app is genuinely
behind a proxy you control. With it on, the app believes those headers; exposed
directly to the internet, a client could forge them.

Example nginx proxy block:

```nginx
location / {
    proxy_pass http://127.0.0.1:8080;
    proxy_set_header Host              $host;
    proxy_set_header X-Real-IP         $remote_addr;
    proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}
```

`GET /health` returns JSON and a 200/503 status for uptime monitoring. It is
the only route that does not require a session.

Also raise PHP's own limits to match `.env`, or large photos will be rejected
before the application sees them:

```ini
upload_max_filesize = 25M
post_max_size       = 26M
max_file_uploads    = 20
```

---

## Upgrading

Deploying a new version is a file copy plus migrations:

```bash
git pull                      # or upload the files
composer install --no-dev --optimize-autoloader   # only if you use Composer
php bin/migrate.php
```

Or, from an unpacked copy of the new version, in one command — it backs
nothing up for you, so take a backup first:

```bash
sudo ./manage.sh backup
sudo ./manage.sh update /path/to/new-version
```

That copies the files (skipping `.env`, `storage/` and `vendor/`), re-applies
the permissions, runs the migrations, re-checks the install and reloads the web
server.

Migrations are numbered `.sql` files applied in order and recorded in a
`migrations` table, so running the command twice is safe — the second run
reports "nothing to migrate". Check what is pending first with
`php bin/migrate.php --status`.

Never edit a migration that has already been applied; add a new numbered one.

---

## Backups

Two things need backing up, and **both** are required for a working restore:

1. **The database** — everything except uploaded files.
   ```bash
   mariadb-dump --single-transaction --routines asset_register > asset-register-$(date +%F).sql
   ```
2. **`storage/uploads/`** — photos, PDF manuals and import files. These cannot
   be regenerated from the database; the database only holds their paths.

```bash
tar -czf uploads-$(date +%F).tar.gz storage/uploads
```

To restore, load the dump and unpack the uploads to the same relative paths.
`.env` is worth keeping too, though it is quicker to rewrite than to lose.

`manage.sh` does all three, keeps the last 14 sets and can schedule itself:

```bash
sudo ./manage.sh backup              # dump + uploads + .env, into /var/backups/asset-register
sudo ./manage.sh cron-install        # nightly at 02:15
sudo ./manage.sh restore /var/backups/asset-register/asset_register-20260807-021500.sql.gz \
                        /var/backups/asset-register/uploads-20260807-021500.tar.gz
```

The backups are written mode 600 in a mode 700 directory because the dump
contains every password hash and the `.env` copy contains the database
password. **They still need copying off the machine** — a backup that only
exists on the server it protects is not a backup.

---

## Project layout

```
install.sh              One-command install on a Linux server
manage.sh               Administration: passwords, backups, updates, checks
bin/                    CLI scripts (migrate, seed, create-admin, console)
config/config.php       Configuration, sourced from .env
database/migrations/    Numbered .sql migrations, applied in order
public/                 Document root: front controller, CSS, JS
routes/web.php          Route table, with per-route middleware
src/Core/               Config, Database, Router, View, Session, Auth, Csrf, ...
src/Controllers/        Request handling
src/Imports/            CSV importers (registry: add a class + one line)
src/Middleware/         auth, guest, csrf, can:<permission>
src/Models/             Data access (prepared statements only)
src/Reports/            Reports (registry: add a class + one line)
src/Services/           Asset tagging, copying
storage/                Uploads and logs — not web-reachable
templates/              PHP templates (layouts, partials, pages)
```

---

## Roles and permissions

Four roles ship with the system:

| Role | Intended for |
|------|--------------|
| **Administrator** | Full access, including users, roles and settings |
| **Manager / Staff** | Day-to-day asset, maintenance, PAT and hire management |
| **Read-only** | Can see the register and reports, changes nothing |
| **Hirer** | Can look up items and see their own hires |

Permissions are **data, not code**: `roles` → `role_permissions` → `permissions`.
Adding a permission or a whole new role is an `INSERT`, never a schema change.
Administrators can retune any non-superuser role at **Roles & permissions** in
the admin area.

Checks are enforced server-side in three places, and the UI simply hides what a
user cannot reach:

```php
// 1. On the route
$router->get('/assets/create', [AssetController::class, 'create'], ['can:assets.create']);

// 2. Inside a controller, for conditional logic
Auth::authorize('assets.delete');

// 3. In a template, to hide a control the user cannot use
<?php if (can('assets.edit')): ?> ... <?php endif; ?>
```

Never rely on the template check alone — it is a courtesy, not a control.

Two permissions cover email:

| Permission | Held by | Allows |
|---|---|---|
| `email.manage` | Administrator | Settings → Email: the SMTP connection, reminder schedule, templates and the send log |
| `email.send` | Administrator, Manager / Staff | The "Email hire list" and "Email reminder" buttons |

The calendar feed needs no permission of its own: every signed-in user can
create their own, and what it contains is decided by the permissions they
already hold.

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

Three kinds, each switched on independently: **PAT**, **maintenance** and
**hire returns**. Each has its own "remind this many days before due" window;
leave it at `0` to use the same window the register and dashboard already show,
so the numbers agree without being written down twice.

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

## Using the asset register

### Asset tags and barcodes

New assets are tagged automatically as `<prefix><number>` — `AST-0001` by
default. Both parts are configurable under **Settings**, and the number is
derived from the tags already in the database, so importing older records or
changing the prefix can never strand the sequence. Overwrite the suggested tag
to record a tag an item already carries.

Each asset also has an optional second `barcode` field for a manufacturer or
previous-system barcode. Scanning either value finds the asset.

### Printing labels

`/assets/{id}/label` prints one asset (add `?copies=6` for a strip of the same
label); ticking rows in the register and pressing **Print labels** prints a
sheet. Labels are Code 128, rendered as inline SVG — no image library, and
crisp at any printer resolution. Three sizes are available via `?size=`:

| Preset | Label | Narrow bar |
|--------|-------|-----------|
| `small` | 50 × 19 mm | 0.26 mm |
| `medium` (default) | 62 × 25 mm | 0.33 mm |
| `large` | 76 × 32 mm | 0.42 mm |

Print at 100% scale — "fit to page" changes the bar widths. Long asset tags are
scaled to fit the label and the print view warns when that happens.

### Sub-assets, accessories and related items

A sub-asset is a normal asset with a parent, so a battery, charger or carry case
keeps its own tag, detail page, PAT history and search entry while still being
listed under the tool it belongs to. Add one from the parent's page, or set
**Parent asset** on any asset form. Nesting is one level deep on purpose:
an asset that already has children cannot itself become a sub-asset.

Archiving a parent archives its attached items with it.

### Search and filters

Keyword search matches asset tag, secondary barcode, name, description, serial
number, manufacturer, model, supplier, notes, category and location. Multiple
words are ANDed — "makita drill" finds rows matching both. Filters cover
category, location, status, condition, PAT requirement and item type, and
retired assets are hidden unless you ask for them.

This is a multi-term `LIKE` search rather than MariaDB FULLTEXT, chosen
deliberately: FULLTEXT tokenises asset tags and serials badly (`AST-0001`,
`MK-884213-A`) and ignores short words, which is exactly what people search for
here. At a few thousand assets on modest hosting the difference in speed is not
noticeable.

### Condition photos

Photos can be added to any asset — including sub-assets — at any point in its
life, building a dated visual record. That is the point: a photo taken when an
item goes out on hire and another when it comes back settles an argument in
seconds.

The gallery on the asset page shows the 12 most recent, newest first, each with
its date, caption and who uploaded it. **Full history** opens
`/assets/{id}/photos`, which groups everything by month and lets captions and
dates be corrected. Tapping a photo opens a keyboard-navigable lightbox.

One photo per asset is the **main** image, shown as a thumbnail in the register.
The first upload claims it automatically; **Set as main** changes it, and if the
main photo is deleted the next most recent takes over.

On upload, each image is:

1. **Straightened** using its EXIF orientation tag. Phones record rotation as
   metadata rather than rotating pixels, so without this step photos routinely
   appear sideways.
2. **Scaled down** to a 2400px longest edge, and a 480px thumbnail is generated.
   A phone camera produces 4–12 MB files; galleries would be unusable over a
   workshop 4G connection otherwise.
3. **Dated** from the camera's EXIF capture time, unless you set the date
   yourself. Implausible dates (a camera with a flat battery reporting 1970) are
   ignored.

Both steps need the GD extension. Without it uploads still work — the original
is stored and served as-is, and `thumbnail_path` stays NULL. Nothing breaks on a
host without GD.

On a phone, **Take photo** opens the camera directly (`capture="environment"`)
while **Choose files** opens the gallery; one combined input makes the phone ask
every time. Both accept multiple files. Before uploading, the browser shows
thumbnails of what is selected with each file's size, and flags anything over
the server limit rather than letting you wait for a rejection.

Uploads are validated by extension *and* by content sniffing, so a PHP script
renamed `photo.jpg` is refused. Files are stored outside the document root and
streamed through PHP.

### Manuals

Any number of PDFs per asset. Files are written to
`storage/uploads/assets/{id}/manuals/` with generated names, outside the
document root, and streamed back through PHP — so a manual is only reachable by
someone signed in with permission to view that asset. Each is viewable in the
browser or downloadable. Uploads are checked by extension *and* by content
sniffing, not by the browser-supplied content type.

### Copying assets

Two distinct workflows, both driven by explicit tick-boxes:

**Copy asset** (`assets.create`) creates 1–50 new assets from an existing one.
Pick which details carry over; each copy gets its own generated tag. Asset tag,
secondary barcode, serial number, status, photos, PAT results, maintenance and
hire history are never copied — they belong to one physical item. Serial numbers
are only carried over for a single copy, never a batch. A batch of more than one
lands on the label sheet, ready to print.

**Copy details to…** (`assets.edit`) pushes selected fields from one asset onto
other existing assets — for example applying a manual and manufacturer URL
across every unit of the same model already in the register. The candidate list
defaults to assets matching the source's make and model. Only the ticked fields
are written; everything else on the targets is untouched. Manuals are added,
skipping any the target already has, so repeat runs do not pile up duplicates.

Both workflows write to the activity log, on the source and on every asset
touched.

### Maintenance

Maintenance comes in four shapes. The first three are *schedules* — an asset can
carry any number of them — and the fourth is not scheduled at all.

| Kind | Recurs | For | Where |
|------|--------|-----|-------|
| **Routine** | Yes, on a standard cadence picked from a list (weekly → annual) | Things that must happen on a regular basis | Maintenance → New schedule |
| **Periodic** | Yes, on any interval you type (e.g. every 18 months) | Regular but on an unusual cycle | Maintenance → New schedule |
| **One-off** | No | A single *planned* job; it closes itself once completed | Maintenance → New schedule |
| **Unplanned work** | Never | The repair nobody saw coming — a broken part, something you noticed and dealt with | Maintenance → **Record work** |

The fourth is the important distinction: it is **recorded, not scheduled**.
There is no schedule behind it, because there never was one. It goes straight
into the asset's history and into Maintenance → History.

Completing a job records the date, who did it (a user *or* a named external
contractor), the work done, parts, cost, downtime, result and optional photos.
Two side effects, both opt-in and visible on the form: setting **condition
afterwards** updates the asset's condition, and an asset sitting *In
Maintenance* can be put back in stock in the same action.

For recurring schedules the next due date is calculated from **the date the work
was actually done**, not the date it was meant to happen — a six-monthly service
completed two weeks late is next due six months from that day. The form shows
the calculated date and lets you override it before saving.

Deleting a schedule keeps every completion already logged against the asset:
`maintenance_logs.schedule_id` is set to NULL rather than cascading, because
history should outlive the plan that produced it.

#### Recording unplanned work

Three ways in, because this is how most workshop repairs actually get recorded:

- **Maintenance → Record work** — scan the label, or search the register.
- **Scan → Record work** — scanning takes you straight to the form.
- **Record work** on the asset's own page, when you are already looking at it.

None of them needs a schedule to exist first.

#### Follow-up checks

Any completion — scheduled or unplanned — can schedule a **follow-up check**:
tick the box, say *3 weeks*, and a one-off job appears in the maintenance list
and in the reminder emails when it falls due. The work you described is copied
into its instructions, and it is assigned to whoever did the original.

It is deliberately a **one-off**: "check the belt again in three weeks" closes
itself once done rather than quietly becoming a recurring job nobody meant to
create. If it turns out the thing does need checking regularly, make it a
routine or periodic schedule.

#### Overdue and upcoming

Due status is computed **in SQL**, not in PHP, so it can be filtered, sorted and
counted by the database:

| Status | Meaning |
|--------|---------|
| `Overdue` | `next_due_date` is in the past |
| `Due soon` | Falls within the configurable window |
| `Scheduled` | Beyond the window |
| `Unscheduled` | Active, but no date set yet |
| `Inactive` | Closed schedule |

The window is **Settings → Maintenance → "Due soon" window**, default 30 days.
One setting drives the dashboard tiles, the maintenance list and the reports
module, so every screen agrees on what "due soon" means.

`MaintenanceSchedule::summary()` returns the counts and
`MaintenanceSchedule::search($filters)` returns rows with `due_status` and
`days_until_due` already computed — the reports module (stage 7) calls these
rather than re-implementing the rules. Retired assets and closed schedules are
excluded from the summary by default.

### PAT testing

Tick **Requires PAT** on an asset (on the asset form, or one-click from the PAT
banner) and it joins the PAT register. Recording a test against an unflagged
asset flags it automatically.

Every test is stored as its own record, so an asset accumulates a full history
rather than just the latest sticker on the plug. Each record captures test date,
retest due date, tester (a user *or* a named contractor) with competency
reference, test equipment, appliance class, visual inspection, earth continuity,
insulation resistance, leakage, load, polarity, functional check, overall
result, PAT label serial, fuse fitted, remedial action and notes.

#### Fixed values live on the asset, not the test

Appliance class, load rating, whether the plug is fused and the fuse rating are
properties of the *appliance*, so they are recorded once on the asset under
**Electrical & PAT** and never asked for again. Re-entering them at every test
invited drift — the same item logged Class I one year and Class II the next —
and made the tester answer questions the register already knew.

The plug fuse rating is a four-way choice (3 A, 5 A, 10 A, 13 A) rather than
free numeric entry. An existing non-standard value is kept and shown flagged for
correction rather than silently discarded.

Each test still stores a **snapshot** of the appliance class it was performed
under, so correcting an asset later never rewrites its history.

#### Recording a test is a guided flow

**Record a test** walks through the job in the order it is actually done, one
step per screen — designed for a phone in a workshop:

1. **This appliance** — the fixed values, shown alongside every later step.
2. **Visual inspection** — plug, cable, case, and (only if the asset is fused)
   a fuse check that asks you to *confirm the fitted fuse matches the recorded
   rating* rather than to type a value in again.
3. **Electrical tests** — only the ones the class calls for. Class I gets earth
   continuity, insulation resistance and leakage; Class II gets insulation and
   leakage, because there is no earth path to test. Each has its reading, its
   unit, and its own pass/fail.
4. **Functional check** — does it work when you switch it on.
5. **Result** — derived, not declared.

**One failed check fails the test.** You never separately declare a failure: if
anything in steps 2–4 is marked fail, the record saves as a Fail with the failed
checks listed automatically, and the flow asks what was wrong. A Pass only
becomes available once every applicable check has passed.

The browser enforces this as you go, but the server derives the result
independently — posting the form by hand with a smuggled `overall_result=Pass`
and a failed cable still saves a Fail.

Every individual step result is stored, not just the overall verdict, so the
history stays inspectable. Tests imported from CSV, and anything predating this
flow, show "not recorded" for the per-check verdicts rather than claiming a pass
nobody gave.

Editing an existing record stays a flat form — correcting a typo in a tester's
name should not mean walking six steps.

#### Guideline pass ranges

Each electrical reading shows typical guidance beside it:

| Reading | Default guidance |
|---------|------------------|
| Insulation resistance | 1 MΩ or more |
| Earth continuity | under 0.1 Ω for the appliance, plus 0.1 Ω per 7.5 m of extension lead |
| Leakage current | under 3.5 mA (Class I) or 0.25 mA (Class II) |

Enter the length of any extension lead under test and the earth continuity
guideline recalculates live. The leakage guideline follows the asset's class
automatically.

**These are guidance, not a rule.** Nothing compares a reading against them to
decide anything — your pass/fail choice is what records the result. All six
values are editable under **Admin → Settings → PAT guideline pass ranges**, so
they can be tuned to your own policy without a code change.

#### Assets that need details filling in

The migration backfills appliance class, load and fuse details from each asset's
most recent test. Anything never tested has nothing to copy from, and the guided
flow will not start without an appliance class — it cannot tell which tests
apply. List the gaps with:

```bash
php bin/console.php pat:missing-details
```

**Units are explicit everywhere** — in the column names, the form labels and the
displayed values:

| Reading | Unit | Column |
|---------|------|--------|
| Earth continuity | Ω (ohms) | `earth_continuity_ohms` |
| Insulation resistance | MΩ (megohms) | `insulation_resistance_mohms` |
| Leakage current | mA (milliamps) | `leakage_current_ma` |
| Load / power | VA (volt-amps) | `load_test_va` |
| Fuse fitted | A (amps) | `fuse_fitted_amps` |

Earth continuity is only shown — and only stored — for **Class I**; a Class II
appliance is double-insulated and has no earth to test, so a stray reading is
discarded rather than saved as if it meant something.

Two rules are enforced rather than left to the operator:

- **A failed visual inspection cannot pass overall.** The form flips the result
  to Fail when the visual check is unticked, and the server rejects the
  contradiction if it is submitted anyway.
- **A failing test can withdraw the item from use** in the same action — moving
  it to *In Maintenance* and optionally marking the condition *Out of Service*.
  Both are tick-boxes, on by default for the first.

#### Status at a glance

The asset page carries a colour-coded banner answering "is this thing in date?":

| Status | Meaning |
|--------|---------|
| `Current` | Last test passed and the retest date has not arrived |
| `Due soon` | Retest falls within the configurable window |
| `Overdue` | Retest date has passed |
| `Failed` | Most recent test failed — flagged **regardless of the retest date** |
| `Never tested` | Flagged as requiring PAT with no record |
| `No retest date` | Tested, but no retest date was set |

`Failed` deliberately outranks the date: an item that failed last week is not
"in date" just because its retest is not due until next year.

Retest dates are suggested from the asset's own `pat_interval_months` where set,
otherwise from **Settings → PAT testing → Default retest interval** (12 months
out of the box). The right interval depends on the equipment and its
environment, so it is a site decision rather than a hard-coded rule, and any
asset can override it.

As with maintenance, status is computed in SQL. `PatRecord::summary()` and
`PatRecord::assetSearch($filters)` are what the reports module (stage 7) calls.

### Hires

Check an asset out to a person or company, set a due-back date, and book it
back in. Photos can be taken at both ends, so the condition going out and
coming back is evidenced rather than argued about.

**Overdue is derived from the due date in SQL**, so it is always correct with
nothing running on a schedule — no cron job to forget. The stored `status`
column is kept in step by `Hire::refreshOverdue()` (two cheap indexed updates,
run when the hires list or dashboard loads), purely so that anything reporting
straight off the database sees the same thing.

**Double-booking is not possible.** An asset that is already out, retired, not
hireable, or in maintenance is refused, with the reason given. The check runs
twice: once for the form, and again inside the checkout transaction with the
asset row locked (`SELECT … FOR UPDATE`), so two people scanning the same item
at the same moment cannot both succeed. On checkout the asset moves to *On
Hire*; on return it goes back to *In Stock* or straight into maintenance if it
came back needing work.

### Scanning

A **Scan** button sits in the header on every page, and a small **Scan** button
sits at the end of every field that takes an asset tag or barcode:

| Where | Field | After a scan |
|-------|-------|--------------|
| Add / edit asset | Asset tag | fills the field |
| Add / edit asset | Existing barcode | fills the field |
| Asset register | Search | fills and searches |
| PAT register | Search | fills and searches |
| Record a PAT test | Asset lookup | fills and jumps to the asset |
| Hire checkout | "Which asset?" | fills and finds the asset |

Where the field is the whole question — a search or a lookup — a successful scan
submits it rather than making you press another button.

This is one partial and one shared decoder, so adding it to a new field is a
single line and no JavaScript:

```php
<?= partial('partials/scan-button', ['target' => 'asset_tag']) ?>
```

The button is hidden until its script loads: without JavaScript there is no
camera, and a dead button is worse than no button. Typing the tag and USB
scanners work regardless. The quick-scan page deliberately has no such button —
it already *is* a camera scanner, and two would fight over the camera.

Three ways in, all landing at the same lookup:

| Input | Notes |
|-------|-------|
| **USB barcode scanner** | Works with no setup — these act as keyboards. The input keeps focus so you can scan one item after another |
| **Device camera** | Uses the browser's native `BarcodeDetector` where available (Chrome/Edge). Where it is not — Safari, including every iPhone — falls back to the reader written for this project |
| **Typing** | Always available |

Scan modes: **Look up** jumps to the asset, **Check out** goes straight to the
checkout form, **Book in** goes to that item's return form, **Record work**
goes to the maintenance form. A successful read beeps and vibrates, so you are
not staring at the screen.

#### What it reads

Three symbologies, in `public/js/barcode.js`:

| Format | Why |
|--------|-----|
| **Code 128** | What this system prints. `src/Core/Barcode.php` is the encoder; the decoder shares its pattern table |
| **Code 39** | Still the default on older label printers, and on tags that arrived stuck to the equipment |
| **QR** | What is on the plate of most machinery bought this decade |

A scan is looked up against both the asset tag *and* the barcode field, so a
Code 39 or QR label already on a machine can be recorded against the asset and
then scanned like any other.

There is no third-party scanning library: the CSP only permits scripts from
this origin, and a reader is not worth a vendored blob nobody can audit. The
1D half reads a scan line, run-length encodes it, normalises against the module
width and matches the pattern table. The QR half is the standard pipeline from
ISO/IEC 18004 — locate the three finder patterns, find the alignment pattern,
correct the perspective, read the format information, undo the data mask,
de-interleave the blocks and Reed-Solomon correct them.

**A misread must not become the wrong asset.** Code 128 has a checksum and QR
has Reed-Solomon, so garbage does not decode, it fails — and where damage
exceeds the correction capacity the QR decoder refuses rather than guesses.
Code 39 has no checksum at all, so it is only believed when the same value
comes off two different scan lines of the same frame, with a clear quiet zone
either side.

Its limits, stated plainly: 1D barcodes want to be roughly horizontal (QR does
not care, and reads upside down); the QR reader handles all 40 versions but not
Micro QR; and EAN/UPC product barcodes are read only where the browser has a
native detector. If a code will not read, type it — nothing is lost.

`tests/barcode-decode.html` is the check: open it in any browser and it
renders known values through an independent encoder and reads them back, plus
the specification's own worked example and a set of frames that must *not*
decode.

### The Hirer role

Setting one up is three ordinary steps: create the user with the **Hirer**
role, create (or open) the hirer record, and pick the login under
**Self-service login**. One login links to one hirer record; that link is
what scopes everything they see.

A hirer signing in lands on **My hires** — a card per item with its photo,
tag, name, checkout date, due date, and any overdue clearly flagged. Opening
one shows the description, condition, manuals (view or download), the
manufacturer link and the **latest** PAT result if the item is tested.

Everything else is closed to them, and the restriction is *structural* rather
than a list of things remembered to be hidden:

- The Hirer role holds exactly one permission, `hires.view_own`. It no
  longer has `assets.view`, which would have opened the whole register.
- The portal is a separate controller that never calls the asset controllers.
  Assets are reduced to an allow-list of visible fields, so a column added to
  `assets` in future cannot leak into it by accident.
- Every query is scoped through the hirer record linked to the signed-in
  user. Another hirer's hire returns **404, not 403** — no confirmation that
  it exists.

They cannot see other people's hires, other assets, maintenance, full PAT
history, financial fields, internal notes, supplier, serial numbers or the
storage location, and there is no add/edit/delete anywhere. Their navigation
contains only *My hires* and their own profile.

### CSV import and export

#### Importing

Two importers ship — **Assets** and **PAT records** — reachable from
**Import** in the admin menu, or from the register.

The flow is upload → preview → confirm. **Nothing is written until you have
seen the preview and pressed the button**, and a row with a problem is skipped
and reported rather than stopping the batch.

Headings are matched loosely: case, spaces, underscores and bracketed hints are
ignored, and each column accepts aliases — so `Asset Tag`, `asset_tag` and
`Tag` all land in the same place, and column order does not matter. A file
saved from Excel works: the byte-order mark is stripped and comma, semicolon
and tab delimiters are all detected. Every importer has a **downloadable
template** with one example row.

Spreadsheets are untidy, so values are parsed forgivingly and the preview says
what it did: `19/03/2024`, `3 Feb 2022` and `2024-03-19` are all dates;
`£1,250.00` is a number; `Yes`, `Y`, `true` and `1` are all true. Anything not
understood becomes a warning and the field is left blank rather than the row
being rejected.

**Assets** — only *Name* is required. A blank tag is generated for you; a tag
that already exists is skipped, so re-running the same file cannot create
duplicates. Categories and locations are matched by name and created on demand
(a checkbox turns that off).

**PAT records** — matched to existing assets by tag, and a tag that matches
nothing is reported plainly rather than silently dropped. `1`, `I` and
`Class 1` all mean Class I. Earth continuity is discarded for anything that is
not Class I. Over-range readings (`>299`, `OL`) are recognised. The same rule
the on-screen form enforces applies here: a failed visual inspection cannot
carry an overall Pass. A test already recorded for that asset on that date is
rejected, so importing the same sheet twice is safe.

#### Exporting

**Export CSV** on the register exports exactly what you are looking at — it
carries the current search and filters — or tick rows and use **Export
selected**. The core columns are the same shape the importer accepts, so an
export can be edited in a spreadsheet and fed straight back in; the test suite
verifies that round trip.

Three optional column groups can be appended: **latest PAT result**, **current
hire**, and **next maintenance**. These are derived data rather than asset
fields, so they are ignored on re-import.

Every import and export is recorded in the activity log with who, which file,
how many rows, and how many were skipped.

#### Column reference — assets

The template at `/import/assets/template` is always authoritative; this is the
same list for reference. Headings are matched loosely, so the "also accepted"
spellings work too, and any column can be left out.

| Column | Required | Notes | Also accepted |
|--------|----------|-------|---------------|
| Asset tag | | Blank generates one. Must be unique | tag, asset id, asset number, barcode |
| **Name** | **Yes** | What the item is | item, title |
| Description | | Free text | details |
| Category | | Matched by name, created on demand | group |
| Location | | Matched by name, created on demand | where, site |
| Condition | | Excellent / Good / Fair / Poor / Out of Service. Default Good | state |
| Status | | In Stock / In Maintenance / Retired. Default In Stock | |
| Purchase date | | `2024-03-19`, `19/03/2024`, `19 Mar 2024` | bought, acquired |
| Purchase cost | | Symbols and commas ignored | cost, price |
| Current value | | | replacement value |
| Supplier | | | bought from, vendor |
| Serial number | | Duplicates warn, they do not block | serial, sn |
| Manufacturer | | | make, brand |
| Model | | | model number |
| Manufacturer URL | | Must start `http://` or `https://` | website, product page |
| Plug fuse rating (A) | | Amps, e.g. 3, 5, 13 | fuse, fuse rating |
| Cable CSA (mm2) | | Square millimetres, e.g. 0.75, 1.5 | csa, cable size |
| Requires PAT | | Yes/No, True/False, 1/0. Default No | pat, needs pat |
| PAT interval (months) | | Blank uses the site default | retest interval |
| Available for hire | | Yes/No. Default Yes | hireable |
| Notes | | Free text | comments |
| Secondary barcode | | A barcode the item already carries | other barcode |
| Warranty expires | | Date | warranty |

*Status `On Hire` is rejected with a warning — a hire needs a hirer, so
check the item out afterwards instead.* Three further columns (`Part of`,
`Relationship`, `Added`) appear in exports and are recognised but ignored on
import, so an exported file re-imports without complaint.

#### Column reference — PAT records

| Column | Required | Notes | Also accepted |
|--------|----------|-------|---------------|
| **Asset tag** | **Yes** | Must match an existing asset's tag or barcode | tag, appliance id |
| **Test date** | **Yes** | Not in the future | date tested, tested on |
| **Overall result** | **Yes** | Pass or Fail | result, outcome |
| Retest due | | Blank is calculated from the asset's interval | next test |
| Tester name | | Person or contractor | tester, tested by |
| Tester ID | | Competency reference | tester reference |
| Test equipment | | Make, model, serial of the tester | instrument |
| Appliance class | | `Class I`, `1`, `Class II`, `2`… Default Class I | class |
| Visual inspection | | Pass/Fail. Default Pass | visual |
| Earth continuity (ohms) | | Ω. **Class I only** — discarded otherwise | earth, continuity |
| Insulation resistance (Mohms) | | MΩ. `>299` and `OL` understood | insulation, ir |
| Leakage current (mA) | | mA | leakage |
| Load (VA) | | Volt-amps | load, power |
| Functional check | | Pass/Fail, blank if not performed | function |
| PAT label | | Serial on the sticker | label |
| Fuse fitted (A) | | Amps found or fitted | fuse |
| Remedial action | | What was done on a failure | remedial |
| Notes | | Free text | comments |

Rows are rejected — not silently altered — when the tag matches nothing, the
test date is missing or in the future, the overall result is neither Pass nor
Fail, a visual failure claims an overall Pass, or that asset already has a test
on that date.

#### Column reference — asset export

The export's core columns are exactly the asset import format above, so a file
can be exported, edited in a spreadsheet and imported back. Ticking **Extra
columns when exporting** appends any of:

| Group | Columns |
|-------|---------|
| Latest PAT result | PAT status, last tested, result, retest due, PAT label |
| Current hire | Hire status, on hire to, out since, due back |
| Next maintenance | Next job, next due, last done |

These are derived from other records rather than asset fields, so they are
ignored if the file is imported again.

### Reports

Five reports ship, grouped on `/reports`:

| Report | Answers |
|--------|---------|
| **All assets** | The whole register, filterable, with cost and value totals |
| **Assets needing maintenance** | What is overdue or due soon, and who it is assigned to |
| **Assets needing PAT** | Overdue, failed, never tested, or due soon |
| **Assets currently on hire** | What is out, with whom, and when it is due |
| **Assets due back** | The chase list — overdue and imminent, with hirer phone and email |

Every report has filters, headline figures, a **print view** and a **CSV
export**. Reports reuse the same model queries as the screens they mirror
(`MaintenanceSchedule::searchAll()`, `PatRecord::assetSearchAll()`,
`Hire::searchAll()`), so a figure in a report and the same figure on its
screen cannot drift apart — they are the same query.

Access needs `reports.view` **plus** the permission for the underlying data, so
someone who cannot see PAT records cannot reach the PAT report either. The CSV
export additionally honours `assets.export` on the register report.

#### Adding a report

Reports are a registry, not a set of pages. To add one:

1. Write a class in `src/Reports/` extending `Report`, declaring its key, name,
   description, permission, columns, filters and rows.
2. Add it to the `REPORTS` list in `src/Reports/ReportRegistry.php`.

That is the whole change. Routing, filtering, the table, the print view and the
CSV export are generic and driven by the class's own declarations — no
controller, route or template is touched. Columns declare a type (`text`,
`date`, `datetime`, `money`, `number`, `badge`, `bool`), optional alignment, an
optional link target and an optional sub-line, and the renderer does the rest.

The test suite proves this rather than assuming it: `smoke5.php` defines a
sixth report in about thirty lines, registers it at runtime, and checks it
appears in the right group, filters correctly, renders through the generic
table with formatted money and dates and working links, and drops out again.

CSV exports carry a UTF-8 byte-order mark so spreadsheets read `£`, `Ω` and
`mm²` correctly, dates are ISO, and any cell beginning `=`, `+`, `-` or `@` is
prefixed with an apostrophe so a spreadsheet cannot treat asset data as a
formula.

### Categories, locations and settings

Categories and locations are managed under **Admin** (`categories.manage` /
`locations.manage`) and can nest one level, e.g. Main Workshop → Bench 3.
Neither can be deleted while assets still reference it — deactivate it instead,
which hides it from the pickers but leaves existing assets intact.

### Archiving vs deleting

Archiving sets an asset to *Retired*, keeps every record, and is reversible.
Permanent deletion is available to `assets.delete` holders but is refused
whenever the asset has hire, PAT or maintenance history, or attached items — the
audit trail wins over tidiness.

---

## Security notes

- **Passwords** — `password_hash()` with `PASSWORD_DEFAULT`, verified with
  `password_verify()`, transparently re-hashed on sign-in when PHP's default
  cost or algorithm changes. Minimum length 12.
- **Login throttling** — failed attempts are recorded per email *and* per IP
  (`login_attempts`); the account locks for `LOGIN_LOCKOUT_MINUTES` after
  `LOGIN_MAX_ATTEMPTS` failures, and an IP hammering many accounts is locked at
  three times that rate. Sign-in timing does not reveal whether an account exists.
- **Sessions** — `HttpOnly`, `SameSite=Lax`, `Secure` when HTTPS is in use;
  strict mode on, ID regenerated on sign-in, idle timeout from `SESSION_LIFETIME`,
  and the session is bound to the browser's user-agent fingerprint.
- **CSRF** — every state-changing route carries the `csrf` middleware; forms
  include `csrf_field()`. A stale token gives a clear "session expired" page
  rather than a silent failure.
- **SQL** — prepared statements throughout. Table and column names come only
  from application code; every value is bound.
- **Output** — all dynamic template output goes through `e()`.
- **Headers** — CSP, `X-Content-Type-Options`, `X-Frame-Options`,
  `Referrer-Policy`, `Permissions-Policy`, plus HSTS when the request is HTTPS.
- **Uploads** — size ceilings and an allow-list of types in
  `config/config.php`. Files are checked by **content**, not by the filename or
  the browser's content-type, written under `storage/uploads/` with generated
  names outside the document root, and served back through PHP with
  `nosniff`. Path resolution rejects anything that escapes the uploads root.
- **Permissions** — enforced on the server at the route (`can:<permission>`)
  and in controllers (`Auth::authorize()`). Hiding a button is a courtesy, not
  a control.
- **Audit trail** — `activity_log` records who did what, when, from which IP,
  with a field-level before/after diff. Rows survive deletion of the thing they
  describe.
- **CSV exports** — cells beginning `=`, `+`, `-` or `@` are prefixed with an
  apostrophe so a spreadsheet cannot execute asset data as a formula.

### How this is checked

The properties above are verified by tooling rather than by inspection, and
that tooling ships in **[`tests/`](tests/README.md)** so it can be re-run after
any change:

```bash
php tests/security-audit.php     # routes, SQL, uploads, headers, forms
php tests/escape-audit.php       # template output escaping
```

Both are static — they read the source and are safe to run anywhere. Two
further checks need the site running; see `tests/README.md`.

| Check | What it proves |
|-------|----------------|
| Route audit | Every POST/PUT/DELETE route carries CSRF; every route is authenticated and permission-gated; no display-only page sits behind a write permission |
| SQL audit | No variable is interpolated into a query; concatenation is limited to controlled fragments; nothing bypasses the `Database` wrapper |
| Escaping audit | Parses all ~1,500 template output expressions with PHP's tokeniser and proves no variable reaches the page unescaped |
| Permission matrix | Drives ~260 route/role combinations as all four roles against a declared expectation |
| Report figures | Every report's row count matches the database, and the CSV matches the screen |

### If you lock yourself out

Reset any account from the server:

```bash
sudo ./manage.sh reset-password you@example.com
```

That sets a new password, reactivates the account if it was disabled and
clears the recorded sign-in attempts so the lockout lifts immediately. To clear
every lockout on the site: `sudo ./manage.sh unlock`.

The original route still works and does the same thing, minus the lockout
clearing:

```bash
php bin/create-admin.php --email=you@example.com
```

Answer `y` when it offers to reset the password.

The application refuses to leave itself with no way in: the last active
administrator cannot be deactivated or demoted, and nobody can deactivate their
own account.

---

## Troubleshooting

**Redirect loop, or "too many redirects"** — the app thinks the request is
plain HTTP. Either the proxy is not sending `X-Forwarded-Proto: https`, or
`TRUST_PROXY=false`. Set both correctly, or set `FORCE_HTTPS=false` if you are
genuinely running without TLS (not recommended).

**Signed out immediately after signing in** — the session cookie is being set
`Secure` but the browser is on plain HTTP. Same cause as above.

**"Session expired" on every form** — cookies are not surviving the round trip.
Check the clock on the server, and that the proxy is not stripping cookies.

**Uploads fail silently or 413** — PHP's `upload_max_filesize` /
`post_max_size`, or the web server's body limit (`client_max_body_size` on
nginx), is lower than the app's own limit. Raise all three together.

**Photos appear sideways, or are huge** — the `gd` and `exif` extensions are
missing. The app still works (it stores the original untouched) but cannot
straighten or resize. Install them and re-upload.

**Barcodes will not scan** — check the label printed at 100% scale, not "fit to
page". The print view warns when a tag is too long for the chosen label size.
On iPhone the camera scanner falls back to the reader built into this project,
which handles Code 128, Code 39 and QR but not EAN/UPC product barcodes; a USB
scanner or typing the tag always works. A 1D barcode needs to be roughly
horizontal in the frame — QR does not, and reads upside down.

**"The uploaded file could not be found" during an import** — the preview and
the confirm step are separate requests; if the session expired or the server
was restarted in between, upload the file again.

**A permission looks wrong** — check **Admin → Roles**. Roles are data, so a
permission can be added or removed without touching code. Administrators always
hold everything.

**Where to look when something breaks** — `sudo ./manage.sh doctor` checks the
usual suspects in one pass (PHP extensions, `.env` permissions, upload limits,
storage, database, pending migrations). `storage/logs/app.log` holds uncaught
errors — `sudo ./manage.sh logs -n 100`. **Admin → Activity log** holds who did
what.

---

## Front end

Mobile-first CSS with light and dark themes driven by `data-theme` on `<html>`.
The choice is stored in `localStorage` *and* a cookie, and applied by an inline
script before first paint so there is no flash of the wrong theme; with no stored
choice it follows the device setting.

Workshop-specific choices: 17px base text, a 44px minimum tap target everywhere,
16px form inputs (below that iOS zooms on focus), high-contrast palettes in both
themes, and status conveyed by text and shape as well as colour.

All text meets the WCAG AA 4.5:1 contrast ratio against its own background in
both themes — verified by measuring every rendered text node across the main
pages at phone, tablet and desktop widths, which is how two badge colours that
sat at 4.42:1 were found and corrected.

No JavaScript framework and no build step. The only scripts are
`public/js/app.js` (navigation, theme, lightbox, form helpers) and
`public/js/scanner.js` (barcode reading). Every page works without JavaScript
except the camera scanner, which falls back to typing or a USB scanner.

---

## Development

```bash
php -S localhost:8000 -t public
```

Set `APP_ENV=local`, `APP_DEBUG=true` and `FORCE_HTTPS=false` in `.env` first,
otherwise the app will redirect you to `https://localhost:8000`.

To rebuild a development database from scratch:

```bash
mariadb -u root -p -e "DROP DATABASE IF EXISTS asset_register; CREATE DATABASE asset_register CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
php bin/migrate.php && php bin/seed.php
```

### MariaDB notes

- `JSON` columns (`activity_log.changes`) are LONGTEXT plus a `json_valid()`
  CHECK constraint. Invalid JSON is rejected by the database, so the audit
  logger substitutes malformed UTF-8 rather than risk failing the write.
- Run with `STRICT_TRANS_TABLES` in `sql_mode` — the default on modern MariaDB.
  The schema relies on it to reject over-long and out-of-range values rather
  than silently truncating them.
- The schema uses no MySQL-8-only syntax, so it applies cleanly to MariaDB
  10.4 through 12.x.
