# Getting started

Requirements, installing the application, and the web-server configuration behind it. If you only want it running, the scripted install is three commands and everything else on this page is reference.

**On this page**

- [Requirements](#requirements)
- [Installation](#installation)
- [Web server configuration](#web-server-configuration)
- [Upgrading](#upgrading)
- [Backups](#backups)

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
**[INSTALL.md](../INSTALL.md)**.

Re-running it over an existing install is an upgrade: it refreshes the files
and re-runs the migrations, and leaves `.env`, `storage/` and the database
alone.

### Installing by hand

Follow these eight steps on any platform, or when you want to know exactly what
the script did.

### 1. Get the files onto the server

```bash
git clone https://github.com/maeterlinckle/kitwell.git /var/www/kitwell
cd /var/www/kitwell
```

If the server has no outbound git access, `manage.sh package` on an existing
install (or a plain tarball of a checkout) works just as well — see
[INSTALL.md](../INSTALL.md).

Composer is optional — the app ships with a fallback autoloader — but running it
gives you a faster classmap:

```bash
composer install --no-dev --optimize-autoloader
```

### 2. Create the database and a dedicated user

Connect as an administrative user (`mariadb -u root -p`, or `mysql -u root -p`
on older installs — same client) and run:

```sql
CREATE DATABASE kitwell CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'kitwell'@'localhost' IDENTIFIED BY 'a-long-random-password';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, REFERENCES ON kitwell.* TO 'kitwell'@'localhost';
FLUSH PRIVILEGES;
```

That grant lets the user own and migrate its own schema. What it withholds is
the part that matters: **no `GRANT OPTION`, no `CREATE USER`, no `FILE`, no
`SUPER`, no `PROCESS`, and no rights on any other database** — so a compromise
stays inside this one schema.

If a migration ever stops with `ERROR 1142: ... command denied`, the grant is
incomplete. `sudo ./manage.sh db-grant` re-applies it, and `manage.sh doctor`
checks for it.

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
order and recorded in a `migrations` table, so re-running is safe. Three ship
with the application: the schema, the built-in roles and permissions, and the
default settings.

```bash
php bin/migrate.php
php bin/migrate.php --status   # see what is applied and what is pending
```

To add a migration later, drop a new numbered file into `database/migrations/`
(e.g. `004_add_something.sql`) and run `php bin/migrate.php` again.

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

## Web server configuration


The document root **must** be `public/`. Everything else — `src/`, `.env`,
`storage/`, `database/` — then sits outside the web-reachable tree.

### Apache

`public/.htaccess` handles rewriting and the HTTPS redirect. The vhost needs:

```apache
<VirtualHost *:80>
    ServerName assets.example.com
    DocumentRoot /var/www/kitwell/public

    <Directory /var/www/kitwell/public>
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
    root /var/www/kitwell/public;
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
   mariadb-dump --single-transaction --routines kitwell > kitwell-$(date +%F).sql
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
sudo ./manage.sh backup              # dump + uploads + .env, into /var/backups/kitwell
sudo ./manage.sh cron-install        # nightly at 02:15
sudo ./manage.sh restore /var/backups/kitwell/kitwell-20260807-021500.sql.gz \
                        /var/backups/kitwell/uploads-20260807-021500.tar.gz
```

The backups are written mode 600 in a mode 700 directory because the dump
contains every password hash and the `.env` copy contains the database
password. **They still need copying off the machine** — a backup that only
exists on the server it protects is not a backup.

---

**See also:** [Documentation index](README.md) · [Administration](administration.md) · [Users, roles and permissions](users-roles-permissions.md)
