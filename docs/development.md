# Development

Running it locally, the shape of the front end, and the verification tooling that ships with it.

**On this page**

- [Front end](#front-end)
- [Running it locally](#running-it-locally)
- [The documentation and the Help pages](#the-documentation-and-the-help-pages)
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

## The documentation and the Help pages

The files in `docs/` are the documentation twice over: read on disk or on
GitHub, and served inside the application at `/help`. There is no build step and
no second copy — `App\Controllers\HelpController` reads the same file.

- **`App\Services\Markdown`** renders the subset those files use. Every piece
  of source text is escaped before any markup is added, so only the tags the
  renderer writes itself reach the page.
- **The contents panel mirrors `docs/README.md`.** Each `## Heading` on the
  index opens a group in the sidebar and the `.md` links beneath it are its
  pages, in order. Moving a page between the user half and the Administration
  half is one edit to the index.
- **`App\Services\HelpSettings`** replaces `{{setting:key}}` with what the site
  actually has configured, so Help cannot show a default an administrator has
  changed. Only the keys on its allow-list are substituted — that is what stops
  a documentation file printing an arbitrary settings row — and a key that is
  not listed is left as written. Add a key there before using it in a page, and
  keep the token away from the start of a line: a resolved number followed by a
  full stop reads as an ordered-list marker.

`tests/docs-audit.php` holds all of this to account.

## Verification tooling

Everything in `tests/` is a plain PHP script — no framework, nothing to install.
`tests/README.md` explains each one and which are safe to run against a live
system.

| Script | Proves |
|---|---|
| `security-audit.php` | Every state-changing route carries CSRF and a permission check; no SQL is built by interpolation; uploads are validated; the security headers are set |
| `escape-audit.php` | No template prints a variable without `e()` |
| `docs-audit.php` | Every documentation link and anchor resolves, every page keeps the house shape, every `{{setting:…}}` token is resolvable, and no page in the user half needs a shell |
| `permission-matrix.php` | Each of the four roles is allowed and refused exactly what it should be, driven over real HTTP |
| `report-figures.php` | Every report's figures agree with the database, and its CSV agrees with its screen |
| `api-contract.php` | The API matches its own generated specification, including that no response carries an undeclared field |
| `fault-flow.php` | The whole fault feature end to end, with a local SMTP catcher |
| `media-library.php` | One file is stored once however many assets use it — counted in the database *and* on disk — plus templates, deduplication and the scan-to-new-asset route |
| `routines.php` | A routine built from every field type, run ad-hoc and from a schedule, with the design/run permissions separated, versioning holding old records still, category scoping enforced past the picker, a checklist run answered out of order by two accounts a step and a page at a time, the Routine scan target's three branches, and the generated PDF checked object by object |
| `loler.php` | A LOLER thorough examination end to end: the asset's own fields, the statutory interval each type implies, the `loler.inspect` permission refused to every role that ships, page-one corrections written back, every defect refusal the regulations require, and each of Schedule 1's eleven paragraphs found in the generated report |
| `totp-vectors.php` | TOTP against RFC 4226 Appendix D and RFC 6238 Appendix B |
| `qr-encode.php` | The QR encoder against the ISO/IEC 18004 worked example, then round-tripped through the project's own independent decoder |

The ones that write (`permission-matrix.php`, `fault-flow.php`,
`api-contract.php`, `routines.php` and `loler.php`) say so at the top and should be pointed
at a scratch database. Each takes the address of the site under test as its
first argument, defaulting to `http://127.0.0.1:8321`.

`src/Core/Pdf.php` is the other piece worth knowing about: a small PDF writer
using the standard fourteen fonts, so a generated document carries no embedded
font data and needs nothing installed. `App\Services\RoutineDocument` is its
only caller today.

---

**See also:** [Documentation index](README.md) · [Installation](installation.md) · [Administration](administration.md) · [API](api.md)
