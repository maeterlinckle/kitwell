# Verification tooling

Nine checks that can be re-run after any change. Eight are plain PHP scripts
with no test-framework dependency — run them with the same PHP binary the site
uses. The ninth is a web page, because the thing it tests is JavaScript.

| Script | Needs | Writes? |
|--------|-------|---------|
| `security-audit.php` | Nothing — reads the source | No |
| `escape-audit.php` | Nothing — reads the templates | No |
| `docs-audit.php` | Nothing — reads `docs/` | No |
| `totp-vectors.php` | Nothing | No |
| `qr-encode.php` | Nothing | No |
| `barcode-decode.html` | A browser | No |
| `report-figures.php` | A running site + seeded database | No |
| `permission-matrix.php` | A running site + seeded database | **Yes** |
| `api-contract.php` | A running site + seeded database | **Yes** |
| `fault-flow.php` | A running site + seeded database + an SMTP catcher | **Yes** |

## Static checks (safe anywhere, including production)

```bash
php tests/security-audit.php
php tests/escape-audit.php
php tests/docs-audit.php
```

`security-audit.php` reads `routes/web.php`, `src/` and `templates/` and
asserts the invariants the application depends on:

- every POST/PUT/DELETE route verifies CSRF;
- every route is authenticated, apart from `/login` and `/health`;
- every route carries a permission check, apart from a documented list of
  self-scoping ones (`/`, `/profile`, `/my-hires`);
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

`docs-audit.php` reads `docs/` and proves that every link and `#anchor`
resolves using the same rule the in-app renderer uses, that every page keeps the
house shape, that every `{{setting:…}}` token names a key
`App\Services\HelpSettings` will substitute — and that no page in the user half
of the documentation contains a shell command, a SQL statement or a server path,
which is what keeps Help readable by somebody with no access to the machine.

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

It expects the demo accounts from `bin/seed.php`, plus a hirer login; adjust
the `$accounts` array at the top if your test data differs.

```bash
php tests/fault-flow.php
```

`fault-flow.php` drives the whole fault feature over real HTTP: setting a
responsible party from the asset edit form as a person and as a team, the report
form refusing a submission with no photo or a future date *before* writing
anything, a complete report creating its row and its photo and moving the
asset's status, the immediate email reaching the named person or every member of
the named team, an unassigned asset sending nothing without erroring, a second
report being kept as history, the dashboard figure and the report agreeing with
the database, and the digest arriving as one consolidated message per person
rather than one per asset.

> **This one writes too**, for the same reason — and it sends. The email
> assertions need a mail catcher on `127.0.0.1:2525` with `mail_host` pointed at
> it and `mail_encryption` set to `none`; without one they are skipped and say
> so rather than passing quietly. It pins the settings it depends on and puts
> them back at the end.

## The barcode decoder

```
tests/barcode-decode.html
```

Open it in a browser — double-click it, or serve it alongside `public/` and
visit it. No server, build step or network access is needed. It goes green or
it names what failed.

It exercises `public/js/barcode.js`, the reader used wherever the browser has
no `BarcodeDetector` of its own (Safari, and so every iPhone). Because that is
the only implementation in the project, the fixtures come from an encoder
written inside the test page from the specification, and are rendered to a real
canvas and read back through the same entry point the camera uses.

An encoder and decoder written by the same hand can agree with each other and
both be wrong, so the page also checks against things outside itself:

- the **module geometry** — the free modules left once the function patterns
  are placed must equal what the error-correction block table claims, for all
  160 version/level pairs;
- the **ISO/IEC 18004 Annex I worked example**, whose data and error-correction
  codewords are published;
- the **defining properties** of each symbology (every Code 39 character is
  three wide elements of nine; every Code 128 symbol is eleven modules);
- frames that must decode to **nothing** — random noise, even stripes, a blank
  frame, and a QR damaged past its correction capacity.

What it cannot cover is a QR produced by somebody else's encoder. That was
checked once by hand against a third-party generator (versions 1-6, all four
error-correction levels, UTF-8 and URL payloads) and is not part of the suite,
because a test that needs the internet is a test that fails on a Monday for
reasons nobody can see.

## Adding to these

The audits are deliberately strict: if a check fails, either the code has a
problem or the check needs to learn about a new, genuinely safe pattern. Fix
the code first, and only widen the audit when you can say precisely why the
pattern is safe — the comments in each script show the form that takes.
