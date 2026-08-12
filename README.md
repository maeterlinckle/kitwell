# Asset Register

Self-hosted asset register for a workshop or office: assets and sub-assets,
condition photos, PDF manuals, maintenance scheduling, PAT (Portable Appliance
Testing) records, fault reporting and hire tracking — usable on a phone next to
the machine as well as on a desktop.

PHP 8.1+ and MariaDB 10.4+, no framework. Server-rendered templates and vanilla
JavaScript: no build step, nothing to compile, deployable by copying files.

**📖 [Full documentation](docs/README.md)** · [Getting started](docs/getting-started.md) · [API](docs/api.md)

---

## What it does

- **Assets** — tags and barcodes, sub-assets and accessories, categories and
  locations, condition and status, purchase and electrical detail, PDF manuals,
  and printable Code 128 labels. Copy an asset into a batch, or push chosen
  fields across a group. → [Assets](docs/assets.md)
- **Condition photos** — a dated visual history per asset, straightened and
  resized on upload.
- **Maintenance** — routine, periodic and one-off schedules; completions with
  parts, cost, photos and the contractor's paperwork; a clear view of what is
  overdue. Assign a job to a person or a team. → [Maintenance](docs/maintenance.md)
- **PAT testing** — a guided test that records every reading with its unit, and
  a status that treats a failure as a failure. → [PAT testing](docs/pat-testing.md)
- **Faults** — mark an item faulty with a photograph, an urgency and the date it
  was noticed. Every report is kept, so an item that keeps breaking says so, and
  whoever is responsible for it is told. → [Faults](docs/faults.md)
- **Hires** — check out by scan or by hand, due dates, returns with condition
  notes and photos, and no way to double-book an item. Hirers can sign in and
  see only what they hold. → [Hires](docs/hires.md)
- **Reports** — six built in, plus your own, all filterable, printable and
  exportable. → [Reports](docs/reports.md)
- **CSV** — import an existing register or a contractor's PAT results with a
  preview before anything is written. → [Import and export](docs/import-export.md)
- **Email** — scheduled reminders for PAT, maintenance, hire returns and faulty
  equipment through your own SMTP server, with editable templates and a log of
  every message. → [Email and notifications](docs/email-and-notifications.md)
- **Calendar feeds** — each user can subscribe their calendar app to the dates
  their role lets them see.
- **Accounts** — invitations, self-service password recovery, and optional
  two-factor authentication with an authenticator app or a code by email.
  → [Users and roles](docs/users-roles-permissions.md) ·
  [Two-factor](docs/two-factor-authentication.md)
- **REST API** — `/api/v1`, authenticated with keys that inherit exactly their
  owner's permissions, documented and runnable at `/api/docs`. → [API](docs/api.md)

## Quick start

On a fresh Debian or Ubuntu server, as root:

```bash
git clone https://github.com/maeterlinckle/kitwell.git /opt/asset-register
cd /opt/asset-register
sudo ./install.sh
```

The installer creates the database, writes `.env`, runs the migrations, sets up
the web server and TLS, and prompts for the first administrator. See
[INSTALL.md](INSTALL.md) for unattended runs and failure modes, or
[Getting started](docs/getting-started.md) to do it by hand.

To try it locally with PHP's built-in server:

```bash
php bin/migrate.php && php bin/seed.php && php -S 127.0.0.1:8000 -t public
```

`bin/seed.php` loads demo data and should never be run on a live system.

## Where things are

| | |
|---|---|
| [`docs/`](docs/README.md) | How to install, configure and use it — one page per topic |
| [`INSTALL.md`](INSTALL.md) | The scripted installer in detail |
| `PROJECT_STATE.md` | The implemented schema, the established patterns, and the reasoning behind them — written for whoever picks the code up next |
| `tests/` | Shipped verification tooling: security and escaping audits, a permission matrix, and end-to-end checks. See [Development](docs/development.md) |

## Licence and status

Built for one workshop and kept deliberately small: one runtime dependency
(PHPMailer), no build tooling, no JavaScript framework. The barcode and QR
encoders, the CSV handling and the reporting are all first-party, which is why
the whole application can be deployed by copying a directory.
