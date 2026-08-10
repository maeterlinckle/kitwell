# Installing

Two scripts sit at the top of the project:

| Script | What it is for |
|--------|----------------|
| `install.sh` | one command that turns a bare Linux server into a working site |
| `manage.sh`  | everything after that: passwords, lockouts, backups, updates, checks |

`README.md` still describes every step by hand. This file covers doing it with
the scripts instead.

---

## The short version

On the server, as root:

```bash
git clone https://github.com/maeterlinckle/kitwell.git /opt/asset-register-src
cd /opt/asset-register-src
sudo ./install.sh
```

Or, if the server has no outbound git access, copy an archive over and unpack it
instead:

```bash
tar -xzf asset-register.tar.gz -C /opt/asset-register-src
cd /opt/asset-register-src
sudo ./install.sh
```

It asks about a dozen questions, then installs Apache, PHP and MariaDB if they
are missing, creates the database, writes `.env`, sets the file permissions,
configures the web server, applies the migrations and creates your
administrator account. When it finishes you can sign in.

Run it with `--dry-run` first if you want to see the plan without anything
being changed.

---

## Getting the files onto the server

The whole project is the payload — there is nothing to build and no packages to
fetch, so a clone, a zip or a tarball are all equally good.

**By clone**, when the server can reach GitHub. This is also the easiest to
update later, since `manage.sh update` can point straight at a `git pull`ed
copy:

```bash
git clone https://github.com/maeterlinckle/kitwell.git
```

**From an existing install** (this leaves out `.env`, the uploads and the
database, so the archive holds nothing secret):

```bash
sudo /var/www/asset-register/manage.sh package /tmp/asset-register.tar.gz
```

**From a source checkout on Linux or macOS:**

```bash
tar -czf asset-register.tar.gz --exclude=.git --exclude=.env \
    --exclude=vendor --exclude='storage/uploads/*' --exclude='storage/logs/*' .
```

**From Windows PowerShell:**

```powershell
Compress-Archive -Path * -DestinationPath asset-register.zip -Force
```

Delete `.env` from the zip afterwards if the source machine has one — it holds
a database password. (`Compress-Archive` includes dotfiles.)

> **Line endings.** `install.sh` and `manage.sh` must have Unix line endings.
> `.gitattributes` enforces that for git checkouts; a zip built on Windows
> keeps whatever is on disk, which is already LF here. If a script ever fails
> with `bad interpreter` or `not found`, fix it with
> `sed -i 's/\r$//' install.sh manage.sh`.

---

## What install.sh actually does

1. **Checks the machine.** Root, Linux, a recognised package manager
   (apt, dnf/yum, zypper, pacman) and a real copy of the source tree.
2. **Looks for PHP 8.1+, a web server and MariaDB**, and lists what it will
   install. On Debian and Ubuntu releases whose own PHP is older than 8.1 it
   offers to add the standard `deb.sury.org` / `ppa:ondrej/php` repository —
   and stops if you decline rather than adding it quietly.
3. **Asks the questions** — install directory, site name and URL, timezone,
   how HTTPS is handled, database name and user, and the first administrator.
4. **Shows the plan and waits for a yes.** Nothing has changed up to this point.
5. **Installs the packages**, starts and enables MariaDB and the web server.
6. **Creates the database** and a user with exactly the rights the application
   and its migrations need — `SELECT, INSERT, UPDATE, DELETE, CREATE, DROP,
   ALTER, INDEX, REFERENCES` on that one database and nothing else.
   `DROP` is there for the migrations: `RENAME TABLE` requires it on the source
   table. Still withheld: `GRANT OPTION`, `CREATE USER`, `FILE`, `SUPER`,
   `PROCESS`, and any rights on another database.
7. **Copies the files**, leaving out `.git`, `.env`, `vendor/` and the contents
   of `storage/`.
8. **Writes `.env`** with a generated 28-character database password and a
   generated `APP_KEY`, mode 640, owned `root:www-data`. An existing `.env` is
   backed up first, never overwritten in place.
   `APP_KEY` encrypts the SMTP password in the database, so outbound email can
   be configured from the Settings page without anyone needing shell access.
   **Back it up with the database** — a dump restored without the matching key
   leaves a password that cannot be decrypted.
8b. **Runs `composer install` if Composer is present**, which fetches PHPMailer,
   the one runtime dependency. If Composer is missing it says so and carries on:
   everything works except *sending* email, and Settings → Email prints the
   exact command to run later.
9. **Sets ownership and modes:** application files `root:www-data`,
   directories 750, files 640; `storage/` owned by the web user, 2775/664 — the
   only directory the application can write to. On SELinux systems it also sets
   the `httpd_sys_content_t` / `httpd_sys_rw_content_t` labels.
10. **Raises PHP's upload limits** to match `UPLOAD_MAX_PDF_MB`, so a large
    photo is not rejected by PHP before the application sees it.
11. **Writes the web server config**, tests it, and reloads only if it is valid.
12. **Runs the migrations, creates the administrator, and checks it over** with
    `bin/console.php doctor` and a real request to `/health`.

Everything it touches is inside the install directory, the web server's config
directory, `/etc/php*` and the one database. It does not modify PHP's main
`php.ini`, other sites, or any other database.

---

## The HTTPS question

This is the choice that most often goes wrong, so the installer asks it
directly.

| Answer | Use when | What it sets |
|--------|----------|--------------|
| **proxy** (default) | TLS terminates at a reverse proxy in front of this server | `FORCE_HTTPS=true`, `TRUST_PROXY=true`; the vhost serves plain HTTP and does no redirecting of its own |
| **direct-https** | this server holds the certificate | `FORCE_HTTPS=true`, `TRUST_PROXY=false`; an SSL vhost plus an HTTP vhost that redirects to it |
| **plain-http** | a trusted LAN with no TLS anywhere | `FORCE_HTTPS=false`, `TRUST_PROXY=false`; no redirect anywhere |

With **proxy**, the proxy must forward `Host`, `X-Forwarded-For` and
`X-Forwarded-Proto: https`. Without the last one the application believes the
request arrived over plain HTTP and redirects it to HTTPS for ever.

The generated Apache vhost sets `AllowOverride None` and carries the rewrite
rules and security headers itself, so `public/.htaccess` is not consulted.
That is deliberate: the `.htaccess` contains a fixed HTTPS redirect that would
cause exactly that loop on a `plain-http` install. `.htaccess` is left in place
for shared hosting, where you cannot edit a vhost.

---

## Unattended installs

Put the answers in a file and skip every prompt:

```bash
sudo install -m 600 /dev/null /root/asset-register.answers
sudo tee /root/asset-register.answers >/dev/null <<'EOF'
INSTALL_DIR=/var/www/asset-register
APP_NAME="Junction Asset Register"
APP_URL=https://assets.example.com
APP_TIMEZONE=Europe/London
ORGANISATION_NAME="Junction Workshop"
SERVER_NAME=assets.example.com
TLS_MODE=proxy
DB_NAME=asset_register
DB_USER=asset_register
# DB_PASSWORD is generated if you leave it out.
ADMIN_NAME="Jo Bloggs"
ADMIN_EMAIL=jo@example.com
ADMIN_PASSWORD='a-long-one-you-chose'
EOF

sudo ./install.sh --answers=/root/asset-register.answers --non-interactive
sudo shred -u /root/asset-register.answers
```

The file holds two passwords. Create it with mode 600 and delete it when the
install finishes — the installer reminds you.

Useful flags: `--dry-run`, `--skip-packages` (you manage packages yourself),
`--seed-demo`, `--web-server=nginx|none`, `--dir=`, `--domain=`, `--tls=`.

---

## Re-running it

Running `install.sh` again over an existing install is an upgrade: it refreshes
the application files, re-applies the permissions and re-runs the migrations,
and it says so before doing anything. **`.env`, `storage/` and the database are
left alone.**

For an ordinary update from a new tarball, `manage.sh update` is the narrower
tool:

```bash
sudo /var/www/asset-register/manage.sh backup
sudo /var/www/asset-register/manage.sh update /opt/new-version
```

---

## After the install: manage.sh

```bash
sudo /var/www/asset-register/manage.sh help
```

The tasks the README describes, as one command each:

| Task | Command |
|------|---------|
| Someone forgot their password | `manage.sh reset-password jo@example.com` |
| Someone is locked out | `manage.sh unlock jo@example.com` |
| Add a user | `manage.sh create-user manager` |
| Someone left | `manage.sh deactivate jo@example.com` |
| Is anything wrong? | `manage.sh doctor` |
| Is it up? | `manage.sh status` / `manage.sh health` |
| Back it up | `manage.sh backup` |
| Restore it | `manage.sh restore dump.sql.gz uploads.tar.gz` |
| Apply an update | `manage.sh update /path/to/new` |
| Permissions went wrong | `manage.sh permissions` |
| What went wrong? | `manage.sh logs -n 100` |
| Change a `.env` value | `manage.sh config FORCE_HTTPS false` |
| Trim the audit trail | `manage.sh prune-activity 730` |
| Schedule backups and reminder emails | `manage.sh cron-install` |
| Is email working? | `manage.sh mail-status` |
| Prove it | `manage.sh mail-test you@example.com` |
| Run the reminders now | `manage.sh send-reminders --dry-run` |
| Find a calendar feed link | `manage.sh calendar-url jo@example.com` |
| Re-run the security audits | `manage.sh audit` |

Email is **off** after an install. To turn it on, sign in and go to
**Settings → Email**: enter your SMTP details, tick *Send email from this
application*, save, and press *Send test email*. Reminders are configured on the
next tab and are each off until you switch them on. `manage.sh cron-install`
adds the daily run that sends them.

Everything that touches the database goes through `bin/console.php`, which uses
the application's own models — so the same prepared statements, the same
"you cannot remove the last administrator" rule, and the same audit log entries
as the web interface. Passwords are typed twice with the echo off and are never
accepted as a command-line argument.

`bin/console.php` can also be run directly if you prefer, and does not need
root:

```bash
cd /var/www/asset-register && sudo -u www-data php bin/console.php doctor
```

---

## If the install fails part way

Nothing is destroyed, and it is safe to fix the problem and run it again.

| Symptom | Where to look |
|---------|---------------|
| Package installation failed | the package manager's own output above the failure |
| `Could not connect to MariaDB as root` | `systemctl status mariadb`; supply `DB_ROOT_PASSWORD` if root has a password |
| Apache rejected the configuration | the `apachectl -t` output printed just before it stopped; the vhost is at `/etc/apache2/sites-available/asset-register.conf` or `/etc/httpd/conf.d/asset-register.conf` |
| The migrations failed | credentials in `.env`, then `sudo -u www-data php bin/migrate.php --status` |
| `/health` did not answer | `manage.sh status`, then the web server log in `/var/log/apache2` or `/var/log/httpd` |
| The site loads but redirects for ever | the HTTPS question above — the proxy is not sending `X-Forwarded-Proto: https` |

`manage.sh doctor` is the fastest way to find out what is actually wrong; it
checks the PHP version and extensions, the `.env` permissions, the storage
directories, the database connection, pending migrations and whether an active
administrator exists.

---

## What is not covered

- **Certificates.** `direct-https` uses a certificate you already have. There is
  no Let's Encrypt integration; run `certbot` yourself and point `TLS_CERT` and
  `TLS_KEY` at the result.
- **Remote databases.** The installer creates the user as
  `'user'@'localhost'`. For a database on another host, create the user there
  and run with `--skip-packages`, then edit `DB_HOST` in `.env`.
- **Windows and macOS servers.** The installer is Linux only. `README.md`
  documents the manual steps, which work anywhere.
