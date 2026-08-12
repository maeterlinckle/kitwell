# Administration

Day-to-day server work: the `manage.sh` wrapper, where everything lives on disk, and what to do when something is wrong.

**On this page**

- [Administration: manage.sh](#administration-managesh)
- [Project layout](#project-layout)
- [Troubleshooting](#troubleshooting)

---

## Administration: manage.sh


Once installed, `manage.sh` is the front door for everything an administrator
does from the server. The installer links it onto `PATH`, so this works from
anywhere:

```bash
sudo kitwell help
```

> **Run the installed copy, not the one in your checkout.** `manage.sh` travels
> with the source, so `~/kitwell/manage.sh` exists too — but it manages nothing,
> because a checkout has no `.env`, and the web server user usually cannot even
> read a directory under `/root`. Run it from the install directory (or use the
> `kitwell` link above). It will tell you, and point at the real install,
> if you get this wrong.

The full path always works too:

```bash
sudo /var/www/kitwell/manage.sh help
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
cd /var/www/kitwell && sudo -u www-data php bin/console.php doctor
```

`doctor` is worth knowing: it checks the PHP version and extensions, whether
`.env` is readable by anyone it should not be, PHP's upload limits against the
application's, the storage directories, the database connection and collation,
pending migrations, and whether an active administrator still exists.

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
src/Mail/               SMTP, templates, reminders, invitation and reset links
src/Services/           Asset tagging, copying, branding, calendar feeds
storage/                Uploads and logs — not web-reachable
templates/              PHP templates (layouts, partials, pages)
```

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

**See also:** [Documentation index](README.md) · [Getting started](getting-started.md) · [Security](security.md)
