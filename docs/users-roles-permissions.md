# Users, roles and permissions

Who can do what, how a role is defined, and how somebody gets an account in the first place.

**On this page**

- [Roles and permissions](#roles-and-permissions)
- [Accounts: invitations and password recovery](#accounts-invitations-and-password-recovery)

---

## Roles and permissions


Four roles ship with the system:

| Role | Intended for |
|------|--------------|
| **Administrator** | Full access, including users, roles and settings |
| **Manager / Staff** | Day-to-day asset, maintenance, PAT and hire management |
| **Read-only** | Can see the register and reports, changes nothing |
| **Hirer** | Can look up items and see their own hires |

Permissions are **data, not code**: `roles` → `role_permissions` → `permissions`.
Adding a permission or a whole new role is an `INSERT`, never a schema change.

**Add role** on the Roles & permissions page creates one: give it a name, a
description and tick what it may do. Its machine name is derived from the name
and then fixed, because that is what the code refers to. A role you create can
be renamed later; the four that ship keep their names, though their permissions
are yours to change.

A role created here can never be a superuser. That flag protects the built-in
Administrator from being edited away, and nothing reachable from the web can set
it.

Every check is enforced on the server. Hiding a button somebody cannot use is a
courtesy on top of that, never the control itself — see [Security](security.md)
for how the checks are arranged and how they are verified.

Two permissions cover email:

| Permission | Held by | Allows |
|---|---|---|
| `email.manage` | Administrator | Settings → Email: the SMTP connection, reminder schedule, templates and the send log |
| `email.send` | Administrator, Manager / Staff | The "Email hire list" and "Email reminder" buttons |

Faults have their own:

| Permission | Held by | Allows |
|---|---|---|
| `faults.report` | Administrator, Manager / Staff | The "Mark as faulty" button and the report form |

It is separate from `assets.edit`, so a role can be allowed to report faults
without being allowed to rewrite purchase costs. It is still a change to the
register, since it moves the asset's status, so Read-only does not hold it.
Reading the fault history needs only `assets.view`.

Reports and the API have one each:

| Permission | Held by | Allows |
|---|---|---|
| `reports.manage` | Administrator, Manager / Staff | Creating, editing and deleting saved reports. See [Reports](reports.md) |
| `api.manage` | Administrator | Issuing and revoking API keys, and switching the interface on. See [API](api.md) |

Neither grants anything new to *see*. A saved report is refused at the moment it
is opened unless the reader also holds its data source's own permission, and an
API key inherits exactly the role of the user it was issued for — the same
`Auth::can()` runs either way. `api.manage` is administrator-only because
issuing a credential that acts as somebody is the same kind of act as creating
their account.

The calendar feed needs no permission of its own: every signed-in user can
create their own, and what it contains is decided by the permissions they
already hold.

---

## Accounts: invitations and password recovery


Both of these need working email. Without it the pages say so and offer the
manual alternative rather than a flow that cannot finish.

### Inviting a new user

With email configured, **Add user** asks for no password. The new user is
emailed a link that shows them their name, sign-in address and role, and lets
them choose their own password.

- The invitation is good for **one use** and expires after a window set in
  Settings → Email — currently **{{setting:invite_expiry_hours}}**.
- The Users list marks an account **Invited** until it is accepted, and **Invite
  expired** if it lapses — an account nobody has finished setting up otherwise
  looks exactly like a working one, which is how a new starter ends up locked out
  on their first morning with nobody able to say why.
- **Send it again** on the user's page issues a fresh link and stops the old one
  working.
- Setting a password directly on that page also revokes any outstanding
  invitation, so an account cannot end up with two different passwords depending
  on which route was used last.

Without email configured, the form asks for an initial password instead and the
new user is told it in person.

### Forgotten passwords

**Forgotten your password?** on the sign-in page leads to a form that emails a
reset link. The link is single-use and expires after a separate, shorter window,
currently **{{setting:password_reset_expiry_hours}}**. An invitation may
reasonably sit unopened for a day; a reset is asked for a moment before it
arrives, and the shorter it lives the smaller the window in which a forwarded
message is worth anything.

Behaviours worth knowing about:

- **The answer never says whether an address is registered.** "If that address
  has an account here, a link is on its way" is what you get either way,
  including when the send itself failed. An open form that answers "no such user"
  is a way to enumerate an organisation's staff list. The failure is in
  Settings → Email → Log, where an administrator will see it.
- **Requests are metered on the same counters as failed sign-ins**, so this
  cannot become an unmetered way to send mail to a chosen address.
- **A successful reset clears the account's lockout.** A forgotten password and a
  locked-out account arrive together often enough that leaving the lock in place
  would send the user straight back round.
- **Setting a password never signs anybody in.** Proving control of a mailbox is
  enough to *set* a password; the password is then what gets you in, through the
  ordinary sign-in path with its own throttle and its own audit entry.

With email switched off, the page explains that and points at an administrator
rather than showing a form.

### What is stored

Only a **SHA-256 of the token**. The link itself exists in exactly one place —
the email that was sent — so a stolen database backup is not a set of working
account-takeover links. A lost link cannot be looked up and re-sent; issue a
fresh one instead.

The two link expiry windows are on **Settings → Email**, under "Account links".

---

**See also:** [Documentation index](README.md) · [Two-factor authentication](two-factor-authentication.md) · [Teams](teams.md) · [Security](security.md)
