# Security

What the application does to protect itself, and how to check it.

**On this page**

- [What is protected](#what-is-protected)
- [How this is checked](#how-this-is-checked)
- [If you lock yourself out](#if-you-lock-yourself-out)

---

## What is protected

- **Passwords** — `password_hash()` with `PASSWORD_DEFAULT`, verified with
  `password_verify()`, transparently re-hashed on sign-in when PHP's default
  cost or algorithm changes. Minimum length 12.
- **Login throttling** — failed attempts are recorded per email *and* per IP
  (`login_attempts`); the account locks for `LOGIN_LOCKOUT_MINUTES` after
  `LOGIN_MAX_ATTEMPTS` failures, and an IP hammering many accounts is locked at
  three times that rate. Sign-in timing does not reveal whether an account exists.
- **Sessions** — `HttpOnly`, `SameSite=Lax`, `Secure` when HTTPS is in use;
  strict mode on, ID regenerated on sign-in, idle timeout from `SESSION_LIFETIME`,
  and the session is bound to the browser's user-agent fingerprint.
- **Two-factor authentication** — TOTP (RFC 6238) or a code by email, per user
  or required site-wide. The secret is encrypted at rest, backup codes are
  hashed with `password_hash()`, and trusted-device tokens are 32 random bytes
  of which only a SHA-256 is stored. **A correct password with a challenge
  outstanding creates no session at all**, so nothing in the application has to
  understand a half-signed-in user. Wrong codes count against the same lockout
  as wrong passwords.
- **CSRF** — every state-changing route carries the `csrf` middleware; forms
  include `csrf_field()`. A stale token gives a clear "session expired" page
  rather than a silent failure.
- **SQL** — prepared statements throughout. Table and column names come only
  from application code; every value is bound.
- **Output** — all dynamic template output goes through `e()`.
- **Headers** — CSP, `X-Content-Type-Options`, `X-Frame-Options`,
  `Referrer-Policy`, `Permissions-Policy`, plus HSTS when the request is HTTPS.
- **Uploads** — size ceilings and an allow-list of types in
  `config/config.php`. Files are checked by **content**, not by the filename or
  the browser's content-type, written under `storage/uploads/` with generated
  names outside the document root, and served back through PHP with
  `nosniff`. Path resolution rejects anything that escapes the uploads root.
- **Permissions** — enforced on the server at the route (`can:<permission>`)
  and in controllers (`Auth::authorize()`). Hiding a button is a courtesy, not
  a control.
- **Audit trail** — `activity_log` records who did what, when, from which IP,
  with a field-level before/after diff. Rows survive deletion of the thing they
  describe.
- **Fault notifications carry asset data**, so recipients are re-checked against
  `assets.view` at send time. Being named as an asset's responsible party is a
  routing decision, not a grant — somebody whose role no longer lets them see
  the register stops receiving the emails, immediate and digest alike. An asset
  with nobody responsible sends nothing rather than falling back to a default
  recipient.
- **CSV exports** — cells beginning `=`, `+`, `-` or `@` are prefixed with an
  apostrophe so a spreadsheet cannot execute asset data as a formula.

## How this is checked

The properties above are verified by tooling rather than by inspection, and
that tooling ships in **[`tests/`](../tests/README.md)** so it can be re-run after
any change:

```bash
php tests/security-audit.php     # routes, SQL, uploads, headers, forms
php tests/escape-audit.php       # template output escaping
```

Both are static — they read the source and are safe to run anywhere. Two
further checks need the site running; see `tests/README.md`.

| Check | What it proves |
|-------|----------------|
| Route audit | Every POST/PUT/DELETE route carries CSRF; every route is authenticated and permission-gated; no display-only page sits behind a write permission |
| SQL audit | No variable is interpolated into a query; concatenation is limited to controlled fragments; nothing bypasses the `Database` wrapper |
| Escaping audit | Parses all ~1,500 template output expressions with PHP's tokeniser and proves no variable reaches the page unescaped |
| Permission matrix | Drives ~260 route/role combinations as all four roles against a declared expectation |
| Report figures | Every report's row count matches the database, and the CSV matches the screen |

## If you lock yourself out

If email is configured, **Forgotten your password?** on the sign-in page is the
quickest way back in and needs nobody's help — see
[Accounts](users-roles-permissions.md#accounts-invitations-and-password-recovery). What follows is the
escape hatch for when email is not working, or when the account you are locked
out of is the only administrator.

Reset any account from the server:

```bash
sudo ./manage.sh reset-password you@example.com
```

That sets a new password, reactivates the account if it was disabled and
clears the recorded sign-in attempts so the lockout lifts immediately. To clear
every lockout on the site: `sudo ./manage.sh unlock`.

The original route still works and does the same thing, minus the lockout
clearing:

```bash
php bin/create-admin.php --email=you@example.com
```

Answer `y` when it offers to reset the password.

The application refuses to leave itself with no way in: the last active
administrator cannot be deactivated or demoted, and nobody can deactivate their
own account.

---

**See also:** [Documentation index](README.md) · [Two-factor authentication](two-factor-authentication.md) · [API](api.md) · [Users, roles and permissions](users-roles-permissions.md)
