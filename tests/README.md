# Verification tooling

Four checks that can be re-run after any change. They are plain PHP scripts
with no test-framework dependency — run them with the same PHP binary the site
uses.

| Script | Needs | Writes? |
|--------|-------|---------|
| `security-audit.php` | Nothing — reads the source | No |
| `escape-audit.php` | Nothing — reads the templates | No |
| `report-figures.php` | A running site + seeded database | No |
| `permission-matrix.php` | A running site + seeded database | **Yes** |

## Static checks (safe anywhere, including production)

```bash
php tests/security-audit.php
php tests/escape-audit.php
```

`security-audit.php` reads `routes/web.php`, `src/` and `templates/` and
asserts the invariants the application depends on:

- every POST/PUT/DELETE route verifies CSRF;
- every route is authenticated, apart from `/login` and `/health`;
- every route carries a permission check, apart from a documented list of
  self-scoping ones (`/`, `/profile`, `/my-loans`);
- no page that only displays data is gated behind a write permission;
- no variable is interpolated into a SQL string, and concatenation is limited
  to controlled fragments (placeholder lists, integer limits, whitelisted
  ORDER BY);
- nothing bypasses the `Database` wrapper;
- every upload entry point validates, and every streamed file resolves through
  the path-traversal guard;
- session cookies, security headers and login throttling are all in place;
- every POST form includes a CSRF token.

It also runs `escape-audit.php`, which parses every `<?= … ?>` in every
template with PHP's own tokeniser and proves no variable reaches the page
unescaped. It understands that a variable used only in a ternary *condition*,
or passed through `e()` or a formatting helper, or cast to a number, never
reaches the output — so a report from it is a real finding, not noise.

## Live checks (need the site running)

```bash
php -S 127.0.0.1:8321 -t public &      # or point at your real URL
php tests/report-figures.php http://127.0.0.1:8321
```

`report-figures.php` runs each report over HTTP, counts the rendered rows and
compares them with the same figure taken straight from the database, plus a
CSV-vs-screen row comparison. It catches a report drifting away from the data
it claims to summarise.

```bash
php tests/permission-matrix.php http://127.0.0.1:8321
```

`permission-matrix.php` signs in as all four roles and drives ~260 route/role
combinations, comparing each against a declared expectation. The table it
prints doubles as the written specification of who can see what.

> **This one writes.** It posts to state-changing routes to prove the server
> refuses them for the wrong roles — which necessarily means the permitted
> roles carry those actions out. Run it against a scratch or demo database
> only, never production. Rebuild afterwards with:
>
> ```bash
> php bin/migrate.php && php bin/seed.php
> ```

It expects the demo accounts from `bin/seed.php`, plus a borrower login; adjust
the `$accounts` array at the top if your test data differs.

## Adding to these

The audits are deliberately strict: if a check fails, either the code has a
problem or the check needs to learn about a new, genuinely safe pattern. Fix
the code first, and only widen the audit when you can say precisely why the
pattern is safe — the comments in each script show the form that takes.
