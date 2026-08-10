#!/usr/bin/env bash
#
# Asset Register — administration.
#
#   sudo ./manage.sh help
#
# The tasks the README describes, in one place: resetting a password, clearing
# a lockout, backups and restores, applying an update, re-running the
# migrations, checking the install over and re-applying file permissions.
#
# Anything that needs the database goes through bin/console.php so it uses the
# application's own models, prepared statements and audit log. Anything that
# needs root — services, ownership, backups, cron — is done here.
#
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="$APP_DIR/.env"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/asset-register}"
BACKUP_KEEP="${BACKUP_KEEP:-14}"

QUIET=no

if [ -t 1 ] && [ -z "${NO_COLOR:-}" ]; then
    C_RESET=$'\033[0m'; C_BOLD=$'\033[1m'; C_DIM=$'\033[2m'
    C_RED=$'\033[31m'; C_GREEN=$'\033[32m'; C_YELLOW=$'\033[33m'; C_BLUE=$'\033[36m'
else
    C_RESET=""; C_BOLD=""; C_DIM=""; C_RED=""; C_GREEN=""; C_YELLOW=""; C_BLUE=""
fi

say()  { [ "$QUIET" = yes ] || printf '%s\n' "$*"; }
step() { [ "$QUIET" = yes ] || printf '\n%s==>%s %s%s%s\n' "$C_BLUE" "$C_RESET" "$C_BOLD" "$*" "$C_RESET"; }
ok()   { [ "$QUIET" = yes ] || printf '  %s[ ok ]%s %s\n' "$C_GREEN" "$C_RESET" "$*"; }
info() { [ "$QUIET" = yes ] || printf '  %s[ .. ]%s %s\n' "$C_DIM" "$C_RESET" "$*"; }
warn() { printf '  %s[warn]%s %s\n' "$C_YELLOW" "$C_RESET" "$*" >&2; }
die()  { printf '\n%sError:%s %s\n' "$C_RED" "$C_RESET" "$*" >&2; exit 1; }

have() { command -v "$1" >/dev/null 2>&1; }

usage() {
    cat <<'USAGE'
Asset Register — administration

  sudo ./manage.sh <command> [arguments]

Checking
  status                      services, versions, disk and database at a glance
  doctor                      full check of PHP, config, storage and database
  health                      call the site's own /health endpoint
  stats                       counts from the register
  audit                       re-run the shipped security and escaping audits
  logs [-f] [-n LINES]        the application log

Users
  users                       list every account
  create-user [ROLE]          create a user (admin, manager, viewer, borrower)
  reset-password [EMAIL]      set a new password and lift any lockout
  unlock [EMAIL]              clear sign-in lockouts (all accounts if no email)
  activate EMAIL              re-enable an account
  deactivate EMAIL            disable an account
  set-role EMAIL ROLE         move an account to another role

Application
  settings                    show the application settings
  set-setting KEY VALUE       change one
  config KEY [VALUE]          read or change a value in .env
  migrate [--status]          apply pending database migrations
  seed                        load the demo data (never on a live system)
  refresh-overdue             recompute the stored overdue flag on loans
  prune-activity [DAYS]       delete audit rows older than DAYS (default 365)

Server
  backup [DIR]                dump the database and archive the uploads
  restore DUMP [UPLOADS]      restore from a backup
  update [SOURCE_DIR]         copy in a new version and migrate
  permissions                 re-apply ownership and file modes
  package [FILE]              build a distributable archive of this install
  cron-install                nightly backup + hourly overdue refresh
  cron-remove                 remove them again
  restart                     restart the web server and PHP-FPM

Options
  --quiet                     only print warnings and errors (for cron)
  --yes                       do not ask for confirmation
USAGE
}

# ---------------------------------------------------------------------------
# Environment
# ---------------------------------------------------------------------------
require_root() {
    [ "$(id -u)" -eq 0 ] || die "This needs root:  sudo $0 $*"
}

env_get() { # env_get KEY
    local key="$1" line value
    [ -r "$ENV_FILE" ] || return 0

    line="$(grep -E "^[[:space:]]*${key}=" "$ENV_FILE" | tail -1 || true)"
    [ -n "$line" ] || return 0

    value="${line#*=}"
    value="${value#"${value%%[![:space:]]*}"}"          # trim leading space
    value="${value%"${value##*[![:space:]]}"}"          # trim trailing space

    case "$value" in
        \"*\") value="${value%\"}"; value="${value#\"}" ;;
        \'*\') value="${value%\'}"; value="${value#\'}" ;;
        *)     value="${value%% #*}" ;;                 # strip an inline comment
    esac

    printf '%s' "$value"
}

env_set() { # env_set KEY VALUE
    local key="$1" value="$2" backup

    [ -f "$ENV_FILE" ] || die "No .env at $ENV_FILE."

    backup="$ENV_FILE.$(date +%Y%m%d-%H%M%S).bak"
    cp -p "$ENV_FILE" "$backup"
    chmod 600 "$backup"

    if grep -qE "^[[:space:]]*${key}=" "$ENV_FILE"; then
        # Written through a temp file so the original mode and owner survive.
        local tmp; tmp="$(mktemp)"
        awk -v k="$key" -v v="$value" '
            $0 ~ "^[[:space:]]*" k "=" { print k "=" v; next }
            { print }
        ' "$ENV_FILE" > "$tmp"
        cat "$tmp" > "$ENV_FILE"
        rm -f "$tmp"
    else
        printf '%s=%s\n' "$key" "$value" >> "$ENV_FILE"
    fi

    ok "$key set (previous .env kept as $(basename "$backup"))"
}

detect_web_user() {
    local candidate
    for candidate in www-data apache http nginx; do
        id -u "$candidate" >/dev/null 2>&1 && { printf '%s' "$candidate"; return 0; }
    done
    printf 'root'
}

PHP_BIN="$(command -v php || true)"
[ -n "$PHP_BIN" ] || die "PHP is not on the PATH."

WEB_USER="$(detect_web_user)"
WEB_GROUP="$(id -gn "$WEB_USER" 2>/dev/null || printf '%s' "$WEB_USER")"
DB_CLIENT="$(command -v mariadb || command -v mysql || true)"
DUMP_BIN="$(command -v mariadb-dump || command -v mysqldump || true)"

WEB_READ_CHECKED=no

# PHP runs as the web user, so the web user has to be able to read the tree.
# It cannot if the app sits somewhere only root can traverse — a checkout under
# /root being the usual way that happens. Say so once, plainly, instead of
# letting PHP fail to open src/bootstrap.php over and over.
assert_web_can_read() {
    [ "$WEB_READ_CHECKED" = yes ] && return 0
    WEB_READ_CHECKED=yes

    [ "$(id -u)" -eq 0 ] || return 0
    id -u "$WEB_USER" >/dev/null 2>&1 || return 0

    if run_as_web test -r "$APP_DIR/src/bootstrap.php" 2>/dev/null; then
        return 0
    fi

    say "" >&2
    printf '%sThe web server user cannot read this installation.%s\n' "$C_BOLD" "$C_RESET" >&2
    say "" >&2
    warn "$WEB_USER cannot read $APP_DIR/src/bootstrap.php"
    say "" >&2
    say "  PHP runs as $WEB_USER, so it needs to read the application files." >&2
    say "  A directory only root can enter — anything under /root, typically —" >&2
    say "  will always fail this way." >&2
    say "" >&2
    say "  Fix the ownership and modes:" >&2
    say "" >&2
    say "      sudo $APP_DIR/manage.sh permissions" >&2
    say "" >&2
    say "  If the application really does live under /root, move it somewhere" >&2
    say "  the web server can reach, such as /var/www/asset-register." >&2
    say "" >&2

    exit 1
}

run_as_web() {
    if [ "$(id -u)" -ne 0 ]; then
        "$@"
    elif have runuser; then
        runuser -u "$WEB_USER" -- "$@"
    elif have sudo; then
        sudo -u "$WEB_USER" -- "$@"
    else
        local quoted="" arg
        for arg in "$@"; do quoted+=" $(printf '%q' "$arg")"; done
        su -s /bin/sh -c "$quoted" "$WEB_USER"
    fi
}

console() {
    assert_web_can_read
    ( cd "$APP_DIR" && run_as_web "$PHP_BIN" bin/console.php "$@" )
}

confirm() {
    local question="$1" answer
    [ "${ASSUME_YES:-no}" = yes ] && return 0
    read -r -p "  $question [y/N]: " answer || true
    [ "${answer,,}" = y ] || [ "${answer,,}" = yes ]
}

web_service() {
    local candidate
    for candidate in apache2 httpd nginx; do
        if have systemctl && systemctl list-unit-files --no-legend "$candidate.service" 2>/dev/null | grep -q .; then
            printf '%s' "$candidate"; return 0
        fi
    done
    printf ''
}

db_service() {
    local candidate
    for candidate in mariadb mysqld mysql; do
        if have systemctl && systemctl list-unit-files --no-legend "$candidate.service" 2>/dev/null | grep -q .; then
            printf '%s' "$candidate"; return 0
        fi
    done
    printf ''
}

# ---------------------------------------------------------------------------
# Commands
# ---------------------------------------------------------------------------
cmd_status() {
    step "Asset Register at $APP_DIR"

    say "  PHP            $("$PHP_BIN" -r 'echo PHP_VERSION;')"
    say "  Application    $(env_get APP_NAME) — $(env_get APP_URL)"
    say "  Environment    APP_ENV=$(env_get APP_ENV)  APP_DEBUG=$(env_get APP_DEBUG)  FORCE_HTTPS=$(env_get FORCE_HTTPS)  TRUST_PROXY=$(env_get TRUST_PROXY)"
    say "  Database       $(env_get DB_DATABASE) on $(env_get DB_HOST) as $(env_get DB_USERNAME)"
    say "  Web user       $WEB_USER"

    step "Services"
    local svc
    for svc in "$(web_service)" "$(db_service)" php-fpm; do
        [ -n "$svc" ] || continue
        if have systemctl && systemctl list-unit-files --no-legend "$svc.service" 2>/dev/null | grep -q .; then
            if systemctl is-active --quiet "$svc"; then ok "$svc is running"; else warn "$svc is NOT running"; fi
        fi
    done

    step "Disk"
    say "  $(df -h "$APP_DIR" | awk 'NR==2 {printf "%s used of %s (%s) on %s", $3, $2, $5, $6}')"
    say "  Uploads        $(du -sh "$APP_DIR/storage/uploads" 2>/dev/null | cut -f1) in $APP_DIR/storage/uploads"

    step "Database"
    console db:check || warn "The database could not be reached — run: $0 doctor"

    step "Migrations"
    assert_web_can_read
    ( cd "$APP_DIR" && run_as_web "$PHP_BIN" bin/migrate.php --status ) | tail -3
}

cmd_doctor()  { console doctor; }
cmd_stats()   { console stats; }
cmd_users()   { console user:list; }

cmd_health() {
    local url; url="$(env_get APP_URL)/health"
    have curl || die "curl is not installed."

    step "GET $url"
    if curl -fsS --max-time 15 "$url"; then
        say ""
        ok "The site answered."
    else
        say ""
        warn "No healthy answer. Trying the loopback interface directly..."
        curl -fsS --max-time 15 -H 'X-Forwarded-Proto: https' "http://127.0.0.1/health" \
            || die "The application is not answering. Check: journalctl -u $(web_service) -n 50"
    fi
}

cmd_create_user() {
    local role="${1:-admin}"
    step "Create a $role"
    console user:create --role="$role"
}

cmd_reset_password() {
    local email="${1:-}"
    step "Reset a password"
    say "  The new password is typed twice and never echoed."
    if [ -n "$email" ]; then console user:password --email="$email"; else console user:password; fi
}

cmd_unlock() {
    local email="${1:-}"
    if [ -n "$email" ]; then console unlock --email="$email"; else console unlock; fi
}

cmd_activate()   { [ -n "${1:-}" ] || die "Which account? Usage: $0 activate EMAIL";   console user:activate --email="$1"; }
cmd_deactivate() { [ -n "${1:-}" ] || die "Which account? Usage: $0 deactivate EMAIL"; console user:deactivate --email="$1"; }

cmd_set_role() {
    [ -n "${2:-}" ] || die "Usage: $0 set-role EMAIL ROLE   (admin, manager, viewer, borrower)"
    console user:role --email="$1" --role="$2"
}

cmd_settings()    { console setting:list; }
cmd_set_setting() {
    [ -n "${2:-}" ] || die "Usage: $0 set-setting KEY VALUE"
    console setting:set --key="$1" --value="$2"
}

cmd_config() {
    [ -n "${1:-}" ] || die "Usage: $0 config KEY [VALUE]"

    if [ -z "${2:-}" ]; then
        local value; value="$(env_get "$1")"
        say "$1=${value}"
        return 0
    fi

    require_root config
    env_set "$1" "$2"
    warn "Restart the web server for it to take effect:  $0 restart"
}

cmd_migrate() {
    step "Migrations"
    assert_web_can_read
    ( cd "$APP_DIR" && run_as_web "$PHP_BIN" bin/migrate.php "$@" )
}

cmd_seed() {
    warn "The demo data adds example assets and four accounts that share a published password."
    confirm "Load it anyway?" || die "Nothing was changed."
    assert_web_can_read
    ( cd "$APP_DIR" && run_as_web "$PHP_BIN" bin/seed.php --force )
}

cmd_refresh_overdue() { console loans:refresh-overdue; }

cmd_prune_activity() {
    local days="${1:-365}"
    console activity:prune --days="$days" ${ASSUME_YES:+--force}
}

cmd_audit() {
    step "Security audit"
    ( cd "$APP_DIR" && "$PHP_BIN" tests/security-audit.php )
    step "Output escaping audit"
    ( cd "$APP_DIR" && "$PHP_BIN" tests/escape-audit.php )
}

cmd_logs() {
    local log="$APP_DIR/storage/logs/app.log" follow=no lines=50

    [ -f "$log" ] || die "No log yet at $log — nothing has gone wrong."

    while [ $# -gt 0 ]; do
        case "$1" in
            -f|--follow) follow=yes ;;
            -n)          shift; lines="${1:-50}" ;;
            [0-9]*)      lines="$1" ;;
        esac
        shift
    done

    if [ "$follow" = yes ]; then
        tail -f -n "$lines" "$log"
    else
        tail -n "$lines" "$log"
    fi
}

# --- Backup and restore -----------------------------------------------------
db_dump_args() {
    # Prefer root over the local socket: the application user deliberately has
    # no rights beyond its own database, and a dump should not need more.
    if [ "$(id -u)" -eq 0 ] && "$DB_CLIENT" -u root -e 'SELECT 1' >/dev/null 2>&1; then
        printf '%s' "-u root"
    else
        printf ''
    fi
}

cmd_backup() {
    require_root backup
    [ -n "$DUMP_BIN" ] || die "Neither mariadb-dump nor mysqldump is installed."

    local dir="${1:-$BACKUP_DIR}"
    local stamp; stamp="$(date +%Y%m%d-%H%M%S)"
    local db;    db="$(env_get DB_DATABASE)"

    mkdir -p "$dir"
    chmod 700 "$dir"

    step "Backing up to $dir"

    local dump="$dir/${db}-${stamp}.sql.gz"
    local cnf=""

    if [ -z "$(db_dump_args)" ]; then
        cnf="$(mktemp)"; chmod 600 "$cnf"
        printf '[client]\nuser=%s\npassword=%s\nhost=%s\nport=%s\n' \
            "$(env_get DB_USERNAME)" "$(env_get DB_PASSWORD)" "$(env_get DB_HOST)" "$(env_get DB_PORT)" > "$cnf"
        "$DUMP_BIN" --defaults-extra-file="$cnf" --single-transaction --routines --events "$db" | gzip -9 > "$dump"
        rm -f "$cnf"
    else
        "$DUMP_BIN" -u root --single-transaction --routines --events "$db" | gzip -9 > "$dump"
    fi

    chmod 600 "$dump"
    ok "Database  $(basename "$dump")  ($(du -h "$dump" | cut -f1))"

    # The uploads cannot be regenerated from the database — it only holds paths.
    local uploads="$dir/uploads-${stamp}.tar.gz"
    tar -czf "$uploads" -C "$APP_DIR/storage" uploads
    chmod 600 "$uploads"
    ok "Uploads   $(basename "$uploads")  ($(du -h "$uploads" | cut -f1))"

    cp -p "$ENV_FILE" "$dir/env-${stamp}.bak"
    chmod 600 "$dir/env-${stamp}.bak"
    ok "Config    env-${stamp}.bak"

    if [ "$BACKUP_KEEP" -gt 0 ]; then
        local removed=0 old
        while IFS= read -r old; do
            rm -f "$old"; removed=$((removed + 1))
        done < <(ls -1t "$dir"/*.sql.gz 2>/dev/null | tail -n +$((BACKUP_KEEP + 1)))
        while IFS= read -r old; do rm -f "$old"; done < <(ls -1t "$dir"/uploads-*.tar.gz 2>/dev/null | tail -n +$((BACKUP_KEEP + 1)))
        while IFS= read -r old; do rm -f "$old"; done < <(ls -1t "$dir"/env-*.bak 2>/dev/null | tail -n +$((BACKUP_KEEP + 1)))
        [ "$removed" -gt 0 ] && info "Removed $removed backup set(s) older than the last $BACKUP_KEEP"
    fi

    say ""
    say "  Both files are needed for a working restore. Copy them off this machine."
}

cmd_restore() {
    require_root restore
    local dump="${1:-}" uploads="${2:-}"

    [ -n "$dump" ] || die "Usage: $0 restore DUMP.sql.gz [UPLOADS.tar.gz]"
    [ -r "$dump" ] || die "Cannot read $dump."
    [ -n "$DB_CLIENT" ] || die "No mariadb/mysql client is installed, so the dump cannot be loaded."

    local db; db="$(env_get DB_DATABASE)"

    warn "This replaces everything in the '$db' database."
    [ -n "$uploads" ] && warn "It also replaces $APP_DIR/storage/uploads."
    confirm "Restore over the live data?" || die "Nothing was changed."

    step "Restoring the database"

    local cnf; cnf="$(mktemp)"; chmod 600 "$cnf"
    printf '[client]\nuser=%s\npassword=%s\nhost=%s\nport=%s\n' \
        "$(env_get DB_USERNAME)" "$(env_get DB_PASSWORD)" "$(env_get DB_HOST)" "$(env_get DB_PORT)" > "$cnf"

    if [ "${dump##*.}" = "gz" ]; then
        gzip -dc "$dump" | "$DB_CLIENT" --defaults-extra-file="$cnf" "$db"
    else
        "$DB_CLIENT" --defaults-extra-file="$cnf" "$db" < "$dump"
    fi
    rm -f "$cnf"
    ok "Database restored"

    if [ -n "$uploads" ]; then
        [ -r "$uploads" ] || die "Cannot read $uploads."
        step "Restoring the uploads"
        rm -rf "$APP_DIR/storage/uploads"
        tar -xzf "$uploads" -C "$APP_DIR/storage"
        ok "Uploads restored"
    fi

    cmd_permissions
    console doctor || true
}

# --- Update, permissions, packaging ----------------------------------------
cmd_update() {
    require_root update
    local source="${1:-}"

    [ -n "$source" ] || die "Usage: $0 update /path/to/new/version"
    [ -f "$source/public/index.php" ] || die "$source does not look like the Asset Register source tree."

    warn "Back up first if you have not already:  $0 backup"
    confirm "Copy $source over $APP_DIR and run the migrations?" || die "Nothing was changed."

    step "Copying the new version"
    tar -cf - -C "$source" \
        --exclude=./.git --exclude=./.claude --exclude=./.env \
        --exclude=./vendor --exclude=./node_modules \
        --exclude=./storage/uploads --exclude=./storage/logs \
        --exclude='./*.zip' --exclude='./*.tar.gz' \
        . | tar -xf - -C "$APP_DIR"
    ok "Files updated — .env, storage/ and the database were left alone"

    if have composer && [ -f "$APP_DIR/composer.json" ]; then
        ( cd "$APP_DIR" && composer install --no-dev --optimize-autoloader --no-interaction --quiet ) || true
    fi

    cmd_permissions
    cmd_migrate
    console doctor || true

    local svc; svc="$(web_service)"
    [ -n "$svc" ] && have systemctl && systemctl reload "$svc" >/dev/null 2>&1 || true
    ok "Done"
}

cmd_permissions() {
    require_root permissions

    step "Re-applying ownership and modes"

    local group="$WEB_GROUP"
    chown -R root:"$group" "$APP_DIR"
    find "$APP_DIR" -type d -exec chmod 750 {} +
    find "$APP_DIR" -type f -exec chmod 640 {} +

    chown -R "$WEB_USER":"$group" "$APP_DIR/storage"
    find "$APP_DIR/storage" -type d -exec chmod 2775 {} +
    find "$APP_DIR/storage" -type f -exec chmod 664 {} +

    local script
    for script in install.sh manage.sh; do
        [ -f "$APP_DIR/$script" ] && chmod 750 "$APP_DIR/$script"
    done

    [ -f "$ENV_FILE" ] && { chown root:"$group" "$ENV_FILE"; chmod 640 "$ENV_FILE"; }

    if have restorecon && have getenforce && [ "$(getenforce)" = "Enforcing" ]; then
        restorecon -R "$APP_DIR" >/dev/null 2>&1 || true
    fi

    ok "Application root:$group (750/640), storage $WEB_USER:$group (2775/664), .env 640"
}

cmd_package() {
    local out="${1:-$PWD/asset-register-$(date +%Y%m%d).tar.gz}"

    step "Building $out"
    tar -czf "$out" -C "$APP_DIR" \
        --exclude=./.git --exclude=./.claude --exclude=./.env \
        --exclude=./vendor --exclude=./node_modules \
        --exclude=./storage/uploads --exclude=./storage/logs \
        --exclude='./*.tar.gz' --exclude='./*.zip' \
        .

    ok "$out  ($(du -h "$out" | cut -f1))"
    say ""
    say "  Copy it to the new server and:"
    say "    mkdir -p asset-register && tar -xzf $(basename "$out") -C asset-register"
    say "    cd asset-register && sudo ./install.sh"
    say ""
    say "  It contains no .env, no uploads and no database — nothing secret."
}

cmd_cron_install() {
    require_root cron-install

    local file=/etc/cron.d/asset-register
    cat > "$file" <<CRON
# Asset Register — installed by manage.sh.
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin

# Nightly backup of the database and the uploads.
15 2 * * * root ${APP_DIR}/manage.sh backup --quiet

# Keep the stored loan status in step with the due dates. Every screen already
# derives this in SQL, so this only tidies the column itself.
5 * * * * root ${APP_DIR}/manage.sh refresh-overdue --quiet
CRON

    chmod 644 "$file"
    ok "Wrote $file"
    say "  Backups go to $BACKUP_DIR, keeping the last $BACKUP_KEEP sets."
    say "  Audit-trail pruning is deliberately not scheduled — decide the retention yourself:"
    say "    $0 prune-activity 730"
}

cmd_cron_remove() {
    require_root cron-remove
    rm -f /etc/cron.d/asset-register
    ok "Removed /etc/cron.d/asset-register"
}

cmd_restart() {
    require_root restart

    local svc; svc="$(web_service)"
    if [ -n "$svc" ] && have systemctl; then
        systemctl restart "$svc" && ok "$svc restarted"
    fi

    local fpm
    for fpm in php-fpm php8.3-fpm php8.2-fpm php8.1-fpm; do
        if have systemctl && systemctl list-unit-files --no-legend "$fpm.service" 2>/dev/null | grep -q .; then
            systemctl restart "$fpm" && ok "$fpm restarted"
            break
        fi
    done
}

# ---------------------------------------------------------------------------
# Dispatch
# ---------------------------------------------------------------------------
ARGS=()
for arg in "$@"; do
    case "$arg" in
        --quiet) QUIET=yes ;;
        --yes|-y) ASSUME_YES=yes ;;
        *) ARGS+=("$arg") ;;
    esac
done

set -- "${ARGS[@]:-}"
COMMAND="${1:-help}"
shift || true

# A copy of this script travels with the source, so it is easy to run it from a
# checkout (~/kitwell) rather than from the installation it manages
# (/var/www/asset-register). That directory has no .env, and on top of that the
# web user usually cannot even read a checkout under /root — which used to
# surface as a wall of "Failed opening required src/bootstrap.php" instead of
# the actual problem. Stop here, and point at the real install.
case "$COMMAND" in
    # These are the only commands meaningful from a source tree.
    help|--help|-h|""|package) : ;;
    *)
        if [ ! -f "$ENV_FILE" ]; then
            say ""
            printf '%sThis is not an installation.%s\n' "$C_BOLD" "$C_RESET" >&2
            say ""
            say "  $APP_DIR has no .env, so it is a copy of the source rather than"
            say "  a site this script can manage."
            say ""

            found=""
            for candidate in /var/www/asset-register /var/www/kitwell /var/www/html/asset-register /srv/asset-register /opt/asset-register; do
                if [ -f "$candidate/.env" ] && [ -x "$candidate/manage.sh" ]; then
                    found="$candidate"
                    break
                fi
            done

            if [ -n "$found" ]; then
                say "  The installation is at ${C_BOLD}${found}${C_RESET}. Run it from there:"
                say ""
                say "      sudo ${found}/manage.sh ${COMMAND}${*:+ $*}"
            else
                say "  No installation was found in the usual places. Either run the"
                say "  installer first:"
                say ""
                say "      sudo ${APP_DIR}/install.sh"
                say ""
                say "  or, if it is installed somewhere unusual, run the manage.sh that"
                say "  sits next to its .env."
            fi

            say ""
            exit 1
        fi

        if [ ! -r "$ENV_FILE" ]; then
            die "$ENV_FILE is not readable by $(id -un). Run this with sudo."
        fi
        ;;
esac

case "$COMMAND" in
    help|--help|-h|"") usage ;;

    status)          cmd_status ;;
    doctor)          cmd_doctor ;;
    health)          cmd_health ;;
    stats)           cmd_stats ;;
    audit)           cmd_audit ;;
    logs)            cmd_logs "$@" ;;

    users)           cmd_users ;;
    create-user)     cmd_create_user "${1:-admin}" ;;
    reset-password)  cmd_reset_password "${1:-}" ;;
    unlock)          cmd_unlock "${1:-}" ;;
    activate)        cmd_activate "${1:-}" ;;
    deactivate)      cmd_deactivate "${1:-}" ;;
    set-role)        cmd_set_role "${1:-}" "${2:-}" ;;

    settings)        cmd_settings ;;
    set-setting)     cmd_set_setting "${1:-}" "${2:-}" ;;
    config)          cmd_config "${1:-}" "${2:-}" ;;
    migrate)         cmd_migrate "$@" ;;
    seed)            cmd_seed ;;
    refresh-overdue) cmd_refresh_overdue ;;
    prune-activity)  cmd_prune_activity "${1:-365}" ;;

    backup)          cmd_backup "${1:-}" ;;
    restore)         cmd_restore "${1:-}" "${2:-}" ;;
    update)          cmd_update "${1:-}" ;;
    permissions)     cmd_permissions ;;
    package)         cmd_package "${1:-}" ;;
    cron-install)    cmd_cron_install ;;
    cron-remove)     cmd_cron_remove ;;
    restart)         cmd_restart ;;

    *) die "Unknown command '$COMMAND'. Run '$0 help' for the list." ;;
esac
