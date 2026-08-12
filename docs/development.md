# Development

Running it locally, the shape of the front end, and the verification tooling that ships with it.

**On this page**

- [Front end](#front-end)
- [Running it locally](#running-it-locally)
- [Verification tooling](#verification-tooling)

---

## Front end

Mobile-first CSS with light and dark themes driven by `data-theme` on `<html>`.
The choice is stored in `localStorage` *and* a cookie, and applied by an inline
script before first paint so there is no flash of the wrong theme; with no stored
choice it follows the device setting.

Sized for a workshop: 17px base text, a 44px minimum tap target everywhere, 16px
form inputs (below that iOS zooms on focus), high-contrast palettes in both
themes, and status conveyed by text and shape as well as colour. All text meets
the WCAG AA 4.5:1 contrast ratio against its own background in both themes.

No JavaScript framework and no build step. The only scripts are
`public/js/app.js` (navigation, theme, lightbox, form helpers) and
`public/js/scanner.js` (barcode reading). Every page works without JavaScript
except the camera scanner, which falls back to typing or a USB scanner.

---

## Running it locally

```bash
php -S localhost:8000 -t public
```

Set `APP_ENV=local`, `APP_DEBUG=true` and `FORCE_HTTPS=false` in `.env` first,
otherwise the app will redirect you to `https://localhost:8000`.

To rebuild a development database from scratch:

```bash
mariadb -u root -p -e "DROP DATABASE IF EXISTS kitwell; CREATE DATABASE kitwell CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
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

---

## Verification tooling

Everything in `tests/` is a plain PHP script — no framework, nothing to install.
`tests/README.md` explains each one and which are safe to run against a live
system.

| Script | Proves |
|---|---|
| `security-audit.php` | Every state-changing route carries CSRF and a permission check; no SQL is built by interpolation; uploads are validated; the security headers are set |
| `escape-audit.php` | No template prints a variable without `e()` |
| `permission-matrix.php` | Each of the four roles is allowed and refused exactly what it should be, driven over real HTTP |
| `report-figures.php` | Every report's figures agree with the database, and its CSV agrees with its screen |
| `api-contract.php` | The API matches its own generated specification, including that no response carries an undeclared field |
| `fault-flow.php` | The whole fault feature end to end, with a local SMTP catcher |
| `totp-vectors.php` | TOTP against RFC 4226 Appendix D and RFC 6238 Appendix B |
| `qr-encode.php` | The QR encoder against the ISO/IEC 18004 worked example, then round-tripped through the project's own independent decoder |

The two that write (`permission-matrix.php` and `fault-flow.php`, plus
`api-contract.php`) say so at the top and should be pointed at a scratch
database.

---

**See also:** [Documentation index](README.md) · [Getting started](getting-started.md) · [API](api.md)
