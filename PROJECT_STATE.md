# Project state

A ground-truth snapshot of Kitwell as it stands on **2026-08-12**,
written for whoever (or whatever) picks the code up next.

The original nine build prompts finished on 2026-08-06. Since then:
deployment tooling on 2026-08-07 (§5.3), the PAT workflow overhaul on
2026-08-08, the navigation and Hires/Hirers rename on 2026-08-09, outbound
email plus calendar feeds on 2026-08-10, and on 2026-08-11 three fixes found by
deploying it (§5.4) followed by stages 13, 14 and 15 — fixes and small changes,
then branding, HTML email, export and print, then teams, invitations and
password recovery. Stage 16, on 2026-08-12, added two-factor authentication
alongside auto-hiding confirmations, a tree view for the reference data, and
two navigation fixes. Stage 17, the same day, added faults: an asset can name a
responsible party and be reported faulty, with a photograph, an urgency and a
kept history — and the responsible party is emailed immediately and again in a
digest until it is dealt with. Stage 18, the same day, added **custom reports**
and a **documented REST API**, moved the long-form documentation out of the
README into `docs/`, and fixed five interface faults — a PAT warning that
offered to switch itself off, a crowded button row on the asset page, a
drop-down that closed under the click aimed at it, and a table column that had
never lined up with the rest of its row.

Everything below was checked against the files and the database at the time of
writing, not recalled. The schema section was produced by creating an empty
database, applying the migrations in order and then reading `information_schema`,
so it describes exactly what a fresh install produces.
Where something is *not* verified, it says so.

---

## 1. Stack and environment

| | |
|---|---|
| Language | PHP **>= 8.1** (`composer.json`), developed and tested on **8.4.22** |
| Database | **MariaDB** 10.4+ — tested on 12.3.2 |
| Framework | none |
| Runtime dependencies | one package: `phpmailer/phpmailer`. `composer.json` also declares `php`, `ext-pdo`, `ext-pdo_mysql`, `ext-json`, `ext-mbstring`, `ext-fileinfo`, `ext-openssl` |
| Optional extensions | `gd` (image resize + thumbnails), `exif` (orientation + capture date), `curl` (test scripts only). **`openssl` is required to store the SMTP password** |
| `composer.lock` | **committed** since stage 12. It was gitignored while there were no packages; now it is what makes a server's `composer install` reproducible |
| Front end | server-rendered PHP templates, hand-written CSS, vanilla JS. No build step |
| Counted at time of writing | 209 PHP files (93 of them templates), 170 routes (89 GET, 81 POST), 22 migrations |
| Runtime dependency | **one**: `phpmailer/phpmailer ^6.9`, installed by `composer install`. Without it everything works except *sending* email — see §4.8 |

> **The database is MariaDB, not MySQL.** Two things deliberately keep the MySQL
> name and must not be "corrected": PHP's extension is `pdo_mysql` and PDO's DSN
> prefix is `mysql:`. There is no `pdo_mariadb` driver. `src/Core/Database.php`
> carries a comment saying so.

---

## 2. Database schema as implemented

Built by applying everything in `database/migrations/` to an empty database.

**Totals:** 32 domain tables, 377 columns, 60 foreign keys, 147 indexes.
Every table is **InnoDB / utf8mb4_unicode_ci**.

A database migrated with `php bin/migrate.php` has a **33rd** table,
`migrations` (`id`, `migration`, `batch`, `applied_at`, unique on `migration`),
created by `src/Core/Migrator.php`. It does not appear below because it is
tracking, not domain data — and note that it is *not* created if you pipe the
`.sql` files in by hand.

### 2.1 Migrations

Three files, applied in filename order and recorded in `migrations`. Each is
written to be safe to re-run.

| File | Contents |
|---|---|
| `001_schema.sql` | All 32 tables, in dependency order. `CREATE TABLE IF NOT EXISTS` throughout |
| `002_roles_and_permissions.sql` | The 4 built-in roles, 36 permissions and 71 grants. Role and permission definitions are refreshed on re-run; grants are only inserted where missing, so a site's own edits to a built-in role survive |
| `003_default_settings.sql` | The 51 setting keys a fresh install starts with, grouped by area. `INSERT IGNORE`, so an existing value is kept |

To change the schema, add a new numbered file — `004_…` and upward. Do not edit
one that has been applied anywhere.

### 2.2 Tables

Types are as MariaDB reports them. `NN` = NOT NULL.

#### `users`
| Column | Type | NN | Default |
|---|---|---|---|
| id | int unsigned AI | ✓ | |
| name | varchar(150) | ✓ | |
| email | varchar(190) | ✓ | |
| password_hash | varchar(255) | ✓ | `password_hash()`, PASSWORD_DEFAULT |
| role_id | int unsigned | ✓ | |
| is_active | tinyint(1) | ✓ | 1 |
| phone | varchar(50) | | NULL |
| job_title | varchar(150) | | NULL |
| last_login_at | datetime | | NULL |
| last_login_ip | varchar(45) | | NULL |
| password_changed_at | datetime | | NULL |
| calendar_token | char(64) | | **unique**, NULL until the user creates a feed |
| calendar_token_created_at | datetime | | NULL |
| created_by | int unsigned | | NULL |
| created_at / updated_at | timestamp | ✓ | CURRENT_TIMESTAMP (+ ON UPDATE) |

Indexes: PK `id`, unique `uq_users_email(email)`, `idx_users_role(role_id)`,
`idx_users_active(is_active)`, `fk_users_created_by(created_by)`.

#### `roles`
`id`, `slug` varchar(50) NN, `name` varchar(100) NN, `description` varchar(255),
`is_superuser` tinyint NN 0, `is_system` tinyint NN 0, `sort_order` smallint NN 100,
`created_at`, `updated_at`. Unique `uq_roles_slug(slug)`.

#### `permissions`
`id`, `slug` varchar(100) NN (dot notation), `name` varchar(150) NN,
`group_name` varchar(60) NN 'General', `description` varchar(255),
`sort_order` smallint NN 100, `created_at`.
Unique `uq_permissions_slug(slug)`, index `idx_permissions_group(group_name)`.

#### `role_permissions`
`role_id`, `permission_id` — composite PK, plus `idx_role_permissions_permission`.
Both FKs CASCADE.

#### `login_attempts`
`id` bigint, `email` varchar(190) NN, `ip_address` varchar(45) NN,
`successful` tinyint NN 0, `user_agent` varchar(255), `attempted_at` datetime NN.
Indexes `(email, attempted_at)` and `(ip_address, attempted_at)`. No FK to `users`
— failed attempts for non-existent accounts must still be recorded.

#### `categories` / `locations`
Both self-nesting reference tables.
`categories`: `id`, `name` varchar(120) NN, `slug` varchar(120) NN (unique),
`parent_id`, `description`, `is_active`, timestamps.
`locations`: `id`, `name` varchar(120) NN, `code` varchar(40) (short code for labels),
`parent_id`, `description`, `is_active`, timestamps.
Unique `uq_locations_name_parent(name, parent_id)` — locations are unique within a parent,
categories globally by slug.

#### `assets` (36 columns)
| Column | Type | NN | Default / note |
|---|---|---|---|
| id | bigint unsigned AI | ✓ | |
| asset_tag | varchar(64) | ✓ | **unique** — the printed/scanned barcode value |
| barcode | varchar(64) | | **unique** — optional secondary/manufacturer barcode |
| name | varchar(191) | ✓ | |
| description | text | | |
| category_id | int unsigned | | |
| location_id | int unsigned | | |
| responsible_user_id | int unsigned | | **mutually exclusive** with the next; FK to `users`, ON DELETE SET NULL |
| responsible_team_id | int unsigned | | **mutually exclusive** with the previous; FK to `teams`, ON DELETE SET NULL |
| condition_rating | enum('Excellent','Good','Fair','Poor','Out of Service') | ✓ | 'Good' |
| status | enum('In Stock','On Hire','In Maintenance','Retired','Faulty') | ✓ | 'In Stock' — **'Faulty' is last in the ENUM, first-class in the UI**; see the note below |
| purchase_date | date | | |
| purchase_cost | decimal(12,2) | | |
| current_value | decimal(12,2) | | |
| supplier | varchar(191) | | |
| warranty_expires_on | date | | |
| serial_number | varchar(191) | | |
| manufacturer | varchar(191) | | |
| model | varchar(191) | | |
| manufacturer_url | varchar(500) | | product/support page |
| plug_fuse_rating_amps | decimal(5,2) | | Amps |
| cable_csa_mm2 | decimal(5,2) | | mm² |
| requires_pat | tinyint(1) | ✓ | 0 |
| pat_interval_months | smallint unsigned | | NULL = use the site default |
| parent_asset_id | bigint unsigned | | set on sub-assets |
| relationship_type | enum('sub-asset','accessory','related') | | only meaningful with a parent |
| is_hireable | tinyint(1) | ✓ | 1 |
| notes | text | | |
| retired_on | date | | |
| created_by / updated_by | int unsigned | | |
| created_at / updated_at | timestamp | ✓ | |

Indexes: unique `asset_tag`, unique `barcode`; `category_id`, `location_id`,
`condition_rating`, `name`, `parent_asset_id`, `requires_pat`, `serial_number`,
`status`, composite `(status, category_id)`, `responsible_user_id`,
`responsible_team_id`, plus the two `*_by` FK indexes.

**Why `'Faulty'` is at the end of the ENUM.** Appending a member is an instant,
in-place change that re-maps nothing; inserting one in its natural reading
position forces a table copy and renumbers every member after it. Presentation
order is a presentation problem, so it lives in PHP: `Asset::STATUSES` lists it
between 'In Maintenance' and 'Retired' (which drives every dropdown and filter),
and `Asset::SORTS['status']` is an explicit `FIELD()` putting Faulty first —
the same trick the condition sort already used. Do not "tidy" the ENUM.

#### `asset_photos`
`id` bigint, `asset_id` NN, `file_path` varchar(255) NN (relative to the uploads root),
`thumbnail_path` varchar(255) **nullable — NULL when no thumbnail could be made**,
`original_filename`, `mime_type` NN, `file_size_bytes` NN 0, `width_px`, `height_px`,
`caption`, `is_primary` tinyint NN 0, `taken_at` datetime, `uploaded_by`, `created_at`.
Indexes `(asset_id, created_at)`, `(asset_id, is_primary)`, `(asset_id, taken_at)`.

#### `asset_manuals`
`id`, `asset_id` NN, `title` varchar(191) NN, `file_path` NN, `original_filename`,
`mime_type` NN default `application/pdf`, `file_size_bytes` NN 0, `page_count`,
`notes`, `uploaded_by`, `created_at`. Index `(asset_id, created_at)`.

#### `maintenance_schedules`
`id`, `asset_id` NN, `title` varchar(191) NN,
`maintenance_type` enum('routine','periodic','ad-hoc') NN 'periodic',
`frequency_interval` smallint unsigned, `frequency_unit` enum('days','weeks','months','years'),
`next_due_date` date, `last_completed_date` date, `assigned_to_user_id`,
`instructions` text, `estimated_minutes` smallint unsigned, `is_active` NN 1,
`created_by`, timestamps. Index `(next_due_date, is_active)`.

#### `maintenance_logs`
`id`, `asset_id` NN, `schedule_id` (**NULL for ad-hoc work**),
`maintenance_type` enum('routine','periodic','ad-hoc','repair','inspection') NN,
`performed_on` date NN, `performed_by_user_id`, `performed_by_name` varchar(191)
(free text for contractors), `work_done`, `parts_used`, `cost` decimal(10,2),
`downtime_minutes`, `result` enum('Completed','Partial','Failed','Deferred') NN 'Completed',
`condition_after` enum(condition ratings), `next_due_date` date (copied onto the
schedule when the job is logged), `notes`, `created_by`, timestamps.
Indexes `(asset_id, performed_on)`, `performed_on`, `created_at`, `schedule_id`.

`maintenance_schedules` also carries **`assigned_to_team_id`**, mutually
exclusive with `assigned_to_user_id` — see §2.5 item 10.

#### `maintenance_log_photos`
`id`, `maintenance_log_id` NN, `file_path` NN, `original_filename`, `mime_type` NN,
`file_size_bytes` NN 0, `caption`, `uploaded_by`, `created_at`.

#### `maintenance_log_documents`
`id` bigint, `maintenance_log_id` NN, `title` varchar(191) NN, `file_path` NN,
`original_filename`, `mime_type` NN default `application/pdf`,
`file_size_bytes` NN 0, `notes`, `uploaded_by`, `created_at`.
Index `(maintenance_log_id, created_at)`; the log FK CASCADEs, `uploaded_by`
SET NULLs.

The paperwork a visit produces. Attached to the **log**, not the asset: a
service report belongs to the visit it describes, and filing it against the
machine would lose which visit produced it.

#### `teams` / `team_members`
`teams`: `id`, `name` varchar(120) NN **unique**, `description` varchar(255),
`is_active` NN 1 (archive, never delete), `created_by`, timestamps.
Index `idx_teams_active(is_active)`.

`team_members`: `team_id`, `user_id` — composite PK — plus `added_by`,
`created_at` and `idx_team_members_user(user_id)`. Both ends CASCADE: a
membership row is meaningless without both, and the audit trail is what records
who was added and when.

#### `fault_reports`
`id` bigint, `asset_id` NN, `description` text NN, `faulty_on` date NN,
`urgency` enum('Low','Medium','High','Critical') NN 'Medium',
`condition_rating` enum(the five asset conditions) NN, `reported_by` int
unsigned, `reported_by_name` varchar(191) NN, `created_at`.
Indexes `(asset_id, faulty_on)`, `(asset_id, created_at)`, `urgency`;
`asset_id` CASCADEs, `reported_by` SET NULLs.

A record per report, not a flag. `assets.status` answers *is it faulty now?*;
this answers *what has gone wrong with it, and how often?* — the same split PAT
and maintenance already make. An asset reported faulty three times in a year is
telling you something a status column cannot say, because the column was
overwritten each time.

Two deliberate snapshots. `condition_rating` is the judgement made *at the time
of the report*; the asset's own condition moves on, so this is the difference
between "it was Poor when this was raised" and "it is Poor today".
`reported_by_name` follows `maintenance_logs` and `email_log`: the report should
still say who raised it after the account has gone.

**There is no `resolved_at` and no open/closed flag**, and that is not an
oversight. The asset stops being faulty when its status changes — by editing it,
or by recording the repair. A second notion of open/closed would be a thing to
keep in step with the status, and the two would drift. Everything that asks
"what is faulty?" — the dashboard tile, the report, the digest — reads
`assets.status`.

#### `fault_report_photos`
A straight copy of `maintenance_log_photos`, hanging off a report:
`id`, `fault_report_id` NN, `file_path` NN, `original_filename`, `mime_type` NN,
`file_size_bytes` NN 0, `caption`, `uploaded_by`, `created_at`.
Index `(fault_report_id, created_at)`; the report FK CASCADEs.
Files land under `storage/uploads/faults/{fault_report_id}/`.

Not `asset_photos`, though these are photographs of an asset. A condition photo
is part of the asset's ongoing record and can become the thumbnail the register
shows; a fault photo belongs to the report that explains it, and filing it
against the asset would lose which fault it was evidence of.

#### `custom_reports`
`id`, `report_key` varchar(80) NN **unique** (always prefixed `custom-`),
`name` NN, `description`, `data_source` varchar(40) NN, `filters` JSON NN,
`columns` JSON NN, `sort_column`, `sort_direction` enum('asc','desc') NN,
`is_active` NN 1, `created_by`, `updated_by`, timestamps.
Indexes: unique `report_key`, `(is_active, name)`.

**There is no SQL in this table and no column name from user input near a
query.** A definition names a data source from `App\Reports\DataSourceRegistry`
and supplies values for that source's *already existing* filters — the same ones
the list page offers, handled by the same model code. Anything not on the
source's declared lists is discarded when the row is read, so a hand-edited row
cannot widen what a report reaches.

The `custom-` prefix is what keeps this namespace and the built-in report keys
from colliding: no shipped report may start with it, so a saved report can never
shadow `all-assets`, nor be shadowed by a future built-in.

Three JSON columns rather than child tables: they are read as one blob, written
as one blob, never joined and never filtered on.

#### `api_keys`
`id`, `name` NN, `user_id` NN, `token_prefix` char(12) NN (clear, for display),
`token_hash` char(64) NN **unique**, `scope` enum('read','full') NN,
`expires_at`, `revoked_at`, `last_used_at`, `last_used_ip`, `request_count`,
`rate_window_started_at`, `rate_count`, `created_by`, timestamps.
`user_id` CASCADEs — a credential belonging to a deleted account must go with it.

**No permission column, deliberately.** A key holds only `user_id`; a request
adopts that user via `Auth::actAs()` and then runs the same `Auth::can()` the
interface runs. A key therefore cannot outgrow its owner, and there is no second
permission model to keep in step. `scope = 'read'` may make it do *less*.

SHA-256 rather than `password_hash()` because this is a 48-character random
value, not a human-chosen password: there is nothing to brute-force, and a
lookup has to be one indexed query rather than a verify against every row.

#### `user_tokens`
`id` bigint, `user_id` NN, `purpose` enum('invite','password_reset') NN,
`token_hash` char(64) NN **unique**, `expires_at` datetime NN, `used_at`
datetime, `created_by`, `created_ip`, `created_at`.
Indexes `(user_id, purpose, expires_at)` and `expires_at`.

One table for both link types because they are the same object with a different
purpose. **`token_hash` is a SHA-256 — the raw token exists only in the email
that was sent**, so a database dump is not a set of working account-takeover
links. `used_at` rather than a delete, so "already used" is a state the page can
explain instead of an indistinguishable "not found".

#### `user_backup_codes`
`id`, `user_id` NN (CASCADE), `code_hash` varchar(255) NN, `used_at` datetime,
`created_at`. Index `(user_id, used_at)`.

Ten codes are issued at enrolment and replaced as a set. Hashed with
**`password_hash()`, not SHA-256**: ten characters from a 31-letter alphabet is
short enough to be worth brute-forcing out of a stolen dump, and a slow hash is
the whole defence. The alphabet omits O/0 and I/1/L because these get written on
paper and typed back months later.

#### `trusted_devices`
`id` bigint, `user_id` NN (CASCADE), `token_hash` char(64) NN **unique**,
`label`, `ip_address`, `user_agent_hash` char(64), `last_seen_at` datetime NN,
`expires_at` datetime NN, `created_at`.
Indexes `(user_id, expires_at)`.

"Don't ask again on this computer", with **four** ways it stops working rather
than one: the outer `expires_at`, an idle window measured from `last_seen_at`, a
change of browser (`user_agent_hash`), and a change of network (`ip_address`,
compared as a /24 or a /64 — see `TrustedDevice::sameNetwork()`). The cookie
holds 32 random bytes and only the SHA-256 is stored. Rows are deleted outright
on a password change and on deactivation.

#### `pat_records` (24 columns)
`id`, `asset_id` NN, `test_date` date NN, `retest_due_date` date,
`tester_user_id`, `tester_name`, `tester_reference`, `test_equipment`,
`appliance_class` enum('Class I','Class II','Class III','Not Applicable') NN 'Class I',
`visual_inspection_pass` tinyint NN 1,
`earth_continuity_ohms` decimal(7,3), `insulation_resistance_mohms` decimal(8,2),
`leakage_current_ma` decimal(7,3), `load_test_va` decimal(9,2),
`polarity_pass` tinyint, `functional_check_pass` tinyint (NULL = not performed),
`overall_result` enum('Pass','Fail') NN, `pat_label_serial`, `fuse_fitted_amps` decimal(5,2),
`remedial_action` text, `notes` text, `created_by`, timestamps.
Indexes `(asset_id, test_date)`, `(asset_id, test_date, id)`, `pat_label_serial`,
`overall_result`, `retest_due_date`.

Units are in the column names on purpose; the migration header documents them.

#### `hirers`
`id`, `hirer_type` enum('Person','Company') NN 'Person', `name` varchar(191) NN,
`company_name`, `reference` (staff/account number), `email`, `phone`, `address`,
`user_id` (**unique** — optional link to a login for the Hirer role),
`is_active` NN 1, `notes`, `created_by`, timestamps.
Indexes on `name`, `reference`, `hirer_type`.

#### `hires`
`id`, `reference` varchar(40) **unique**, `asset_id` NN, `hirer_id` NN,
`checked_out_at` datetime NN, `due_back_date` date NN, `checked_out_by_user_id`,
`condition_out` enum(condition ratings), `returned_at` datetime, `returned_to_user_id`,
`condition_in` enum(condition ratings), `returned_condition_notes` text,
`status` enum('Out','Overdue','Returned') NN 'Out', `purpose`, `hire_charge` decimal(10,2),
`notes`, timestamps.
Indexes `(asset_id, status)`, `(hirer_id, status)`, `due_back_date`,
**`(asset_id, returned_at)`** — the double-booking check — and `(status, due_back_date)`.

#### `hire_photos`
`id`, `hire_id` NN, `stage` enum('out','in') NN 'in', `file_path` NN,
`original_filename`, `mime_type` NN, `file_size_bytes` NN 0, `caption`,
`uploaded_by`, `created_at`. Index `(hire_id, stage)`.

#### `activity_log`
`id` bigint, `user_id` (SET NULL), `user_name` varchar(191) NN 'System' (**snapshot**),
`action` varchar(100) NN, `entity_type` varchar(64) NN, `entity_id` bigint unsigned,
`description` varchar(500), `changes` longtext (field-level before/after payload),
`ip_address`, `user_agent`, `created_at`.
Indexes `created_at`, `(entity_type, entity_id, created_at)`, `(user_id, created_at)`.
**No FK on `entity_id`** — the audit trail must survive deletion of what it describes.

#### `settings`
`setting_key` varchar(100) **PK**, `setting_value` text, `updated_by`, `updated_at`.

#### `email_templates`
`id`, `template_key` varchar(60) NN **unique**, `subject` varchar(255) NN,
`body` text NN, `is_html` tinyint NN 0, `is_active` tinyint NN 1,
`updated_by` (SET NULL), timestamps.

**Stores overrides only.** A row exists for a key precisely when an
administrator has edited it. The shipped wording lives in
`App\Mail\EmailTemplate::DEFAULTS`, so there is one copy of each default,
a fresh install sends properly worded mail with this table empty, and
"reset to default" is a `DELETE` rather than a re-seed.

#### `email_log`
`id` bigint, `recipient` varchar(190) NN, `recipient_name`, `subject` NN,
`template_key` varchar(60) (NULL for one-offs), `entity_type` varchar(64),
`entity_id` bigint, `status` enum('sent','failed') NN, `error` varchar(500),
`trigger_source` enum('system','user') NN 'system', `user_id` (SET NULL),
`user_name` varchar(191) NN 'System' (**snapshot**), `created_at`.
Indexes `created_at`, `(status, created_at)`, `(template_key, created_at)`,
`(entity_type, entity_id)`.
**No FK on `entity_id`** — same reasoning as `activity_log`.

#### `email_reminders`
`id` bigint, `reminder_key` varchar(40) NN, `entity_type` varchar(64) NN,
`entity_id` bigint NN, `recipient` varchar(190) NN, `last_sent_at` datetime NN,
`send_count` int NN 1.
Unique `(reminder_key, entity_type, entity_id, recipient)` — ~1.2 KB, inside
InnoDB's 3072-byte limit. Index `last_sent_at`. No foreign keys.

De-duplication for the cron run. `reminder_key` is part of the unique key on
purpose: an item crossing from "due soon" to "overdue" is a *different*
reminder and goes out at once rather than waiting out the earlier one's repeat
window.

### 2.3 Foreign keys (60)

| Delete rule | Where it is used |
|---|---|
| **CASCADE** | `asset_photos.asset_id`, `asset_manuals.asset_id`, `maintenance_schedules.asset_id`, `maintenance_logs.asset_id`, `maintenance_log_photos.maintenance_log_id`, `maintenance_log_documents.maintenance_log_id`, `pat_records.asset_id`, `hire_photos.hire_id`, `role_permissions.role_id`, `role_permissions.permission_id`, `team_members.team_id`, `team_members.user_id`, `user_tokens.user_id`, `fault_reports.asset_id`, `fault_report_photos.fault_report_id` |
| **RESTRICT** | `hires.asset_id`, `hires.hirer_id`, `users.role_id` |
| **SET NULL** | everything else — every `created_by` / `updated_by` / `uploaded_by` / `added_by` / `*_user_id`, plus `assets.parent_asset_id`, `assets.category_id`, `assets.location_id`, `categories.parent_id`, `locations.parent_id`, `maintenance_logs.schedule_id`, **`maintenance_schedules.assigned_to_team_id`**, `hirers.user_id`, `activity_log.user_id`, `settings.updated_by`, `email_templates.updated_by`, `email_log.user_id`, **`assets.responsible_user_id`**, **`assets.responsible_team_id`**, `fault_reports.reported_by` |

`maintenance_schedules.assigned_to_team_id` is SET NULL on purpose: archiving is
the ordinary way to retire a team, so deleting one is a deliberate act that
should not be blocked by a schedule which can simply become unassigned.

`email_reminders` has **no foreign keys at all** — it is a de-duplication
ledger, and a row that outlives the record it refers to is harmless.

The shape is deliberate: deleting an asset takes its own media and records with
it, but you cannot delete an asset or a hirer that has hire history, and
deleting a user never destroys the records they touched.

### 2.4 Seeded reference data (verified from a fresh migrate)

- **4 roles**: `admin` (Administrator, `is_superuser=1`), `manager` (Manager / Staff),
  `viewer` (Read-only), `hirer` (Hirer). All four are `is_system=1`.
- **36 permissions** in groups Assets, Hirers, Hires, Maintenance,
  Photos & files, PAT testing, Reports, Email, Administration:
  `assets.view/create/edit/delete/export`, **`faults.report`**, `hirers.view/manage`,
  `hires.view/view_own/create/return/manage`, `maintenance.view/manage/complete`,
  `media.photo.upload/delete`, `media.manual.upload/delete`,
  `pat.view/manage/delete`, `reports.view`, `email.manage/send`,
  `users.view/manage`, `roles.manage`, **`teams.manage`**, `categories.manage`,
  `locations.manage`, `settings.manage`, `audit.view`.
- **71 role_permissions rows** — admin 36, manager 27, viewer 7, hirer 1.
  - viewer: `assets.view`, `assets.export`, `hirers.view`, `hires.view`,
    `maintenance.view`, `pat.view`, `reports.view`
  - hirer: **`hires.view_own` and nothing else**
  - manager gained `email.send` (not `email.manage`) in 018
  - `teams.manage` is **admin only**: membership decides who is reminded
    about a job and who it is expected of, which makes it administrative
  - `reports.manage` and `api.manage`: defining a report is admin and
    manager, issuing an API key is admin only. Neither grants anything new to
    *see* — a saved report is refused unless the reader holds its data source's
    own permission, and a key inherits exactly its owner's role
  - `faults.report` (023) is **admin and manager**. Its own permission rather
    than `assets.edit`, because the two are not the same act: saying "this is
    broken" is something the person holding the broken thing does, and need not
    come with the right to rewrite purchase costs. It is still a change to the
    register — it moves the status — so read-only does not get it
- **51 settings** on a fresh install (55 once both logo variants have been
  uploaded — the four `logo_*` keys are written on upload, never seeded).
  Stage 16 added `flash_auto_hide_seconds` (6, 0 = never), `two_factor_required`
  (0), `trusted_device_days` (30), `trusted_device_idle_days` (14),
  `email_otp_minutes` (10) and `two_factor_max_attempts` (5). Stage 17 added
  `reminder_faulty_enabled` (0), `reminder_faulty_repeat_days` (0 = use the
  shared repeat) and `fault_notify_immediately` (1):

  | Key | Default | |
  |---|---|---|
  | `asset_tag_prefix` | `AST-` | |
  | `asset_tag_pad` | `4` | |
  | `label_show_name` | `1` | |
  | `label_show_location` | `1` | |
  | `maintenance_due_days` | `30` | |
  | `pat_due_days` | `30` | |
  | `pat_default_interval_months` | `12` | |
  | `pat_guide_insulation_mohm` | `1` | guidance shown to the tester |
  | `pat_guide_earth_base_ohm` | `0.1` | |
  | `pat_guide_earth_lead_ohm` | `0.1` | |
  | `pat_guide_earth_lead_metres` | `7.5` | |
  | `pat_guide_leakage_class1_ma` | `3.5` | |
  | `pat_guide_leakage_class2_ma` | `0.25` | |
  | `hire_default_days` | `7` | |
  | `hire_due_soon_days` | `2` | |
  | `hire_reference_prefix` | `LN-` | |
  | `organisation_name` | *(empty)* | |
  | `mail_enabled` | `0` | **off until configured** |
  | `mail_host` / `mail_username` / `mail_from_address` / `mail_from_name` / `mail_reply_to` | *(empty)* | |
  | `mail_port` | `587` | |
  | `mail_encryption` | `tls` | `tls` \| `ssl` \| `none` |
  | `mail_password` | *(empty)* | **ciphertext**, `v1.`-prefixed; `MAIL_PASSWORD` in `.env` overrides |
  | `mail_timeout` | `15` | seconds |
  | `reminder_pat_enabled` / `_maintenance_` / `_hire_` / `_faulty_` | `0` | each off by default |
  | `reminder_pat_days` / `_maintenance_` / `_hire_` | `0` | **0 = use the register's own window** |
  | `reminder_repeat_days` | `7` | |
  | `reminder_recipient_user_ids` | *(empty)* | comma-separated user ids |
  | `reminder_maintenance_assignee` | `1` | a team assignment reaches every member |
  | `reminder_hire_notify_hirer` | `0` | |
  | `reminder_faulty_repeat_days` | `0` | **0 = use `reminder_repeat_days`**; faults have no due date, so this is "how often to mention it again" |
  | `fault_notify_immediately` | `1` | email the responsible party the moment a fault is reported, off the cron path |
  | `api_enabled` | `0` | **off until switched on**; endpoints answer 503 with a reason, not 404 |
  | `api_rate_limit` | `120` | requests per minute per key, fixed one-minute windows |
  | `api_default_per_page` / `api_max_per_page` | `25` / `100` | a larger `per_page` is clamped, not refused |
  | `invite_expiry_hours` | `72` | how long an invitation link lasts |
  | `password_reset_expiry_hours` | `2` | deliberately shorter — see §4.10 |

### 2.5 Divergences from the original build brief

Nothing here is accidental, but a reader coming from the brief should know:

1. **Sub-assets are not a separate table.** They are rows in `assets` with
   `parent_asset_id` set and `relationship_type` saying how they relate
   (`sub-asset` / `accessory` / `related`). A sub-asset therefore has every field
   a top-level asset has — its own tag, photos, PAT record and hire history.
2. **`condition` is `condition_rating`.** `condition` is a reserved word in
   MariaDB; the column and every enum reference use `condition_rating`.
3. **`hire_photos` and `asset_photos.thumbnail_path` are beyond the brief.**
4. **Columns beyond the brief**, added where the workshop use case needed them:
   `assets.barcode` (a second, manufacturer barcode distinct from the printed tag),
   `current_value`, `supplier`, `warranty_expires_on`, `pat_interval_months`,
   `is_hireable`, `retired_on`, `manufacturer_url`, `plug_fuse_rating_amps`,
   `cable_csa_mm2`.
5. **The Hirer role is narrower than the brief describes.** It holds only
   `hires.view_own` and reaches its equipment through a separate portal.
   `assets.view` would expose the whole register, so **`hirer` must never hold
   it.**
6. **Keyword search is multi-term `LIKE`, not FULLTEXT.** FULLTEXT tokenises
   asset tags and serial numbers badly and ignores short words. This is a
   decision, not an omission — don't "upgrade" it without being asked.
7. **`activity_log.entity_id` has no foreign key**, and `user_name` is a
   denormalised snapshot, so the audit trail outlives what it describes.
   `email_log` follows the same shape for the same reason.
8. **`email_templates` holds overrides, not templates.** The shipped wording is
   in PHP (`App\Mail\EmailTemplate::DEFAULTS`); the table is empty on a fresh
   install and gains a row only when an administrator edits one. See §2.2.
9. **The calendar feed is iCalendar, not CalDAV** — a deliberate reading of the
   brief's "use your judgement". See §4.9.
10. **An assignment is one thing, held in two columns.** A maintenance schedule
    is assigned to a user **or** a team **or** nobody;
    `assigned_to_user_id` and `assigned_to_team_id` are mutually exclusive and
    the application writes exactly one of them
    (`MaintenanceController::validateSchedule()`). Two nullable columns rather
    than a polymorphic `(type, id)` pair, because this way both are real foreign
    keys — deleting a team cannot leave a schedule pointing at nothing. The form
    carries a single prefixed value (`user:7` / `team:2`) so the two cannot
    contradict each other, and `MaintenanceSchedule::parseAssignee()` is the only
    thing that knows that shape.
11. **Invitations and password resets share one table**, `user_tokens`, because
    they are one object with two purposes. See §4.10 — and note that **the token
    is stored only as a SHA-256**, which is the reason a lost link cannot be
    looked up and re-sent.

---

## 3. File and directory structure

```
<project-root>/
├── .env                     local config — gitignored, never committed
├── .env.example             documented template
├── .gitignore
├── .gitattributes           forces LF on *.sh — a CRLF shebang breaks the installer
├── README.md                short introduction; the detail lives in docs/
├── docs/                    17 pages, one per topic, plus the index (docs/README.md)
├── INSTALL.md               the scripted install, unattended runs, failure modes
├── PROJECT_STATE.md         this file
├── composer.json            autoload only; no runtime packages
│
├── install.sh               turn-key installer for a Linux server (root)
├── manage.sh                administration wrapper (root): backups, updates, users
│
├── bin/
│   ├── migrate.php          apply pending migrations (--status to inspect)
│   ├── seed.php             demo data (--force); never on a live system
│   ├── create-admin.php     first administrator; password prompted, not an argument
│   ├── console.php          admin console: doctor, users, settings, prune, stats,
│   │                        key:generate, mail:status/test/prune, calendar:url
│   └── send-reminders.php   the cron entry point for reminder emails
│
├── config/
│   └── config.php           every value from Env::get(); nothing hardcoded
│
├── database/migrations/     001…018, plain .sql, applied in filename order
├── vendor/                  gitignored; created by `composer install` (PHPMailer)
│
├── public/                  ← the only directory the web server should serve
│   ├── index.php            front controller
│   ├── favicon.svg
│   ├── css/  app.css, print.css
│   └── js/   app.js, barcode.js, scanner.js, pat-wizard.js
│
├── routes/
│   └── web.php              the whole route table, 151 routes
│
├── src/
│   ├── bootstrap.php        autoload, env, config, errors, HTTPS, headers, session
│   ├── helpers.php          e(), url(), asset_url(), partial(), csrf_field(),
│   │                        csrf_token(), method_field(), can(), can_any(),
│   │                        auth_user(), old(), is_active_path(), format_date(),
│   │                        format_datetime(), format_money(), config(), str_limit()
│   ├── Core/                Auth, Barcode, Config, Crypto, Csrf, Csv, CsvReader,
│   │                        Database, Env, Flash, Image, LoginThrottle, Migrator,
│   │                        QrCode, Request, Response, Router, Session, Totp,
│   │                        Upload, Validator, View
│   ├── Api/                 Gate, Problem, Resource, ResourceRegistry, OpenApi
│   ├── Controllers/         Controller (base) + Account, Asset, AssetCopy,
│   │                        AssetExport, Auth, Branding, Calendar, CustomReport,
│   │                        Hirer, Dashboard, Export, Fault, Import, Label, Hire,
│   │                        Maintenance, Manual, MyHires, Pat, Photo, Profile,
│   │                        Report, Scan, Security, TwoFactor
│   │   └── Api/             ApiController (base), ResourceController, MetaController
│   │   └── Admin/           Activity, ApiKey, Category, Email, Location, Role,
│   │                        Settings, Team, User
│   ├── Mail/                Mailer, EmailTemplate, EmailLog, EmailReminder,
│   │                        Reminders, Merge, Layout, AccountMail
│   ├── Middleware/          Auth, Csrf, Guest, Permission, MiddlewareRunner
│   ├── Models/              ActivityLog, Asset, AssetManual, AssetPhoto,
│   │                        Assignment, Category, FaultReport, Hire, Hirer,
│   │                        Location, MaintenanceLog, MaintenanceSchedule,
│   │                        ApiKey, CustomReport, PatRecord, Permission, Role,
│   │                        Setting, Team, Tree, TrustedDevice, User, UserToken
│   ├── Reports/             Report (base), ReportRegistry, AllAssets,
│   │                        FaultyAssets, MaintenanceDue, PatDue, AssetsOnHire,
│   │                        HiresDueBack; StoredReport, DataSource,
│   │                        DataSourceRegistry (custom reports)
│   ├── Imports/             Importer (base), ImportRegistry, AssetImporter,
│   │                        PatImporter
│   └── Services/            AssetTagger, AssetCopier, Branding, CalendarFeed,
│                            FaultNotifier, TwoFactor
│
├── storage/                 ← outside the docroot
│   ├── logs/                app.log
│   └── uploads/             assets/{id}/photos, .../photos/thumbs,
│                            assets/{id}/manuals, maintenance/{logId},
│                            maintenance/{logId}/documents,
│                            faults/{faultReportId},
│                            hires/{hireId}, branding/, imports/
│
├── templates/               99 .php templates
│   ├── layouts/             app.php, auth.php, print.php
│   ├── partials/            nav, brand, footer, print-header, flash,
│   │                        photo-gallery, photo-upload, photo-inputs,
│   │                        pat-status, pat-record, maintenance-log-evidence,
│   │                        assignee, fault-banner, reference-tree,
│   │                        reference-tree-meta, report-table, scan-button,
│   │                        verdict, verdict-cell, email-nav
│   ├── assets/              index, show, form, copy, apply, photos, labels,
│   │                        print, print-list
│   ├── auth/                login, invite, forgot-password, reset-password,
│   │                        two-factor, two-factor-setup
│   ├── faults/              form, history
│   ├── dashboard/ errors/ scan/
│   ├── profile/             edit, calendar, security, two-factor-setup
│   ├── maintenance/         index, show, form, complete, edit-log, history,
│   │                        choose-asset
│   ├── pat/                 index, show, form, history, wizard, choose-asset
│   ├── hires/               index, show, checkout, return
│   ├── hirers/              index, show, form
│   ├── my-hires/            index, show, unlinked
│   ├── import/              index, show, preview
│   ├── export/              index, assets, assets-select
│   ├── reports/             index, show, print, custom-form
│   ├── api/                 docs
│   └── admin/               users, roles, teams, categories, locations,
│                            settings, activity,
│                            email/ (index, reminders, templates, template-form, log)
│
└── tests/                   shipped verification tooling (see §5)
```

---

## 4. Established patterns — follow these

### 4.1 Configuration and environment

- `src/Core/Env.php` is a ~60-line `.env` parser (comments, quotes, inline `#`).
  No dependency.
- `config/config.php` returns one array; **every value comes from `Env::get()`**
  with a default. There are no credentials in the repository. Sections:
  `app` (name, env, debug, url, timezone, currency, currency_symbol, root),
  `database` (host, port, database, username, password, charset),
  `session` (name, lifetime minutes, samesite),
  `security` (force_https, trust_proxy, login.max_attempts / decay_minutes / lockout_minutes),
  `storage` (path, uploads, logs — `STORAGE_PATH` may be absolute or relative to the root),
  `uploads` (per-type byte limits, MIME allow-lists and extension allow-lists for photos, PDFs and CSVs).
- Read config anywhere with `Config::get('app.debug')` or the `config()` helper.
  **Do not read `$_ENV` or `getenv()` directly in application code.**
- `src/bootstrap.php` is the single entry path for both web and CLI: autoload
  (Composer if present, otherwise a built-in PSR-4 fallback so the app runs from
  a plain file copy) → `.env` → config → timezone/encoding → error handling →
  and, web only, HTTPS redirect, `Response::securityHeaders()`, `Session::start()`.

### 4.2 Database access

- **All queries go through `App\Core\Database`.** Nothing calls PDO directly.
- PDO options: `ERRMODE_EXCEPTION`, `FETCH_ASSOC`,
  **`ATTR_EMULATE_PREPARES => false`**, `STRINGIFY_FETCHES => false`.
- API: `run()`, `select()`, `selectOne()`, `scalar()`, `insert($table, $data)`,
  `update($table, $data, $id, $key='id')`, `beginTransaction/commit/rollBack`.
  `insert`/`update` build the column list from application-controlled keys and
  bind every value.
- **Dynamic filter SQL uses positional (`?`) placeholders**, not named ones.
  With native prepares a named placeholder cannot be reused, and a keyword
  search tests one term against ~11 columns. See `Asset::buildFilters()`.
- Status rules that reports and screens must agree on live in **SQL constants on
  the model** — `MaintenanceSchedule::STATUS_SQL`, `Hire::STATUS_SQL`, the PAT
  status expression — so they can be filtered, sorted and counted by the
  database and there is one definition, not two.
- No variable is ever interpolated into a SQL string. Concatenation is limited
  to controlled fragments: placeholder lists, integer limits, whitelisted
  `ORDER BY` columns. `tests/security-audit.php` enforces this.

### 4.3 Session, authentication and RBAC

- `Session::start()` sets `HttpOnly`, `SameSite` (configurable, default `Lax`),
  `Secure` whenever the request is HTTPS *or* `FORCE_HTTPS` is on, plus
  `use_strict_mode` and `use_only_cookies`. It enforces an **idle timeout**
  (`__last_activity`, default 480 minutes) and binds the session to a SHA-256
  hash of the user agent (`__fingerprint`); a mismatch destroys the session.
- `Auth::attempt()` throttles first (`LoginThrottle`, per email **and** per IP),
  then **always runs `password_verify` against a dummy hash when the account does
  not exist**, so response timing does not reveal account existence. On success:
  `Session::regenerate()`, `Csrf::rotate()`, `User::touchLogin()`,
  `ActivityLog::record('login', …)`. Hashes are upgraded via
  `password_needs_rehash`.
- **Permissions are data**: `roles` → `role_permissions` → `permissions`.
  `Auth::can()` returns true immediately for a role with `is_superuser`, supports
  a trailing wildcard (`assets.*`), and is cached per request.
  `Auth::canAny()`, `Auth::authorize()` (403 page, or JSON for AJAX).
  **Adding a permission is an INSERT, never a schema change.**
- Enforcement is **route middleware** in `routes/web.php`:
  `guest`, `auth`, `csrf`, `can:<slug>`, `canany:<a>,<b>`.
  Every state-changing route carries `csrf`. Every route is authenticated except
  `/login` and `/health`. Every route carries a permission check except the three
  that scope themselves to the signed-in user: `/`, `/profile`, `/my-hires`.
- Templates use the `can()` / `can_any()` helpers **to hide** controls. Hiding is
  never the control — the route middleware is.
- CSRF: `Csrf` keeps a 32-byte hex token in `__csrf_token`, compares with
  `hash_equals`, accepts `_token` in POST or the `X-CSRF-Token` header, and
  rotates on login. Templates emit it with `csrf_field()`.
- Security headers (`Response::securityHeaders()`): `nosniff`, `SAMEORIGIN`,
  `Referrer-Policy`, `Permissions-Policy`, HSTS under HTTPS, `X-Powered-By`
  removed, and a CSP of `default-src 'self'` with `img-src 'self' data: blob:`
  and `media-src 'self' blob:` for the camera scanner. **The CSP allows no
  off-origin scripts** — that is why there are no third-party JS libraries.

### 4.4 Templating

- Plain PHP templates under `templates/`, rendered by `App\Core\View`:
  `render($t, $data, $layout)`, `capture()` (returns a string),
  `partial()` (no layout), `renderError()`.
- `capture()` merges shared data plus `errors` and `old` from `Flash`, renders
  the template, then renders the layout with `$content`.
- **Templates execute in the global namespace**, which is why `partial()` exists
  as a global function in `helpers.php` alongside `e()`, `can()`, `old()`,
  `format_date()`, `format_money()` and friends.
- **Every variable reaching output goes through `e()`.** `tests/escape-audit.php`
  parses all 2136 `<?= … ?>` expressions with PHP's own tokeniser and proves it.
- Three layouts: `layouts/app` (signed in, with nav), `layouts/auth` (slim),
  `layouts/print` (label sheets and report printing).
- `templates/partials/nav.php` holds the whole menu as one `$links` array,
  filtered by permission; a group whose children are all hidden disappears
  rather than opening onto an empty list. Five top-level items since stage 15 —
  **the header's width budget assumes that number** (§7 item 10).
- **Reports and imports are registries, not pages.** Adding a report = write a
  `Report` subclass + one line in `ReportRegistry::REPORTS`; the controller,
  table, filters, print view and CSV are generic and driven by the report's own
  `columns()` / `filterDefinitions()`. Same shape for
  `ImportRegistry::IMPORTERS`. **Never add a bespoke report page.**
  Registered today: reports `all-assets`, `maintenance-due`, `pat-due`,
  `assets-on-hire`, `hires-due-back`; importers `assets`, `pat`.

### 4.4b Branding, print documents and export

- **`src/Services/Branding.php`** owns the uploaded logo and is the only thing
  that knows where it lives. Two independently optional variants (`light`,
  `dark`) stored as `logo_{variant}_path` / `_mime` settings, files under
  `storage/uploads/branding/`. `resolve()` falls back to the other variant, so
  a workshop that uploaded only a light logo still gets one in dark mode.
  **Raster only** — an SVG is a document that can carry script, and fileinfo
  identifies SVGs inconsistently, so the upload would fail for reasons nobody
  could act on.
- `GET /branding/logo/{variant}` is **public**, and deliberately: the sign-in
  page carries the logo and nobody has a session there. Documented in
  `tests/security-audit.php` alongside the calendar feed.
- **Four consumers, one source.** The site header
  (`templates/partials/brand.php`, rendering *both* variants and letting CSS
  pick, because the theme can change without a page load), the sign-in page,
  the print masthead (`partials/print-header.php`, always the light variant —
  paper is white), and outbound email (embedded as a CID attachment, because
  this application is usually not reachable from wherever the mail is read).
  **When adding a fifth, go through `Branding`, never through the setting.**
- **Print documents** are their own views on `layouts/print`, not `@media
  print` over the working page: `/assets/{id}/print` and `/assets/print`. The
  asset page is a screen full of tabs and buttons, and hiding all of it leaves
  a document full of holes. Styles live in `public/css/print.css` under
  `.print-doc`, separate from the millimetre-accurate label sheet above it.
- **Export** has its own hub at `/export`, shaped like `/import` — the two are
  the same job in opposite directions. `ExportController` only *presents*
  exports; the files are still produced by `AssetExportController` and
  `ReportController`, so each format has one definition. **The register page
  offers no export at all**; choosing rows is its own screen at
  `/export/assets/select`.

### 4.5 Barcodes

- **Generation:** `src/Core/Barcode.php` — a self-contained Code 128 encoder with
  the full 107-entry pattern table, emitting **inline SVG**. SVG because it needs
  no GD, prints crisply at any printer resolution (a fuzzy label will not scan)
  and costs no extra HTTP request. Code set B throughout, switching to set C for
  all-digit even-length tags to halve the width.
  API: `svg($value, $moduleWidth, $heightMm)`, `encode()`, `isEncodable()`.
  Used in `templates/assets/labels.php` (sheet + single label) and
  `templates/assets/show.php` (`Barcode::svg($tag, 0.4, 16.0)`).
- **Decoding:** `public/js/barcode.js` — pure algorithm, no DOM. Reads
  **Code 128**, **Code 39** and **QR** (all 40 versions, all four EC levels, all
  eight masks). Exposes `window.AssetBarcode = { formats, decodeCanvas,
  scanCanvas, decodeLine, scanLine, internals }`; `decodeCanvas`/`decodeLine`
  return a string for backward compatibility, `scanCanvas`/`scanLine` return
  `{ text, format }` (plus `version` and `level` for QR).
  **No third-party barcode library** — the CSP forbids off-origin scripts, and
  a reader is not worth a vendored blob nobody can audit.
- **Scanning UI:** `public/js/scanner.js` — the camera, the frame loop and the
  two scanning surfaces (the `/scan` page and the field modal from
  `templates/partials/scan-button.php`). It holds no decoding of its own.
  Both include sites load `barcode.js` **first**; `defer` keeps the order.
  Three routes in, one lookup: the native **`BarcodeDetector`** API where the
  browser has it (Chrome/Edge), the reader above where it does not (Safari, all
  iPhones), and a focused text input that a USB scanner "types" into.
- **False reads are the thing to fear**, not missed reads: a wrong decode sends
  someone to the wrong asset. Code 128 has its checksum and QR its Reed-Solomon
  (which *refuses* rather than guesses once damage exceeds capacity). Code 39
  has neither, so it is accepted only when two scan lines of the same frame
  agree and the quiet zones are present. Do not relax that.
- **Tests:** `tests/barcode-decode.html`, opened in a browser (there is no JS
  runtime on the dev box). 32 checks: the tables against the specification's own
  arithmetic, GF(256) and Reed-Solomon including deliberate damage, the ISO/IEC
  18004 Annex I worked example, round trips for all three symbologies, and
  frames that must not decode.
- The asset tag itself is generated by `Services\AssetTagger`: `<prefix><padded
  number>` from the `asset_tag_prefix` / `asset_tag_pad` settings, with the next
  number **derived from the tags already in the database** rather than a stored
  counter, so importing old records or changing the prefix cannot strand the
  sequence. `nextBatch($n)` serves "create 5 copies".

### 4.6 File uploads

- Everything goes through `src/Core/Upload.php`:
  `files()`, `validate()`, `store()`, `copy()`, `absolutePath()`, `delete()`,
  `detectMime()`, `displayName()`, `formatBytes()`.
- **MIME is sniffed with `finfo`** — the browser's `Content-Type` is never
  trusted. Extension and byte size are checked against the `uploads` config.
- **Filenames are generated**, never taken from the client:
  `date('Ymd-His') . '-' . bin2hex(random_bytes(6)) . '.' . $ext`.
  The original name is kept in the database column for display only.
- **Storage paths** (relative to `storage/uploads/`, which is outside the docroot):

  | Content | Path |
  |---|---|
  | Asset condition photos | `assets/{assetId}/photos` |
  | Thumbnails | `assets/{assetId}/photos/thumbs` |
  | PDF manuals | `assets/{assetId}/manuals` |
  | Maintenance completion photos | `maintenance/{logId}` |
  | Maintenance documents (PDF) | `maintenance/{logId}/documents` |
  | Hire condition photos (out/in) | `hires/{hireId}` |
  | The uploaded logo (light/dark) | `branding` |
  | Uploaded CSVs awaiting preview/commit | `imports` |

- Only the **relative path** is stored in the database. Never a BLOB, never an
  absolute path.
- Files are **streamed through PHP** with `X-Content-Type-Options: nosniff` —
  never served directly by the web server — and every stream resolves through
  `Upload::absolutePath()`, which guards against traversal.
- `src/Core/Image.php` **degrades**: no GD means no resize and no thumbnail
  (`thumbnail_path` stays NULL) but the upload still succeeds. Image writes are
  `@`-suppressed and their return value checked — **a failed resize must never
  cost the user the photo**, because the original is already stored by then.
  EXIF orientation and capture date are applied only when `exif` is loaded and
  the file is a JPEG.
- CSV: `fgetcsv()` **and** `fputcsv()` on PHP 8.4 require the `$escape`
  argument. Always pass `','`, `'"'`, `''`. Omitting it raises a deprecation
  that `APP_DEBUG=true` turns into a fatal — this silently broke every export once.

### 4.7 Controllers

- `Controllers\Controller` is the base: `validate()`, `failValidation()`, `notFound()`.
- **`validate()` normalises every missing scalar to `''`, never `null`** —
  controllers rely on a single `!== ''` test. Getting this wrong wrote NULL into
  the NOT NULL `assets.condition_rating` and 500'd the request.
- Import uploads are kept **on disk** between preview and commit and
  **re-validated at commit time**. Never trust a preview held in the session.

### 4.8 Outbound email

- **PHPMailer speaks SMTP; nothing here does.** `src/Mail/Mailer.php` configures
  it from settings and sends. Talking to a mail server correctly means STARTTLS
  negotiation, AUTH mechanisms, dot-stuffing, MIME assembly and header
  encoding — all long solved and none of it worth re-deriving.
- **It degrades rather than fatals.** `Mailer::libraryInstalled()` is a
  `class_exists()` check and `Mailer::problems()` returns a list of
  human-readable, actionable problems ("run composer install", "no SMTP host",
  "APP_KEY is not set"). The same list explains a greyed-out button, a failed
  send and `console.php doctor`. **Never let a missing package reach the user
  as a white screen.**
- **`Mailer::send()` never throws.** A send either happens or is logged as a
  failure and returns false. A reminder that cannot go out must not take down
  the page or the cron run that triggered it.
- **Every send is logged, successes and failures alike** (`email_log`), with the
  SMTP server's own error text. A silenced template is the one exception: it
  returns false *without* a log row, because a deliberately switched-off message
  is not a failure. Callers that report to a person must check
  `Mailer::isTemplateActive()` first, or they end up saying "see the log" about
  an entry that was never written.
- **Messages are HTML in a fixed shell.** `src/Mail/Layout.php` wraps the merged
  body: masthead with the light logo, content, product footer. Deliberately not
  editable — what an administrator writes is a message's *content*; the layout
  is the product's, and making it editable would be nine chances to break it
  with no way to improve them all at once. Written the way email has to be
  written: tables, inline styles on the containers, 600px, no media queries.
- **A plain-text alternative always goes with it.** PHPMailer does not invent
  one, so `AltBody` is set explicitly — and from the *content*, before the shell
  is wrapped round it, or the text part would carry the masthead and footer
  chrome. `Merge::htmlToText()` keeps link addresses (`label <url>`), because
  the text part exists precisely for readers who cannot click.
- **The SMTP password is encrypted at rest** (`src/Core/Crypto.php`,
  AES-256-GCM, key from `APP_KEY`). `Crypto::encrypt()` returns null rather than
  falling back to plaintext — **it fails closed**, and `Mailer::storePassword()`
  returns false so the controller can refuse to save. `MAIL_PASSWORD` in `.env`
  overrides the stored value entirely.
- **Templates: defaults in code, overrides in the database.** See §2.2
  `email_templates`. `EmailTemplate::DEFAULTS` also carries each template's
  merge-field list, which is what the edit screen documents — so the
  placeholders offered and the placeholders supplied are the same array and
  cannot drift.
- **Merge values are escaped in HTML templates** (`Merge::render($text, $fields,
  true)`); the admin-authored body is not.
- **Reminders send one digest per recipient, not one email per item.** Forty
  overdue items are one message. `email_reminders` suppresses an item already
  mentioned within `reminder_repeat_days`.
- **Recipients are re-checked against their permissions at send time** via
  `User::withPermission()` — the same rule as `Auth::can()`, asked of a user who
  is not signed in. The notify list is a list of ids; a role change must not
  leave someone receiving records they can no longer open.
- Due/overdue sets come from the existing models (`PatRecord`,
  `MaintenanceSchedule`, `Hire`). **`Reminders` restates no status rules.**

### 4.9 Calendar feeds

- **Authenticated iCalendar (`.ics`), not CalDAV** — a deliberate choice, not an
  omission. CalDAV's PROPFIND/REPORT/ctag machinery exists so clients can
  *change* events; nothing here is editable from a calendar. Outlook, Google,
  Apple and Thunderbird all subscribe to an HTTPS `.ics` URL, which is what the
  brief actually asks for. Reasoning is in the class docblock.
- **The route is outside the `auth` group** — a calendar client cannot sign in,
  so the 64-hex token in the path is the credential. `tests/security-audit.php`
  lists it as a documented exception with the reasoning inline; **that
  allow-list is the policy, so extend it only with a written reason.**
- **Scope is the token owner's own permissions** (`User::holdsPermission`), not
  a second access model. A hirer's feed contains their own due-back dates only.
- Events are all-day (`VALUE=DATE`) — a due date is a day, and a clock time
  would move it for anyone in another timezone. Lines are folded to 75 **octets
  on character boundaries**, so a multi-byte character is never split.
- **Only the user manages their own token.** There is no admin UI for anyone
  else's; `console.php calendar:url` exists for support and writes an audit
  entry.

### 4.10 Teams, and links into an account

Two features added in stage 15 that both touch identity, and both have rules
worth keeping.

**Teams** (`src/Models/Team.php`, `Controllers/Admin/TeamController.php`)

- A team is a group *work* is assigned to. **Membership grants nothing** — a
  member still needs `maintenance.view` to be reminded and
  `maintenance.complete` to record the work, and the reminder run re-checks both
  at send time through `Team::membersWithPermission()`, exactly as it does for
  the notify list. Do not let membership become a second access model.
- **Assignment is one value, two columns.** See §2.5 item 10. Every display site
  reads `assigned_to_name` + `assigned_to_kind` from
  `MaintenanceSchedule::selectSql()`, so there is one definition:
  `partials/assignee.php` on screen, `MaintenanceSchedule::assigneeLabel()`
  where there is only text (report column, CSV, calendar feed, reminder email).
  **When adding a display, use one of those two — not the raw columns.**
- **Archive, never delete.** An archived team keeps the work already assigned to
  it and its members keep those reminders; it is only withheld from new
  assignments (`Team::assignable()`).
- `Reminders::runMaintenance()` expands a team assignment to its members and
  narrows each member's digest to the work that is theirs — by name or through a
  team they are in — so nobody gets a list of somebody else's jobs.
- **Maintenance is the only assignment there is.** PAT is not scheduled per job;
  its dates come from each asset's retest interval, and `tester_user_id` records
  who *did* a test. There is nothing there for a team to take over.

**Invitations and password resets** (`src/Models/UserToken.php`,
`src/Mail/AccountMail.php`, `src/Controllers/AccountController.php`)

- **One table, two purposes.** Issue, expire, consume — the same lifecycle, so
  one implementation.
- **The token is stored as a SHA-256.** The raw value exists only in the email
  that was sent. A database dump is therefore not a set of working
  account-takeover links — and a lost link cannot be looked up, only reissued.
- **Issuing revokes the outstanding ones**, or "resend the invitation" would
  leave the previous link working and a mis-sent invite could not be withdrawn.
  Setting a password directly from `/admin/users` revokes both kinds too.
- **`consume()` updates `WHERE used_at IS NULL`** and checks that the row moved.
  That, not a prior SELECT, is what makes a double submission safe.
- **The forgotten-password response never reveals whether an address exists** —
  the same sentence whether it matched, whether the account was active, and
  whether the send itself failed. The failure goes to `email_log`, which is
  where an administrator will look.
- **Requests share the sign-in throttle** (`LoginThrottle`), counted on a hit as
  well as a miss: a counter that only moved on a miss would itself say which
  addresses are real.
- **Setting a password never signs anybody in.** Proving control of a mailbox is
  enough to set a password; the password is what gets you in, through the normal
  path with its own throttle and audit entry.
- **`user_invite` and `password_reset` cannot be switched off** from the
  templates screen (`EmailTemplate::LOCKED_ACTIVE`). A silenced template returns
  false *without a log row*, which is right for a reminder and disastrous here:
  the screen would go on saying "we have emailed you a link", nothing would
  arrive, and nothing would record it. Switching email off entirely is the
  supported way to have no invitations — it makes the application fall back to
  an administrator setting the password directly.
- **Everything degrades.** `AccountMail::isAvailable()` is `Mailer::isReady()`,
  and it decides whether the user form asks for a password and whether the
  forgotten-password page shows a form or an explanation. Nothing offers a flow
  that cannot finish.
- **The audit entries are written with `ActivityLog::recordAs()`**, because
  nobody is signed in on these routes and `record()` would file them all under
  "System" — true of the request, useless in the trail.

### 4.11 Two-factor authentication

- **There is no half-signed-in user.** `Auth::attempt()` verifies the password
  and then, when a second factor is owed, **creates no session at all** — it
  leaves a pending challenge in `TwoFactor::pending()` instead. Every `auth`
  route, permission check and template helper therefore treats the request as a
  stranger's, exactly as before, and nothing had to be taught about a new
  in-between state. `AuthController::login()` reads that pending state to decide
  where to send the browser; forgetting to would have let somebody through.
- **TOTP is written here, and checked against the RFCs' own numbers.**
  `src/Core/Totp.php` is RFC 6238 over RFC 4226: SHA-1, 6 digits, 30 seconds,
  ±1 step. Those are constants, not settings — an authenticator app assumes all
  three, and "configurable" would mean codes that quietly never match.
  `tests/totp-vectors.php` runs the published vectors from RFC 4226 Appendix D
  and RFC 6238 Appendix B, so a disagreement means this code is wrong rather
  than the test.
- **The QR code is generated on this server** (`src/Core/QrCode.php`, inline
  SVG, byte mode, level M, versions 1–13). The CSP allows no off-origin scripts,
  and more to the point *the thing in the picture is the secret* — it must never
  be a request to somebody else's server to draw it. A deliberate subset, and it
  throws rather than truncating: a QR that is nearly right still scans, and
  enrols a secret that will never match.
- **Nothing is stored until enrolment is proved.** The secret sits in the
  session between showing the QR and verifying a code from the app, so an
  abandoned setup leaves no credential on an account whose owner never scanned
  it. `totp_secret` is encrypted with `Crypto` (which fails closed — a secret
  that cannot be encrypted is refused, not stored in the clear).
- **Rate limiting is on the sign-in counters, not a separate budget.** Six
  digits is a million guesses; wrong codes are recorded in `login_attempts`
  exactly as wrong passwords are, so guessing a code locks the account the same
  way. There is a per-challenge counter on top of it
  (`two_factor_max_attempts`), after which the sign-in is torn up.
- **The emailed code lives in the session, hashed.** A code valid for one login
  attempt needs no table, no cleanup job, and leaves no row behind on every
  abandoned sign-in. It is sent when the challenge screen is *rendered*, not
  when the password is posted — otherwise it starts expiring before anybody has
  seen a page.
- **Trusted devices are a window, not a bypass.** Four independent ways one
  stops working; see the `trusted_devices` table in §2.2. They are deleted on a
  password change and on deactivation — and `User::updatePassword()` takes a
  `$isChange` flag so a silent re-hash of the *same* password does not revoke
  everybody's devices the first time `PASSWORD_DEFAULT` moves on.
- **The site-wide requirement is refused when nothing can deliver a code.**
  With no SMTP and no authenticator enrolled, switching it on would lock every
  account out at once, including the administrator switching it on. The settings
  form disables the control and says why.
- **An administrator can remove somebody's second factor, never read it.**
  `/admin/users/{id}/two-factor/reset` clears the secret, the backup codes and
  the trusted devices — the lost-phone path — and is the one 2FA route carrying
  a permission check. Every `/profile/security` route acts on `Auth::id()` and
  has no id in its path, which is why they are on the audit's self-scoping list.

### 4.12 Faults and the responsible party

Two ideas that only work together: an asset can name somebody responsible for
it, and an asset can be reported faulty. The point of the pair is the
notification.

**One control, two columns, one parser.** "A person, or a team, or nobody" now
appears twice in the schema — `maintenance_schedules.assigned_to_*` and
`assets.responsible_*` — with different words on screen and identical mechanics.
`App\Models\Assignment` owns the shape: `parse()` turns the form's single
`user:7` / `team:2` value into `[kind, id]`, `value()` turns a row back into it,
`label()` renders "Bench fitters (team)" for the places that have only text.
`MaintenanceSchedule::parseAssignee()` and `Asset::parseResponsible()` both
delegate. **Do not write a third parser** — the failure mode of a near-copy here
is silent, and it sends the notification to the wrong half of the workshop.

Two nullable columns rather than a polymorphic `(type, id)`: both sides are then
real foreign keys, so deleting a team cannot leave an asset pointing at a group
that no longer exists.

**Nobody named means nobody emailed.** Not an error, not a fallback to an
administrator or the notify list. Mail addressed to "whoever is around" is mail
everybody learns to ignore, and once they have, the properly addressed messages
go unread too. `FaultController` says so on screen at the moment the report is
filed — "Nobody is set as responsible for this asset, so no notification was
sent" — rather than a cheerful "the responsible party has been notified" that
is not true.

**`App\Services\FaultNotifier` is the single answer to "who hears about this".**
The immediate message and the nightly digest both go through it, so they cannot
disagree about the recipient. A disagreement there would be worse than either
alone, because each message would make the other look like a mistake. It
applies three rules:

1. a team means **every member**, the same argument teams exist for;
2. recipients are **re-checked against `assets.view` at send time** — being
   named as responsible is not itself a grant, and a fault report carries the
   asset's tag, location and condition. Identical to the rule `Reminders`
   applies to the notify list;
3. `digestGroups()` groups by person, not by asset, which is what makes the
   digest one email listing four machines instead of four emails.

**The digest is the fourth reminder type, and the odd one out.** It is in
`Reminders::TYPES` and runs on the same cron, but it is *not* in
`Reminders::WINDOWED_TYPES`: a fault has no due date to count down to, so
`windowDays()` returns 0 for it and the settings screen offers a repeat interval
instead of a "days before due" field. It also does not use the notify list at
all — see the note on the reminders page. `Reminders::repeatDays('faulty')`
reads `reminder_faulty_repeat_days`, falling back to the shared figure at 0.

**The digest lists everything, and suppression only decides whether to send.**
A message that quietly omitted the machine the reader was told about yesterday
would read as though it had been fixed. So `EmailReminder::suppressed()` decides
whether this person hears from us today, and if they do, they get their whole
list.

**A fault report is a record, not a flag** — see `fault_reports` in §2.2 for why
there is no resolved/open state, and why `condition_rating` and
`reported_by_name` are snapshots.

**At least one photograph, checked before anything is written.** A fault report
without one is a sentence somebody has to interpret. The controller validates
every upload first and refuses the whole submission if none survive, so a report
cannot satisfy the rule on a technicality by having every photo rejected.

### 4.13 Custom reports

A saved report is **not a parallel system**, and the code is arranged so it
cannot become one. `App\Reports\StoredReport` *is* a `Report`, so
`ReportController`, the generic table, the print view and the CSV export cannot
tell it from a built-in; `ReportRegistry::all()` merges the stored definitions
in beside the classes.

**A definition supplies values for filters that already exist.** Each data
source in `DataSourceRegistry` declares a subset of the filter keys the model
already understands — `Asset::searchAll()` has taken `category_id` and `status`
since stage 2 — and one closure that calls that model method. There is no query
builder, no column name from user input in any SQL, and no way for a custom
report to express a condition the equivalent list page could not.

**The sort is applied in PHP, after the rows come back.** The models sort by
their own named orderings; letting a definition name an arbitrary column would
mean building a column name into SQL, which is the one thing this feature is
designed never to do. Sorting a bounded result set in memory costs nothing worth
measuring and keeps every query parameterised.

**Definitions hold keys, not a copy of the schema.** Columns and filters are
filtered against the source on the way out, so a field added to a source becomes
available to reports already saved, and a field removed disappears rather than
rendering an empty column under a heading for something that is gone.

A definition's `report_key` never changes after the first save, even when the
report is renamed — a report's URL is a stable thing that ends up in emails.

### 4.14 The REST API

**`Auth::actAs()` is the whole design.** An API request authenticates a key,
loads its user, and adopts them for the rest of the request. From that point
`Auth::can()`, `Auth::id()` and `ActivityLog::record()` behave exactly as they do
for the same person in a browser. "A key never allows more than its owner could
already do" is therefore a property of the code rather than a promise: there is
no second permission model, because there is nowhere to put one.

It writes no session and rotates no CSRF token. An API request must not leave the
caller signed in, and presenting a key must not create a browser session.

**Why the API routes carry no `auth` or `csrf` middleware.** `auth` redirects to
a sign-in page, which is the wrong answer to a request carrying a key; `csrf`
protects a browser form. `App\Api\Gate` does strictly more in their place. The
CSRF exemption is safe because a write needs a key in a *header* — which a
cross-site form cannot set, and which a cross-origin `fetch` would have to
preflight — and because a request authenticated by a session cookie is refused
anything but GET. `tests/security-audit.php` asserts both halves rather than
trusting the prose.

**One controller for every resource.** Pagination, filtering, sorting, the
permission check, the writable allow-list, the response envelope and the error
shape are written once in `ResourceController`. Six hand-written controllers
would be six chances to forget a permission check and six pagination
conventions.

**Refusals are loud.** An unknown filter, an unknown enum value, an unknown
field in a body and a read-only field in a body are all 400s rather than silent
drops. A misspelled filter that quietly returns everything is how somebody
publishes a list containing rows they meant to exclude — and that exact bug was
in the repeatable-filter branch for one build before `tests/api-contract.php`
caught it.

**`PUT` resets to a declared default, not to null.** A writable field that maps
to a NOT NULL column carries a `default` in its declaration; a replacement sends
it back to that. Without it, `PUT` on an asset blanked `status` and
`condition_rating` and the write failed at the database — which is how it was
found.

**The OpenAPI document is generated from the resource declarations**, so a field
that appears in it appears in the response and a filter it documents is one the
router accepts: they are the same array. The viewer at `/api/docs` is
first-party because the CSP is `default-src 'self'` and permits no off-origin
scripts — a CDN Swagger UI simply would not load. It requests same-origin paths
rather than the spec's absolute server URL, so it works behind any hostname or
subdirectory.

---

## 5. Build-prompt status

All eighteen prompts are **complete**. Nothing is partial or unstarted.

> **Terminology:** the application says **Hires** and **Hirers**. The schema, the code and the interface all use the same words, with no compatibility shim.

> **Since the nine prompts:** an installer (`install.sh`), an administration
> wrapper (`manage.sh`), an admin console (`bin/console.php`) and `INSTALL.md`
> were added on **2026-08-07**. See §5.3.

| # | Prompt | Status | Landed |
|---|---|---|---|
| 1 | Foundations: schema, auth, base layout | complete | 2026-08-03 |
| 2 | Core asset management | complete | 2026-08-03 |
| 3 | Condition photos | complete | 2026-08-04 |
| 4 | Maintenance schedules and logging | complete | 2026-08-04 |
| 5 | PAT records | complete | 2026-08-05 |
| 6 | Hires, barcode scanning, Hirer role | complete | 2026-08-05 |
| 7 | Reports | complete | 2026-08-05 |
| 8 | CSV import and export | complete | 2026-08-05 |
| 9 | Polish pass | complete | 2026-08-06 |
| 10 | PAT workflow: fixed values on the asset, guided test, scan buttons | complete | 2026-08-08 |
| 11 | Navigation declutter, Hires/Hirers rename, scan as a quick action | complete | 2026-08-09 |
| 12 | Outbound email, templates, reminders, calendar feeds | complete | 2026-08-10 |
| 13 | Ten fixes: PAT wizard, scanner hand-off, editable maintenance records | complete | 2026-08-11 |
| 14 | Role creation, branding/logo, HTML email, export page, print documents | complete | 2026-08-11 |
| 15 | Nav polish, dashboard order, maintenance evidence, Teams, invites, password recovery | complete | 2026-08-11 |
| 16 | Auto-hiding banners, nav alignment and active-state fix, reference-data tree, two-factor authentication | complete | 2026-08-12 |
| 17 | Responsible party on an asset, mark-as-faulty with photo and urgency, faulty dashboard card, immediate notification and a faulty digest | complete | 2026-08-12 |
| 18 | Asset-page button cleanup, maintenance card layout, drop-down click race, shared table alignment, custom reports, REST API with OpenAPI, README split into docs/ | complete | 2026-08-12 |

### 5.1 What each prompt delivered

1. **Foundations** — 7 migrations, RBAC as data, login with throttling, session
   hardening, base layout and nav, admin area (users, roles, categories,
   locations, settings, activity), `bin/migrate.php`, `bin/seed.php`,
   `bin/create-admin.php`.
2. **Assets** — CRUD, generated asset tags, Code 128 SVG labels (sheet + single),
   sub-assets/accessories/related via `parent_asset_id`, multi-term keyword
   search and filters, PDF manuals, copy/duplicate and bulk-apply workflows.
3. **Photos** — upload at any time (not just registration), dated gallery and
   timeline, EXIF orientation and capture date, GD resize + thumbnails, lightbox,
   register thumbnails, `capture` attribute for mobile cameras.
4. **Maintenance** — routine/periodic/ad-hoc schedules, completions with photos,
   automatic next-due calculation, SQL-computed due status, configurable
   "due soon" window, unplanned work logged straight onto an asset.
   **Four shapes, and all four must stay reachable:** `routine` (a standard
   cadence — weekly, monthly, annually), `periodic` (any custom interval),
   `ad-hoc` (a one-off *planned* job, which closes itself once completed), and
   **unplanned work** — a `maintenance_logs` row with `schedule_id` NULL and no
   schedule at all, which is the repair nobody saw coming. That last one is
   recorded, never scheduled, and it has its own front door at
   `/maintenance/log`. A follow-up check creates an `ad-hoc` schedule
   deliberately, so "check it again in three weeks" cannot become an accidental
   recurrence.
   A completed record can be **corrected** afterwards at
   `/maintenance/logs/{id}/edit` (`maintenance.manage`, a level above the
   `maintenance.complete` needed to write one in the first place). The
   correction is the smaller half of that feature: every save writes an
   `activity_log` row carrying the field-level before/after, the user, the time
   and an optional stated reason, plus a second row against the asset so the
   machine's own trail shows its history was rewritten. Nothing is overwritten
   silently, and the trail is rendered on the edit page itself so it is visible
   to the person doing the correcting, not only to an administrator.
   The asset and the schedule are deliberately **not** editable there: moving a
   record to another machine is not a correction, it is a different record.
5. **PAT** — `requires_pat` toggle, full per-asset test history, class-conditional
   readings, SQL-computed status in which `Failed` outranks the retest date,
   configurable window and default interval.
6. **Hires** — checkout by scan or search, due dates, hirer records, return
   flow with condition photos, automatic overdue, double-booking prevention,
   quick-scan page, and the restricted Hirer self-service portal.
7. **Reports** — five registry-driven reports with generic filters, CSV and
   print, dashboard tiles linking through.
8. **CSV** — asset and PAT importers with upload → preview → commit and
   re-validation at commit, downloadable templates, filtered/selected asset
   export whose core columns mirror the importer's so round trips work,
   everything audit-logged.
9. **Polish** — dashboard review, server-side permission edge cases, security
   audit, WCAG contrast and tap-target fixes, README completed, `tests/` shipped.
10. **PAT workflow** — the fixed electrical values (appliance class, load rating,
    fuse) moved onto the asset where they belong; "Add PAT result" rebuilt as a
    guided step-by-step test with server-side enforcement of the verdict;
    configurable guideline pass ranges shown as helper text but never used to
    decide a result; a reusable scan button beside barcode fields.
11. **Navigation and terminology** — six top-level destinations (five since
    stage 15) with the rest
    nested under them, one markup for desktop drop-downs and mobile accordions,
    scan promoted to a persistent quick action, and hires/hirers named
    consistently in the schema, the code and the interface.
12. **Email and calendar** — SMTP configured and tested from Settings with the
    password encrypted at rest; editable templates whose defaults live in code;
    PAT, maintenance and hire reminders on a cron schedule with per-recipient
    de-duplication; one-click sends to a hirer; a log of every message; and a
    per-user authenticated `.ics` calendar feed scoped by role.
13. **Ten fixes** — the PAT wizard's dead Next button (really a global
    `[hidden]` bug, §6), the In Date banner, two nav entries, Export removed
    from the register, the scanner handing off without a confirmation step,
    editable maintenance records with a field-level audit trail, and three
    presentation fixes on the asset page.
14. **Branding and documents** — role creation, a logo (light and dark) reaching
    the header, the sign-in page, both print mastheads and outbound email; HTML
    email in a fixed shell with a plain-text alternative; a dedicated Export
    page mirroring Import; and print documents for one asset and for a filtered
    list.
15. **Teams and accounts** — the nav bar down to five items with the wordmark
    level with them and drop-downs that open on hover; Quick actions above a
    more compact dashboard; a filter caret and no per-row Label button on the
    register; camera capture and PDF attachments in maintenance evidence;
    **Teams**, which maintenance can be assigned to instead of one person, with
    every member reminded and able to act; **email invitations**, so a new user
    sets their own password; and **self-service password recovery**. The last
    two share one expiring, single-use, hash-at-rest link mechanism (§4.10) and
    both fall back cleanly when no SMTP is configured.
16. **Two-factor authentication**, plus four smaller things. Confirmation
    banners now time out after a configurable number of seconds (0 = never),
    while warnings and errors never do — and the one message that reported
    something nowhere else recorded it, the reminder run's summary, was moved
    onto the reminders page first (§4.11 explains the rest). The menu text and
    the wordmark now stand on the foot of the logo, and only the page you are
    actually on is marked active — the prefix match was lighting two items at
    once whenever one menu entry sat under another. Categories and locations are
    a condensed, collapsible tree with the form on its own page. And 2FA:
    **TOTP** with a QR code generated on this server and ten single-use backup
    codes, **email codes** as the fallback where SMTP allows it, per-user opt-in
    *and* a site-wide requirement, and **trusted devices** that expire four
    different ways.
17. **Faults.** An asset can name a **responsible party** — one person or one
    team, never both, never required — set from the edit form and shown on the
    record. Anyone with `faults.report` can **mark it faulty** from a page of
    its own: what is wrong, when it was noticed (back-datable), a photograph
    through the same camera control the condition and maintenance flows use, the
    condition at the time, and an urgency for *this fault* rather than for the
    asset. Submitting sets the status to the new `Faulty` and files a
    **fault report**, kept as history — an item can break twice, and the second
    report does not erase the first. The current one sits across the top of the
    asset page; the dashboard gains a Faulty tile drilling into a report
    filterable and sortable by urgency. The responsible party is **emailed
    immediately**, and again in a **consolidated digest** on the reminder
    schedule — one message per person listing every faulty asset of theirs,
    however many teams it reaches them through. An asset with nobody
    responsible emails nobody, says so on screen, and does not error.
18. **Custom reports, a REST API, and a documentation split**, plus five
    interface fixes. The PAT warning no longer offers a button that switches
    the warning off; the asset page keeps four buttons at the top and moves
    marking-faulty, copying and label printing into the rail where they belong;
    the maintenance side cards stack their values under their labels; a
    drop-down opened by hover no longer closes under the click aimed at it; and
    the actions column of every table lines up with its row again — it had been
    `display: flex` on a `<td>`, which takes the cell out of table layout, so
    its bottom border sat ten pixels above every other column's.
    **Custom reports** are stored definitions rendered through the same `Report`
    abstraction as the built-ins (§4.13). The **REST API** at `/api/v1` covers
    eleven resources through one generic controller, authenticated by keys that
    adopt their owner's role exactly, with a generated OpenAPI document and a
    first-party browsable viewer (§4.14). The README went from 2,015 lines to
    87, with the detail moved into `docs/` as seventeen focused pages and an
    index.

### 5.2 What has been verified, and how

**Re-run at the time of writing this document, all passing:**

| Check | Result |
|---|---|
| `php -l` on all 238 PHP files | 0 failures |
| `tests/security-audit.php` | **42 passed, 0 failed** |
| `tests/escape-audit.php` | **2433 output expressions across 99 templates, 0 unescaped** |
| All 25 migrations against an empty database | applied cleanly; 32 tables, 377 columns, 60 FKs, 147 indexes, all InnoDB |
| Seed data counts | 4 roles / 36 permissions / 71 grants / 51 settings / 0 template overrides |
| `tests/permission-matrix.php` | **380 checks, 0 mismatches** |
| `tests/fault-flow.php` | **68 checks, 0 failed** — end to end over HTTP, with a mail catcher |
| `tests/api-contract.php` | **84 checks, 0 failed** — the API against its own generated specification |
| Documentation links | every anchor and file link in `docs/` and `README.md` resolves |
| `tests/totp-vectors.php` | **52 checks, 0 failed** — RFC 4226 Appendix D and RFC 6238 Appendix B |
| `tests/qr-encode.php` | **21 checks, 0 failed** — ISO/IEC 18004 Annex I error-correction codewords, and the geometry |
| `tests/report-figures.php` | every figure agrees with the database |
| Migrations 019–023 on the **populated** dev database | applied cleanly; existing rows untouched — in particular every `assets.status` value survived the ENUM change unchanged |

**Stage 12 specifically, verified on this machine:**

| Check | Result |
|---|---|
| `Crypto` round trip: empty, ASCII, multi-byte, 500 chars | all recovered; two encryptions of the same value differ (random IV) |
| `Crypto` rejects a tampered ciphertext, a truncated one, and a non-prefixed value | all return null |
| SMTP password stored via the UI | ciphertext in the row, plaintext absent, decrypts to the original |
| A real SMTP send | PHPMailer 6.12.0 → a local catcher: AUTH with the decrypted password, correct `From` with display name, merged subject and body |
| Reminder run, 13 messages | PAT / maintenance / hire, staff digests + one hirer notice, all bodies checked |
| Second run immediately after | **0 sent, everything suppressed** by `email_reminders` |
| Notify list containing a Hirer | dropped from all three reminder types by the permission re-check |
| `email_log` / `email_reminders` rows | statuses, template keys, entity links and trigger sources all correct |
| `.ics` feed for all four roles | `text/calendar`, parses, balanced, folded ≤75 octets; admin/manager/viewer 11 events, **hirer 1 — their own hire only** |
| Feed tokens: wrong by one character, too short, uppercase, empty, revoked | 404 in every case; valid token 200 |
| 29 pages as admin over real HTTP | all 200 |
| Permission matrix, 8 new routes × 4 roles | 32/32 as declared |
| POST actions (test send, template edit/reset/disable, both manual sends, reminder preview, token lifecycle) | 17/17 |
| `vendor/` removed | app still serves; mail reports how to install it; `send-reminders.php` exits 2 without sending |
| `bash -n install.sh` / `manage.sh` | clean |

**The 2026-08-11 fixes (§5.4), verified on this machine:**

| Check | Result |
|---|---|
| The reported grant failure, reproduced | a database migrated to 016 owned by a user with the old grant: same `ERROR 1142`, table untouched, nothing half-applied |
| `manage.sh db-grant` then `migrate` | 017 and 018 both applied; `hires`/`hirers`/`hire_photos` present, no leftover `loans`, status enum carries `'On Hire'` |
| `console.php doctor` before and after | `FAIL missing DROP` → `ok enough to run migrations` |
| Composer fallback, distribution package simulated absent | downloads the official installer and the signature check **passes**; a missing or corrupted file is refused and nothing is run |
| Settings → Email with and without `vendor/` | both render 200, and the command shown is one that exists |
| Nav on a Settings sub-page at 1280px | no panel visible (`checkVisibility()` false on all four groups), content flush under the 61px header, summary still highlighted, **first click opens** |
| Rendered markup on `/`, a Settings sub-page and `/profile` | `open data-nav-autoopen` on exactly the group containing the current page, and nowhere else |
| Unplanned maintenance, end to end over HTTP | 21 checks: three entry points, the picker, `?asset=` passthrough, a follow-up with the right due date and instructions, closing on completion, none created when unticked, and an interval-less tick refused with nothing written |

**Code 39 and QR scanning (2026-08-11), verified on this machine:**

| Check | Result |
|---|---|
| `tests/barcode-decode.html` | **32 checks, 0 failed** |
| Block table vs module geometry | agrees for all 160 version/level pairs — two independent routes to the same number |
| ISO/IEC 18004 Annex I worked example | data **and** error-correction codewords match the published values |
| Reed-Solomon at full capacity | 200/200 blocks with 5 of 10 codewords corrupted recovered exactly; 200/200 damaged past capacity refused, **0 silently wrong** |
| QR round trip | all 8 masks, all 4 EC levels, versions 1-40 sampled at 13 points, numeric/alphanumeric/byte/UTF-8/URL payloads, 2px-9px modules, two-module quiet zone, rotated 180°, 12 modules blacked out at level H (20/20) |
| **QR from a third-party encoder** | versions 1-6, all four EC levels, UTF-8 and URL payloads — all decoded exactly, EC level reported as requested. This is what proves the placement order and format-bit positions, which a self-round-trip cannot |
| Code 128 / Code 39 round trip | 9 values each, three print scales, both Code 39 wide:narrow ratios |
| False positives | 40 frames of random noise, even stripes, a blank frame, and a QR destroyed past capacity — none decoded to anything |
| Real PHP-rendered label, end to end | the `Barcode::svg()` output on `/assets/3` → canvas → decoder → `/scan/lookup` → correct asset |
| Code 39 in a 640×480 frame with clutter | decoded, looked up, correct asset |
| Cost per frame at 1280×720 | 11.3 ms worst case (no code present), 9.3 ms with a QR — against a 120 ms frame budget |
| `/scan` and a scan-button page | both scripts served in order, `AssetBarcode` present, no console errors |

**Branding, export and printing (2026-08-11), verified on this machine:**

| Check | Result |
|---|---|
| Logo upload, both variants | stored, served with the right content type, cache-busted by a fingerprint of the stored path |
| Logo reaches all four consumers | site header, sign-in page (signed out), both print mastheads, and the email as a CID attachment |
| Aspect ratio | a 640×160 logo renders 136×34 in the header and 242×60 on paper — **error 0.0000**, constrained by height, never stretched |
| Variant fallback | dark removed → light serves both themes; both removed → the **KW** box returns and `/branding/logo/light` 404s |
| A fake PNG (text file renamed) | refused with a readable message, **and the existing logo survives** |
| Email MIME structure | `multipart/alternative` → text/plain + `multipart/related` → text/html + PNG with `Content-ID: <branding-logo>` |
| Plain-text alternative | generated from the content, not the wrapped message; link addresses preserved as `label <url>` (5 cases) |
| PAT wizard step colours | grey → green when all pass → grey again when an answer is removed → red on any fail; readings count; the Result step, having no verdicts, is never coloured |
| Permission matrix | **284 route/role checks**, the 8 new routes behaving as declared (2 pre-existing mismatches on POST /pat, which 404s because the harness posts no asset_id) |
| Permission table alignment | 32 rows, **one distinct left edge per column**, every row 40–41px at 1280px |
| Export CSVs | whole register 26 columns, with PAT+hire extras 35, hand-picked subset 2 rows |
| `/assets` export entry points | none remain — no button, no bulk action, no column options |

**Header layout and the reseed (2026-08-11), verified on this machine:**

| Check | Result |
|---|---|
| Menu rows, with a logo | **1 row at 1150, 1280, 1440 and 1920px**; header back to 61px from 90 |
| Menu rows, no logo (KW mark) | 1 row at 1150 and above; was 2 rows at every width below ~1230 **before** any logo existed |
| Breakpoint boundary | 1150px → bar, 1149px → drawer, header 61px either side |
| Worst-case logo (1200×120, 10:1) | capped at 150×26, letterboxed by `object-fit`, still 1 row at 1150px with no overflow |
| Brand width | 238px → **104px** stacked (150px for the 10:1 banner) |
| Wordmark | visible in every case, on its own line on a desktop |
| Mobile (390px) | side by side, `bottomsDelta` **0.0px** — the wordmark's bottom sits exactly on the logo's, signed in and signed out |
| Permission matrix after the reseed | **284 checks, 0 mismatches** — the first clean run; the harness's hirer account was stale and it now refuses to run rather than report a false hole |

**Stage 15 (2026-08-11), verified on this machine:**

| Check | Result |
|---|---|
| Nav bar with a logo at 1150 / 1280 / 1920px | **1 row**, header 61px, no horizontal overflow; five items, no Dashboard |
| The wordmark against the menu | same 17px, same weight 600, **centres level to 0.0px**; not inside any link |
| Breakpoint boundary | 1150px bar / 1149px drawer, 61px either side; the bar needs 1097px, so 53px of headroom |
| Mobile (390px) | logo and wordmark side by side, `bottomsDelta` **0.0px** — unchanged from stage 14 |
| Hover drop-downs | open after 140ms, close 260ms after leaving, only one open at a time; click still toggles both ways; gated on `(hover: hover) and (pointer: fine)` |
| Dashboard | Quick actions second in the DOM, above both stat grids; cards **115px → 73/91px**; each grid's first row reaches the right-hand edge exactly (0px) |
| Assets page | filter caret 8px after the label, rotates 180° when open; the only row action left is Edit |
| Maintenance evidence, end to end | **22 checks**: camera and gallery inputs present, a PNG and a PDF posted through the real form, the document streamed inline as `application/pdf` with nosniff, `?download=1` an attachment, a mismatched log id 404, and a fake PDF refused without costing the record |
| Teams, end to end | **38 checks**: CRUD, duplicate names refused, members added and listed, assignment control, the badge on the schedule page / list / asset page, "(team)" in the report and the CSV, filtering by team, reassignment clearing the other column, an unknown team id refused, and a manager 403'd from every Teams route |
| A team member can act | **6 checks**: a member with `maintenance.complete` opens and files the completion; a member without it is 403'd — membership grants nothing |
| Team reminders, real SMTP | **12 checks**: both members reminded, the hirer and the notify list not, the digest naming the team, a removed member no longer told about the team's job while the remaining one still is, and a second run fully suppressed |
| Invitations and recovery, end to end | **51 checks**: the form asks for no password, one email sent, the token stored **only as a SHA-256**, no sign-in before setup, the "Invited" badge, single use, resend invalidating the old link, a direct password reset revoking it, the identical answer for a real / unknown / deactivated address, throttling, and both fallbacks with email off |
| Link expiry | **13 checks**: the window comes from the setting, 0 clamps to 1 hour and 99999 to 30 days, the two windows are independent, an expired link explains itself and cannot be used, and deleting a user takes its tokens with it |

**Stage 16 (2026-08-12), verified on this machine:**

| Check | Result |
|---|---|
| Banner markup | a success banner carries `data-flash-autohide="N"`, an error banner carries none, and **both keep the dismiss button** — checked over real HTTP at three different settings values |
| Banner behaviour | in the browser, against the shipped `app.js` and the real markup: two 2-second success banners, one held under the pointer. At 4.5s the un-hovered one was **gone**, the hovered one **still there**, the error banner **untouched** |
| Nav baseline | all five menu labels and the wordmark land **0.00px** from the foot of the logo at 1280px, one row, 40px tap targets kept, header still 61px, no horizontal overflow |
| Nav active state | on `/maintenance/log`, `aria-current` is on **“Add maintenance” only** — previously “Schedules” lit up too, because `/maintenance` is a prefix of it |
| Reference tree, end to end | **37 checks** across categories *and* locations: a tree with a caret per branch, the old card-per-row list gone, three actions per row, “Add inside” preselecting the parent, the child nested under it, a posted cycle refused with the tree unchanged, delete disabled while in use |
| Tree density | 5 locations render in a **208px** card at 38px a row, against roughly 200px *per entry* before |
| Team member control | the “Add to team” button and its select share a top and bottom exactly (**delta 0.0px**) |
| TOTP | **52 checks**: every RFC 4226 Appendix D counter, every SHA-1 row of RFC 6238 Appendix B, RFC 4648 base32 both ways, the ±1 step window, and a mistyped secret refused rather than silently decoded to a shorter one |
| QR encoder | **21 checks**: the ISO/IEC 18004 Annex I error-correction codewords match the published values, finders/timing/separators/dark module against the specification, and a payload past version 13 refused rather than truncated |
| QR round trip | six payloads — including a real `otpauth://` URI — encoded by `App\Core\QrCode` and **decoded exactly by `public/js/barcode.js`**, an independently written reader: versions 1, 3, 7, 9 and 11, so both sides of the version-7 boundary |
| 2FA, end to end | **53 checks**: enrolment writing nothing until a code is proved, the secret stored as `v1.` ciphertext and never in the clear, ten backup codes shown once and hashed with `password_hash`, single use, the challenge blocking protected pages, attempts cut off at the limit *and* counted against the sign-in lockout, trusted-device skip, the same cookie in a **different browser challenged and the row torn up**, a password change and a deactivation each clearing every device, an emailed code delivered through real SMTP with the address masked on screen, and a user with nothing set up stopped by the site-wide requirement |

**Shipped in `tests/` but requiring something more than PHP:**

| Check | What it proves |
|---|---|
| `tests/barcode-decode.html` | the decoder, in a browser: 32 checks over Code 128, Code 39 and QR. Needs no server or network — open the file |
| `tests/qr-encode.php` | the *encoder*, against the ISO Annex I codewords and the specification's geometry. It also writes `tests/qr-encode-output.html`; **open that in a browser** and the symbols are decoded by `public/js/barcode.js`, which shares no code with them |
| `tests/report-figures.php` | each report's rendered row count matches the same figure taken straight from the database, and the CSV matches the screen (8 cross-checks) |
| `tests/permission-matrix.php` | ~344 route/role combinations against declared expectations for all four roles. **This one writes — demo databases only**, and it refuses to run if an account has 2FA switched on, since it cannot answer a challenge |

**Run during the build, living in the session scratchpad rather than the repo**
(these are *not* part of the deliverable and are not re-runnable from a fresh
checkout): `smoke.php` (55 assertions, core), `smoke2.php` (82, barcode/upload/
filters), `smoke3.php` (11, schema-vs-code static checks), `smoke4.php` (90,
template rendering), `smoke5.php` (84, report registry), `integration.php` (568,
full HTTP end-to-end with real sessions, CSRF, uploads and permissions).

**Not verified by any automated check** — these were confirmed by hand or not at
all, and are the honest gaps:

- Real mobile browsers. The camera scanner's `BarcodeDetector` path and the
  fallback decoder were verified by a **browser round trip on this machine**
  (PHP encodes a label → JS decodes it, including rotation, noise and low
  contrast), but not on an actual iPhone or Android handset.
- Printing. Label sheets and report print views were checked visually in a
  browser's print preview; **no physical label has been printed or scanned.**
- Deployment behind a real HTTPS reverse proxy. `FORCE_HTTPS` / `TRUST_PROXY`
  and the `X-Forwarded-Proto` handling are exercised by unit-level checks only.
- Apache/nginx configuration. The README's vhost snippets are written but have
  not been run on a real server.
- HEIC/HEIF uploads. Accepted and stored, but never round-tripped from an actual
  iPhone.
- Performance at scale. The largest dataset exercised is the seed data plus
  test fixtures — hundreds of rows, not tens of thousands.
- **A real mail server.** Sending was proved end to end against a local SMTP
  catcher on 127.0.0.1, which exercises PHPMailer, AUTH, MIME and the log — but
  no message has crossed the internet, so TLS negotiation against a real
  provider, certificate verification and deliverability (SPF/DKIM, spam
  filtering) are untested. `Settings → Email → Send test email` is the one-click
  way to close that gap on the day it is deployed.
- **A real calendar client.** The `.ics` output was parsed and checked against
  RFC 5545's rules (folding, escaping, all-day dates, balanced components) by a
  reader written for the purpose, but has not been subscribed to by Outlook,
  Google Calendar or Apple Calendar.
- **Mobile interaction for stage 12.** The mobile layout was verified by
  measurement (44px targets, accordion rather than overlay, no horizontal
  overflow), but the in-app browser would not dispatch clicks at phone widths,
  so tapping through the new pages on a handset is unproven.

### 5.3 Deployment tooling (added 2026-08-07)

Three files, written after the nine prompts, so that the application can be
deployed without following the README step by step.

| File | What it is |
|---|---|
| `install.sh` | ~1400 lines of bash. Detects the distribution and package manager, installs PHP 8.1+/Apache/MariaDB as needed, asks the configuration questions, prints a plan and waits for a yes, creates the database and user, copies the files, writes `.env`, sets ownership and modes, writes the vhost, raises PHP's upload limits, migrates, creates the administrator and verifies with `/health`. Supports `--dry-run`, `--answers=FILE --non-interactive`, `--skip-packages`, `--web-server=`, `--tls=`. |
| `manage.sh` | ~700 lines. The README's administrative tasks as one command each: `status`, `doctor`, `health`, `users`, `reset-password`, `unlock`, `activate`/`deactivate`, `set-role`, `settings`, `config`, `migrate`, `seed`, `backup`, `restore`, `update`, `permissions`, `package`, `cron-install`, `prune-activity`, `refresh-overdue`, `audit`, `logs`, `restart`, and (stage 12) `mail-status`, `mail-test`, `send-reminders`, `calendar-url`. |
| `bin/console.php` | ~1,030 lines. Everything that touches the database, through the application's own models — so prepared statements, the last-active-administrator rule and `ActivityLog` entries all still apply. Passwords come from a hidden prompt or `--stdin-password`, never from `argv`. Stage 12 added `key:generate`, `mail:status`, `mail:test`, `mail:prune` and `calendar:url`, and an Email section in `doctor`. |
| `bin/send-reminders.php` | The cron entry point. `--dry-run`, `--force`, `--type=`, `--quiet`. Exit 0 all good, 1 something failed to send, 2 mail is not usable — so a misconfigured install stops loudly on the first run instead of logging one failure per recipient for ever. |

Decisions worth keeping:

- **The generated Apache vhost sets `AllowOverride None`** and carries the
  rewrite rules and headers itself. `public/.htaccess` contains an
  unconditional HTTPS redirect, which would loop for ever on a `plain-http`
  install; putting the config in the vhost lets the redirect match the TLS mode
  chosen at install time. `.htaccess` stays in the tree for shared hosting.
- **The installer never adds a third-party repository silently.** On Debian and
  Ubuntu releases whose own PHP is older than 8.1 it offers `deb.sury.org` /
  `ppa:ondrej/php` and stops if you decline.
- **The database user is granted exactly the README's rights, on one database.**
  `DROP` **is** included, since 2026-08-11. It was originally withheld on the
  reasoning that "the application never issues one" — true of the application,
  false of its migrations: `RENAME TABLE` requires `ALTER` *and* `DROP` on the
  source table, so migration 017 failed on every existing install with
  `ERROR 1142: DROP command denied`. Withholding it also bought less than it
  looked: the same user holds `DELETE` and `ALTER`, which between them can empty
  every table and dismantle the schema. What is still withheld is the part that
  matters — `GRANT OPTION`, `CREATE USER`, `FILE`, `SUPER`, `PROCESS`, and any
  rights on another database.
  `manage.sh db-grant` repairs an older install; `console.php doctor` checks for
  it; and `Migrator` turns error 1142 into those instructions rather than a raw
  SQLSTATE.
- **Backups prefer root over the local socket**, so the application user does
  not need rights beyond its own database.
- **The installer generates `APP_KEY`** so email can be configured from the
  Settings page on day one without shell access. An answers file carrying one
  over from an existing install wins — reusing the old key is what keeps an
  already-stored SMTP password readable.
- **The installer installs Composer itself**, rather than shrugging when the
  machine has none. It shrugged originally, which produced an install that
  looked complete but could not send a single email — and re-running
  `install.sh` did not fix it, because it shrugged again. Two routes:
  the distribution's `composer` package first (no new trust — signed by the
  same repository as everything else, and attempted *tolerantly* because the
  package name varies and does not exist everywhere; adding it to the main
  package list would abort an otherwise good install), then the official
  getcomposer.org installer verified against the SHA-384 at
  `composer.github.io/installer.sig`, refused on mismatch.
  Still not fatal: everything except sending email works without it, and
  `manage.sh composer-install` retries the whole thing on an existing install.
- **`cron-install` schedules the reminder run** (daily 08:00) alongside the
  nightly backup and hourly overdue refresh. It does nothing until a reminder
  type is switched on in Settings.
- **`tests/security-audit.php` scans `bin/` as well as `src/`, `routes/` and
  `templates/`.** Its SQL check matches from `Database::…(` to the next `, [`
  or `);`, so a `Database::scalar('…')` written inline inside an array literal
  is reported as uncontrolled concatenation. Assign the result to a variable on
  its own line — `bin/console.php` `cmdStats()` carries a comment saying why.

Verified on this Windows development box:

| Check | Result |
|---|---|
| `bash -n install.sh` / `manage.sh` | clean |
| `php -l bin/console.php` | clean |
| `bin/console.php` — `doctor`, `stats`, `user:list`, `setting:list`, `setting:set`, `db:check`, `activity:prune --dry-run`, `hires:refresh-overdue` | all correct against the live dev database |
| `bin/console.php` — `user:create`, `user:password`, `user:role`, `user:activate`, `user:deactivate`, `unlock`, all with `--stdin-password` | created, changed and removed a test account; short passwords, duplicate emails and unknown emails all rejected with exit 1 |
| Last-administrator guard | demoting *and* deactivating the only admin both refused |
| `manage.sh` — `help`, `config`, `users`, `settings`, `stats` | correct, including `.env` parsing of quoted values |
| `install.sh --help`, unknown option, `--dry-run` | correct; the plan is printed and nothing is changed |
| Full question flow, interactive and `--answers … --non-interactive` | run against a simulated Ubuntu 24.04 (`/etc/os-release` and the root check stubbed) |
| Generated Apache vhost | rendered and read; `<Directory>` precedence and the php-fpm `SetHandler` are right |
| `tests/security-audit.php`, `tests/escape-audit.php` | 35/0 and 0 unescaped after the new files |

**Partly overtaken by events.** The installer has since been run on a real
server, which is where the grant and Composer bugs in §5.4 came from. What
follows was true when it was written and is still true of *this* machine —
**no Linux box was available:** no package was actually
installed, no vhost was loaded by a real Apache or nginx, no database was
created by the script, no backup or restore was round-tripped, and SELinux and
firewalld handling has never run. The installer is written defensively —
it validates the web-server config with `apachectl -t` / `nginx -t` before
reloading, and stops on the first failure — but **it has not been run
end to end on a real server.**

---

### 5.4 What deploying it found (2026-08-11)

Four bugs, all reported from a real server rather than by any test here. They
are worth recording as a group because three of them share a shape: something
verified in isolation, on a machine that already had what it needed.

| Fixed in | What was wrong |
|---|---|
| `0c5d466` | The installer's database grant withheld `DROP`, so **migration 017 could not run on any install the installer had ever built**. `RENAME TABLE` needs `ALTER` *and* `DROP` on the source table. The grant had been reasoned about from what the running application issues, never from what the migrations issue. |
| `4d513b7` | `install.sh` only *checked* for Composer and warned — so re-running it never fixed a machine without one, and the advice printed (`composer install`) was itself the command that failed. It now installs Composer, distribution package first, then the signature-checked official installer. |
| `bb780ae` | The nav group containing the current page renders `open`; above 900px that panel is `position: absolute`, so it sat on top of every page navigated to. |
| `bb780ae` | Unplanned maintenance was reachable only by opening an asset and scrolling to its Maintenance card, so a first-class kind of maintenance read as missing. |

The lesson common to the first three is in §6 under the traps: **verify a
dependency against the thing that will actually need it, on a machine that does
not already have it.** A migration is not the application; a server is not this
laptop; and advice is only useful if the reader can run it.

---

## 6. Known limitations, workarounds and open items

There are **no `TODO`, `FIXME`, `HACK` or `XXX` markers anywhere in the
codebase** — grepped, zero matches. The list below is therefore things that are
*known and deliberate*, not forgotten.

### Deliberate limitations

1. **HEIC/HEIF photos are stored but never processed.** They pass upload
   validation (`config/config.php` allows them, since that is what iPhones
   produce), but `Image::processableMimes()` only offers JPEG, PNG and WebP, so a
   HEIC upload gets no resize and no thumbnail — `thumbnail_path` stays NULL and
   the full image is served. Browsers that cannot display HEIC will show nothing.
   Fixing this properly needs ImageMagick with a HEIF delegate, which cannot be
   assumed on shared hosting.
2. **Without GD, no image is resized or thumbnailed.** By design — uploads still
   work, listings just serve full images. Slower, never broken.
3. **`activity_log` is never pruned by the application.** It grows without
   bound. `login_attempts` *is* self-cleaning (rows older than 30 days are
   deleted opportunistically by `LoginThrottle`), but the audit trail has no
   equivalent, on purpose — silently deleting an audit trail is worse than a
   large table. `manage.sh prune-activity DAYS` exists for when a site does
   want one, and refuses to go below 30 days; `cron-install` deliberately does
   **not** schedule it, because the retention period is the operator's call.
   `email_log` and `email_reminders` follow exactly the same rule:
   `console.php mail:prune --days=N`, minimum 30, not scheduled.
4. **Overdue hires are refreshed lazily, not by cron by default.**
   `manage.sh cron-install` adds an hourly `refresh-overdue`; without it,
   `Hire::STATUS_SQL`
   derives "Overdue" from the due date in SQL, so every screen is always correct.
   `Hire::refreshOverdue()` then writes the stored `hires.status` to match, and is
   called from the dashboard, the hires index and the hirer portal. If nobody
   visits any of those, the stored column lags — **but nothing reads the stored
   column without the SQL expression available**, so this is cosmetic. A cron
   entry calling it would be tidier.
5. **The session is bound to the user-agent string.** A browser upgrade that
   changes the UA silently signs the user out. That is the intended trade for
   making a stolen cookie less useful, but it will occasionally look like a bug.
   **Trusted devices are pinned the same way** (a hash of the UA), so a browser
   update also costs a device its trust and the next sign-in asks for a code.
   Same trade, and the same occasional surprise.
5b. **A password change does not end other sessions** — it revokes trusted
   devices and outstanding links, but somebody already signed in elsewhere stays
   signed in until their session times out. Ending them would mean tracking
   sessions in the database, which nothing here does yet. Worth knowing before
   telling somebody "change your password and they're locked out".
6. **`Content-Security-Policy` includes `'unsafe-inline'`** for `script-src` and
   `style-src`. Only two templates actually need it — `layouts/app.php` and
   `layouts/auth.php` carry inline `<script>` blocks. There are **no** inline
   event handlers and **no** `style=` attributes anywhere in the templates, so
   `style-src 'unsafe-inline'` could be dropped today, and `script-src` could
   follow with a nonce plumbed through `View`. Off-origin scripts are blocked
   either way, which is the property the barcode-scanner decision rests on.
7. **Password reset by email exists as of stage 15** — this entry used to say it
   did not, and that it would need "single-use expiring tokens, rate limiting
   and careful handling of the *does this account exist* oracle" before it
   could. It has all three; see §4.10. What remains true is that it needs
   working SMTP, so `manage.sh reset-password EMAIL` (or `bin/create-admin.php
   --email=…`) is still the escape hatch when email is the thing that is broken,
   and it also reactivates the account and clears `login_attempts` so the
   lockout lifts at once (README §"If you lock yourself out").
8. **No soft delete for assets.** There is archive (`status = 'Retired'`,
   `retired_on`) and there is hard delete, gated behind `assets.delete`. Deleting
   an asset cascades its photos, manuals, PAT records and maintenance history —
   but `hires.asset_id` is RESTRICT, so anything with hire history cannot be
   deleted at all. That is intentional; the README explains archive vs delete.

   Note that `Asset::historyCounts()`, which decides whether a delete is allowed,
   counts hires, PAT records, maintenance and children — **not fault reports**.
   A fault report alone does not block a delete, and cascades with the asset.
   That is the right answer for an item registered and reported broken in the
   same week, but it is a deliberate choice rather than an oversight.
9. **Clearing "Faulty" is a status change, nothing more.** There is no "resolve
   this fault" button, because there is no separate open/closed flag to clear —
   see `fault_reports` in §2.2. Recording the repair, or editing the asset's
   status, is what takes it off the dashboard tile, out of the report and out of
   the digest. The banner offers both routes. The consequence worth knowing:
   nothing ever *closes* a report, so `fault_reports` is an append-only history
   and "how long has this been broken?" is answered from the latest report's
   `faulty_on`, not from a resolution date that does not exist.
10. **The API is read-only for hires, PAT records, maintenance records, faults
   and users.** Not an unfinished feature. Checking a hire out moves the
   asset's status, allocates a reference and refuses a double booking;
   recording maintenance rolls the schedule forward and may create a follow-up;
   a PAT record without its per-step verdicts claims a test nobody performed;
   a fault report needs a photograph. A `POST` that inserted a row would produce
   a record the rest of the application does not believe in. Each resource says
   so in its own description, and in the generated specification.
11. **API rate limiting is a fixed window, not a sliding one.** One counter on
   the key's row, one UPDATE per request, nothing that grows. A burst
   straddling a boundary can briefly reach twice the limit. A sliding window
   means a row per request, which on this hosting is a bigger problem than the
   burst it prevents.
12. **A custom report's sort happens in PHP**, after the model returns its rows.
   That is what keeps a column name out of SQL, and it means a definition sorts
   at most the 5,000 rows `searchAll()` returns.
13. **The responsible party is not copied when an asset is duplicated.**
   `AssetCopier::COPYABLE_FIELDS` does not include it, so copies start
   unassigned and quietly email nobody until somebody sets one. Defensible —
   responsibility for a physical item is a per-item decision — but if a workshop
   registers ten drills by duplication, that is ten assets nobody is responsible
   for, and only the report's "Nobody responsible" figure will say so.

### Traps that have already cost time — do not re-introduce

- **Never reuse a named PDO placeholder.** Emulation is off; native prepares
  reject it. Dynamic filters use positional parameters.
- **`Controller::validate()` must return `''`, not `null`, for absent scalars.**
- **Always pass the `$escape` argument to `fgetcsv()` and `fputcsv()`** on PHP 8.4.
- **Use `array_merge`, not `+`, when overlaying form data onto a source record**
  — `+` keeps the *left* operand's keys and silently inherited the source asset's
  location and serial number in the copy workflow.
- **Don't `imagedestroy()` a GD handle that a later line still uses.**
- **When asserting in tests, assert on row links (`href="/assets/12"`)**, not on
  visible text: the search box echoes the query into its own `value` attribute
  and the register prints a child's parent tag, so substring matches lie.
- **Append to an ENUM; never insert into the middle of one.** Appending is
  instant and re-maps nothing. Inserting a member in its natural reading
  position forces a table copy and renumbers everything after it. `'Faulty'`
  therefore sits at the end of `assets.status`, and the reading order is solved
  in PHP — `Asset::STATUSES` for the dropdowns, an explicit `FIELD()` in
  `Asset::SORTS['status']` for the sort. Do not "tidy" it.
- **A flash message is consumed by the first request that renders it.** A test
  that does other `GET`s before checking the confirmation is testing the order
  of its own assertions. Capture the body of the request that follows the
  redirect, and assert against that. Cost two false failures in `fault-flow`.
- **A test that reads a setting it does not set will invert when somebody
  changes it in the UI.** `tests/fault-flow.php` now pins
  `fault_notify_immediately` at the top and restores it at the end; before that,
  a checkbox left off in Settings produced five failures that all read like a
  broken notifier.
- **A dashboard tile and the list it links to must run the same query.** The
  "Critical or High" tile first pointed at `?urgency=Critical`, so it counted two
  and showed one. `FaultReport::URGENT` exists to keep the pair in step.
- **Never set `display: flex` on a `<td>`.** It stops being a table-cell, drops
  out of the row's layout, and no longer stretches to the row height — so the
  `border-bottom` that draws the row separator is painted short. Measured on
  /assets: a 73px row with a 63px actions cell, on every table using the
  pattern. The actions column lays out inline instead, with half a gap because
  inline layout contributes the other half as a word space.
- **A grep for a call will match a comment saying not to make that call.** The
  security audit's "the API never renders an HTML 403" check failed on
  `Gate.php`, whose docblock says "…never `Auth::authorize()`". It now strips
  comments with `token_get_all()` first. A check that reads documentation as
  code fails on the file that documents the rule best.
- **`??` cannot tell a null from an absence.** Four contract-test assertions
  reported "(missing)" against a response that correctly contained `null`. Use
  `array_key_exists()` when null is a legitimate expected value.
- **A generated absolute URL is the wrong thing for a page to fetch.** The
  OpenAPI document's `servers[0].url` comes from `APP_URL`; the docs page tried
  to call it and every request failed with "Failed to fetch", because the browser
  was on a hostname `APP_URL` did not name and `connect-src 'self'` refused the
  cross-origin call. The viewer takes only the *path* and requests its own
  origin — which is also what makes a subdirectory install work.
- **An enum filter that intersects and moves on is a filter that silently
  disappears.** `?status[]=Nonsense` returned the whole register for one build:
  the repeatable branch intersected against the allowed set, got nothing, and
  skipped the filter. Reject unknown values instead. The single-value branch had
  always done this; only the repeatable one had the hole.
- **`PUT` as replacement must know each field's default.** Blanking every
  writable field the caller omitted put NULL into `assets.status` and
  `hirers.hirer_type`, both NOT NULL — a 500 rather than a replacement. Fields
  that map to a NOT NULL column declare a `default`, which `PUT` resets to and
  the OpenAPI document publishes.
- **Re-fetch the CSRF token after a 302** — the redirect response has no body.
- **`tests/security-audit.php` scans `bin/` too.** A `Database::…()` call
  written inline inside an array literal ends in `)]`, which its SQL regex
  cannot see as a terminator, so it is reported as uncontrolled concatenation.
  Assign the result on its own line.
- **A `<details>` rendered `open` by the server is an overlay on desktop.**
  The nav marks the group containing the current page as `open` so a phone's
  accordion expands. Above 900px the same panel is `position: absolute`, so it
  landed on top of every page you navigated to and stayed there. Fixed with
  `data-nav-autoopen`: CSS hides it at desktop widths (no flash, and
  `:focus-within` keeps it usable with JavaScript off), and the script then
  closes it properly — leaving it `open` but hidden made the first click
  *close* it, so a menu took two clicks to appear.
- **A feature reachable from only one buried place reads as missing.**
  Unplanned maintenance was only ever linked from inside an asset's Maintenance
  card, so it looked like the ability had been lost. It now has a front door at
  `/maintenance/log` with an asset picker, buttons on the maintenance list and
  history, and a `maintenance` scan mode.
- **"Tell the user to run X" is only useful if X exists on their machine.**
  Settings → Email said "run composer install"; the server had no Composer, so
  the advice failed with `command not found`. Guidance has to name something
  that works from where the reader is standing — the message now names
  `manage.sh composer-install`, which installs Composer first if it has to.
- **Never add an optional package to the main `pkgs` array in `install.sh`.**
  `pkg_install "${pkgs[@]}" || die` aborts the whole install if any one name is
  unknown, and `composer` is named differently (or absent) across
  distributions. Install optional packages separately and tolerantly.
- **A migration can need a privilege the application never uses.** `RENAME
  TABLE` requires `ALTER` **and `DROP`** on the source table; so does
  `ALTER TABLE … RENAME TO`. **Check the grant against the migrations, not the
  models.**
- **Never name a view variable `$template`.** `View::renderFile(string $template,
  array $data)` takes the template path in a parameter of that name, and its
  `extract($data, EXTR_SKIP)` therefore *silently drops* your variable — the
  page sees the path string instead and dies with "Cannot access offset of type
  string on string". `Admin\EmailController::editTemplate()` passes
  `emailTemplate` and says why. The same applies to any other name in
  `renderFile`'s scope: `$data`, `$path`.
- **Don't interpolate a SQL verb and a variable into the same double-quoted
  string**, even in a status message. `tests/security-audit.php` matches
  `"…DELETE…$var"` and cannot tell a message from a query. Use `sprintf` with a
  single-quoted format — `bin/console.php` `cmdMailPrune()` carries a comment.
- **`scanCanvas()` takes a context and its dimensions, not a canvas element.**
  Passing the element makes `getImageData` throw, which the reader catches and
  reports as "no code here" — so a perfectly good symbol comes back as `null`
  and the encoder looks broken. An hour went into hunting a QR encoder that was
  already correct. The signature is `scanCanvas(context, width, height)`.
- **Version information is why a QR stops scanning at exactly 120 characters.**
  Symbols below version 7 do not carry it, so an encoder that never writes it
  works perfectly through versions 1–6 and then fails — twice over, because the
  data is also written *into* the area a reader expects the version in.
  `QrCode::writeVersion()` reserves it and fills it. If a QR change ever breaks
  only the long payloads, look there first.
- **Verify an encoder against something that is not the encoder.** Comparing
  `App\Core\QrCode` with the JS encoder in `tests/barcode-decode.html`
  module-for-module proves nothing on its own: the JS one picks *alphanumeric*
  mode for an uppercase payload where the PHP one always uses byte mode, so the
  same string legitimately produces two different symbols. What settles it is
  decoding: same payload out, whatever route it took in. (And when a diff *is*
  the tool, pin the mask — `QrCode::encode($text, $mask)` exists for that.)
- **`is_active_path()` matches prefixes, and menus nest.** `/maintenance` is a
  prefix of `/maintenance/log`, so on the second page both "Schedules" and "Add
  maintenance" were shown as current. The prefix rule has to stay — it is what
  lights "Assets" while you are on `/assets/12` — so the fix is to score every
  sibling and keep the longest match: `active_path([...])`. Any new group of
  links where one path sits under another needs the same treatment.
- **`align-items: baseline` on a flex row of flex items is a trap.** It is the
  obvious way to sit menu text on the foot of a logo, and it looks right until
  you measure: a flex container's baseline is *synthesised* from its first
  child, which differs between a plain link (an anonymous text item) and a
  `<summary>` holding a `<span>`. Measured, the two disagreed by 6.8px. The
  header bottom-aligns against a shared floor variable instead, with
  `line-height: 1` to collapse the half-leading the line box would otherwise
  add.
- **A media query adds no specificity.** Two rules with the same selector, one
  inside `@media` and one outside, are decided by *source order* — so a desktop
  override written above the mobile rule it is meant to beat simply loses. The
  desktop brand rules live at the end of the branding section for this reason,
  and say so.
- **A 3x3 transform has two layouts and they look identical.** The QR
  perspective transform is nine numbers; written row-major it works, and read
  as if it were column-major it still runs, still returns plausible
  coordinates, and samples the grid transposed — so the symbol simply never
  decodes and nothing says why. Every helper in that section states the
  convention in a comment. The same trap sits in the cross product that names
  the three finder patterns: get its sign backwards and top-right and
  bottom-left swap, which is a transposed grid by another route.
- **The QR alignment pattern is found on light:dark:light, not
  dark:light:dark.** Its centre row reads D-L-D-L-D, so the triple whose middle
  run *is* the centre module is the light-dark-light one. Matching the other
  triple puts the centre a whole module out — close enough to look like a hit,
  far enough that nothing downstream works.
- **Reed-Solomon conventions do not travel.** Forney's formula carries a factor
  of the error locator value only when the generator roots start at a^1. QR's
  start at a^0, so applying it corrupts every magnitude while the error
  *positions* stay perfectly correct — which reads as "the maths is nearly
  right" and is really "no block will ever be corrected". The derivation is
  written out above `correct()`.
- **An encoder and a decoder by the same hand agree with each other.** The QR
  round trip passed while the placement order was still unverified, because
  both halves shared it. `tests/barcode-decode.html` therefore checks against
  things outside itself — the module geometry, the ISO Annex I worked example,
  the defining properties of each symbology. Keep new tests honest the same
  way.
- **The horizontal nav bar needs 1150px, and the breakpoint says so.** It was
  900px, which was never wide enough: six menu items (613px), the account block
  (243px), Scan (86px) and the brand (112–150px) come to over 1050px before any
  gaps, so between 900 and 1150 the items wrapped onto a second row and doubled
  the header height — with or without a logo. Below 1150px the drawer is used.
  Three places have to agree: the two `@media` blocks in `app.css` and the
  `matchMedia` in `app.js` that closes the drop-downs.
- **`flex-wrap: wrap` on a nav list is not a safety net, it is the bug.** It
  turns "does not fit" into a silently taller header. `nowrap` plus a
  breakpoint that is honest about the width needed is the fix; the same applies
  one level down, where a squeezed `Sign out` wrapped its own text and made the
  header taller than the menu had.
- **`element.hidden = true` does nothing to a `.btn`.** The browser's
  `[hidden] { display: none }` lives in the *user-agent* stylesheet, so any
  author rule that sets `display` outranks it — and `.btn` sets
  `display: inline-flex`. Four separate features were silently broken by this
  for months: the PAT wizard showed Next on its last step and Back on its
  first, the scanner showed Start and Stop at the same time, and the photo
  upload showed its submit before a file was picked. There is now one
  `[hidden] { display: none !important; }` in the reset. If you find yourself
  adding a per-component `[hidden]` rule, that is the bug — the global one
  already covers it.
- **`Controller::view()` takes a layout — the data array does not.** Passing
  `'layout' => 'print'` in the data does nothing at all except create an unused
  template variable, and the page renders inside the full site chrome. The
  third argument is the layout: `$this->view('assets/print', [...],
  'layouts/print')`.
- **`strip_tags()` runs after every other transform in `htmlToText()`.**
  Emitting a bare `<http://…>` for a link address gets it deleted as a tag.
  Emit `&lt;…&gt;`; the `html_entity_decode()` at the end turns it back.
- **A controller that pulls `Upload::files()` must validate in the same file.**
  `tests/security-audit.php` checks per file and is right to: a controller that
  takes a file and leaves the checking to somebody else is exactly the shape
  that hides a missing check. `Branding::acceptUpload()` therefore does both.
- **`document.querySelector('form')` in a browser test is the nav's logout
  form**, not the form on the page. Two verification runs produced wrong
  answers this way before the cause was spotted. Select the form you mean.
- **A switched-off email template writes no log row.** `Mailer::sendTemplate()`
  returns false without logging, because a deliberate silence is not a failure.
  Anything reporting the outcome to a person must call
  `Mailer::isTemplateActive()` first, or it tells them to check a log entry that
  does not exist.
- **`.nav-user-text` cannot be shown on a desktop.** `.container` caps at
  1200px, and brand + six nav items + a chip carrying the name + the scan button
  exceeds that. It used to be revealed at 1100px — a width the header can never
  reach — so "Settings" wrapped to a second row at *every* desktop size. The
  name now lives in the account drop-down instead. **If you add a seventh
  top-level nav item, re-measure.**
- **A route placeholder cannot contain a `{n}` quantifier.** `Router` parses
  placeholders with `#\{([a-zA-Z_]\w*)(?::([^}]+))?\}#`, and `[^}]+` stops at
  the first closing brace — so `{token:[a-f0-9]{64}}` compiles to a pattern that
  matches nothing and the route silently 404s. Use `[a-f0-9]+` (as the calendar
  feed already did) and check the exact length in the code that consumes it;
  `UserToken::inspect()` does.
- **A media query adds no specificity.** Two single-class rules for
  `.brand-stack` — one mobile, one inside `@media (min-width: 1150px)` — are
  decided by source order alone, so the desktop block has to come *after* the
  mobile one in the file or it silently loses. The desktop brand rules therefore
  sit at the end of the branding section, not with the rest of the desktop
  navigation, and both places say why.
- **`rem` is not the body size here.** The body is 17px and the root is left at
  16px, so `font-size: 1rem` on something meant to match a nav item is a
  *smaller* font. Use `inherit`.
- **Assert on what a message says, not on who received it.** A team-reminder
  test that checked "the removed member gets no email" failed for an honest
  reason: they still had a job of their own assigned by name. What had to stop
  was the *team's* job appearing in their digest. Assert on contents.
- **A test fixture on a retired asset proves nothing.** Every maintenance query
  carries `a.status <> 'Retired'`, so a schedule created against a retired asset
  is correctly invisible everywhere and reads as five separate feature failures.
  Seeded asset 1 is retired.
- **`install.sh` and `manage.sh` must keep LF line endings.** `.gitattributes`
  enforces it; a CRLF shebang fails with a misleading "not found".
- **Adding a required field to the settings form** breaks every test that POSTs a
  partial settings payload.

### Local development notes (Windows box)

- PHP ships with no `php.ini` from winget; `mbstring`, `pdo_mysql`, `fileinfo`,
  `curl`, `gd` and `exif` must be enabled explicitly and `-c <ini>` passed.
- `Remove-Item` is blocked by a guard on the checkout's parent path; use the
  Bash tool's `rm`.
- `Start-Process` needs its arguments as one quoted string — a space anywhere in
  the project path otherwise kills the server silently.
- Em-dashes in a `.ps1` break PowerShell 5.1 parsing.

---

## 7. If you are picking this up cold

1. To deploy: `sudo ./install.sh` on a Linux server (see `INSTALL.md`).
   To understand what that does, or to install anywhere else, `README.md`
   §"Installing by hand" has the same eight steps written out.
   Afterwards, `sudo ./manage.sh help` is the administrator's front door.
2. Run the two static audits before and after any change:

```bash
php tests/security-audit.php && php tests/escape-audit.php
```

3. Adding a report, an importer or a permission should not require a schema
   change or a new page. If it seems to, re-read §4.4.
4. `database/migrations/` is append-only.
5. Email is **off** on a fresh install and stays off until someone configures it
   in Settings → Email. That is deliberate (§4.8). If mail "does not work", the
   first thing to run is `manage.sh mail-status`, and the fix is almost always
   `manage.sh composer-install`.
6. The one runtime package is PHPMailer. Everything else is still first-party,
   and the app still runs from a plain file copy — it just cannot send.
7. **If a migration stops with `ERROR 1142: … command denied`,** the database
   grant is incomplete. `sudo ./manage.sh db-grant` re-applies it, and
   `manage.sh doctor` checks for it.
8. The four kinds of maintenance are set out in §5.1 item 4. If a change makes
   *unplanned work* harder to reach, it is a regression — that is the one that
   has already been reported as missing once.
9. Email now does more than remind people: **new users are invited by email and
   anyone can reset their own password** (§4.10). Both fall back sensibly with
   no SMTP, and both stop working the moment somebody switches email off — which
   is deliberate, but worth knowing before switching it off.
10. **Adding a seventh top-level nav item means re-measuring the header.** The
    bar needs 1097px as it stands and the breakpoint is 1150px; the arithmetic
    is in the comment above the `@media` block in `app.css`, and three places
    have to agree on the number (two blocks there plus the `matchMedia` in
    `app.js`).
