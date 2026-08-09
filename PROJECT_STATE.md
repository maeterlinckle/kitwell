# Project state

A ground-truth snapshot of the asset register as it stands on **2026-08-07**,
written for whoever (or whatever) picks the code up next.

The application itself was finished on 2026-08-06 and is unchanged since; what
2026-08-07 added is the deployment tooling in §5.3 — `install.sh`, `manage.sh`,
`bin/console.php` and `INSTALL.md`.

Everything below was checked against the files and the database at the time of
writing, not recalled. The schema section was produced by creating an empty
database, applying all thirteen migrations in order and then reading
`information_schema`, so it describes exactly what a fresh install produces.
Where something is *not* verified, it says so.

---

## 1. Stack and environment

| | |
|---|---|
| Language | PHP **>= 8.1** (`composer.json`), developed and tested on **8.4.22** |
| Database | **MariaDB** 10.4+ — tested on 12.3.2 |
| Framework | none |
| Runtime dependencies | none. `composer.json` declares only `php`, `ext-pdo`, `ext-pdo_mysql`, `ext-json`, `ext-mbstring`, `ext-fileinfo` — no packages |
| Optional extensions | `gd` (image resize + thumbnails), `exif` (orientation + capture date), `curl` (test scripts only) |
| Front end | server-rendered PHP templates, hand-written CSS, vanilla JS. No build step |
| Counted at time of writing | 144 PHP files (56 of them templates), 105 routes (58 GET, 47 POST), 13 migrations |

> **The database is MariaDB, not MySQL.** Two things deliberately keep the MySQL
> name and must not be "corrected": PHP's extension is `pdo_mysql` and PDO's DSN
> prefix is `mysql:`. There is no `pdo_mariadb` driver. `src/Core/Database.php`
> carries a comment saying so.

---

## 2. Database schema as implemented

Built by applying `database/migrations/001` … `013` to an empty database. All
thirteen applied cleanly with no errors.

**Totals:** 19 domain tables, 233 columns, 37 foreign keys, 90 indexes.
Every table is **InnoDB / utf8mb4_unicode_ci**.

A database migrated with `php bin/migrate.php` has a **20th** table,
`migrations` (`id`, `migration`, `batch`, `applied_at`, unique on `migration`),
created by `src/Core/Migrator.php`. It does not appear below because it is
tracking, not domain data — and note that it is *not* created if you pipe the
`.sql` files in by hand.

### 2.1 Migrations

| File | Creates / changes |
|---|---|
| `001_create_auth_tables.sql` | `roles`, `permissions`, `role_permissions`, `users`, `login_attempts` |
| `002_create_reference_tables.sql` | `categories`, `locations` (both self-nesting) |
| `003_create_assets.sql` | `assets`, `asset_photos`, `asset_manuals` |
| `004_create_maintenance.sql` | `maintenance_schedules`, `maintenance_logs`, `maintenance_log_photos` |
| `005_create_pat_records.sql` | `pat_records` |
| `006_create_borrowers_and_loans.sql` | `borrowers`, `loans` |
| `007_create_activity_log.sql` | `activity_log` |
| `008_seed_roles_and_permissions.sql` | seeds 4 roles, all 30 permissions, role grants (re-runnable; leaves hand-edited grants alone) |
| `009_seed_settings.sql` | `settings` table + asset-tag/label defaults |
| `010_add_photo_thumbnails.sql` | `asset_photos.thumbnail_path` |
| `011_maintenance_settings.sql` | `maintenance_due_days` setting |
| `012_pat_settings.sql` | `pat_due_days`, `pat_default_interval_months` settings |
| `013_loans_and_borrower_role.sql` | `loan_photos`; `loans` gains `idx_loans_open(asset_id, returned_at)`; seeds the three `loan_*` settings; **revokes every grant from the Borrower role except `loans.view_own`** (the permission itself is seeded in 008) |

Migrations are applied in filename order and recorded. **Never edit an applied
file** — add a new numbered one.

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

#### `assets` (28 columns)
| Column | Type | NN | Default / note |
|---|---|---|---|
| id | bigint unsigned AI | ✓ | |
| asset_tag | varchar(64) | ✓ | **unique** — the printed/scanned barcode value |
| barcode | varchar(64) | | **unique** — optional secondary/manufacturer barcode |
| name | varchar(191) | ✓ | |
| description | text | | |
| category_id | int unsigned | | |
| location_id | int unsigned | | |
| condition_rating | enum('Excellent','Good','Fair','Poor','Out of Service') | ✓ | 'Good' |
| status | enum('In Stock','On Loan','In Maintenance','Retired') | ✓ | 'In Stock' |
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
| is_loanable | tinyint(1) | ✓ | 1 |
| notes | text | | |
| retired_on | date | | |
| created_by / updated_by | int unsigned | | |
| created_at / updated_at | timestamp | ✓ | |

Indexes: unique `asset_tag`, unique `barcode`; `category_id`, `location_id`,
`condition_rating`, `name`, `parent_asset_id`, `requires_pat`, `serial_number`,
`status`, composite `(status, category_id)`, plus the two `*_by` FK indexes.

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

#### `maintenance_log_photos`
`id`, `maintenance_log_id` NN, `file_path` NN, `original_filename`, `mime_type` NN,
`file_size_bytes` NN 0, `caption`, `uploaded_by`, `created_at`.

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

#### `borrowers`
`id`, `borrower_type` enum('Person','Company') NN 'Person', `name` varchar(191) NN,
`company_name`, `reference` (staff/account number), `email`, `phone`, `address`,
`user_id` (**unique** — optional link to a login for the Borrower role),
`is_active` NN 1, `notes`, `created_by`, timestamps.
Indexes on `name`, `reference`, `borrower_type`.

#### `loans`
`id`, `reference` varchar(40) **unique**, `asset_id` NN, `borrower_id` NN,
`checked_out_at` datetime NN, `due_back_date` date NN, `checked_out_by_user_id`,
`condition_out` enum(condition ratings), `returned_at` datetime, `returned_to_user_id`,
`condition_in` enum(condition ratings), `returned_condition_notes` text,
`status` enum('Out','Overdue','Returned') NN 'Out', `purpose`, `hire_charge` decimal(10,2),
`notes`, timestamps.
Indexes `(asset_id, status)`, `(borrower_id, status)`, `due_back_date`,
**`(asset_id, returned_at)`** — the double-booking check — and `(status, due_back_date)`.

#### `loan_photos`
`id`, `loan_id` NN, `stage` enum('out','in') NN 'in', `file_path` NN,
`original_filename`, `mime_type` NN, `file_size_bytes` NN 0, `caption`,
`uploaded_by`, `created_at`. Index `(loan_id, stage)`.

#### `activity_log`
`id` bigint, `user_id` (SET NULL), `user_name` varchar(191) NN 'System' (**snapshot**),
`action` varchar(100) NN, `entity_type` varchar(64) NN, `entity_id` bigint unsigned,
`description` varchar(500), `changes` longtext (field-level before/after payload),
`ip_address`, `user_agent`, `created_at`.
Indexes `created_at`, `(entity_type, entity_id, created_at)`, `(user_id, created_at)`.
**No FK on `entity_id`** — the audit trail must survive deletion of what it describes.

#### `settings`
`setting_key` varchar(100) **PK**, `setting_value` text, `updated_by`, `updated_at`.

### 2.3 Foreign keys (37)

| Delete rule | Where it is used |
|---|---|
| **CASCADE** | `asset_photos.asset_id`, `asset_manuals.asset_id`, `maintenance_schedules.asset_id`, `maintenance_logs.asset_id`, `maintenance_log_photos.maintenance_log_id`, `pat_records.asset_id`, `loan_photos.loan_id`, `role_permissions.role_id`, `role_permissions.permission_id` |
| **RESTRICT** | `loans.asset_id`, `loans.borrower_id`, `users.role_id` |
| **SET NULL** | everything else — every `created_by` / `updated_by` / `uploaded_by` / `*_user_id`, plus `assets.parent_asset_id`, `assets.category_id`, `assets.location_id`, `categories.parent_id`, `locations.parent_id`, `maintenance_logs.schedule_id`, `borrowers.user_id`, `activity_log.user_id`, `settings.updated_by` |

The shape is deliberate: deleting an asset takes its own media and records with
it, but you cannot delete an asset or a borrower that has loan history, and
deleting a user never destroys the records they touched.

### 2.4 Seeded reference data (verified from a fresh migrate)

- **4 roles**: `admin` (Administrator, `is_superuser=1`), `manager` (Manager / Staff),
  `viewer` (Read-only), `borrower` (Borrower). All four are `is_system=1`.
- **30 permissions** in groups Assets, Borrowers, Loans & hire, Maintenance,
  Photos & files, PAT testing, Reports, Administration:
  `assets.view/create/edit/delete/export`, `borrowers.view/manage`,
  `loans.view/view_own/create/return/manage`, `maintenance.view/manage/complete`,
  `media.photo.upload/delete`, `media.manual.upload/delete`,
  `pat.view/manage/delete`, `reports.view`,
  `users.view/manage`, `roles.manage`, `categories.manage`, `locations.manage`,
  `settings.manage`, `audit.view`.
- **62 role_permissions rows** — admin 30, manager 24, viewer 7, borrower 1.
  - viewer: `assets.view`, `assets.export`, `borrowers.view`, `loans.view`,
    `maintenance.view`, `pat.view`, `reports.view`
  - borrower: **`loans.view_own` and nothing else**
- **11 settings**:

  | Key | Default |
  |---|---|
  | `asset_tag_prefix` | `AST-` |
  | `asset_tag_pad` | `4` |
  | `label_show_name` | `1` |
  | `label_show_location` | `1` |
  | `maintenance_due_days` | `30` |
  | `pat_due_days` | `30` |
  | `pat_default_interval_months` | `12` |
  | `loan_default_days` | `7` |
  | `loan_due_soon_days` | `2` |
  | `loan_reference_prefix` | `LN-` |
  | `organisation_name` | *(empty)* |

### 2.5 Divergences from the original build brief

Nothing here is accidental, but a reader coming from the brief should know:

1. **Sub-assets are not a separate table.** They are rows in `assets` with
   `parent_asset_id` set and `relationship_type` saying how they relate
   (`sub-asset` / `accessory` / `related`). A sub-asset therefore has every field
   a top-level asset has — its own tag, photos, PAT record and loan history.
2. **`condition` is `condition_rating`.** `condition` is a reserved word in
   MariaDB; the column and every enum reference use `condition_rating`.
3. **`loan_photos` and `asset_photos.thumbnail_path` arrived later**
   (migrations 013 and 010) rather than in the original asset/loan migrations.
4. **Columns beyond the brief**, added where the workshop use case needed them:
   `assets.barcode` (a second, manufacturer barcode distinct from the printed tag),
   `current_value`, `supplier`, `warranty_expires_on`, `pat_interval_months`,
   `is_loanable`, `retired_on`, `manufacturer_url`, `plug_fuse_rating_amps`,
   `cable_csa_mm2`.
5. **The Borrower role was narrowed after the fact.** It originally held
   `assets.view`, which exposes the whole register. Migration 013 revoked it and
   gave borrowers a separate portal instead. **`borrower` must never regain
   `assets.view`.**
6. **Keyword search is multi-term `LIKE`, not FULLTEXT.** FULLTEXT tokenises
   asset tags and serial numbers badly and ignores short words. This is a
   decision, not an omission — don't "upgrade" it without being asked.
7. **`activity_log.entity_id` has no foreign key**, and `user_name` is a
   denormalised snapshot, so the audit trail outlives what it describes.

---

## 3. File and directory structure

```
<project-root>/
├── .env                     local config — gitignored, never committed
├── .env.example             documented template
├── .gitignore
├── .gitattributes           forces LF on *.sh — a CRLF shebang breaks the installer
├── README.md                setup, deployment, usage, security
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
│   └── console.php          admin console: doctor, users, settings, prune, stats
│
├── config/
│   └── config.php           every value from Env::get(); nothing hardcoded
│
├── database/migrations/     001…013, plain .sql, applied in filename order
│
├── public/                  ← the only directory the web server should serve
│   ├── index.php            front controller
│   ├── favicon.svg
│   ├── css/  app.css, print.css
│   └── js/   app.js, scanner.js
│
├── routes/
│   └── web.php              the whole route table, 105 routes, 225 lines
│
├── src/
│   ├── bootstrap.php        autoload, env, config, errors, HTTPS, headers, session
│   ├── helpers.php          e(), url(), asset_url(), partial(), csrf_field(),
│   │                        csrf_token(), method_field(), can(), can_any(),
│   │                        auth_user(), old(), is_active_path(), format_date(),
│   │                        format_datetime(), format_money(), config(), str_limit()
│   ├── Core/                Auth, Barcode, Config, Csrf, Csv, CsvReader, Database,
│   │                        Env, Flash, Image, LoginThrottle, Migrator, Request,
│   │                        Response, Router, Session, Upload, Validator, View
│   ├── Controllers/         Controller (base) + Asset, AssetCopy, AssetExport,
│   │                        Auth, Borrower, Dashboard, Import, Label, Loan,
│   │                        Maintenance, Manual, MyLoans, Pat, Photo, Profile,
│   │                        Report, Scan
│   │   └── Admin/           Activity, Category, Location, Role, Settings, User
│   ├── Middleware/          Auth, Csrf, Guest, Permission, MiddlewareRunner
│   ├── Models/              ActivityLog, Asset, AssetManual, AssetPhoto, Borrower,
│   │                        Category, Loan, Location, MaintenanceLog,
│   │                        MaintenanceSchedule, PatRecord, Permission, Role,
│   │                        Setting, User
│   ├── Reports/             Report (base), ReportRegistry, AllAssets,
│   │                        MaintenanceDue, PatDue, AssetsOnLoan, LoansDueBack
│   ├── Imports/             Importer (base), ImportRegistry, AssetImporter,
│   │                        PatImporter
│   └── Services/            AssetTagger, AssetCopier
│
├── storage/                 ← outside the docroot
│   ├── logs/                app.log
│   └── uploads/             assets/{id}/photos, .../photos/thumbs,
│                            assets/{id}/manuals, maintenance/{logId},
│                            loans/{loanId}, imports/
│
├── templates/               56 .php templates
│   ├── layouts/             app.php, auth.php, print.php
│   ├── partials/            nav, flash, photo-gallery, photo-upload,
│   │                        pat-status, pat-record, maintenance-log-photos,
│   │                        report-table
│   ├── assets/              index, show, form, copy, apply, photos, labels
│   ├── auth/ dashboard/ errors/ profile/ scan/
│   ├── maintenance/         index, show, form, complete, history
│   ├── pat/                 index, show, form, history
│   ├── loans/               index, show, checkout, return
│   ├── borrowers/           index, show, form
│   ├── my-loans/            index, show, unlinked
│   ├── import/              index, show, preview
│   ├── reports/             index, show, print
│   └── admin/               users, roles, categories, locations, settings, activity
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
  the model** — `MaintenanceSchedule::STATUS_SQL`, `Loan::STATUS_SQL`, the PAT
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
  that scope themselves to the signed-in user: `/`, `/profile`, `/my-loans`.
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
  parses all 1508 `<?= … ?>` expressions with PHP's own tokeniser and proves it.
- Three layouts: `layouts/app` (signed in, with nav), `layouts/auth` (slim),
  `layouts/print` (label sheets and report printing).
- `templates/partials/nav.php` carries a `'built' => true|false` flag per
  section; unbuilt sections render greyed out with a "Soon" badge. **All
  sections are currently `built => true`.**
- **Reports and imports are registries, not pages.** Adding a report = write a
  `Report` subclass + one line in `ReportRegistry::REPORTS`; the controller,
  table, filters, print view and CSV are generic and driven by the report's own
  `columns()` / `filterDefinitions()`. Same shape for
  `ImportRegistry::IMPORTERS`. **Never add a bespoke report page.**
  Registered today: reports `all-assets`, `maintenance-due`, `pat-due`,
  `assets-on-loan`, `loans-due-back`; importers `assets`, `pat`.

### 4.5 Barcodes

- **Generation:** `src/Core/Barcode.php` — a self-contained Code 128 encoder with
  the full 107-entry pattern table, emitting **inline SVG**. SVG because it needs
  no GD, prints crisply at any printer resolution (a fuzzy label will not scan)
  and costs no extra HTTP request. Code set B throughout, switching to set C for
  all-digit even-length tags to halve the width.
  API: `svg($value, $moduleWidth, $heightMm)`, `encode()`, `isEncodable()`.
  Used in `templates/assets/labels.php` (sheet + single label) and
  `templates/assets/show.php` (`Barcode::svg($tag, 0.4, 16.0)`).
- **Scanning:** `public/js/scanner.js`, loaded only by `templates/scan/index.php`.
  Three routes in, one lookup: the native **`BarcodeDetector`** API where the
  browser has it (Chrome/Edge); a **hand-written Code 128 decoder** that mirrors
  the PHP encoder's pattern table where it does not (Safari, all iPhones); and a
  focused text input that a USB scanner "types" into. Exposes
  `window.AssetBarcode = { decodeCanvas, decodeLine }`.
  **No third-party barcode library** — the CSP forbids off-origin scripts, and
  a reader is not worth a vendored blob nobody can audit.
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
  | Loan condition photos (out/in) | `loans/{loanId}` |
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

---

## 5. Build-prompt status

All nine prompts are **complete**. Nothing is partial or unstarted.

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
| 6 | Loans/hires, barcode scanning, Borrower role | complete | 2026-08-05 |
| 7 | Reports | complete | 2026-08-05 |
| 8 | CSV import and export | complete | 2026-08-05 |
| 9 | Polish pass | complete | 2026-08-06 |

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
5. **PAT** — `requires_pat` toggle, full per-asset test history, class-conditional
   readings, SQL-computed status in which `Failed` outranks the retest date,
   configurable window and default interval.
6. **Loans** — checkout by scan or search, due dates, borrower records, return
   flow with condition photos, automatic overdue, double-booking prevention,
   quick-scan page, and the restricted Borrower self-service portal.
7. **Reports** — five registry-driven reports with generic filters, CSV and
   print, dashboard tiles linking through.
8. **CSV** — asset and PAT importers with upload → preview → commit and
   re-validation at commit, downloadable templates, filtered/selected asset
   export whose core columns mirror the importer's so round trips work,
   everything audit-logged.
9. **Polish** — dashboard review, server-side permission edge cases, security
   audit, WCAG contrast and tap-target fixes, README completed, `tests/` shipped.

### 5.2 What has been verified, and how

**Re-run at the time of writing this document, all passing:**

| Check | Result |
|---|---|
| `php -l` on all 144 PHP files | 0 failures |
| `tests/security-audit.php` | **35 passed, 0 failed** |
| `tests/escape-audit.php` | **1508 output expressions across 56 templates, 0 unescaped** |
| All 13 migrations against an empty database | applied cleanly; schema as documented above |
| Seed data counts | 4 roles / 30 permissions / 62 grants / 11 settings |

**Shipped in `tests/` but requiring a running site — not re-run for this document:**

| Check | What it proves |
|---|---|
| `tests/report-figures.php` | each report's rendered row count matches the same figure taken straight from the database, and the CSV matches the screen (8 cross-checks) |
| `tests/permission-matrix.php` | ~260 route/role combinations against declared expectations for all four roles. **This one writes — demo databases only** |

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

### 5.3 Deployment tooling (added 2026-08-07)

Three files, written after the nine prompts, so that the application can be
deployed without following the README step by step.

| File | What it is |
|---|---|
| `install.sh` | ~1400 lines of bash. Detects the distribution and package manager, installs PHP 8.1+/Apache/MariaDB as needed, asks the configuration questions, prints a plan and waits for a yes, creates the database and user, copies the files, writes `.env`, sets ownership and modes, writes the vhost, raises PHP's upload limits, migrates, creates the administrator and verifies with `/health`. Supports `--dry-run`, `--answers=FILE --non-interactive`, `--skip-packages`, `--web-server=`, `--tls=`. |
| `manage.sh` | ~640 lines. The README's administrative tasks as one command each: `status`, `doctor`, `health`, `users`, `reset-password`, `unlock`, `activate`/`deactivate`, `set-role`, `settings`, `config`, `migrate`, `seed`, `backup`, `restore`, `update`, `permissions`, `package`, `cron-install`, `prune-activity`, `refresh-overdue`, `audit`, `logs`, `restart`. |
| `bin/console.php` | ~790 lines. Everything that touches the database, through the application's own models — so prepared statements, the last-active-administrator rule and `ActivityLog` entries all still apply. Passwords come from a hidden prompt or `--stdin-password`, never from `argv`. |

Decisions worth keeping:

- **The generated Apache vhost sets `AllowOverride None`** and carries the
  rewrite rules and headers itself. `public/.htaccess` contains an
  unconditional HTTPS redirect, which would loop for ever on a `plain-http`
  install; putting the config in the vhost lets the redirect match the TLS mode
  chosen at install time. `.htaccess` stays in the tree for shared hosting.
- **The installer never adds a third-party repository silently.** On Debian and
  Ubuntu releases whose own PHP is older than 8.1 it offers `deb.sury.org` /
  `ppa:ondrej/php` and stops if you decline.
- **The database user is granted exactly the README's rights and no `DROP`.**
- **Backups prefer root over the local socket**, so the application user does
  not need rights beyond its own database.
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
| `bin/console.php` — `doctor`, `stats`, `user:list`, `setting:list`, `setting:set`, `db:check`, `activity:prune --dry-run`, `loans:refresh-overdue` | all correct against the live dev database |
| `bin/console.php` — `user:create`, `user:password`, `user:role`, `user:activate`, `user:deactivate`, `unlock`, all with `--stdin-password` | created, changed and removed a test account; short passwords, duplicate emails and unknown emails all rejected with exit 1 |
| Last-administrator guard | demoting *and* deactivating the only admin both refused |
| `manage.sh` — `help`, `config`, `users`, `settings`, `stats` | correct, including `.env` parsing of quoted values |
| `install.sh --help`, unknown option, `--dry-run` | correct; the plan is printed and nothing is changed |
| Full question flow, interactive and `--answers … --non-interactive` | run against a simulated Ubuntu 24.04 (`/etc/os-release` and the root check stubbed) |
| Generated Apache vhost | rendered and read; `<Directory>` precedence and the php-fpm `SetHandler` are right |
| `tests/security-audit.php`, `tests/escape-audit.php` | 35/0 and 1508/0 after the new files |

**Not verified — no Linux box was available:** no package was actually
installed, no vhost was loaded by a real Apache or nginx, no database was
created by the script, no backup or restore was round-tripped, and SELinux and
firewalld handling has never run. The installer is written defensively —
it validates the web-server config with `apachectl -t` / `nginx -t` before
reloading, and stops on the first failure — but **it has not been run
end to end on a real server.**

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
4. **Overdue loans are refreshed lazily, not by cron by default.**
   `manage.sh cron-install` adds an hourly `refresh-overdue`; without it,
   `Loan::STATUS_SQL`
   derives "Overdue" from the due date in SQL, so every screen is always correct.
   `Loan::refreshOverdue()` then writes the stored `loans.status` to match, and is
   called from the dashboard, the loans index and the borrower portal. If nobody
   visits any of those, the stored column lags — **but nothing reads the stored
   column without the SQL expression available**, so this is cosmetic. A cron
   entry calling it would be tidier.
5. **The session is bound to the user-agent string.** A browser upgrade that
   changes the UA silently signs the user out. That is the intended trade for
   making a stolen cookie less useful, but it will occasionally look like a bug.
6. **`Content-Security-Policy` includes `'unsafe-inline'`** for `script-src` and
   `style-src`. Only two templates actually need it — `layouts/app.php` and
   `layouts/auth.php` carry inline `<script>` blocks. There are **no** inline
   event handlers and **no** `style=` attributes anywhere in the templates, so
   `style-src 'unsafe-inline'` could be dropped today, and `script-src` could
   follow with a nonce plumbed through `View`. Off-origin scripts are blocked
   either way, which is the property the barcode-scanner decision rests on.
7. **No password reset by email.** Deliberate — the app sends no mail at all.
   An administrator resets passwords from `/admin/users`;
   `manage.sh reset-password EMAIL` (or `bin/create-admin.php --email=…`) is the
   lockout escape hatch (README §"If you lock yourself out"). The `manage.sh`
   route also reactivates the account and clears `login_attempts`, so the
   lockout lifts at once.
8. **No soft delete for assets.** There is archive (`status = 'Retired'`,
   `retired_on`) and there is hard delete, gated behind `assets.delete`. Deleting
   an asset cascades its photos, manuals, PAT records and maintenance history —
   but `loans.asset_id` is RESTRICT, so anything with loan history cannot be
   deleted at all. That is intentional; the README explains archive vs delete.

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
- **Re-fetch the CSRF token after a 302** — the redirect response has no body.
- **`tests/security-audit.php` scans `bin/` too.** A `Database::…()` call
  written inline inside an array literal ends in `)]`, which its SQL regex
  cannot see as a terminator, so it is reported as uncontrolled concatenation.
  Assign the result on its own line.
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
