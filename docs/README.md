# Documentation

Everything about using and running Kitwell. Each page covers one topic and
stands on its own; the root [README](../README.md) is the two-minute
introduction.

Signed in, these pages are also available from **Settings → Help**.

The pages are in two halves. **Using Kitwell** is what you do in the browser,
whatever your role. **Administration** is the server side — installing,
backing up, scheduling, and the developer reference — and needs shell access to
the machine the site runs on.

## Using Kitwell

| Page | What is on it |
|---|---|
| [Assets and sub-assets](assets.md) | Asset tags, sub-assets and accessories, search and filters, manuals, copying, archiving and deleting |
| [Photos](photos.md) | Condition photos, taking them on a phone, the dated history they build up |
| [Faults and the responsible party](faults.md) | Naming who looks after an asset, reporting a fault with a photograph, who gets told |
| [Maintenance](maintenance.md) | Routine, periodic and one-off schedules, recording completions with evidence, how "due" is decided |
| [PAT testing](pat-testing.md) | The guided test flow, what belongs to the appliance and what to a test, how status is worked out |
| [Hires and hirers](hires.md) | Checking out and booking in, hirer records, the self-service Hirer role |
| [Teams](teams.md) | Groups that work can be assigned to |
| [Reports](reports.md) | The six built-in reports, and defining your own |
| [Import and export](import-export.md) | Uploading a CSV of assets or PAT results, previewing it, and downloading data back out |
| [Barcode scanning and labels](barcode-scanning.md) | Camera and hardware scanning, printing labels and records, your logo |
| [Email and notifications](email-and-notifications.md) | Reminder settings, message templates, the send log, calendar feeds |
| [Users, roles and permissions](users-roles-permissions.md) | The four roles, creating your own, invitations, password recovery, API keys |
| [Two-factor authentication](two-factor-authentication.md) | Authenticator apps, email codes, site-wide enforcement, trusted devices |

## Administration

Server-level work. Everything in this half assumes shell access to the machine
Kitwell runs on.

| Page | What is on it |
|---|---|
| [Installation](installation.md) | Requirements, the scripted install and the manual one, Apache and nginx configuration, upgrading |
| [Administration](administration.md) | `manage.sh`, backups, migrations, the reminder schedule, project layout, troubleshooting |
| [Development](development.md) | Running it locally, the shape of the front end, the verification tooling |
| [Security](security.md) | What is protected, how permissions are enforced, secrets and encryption, hardening the server |
| [API](api.md) | The REST interface, keys, conventions and the endpoint reference |

---

## Finding your way around

The menu is the same on every page, and every entry is hidden from anyone whose
role does not include it — so two people signed in at once may not see the same
menu.

| Menu | Goes to | Also under it |
|---|---|---|
| **Assets** | The register | — |
| **Maintenance** | Schedules | Add maintenance · Schedules · PAT records |
| **Hires** | Current hires | Check out · Current & history · Hirers |
| **My hires** | A hirer's own equipment — shown *instead of* Hires to somebody who can only see their own | — |
| **Reports** | The report index, built-in and saved | — |
| **Settings** | Application settings | Users · Roles · Teams · Categories · Locations · Email · API keys · Activity log · Import data · Export data · Application settings · Help |

**Help** sits at the bottom of the Settings menu and opens this documentation
inside the application. Every signed-in user can reach it, whatever their role.

Your own name at the right opens **My account**, **Security** (two-factor and
trusted devices), **Calendar feed** and **Sign out**. Personal settings only —
nothing administrative is in there.

The site logo is the link to the dashboard; there is no separate menu entry
for it.

## Conventions used here

**Hires and hirers.** The workshop hires equipment out. The interface, the
database and these pages all use the same words.

**Permissions are named as the application names them** — `assets.view`,
`maintenance.complete`, `reports.manage`. They are rows in a table, not
constants in code, so a role can be given any combination of them.

**Paths are as the application serves them**, without a leading host:
`/assets/12`, `/admin/api`, `/api/v1/assets`.

**Configured values appear as `{{setting:key}}` in these files** and are
replaced with the site's current value when the page is read inside the
application, so what you see in Help is what is actually set rather than an
example.

## Editing these pages

One page per topic, each with the same shape: a title, a one-line summary, an
**On this page** list, the content, and a **See also** footer. Keeping to that
is what lets somebody find the right page to change without reading the whole
set.

Keep the split: if a step needs a shell, SQL, a file path or a cron entry, it
belongs in the Administration half, and the user-facing page should link to it
rather than explain it.

If a change touches how the application behaves, the page that documents it is
part of the change. `PROJECT_STATE.md` at the repository root is the technical
counterpart to this folder: the schema and the patterns the code follows.
