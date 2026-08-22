# Users, roles and permissions

Who can do what, how a role is defined, and how somebody gets an account in the first place.

**On this page**

- [Roles and permissions](#roles-and-permissions)
- [Permissions for one person](#permissions-for-one-person)
- [Password policy](#password-policy)
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

A role is the baseline. An individual account can differ from it in either
direction — see [Permissions for one person](#permissions-for-one-person).

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

Lifting equipment has its own:

| Permission | Held by | Allows |
|---|---|---|
| `loler.inspect` | Nobody, until granted | Carrying out a LOLER thorough examination and submitting the report |

It is the one permission no role holds on a fresh install. LOLER regulation 9
requires a competent person, and who that is at a site is not something an
installation can assume — so it has to be granted deliberately rather than
inherited from a role somebody already had. See
[LOLER thorough examination](loler.md).

Maintenance routines have their own:

| Permission | Held by | Allows |
|---|---|---|
| `routines.manage` | Administrator | Maintenance → Routines: creating, editing, publishing and archiving the procedures technicians fill in |

Carrying a routine out needs only `maintenance.complete`, which Manager / Staff
already hold. The split is the point: most staff should be able to work through
a procedure without being able to redesign what it asks. Add `routines.manage`
to any role, or make a role of its own, where that is not the right split for a
site. See [Maintenance routines](routines.md).

Templates have their own:

| Permission | Held by | Allows |
|---|---|---|
| `templates.manage` | Administrator, Manager / Staff | Settings → Asset templates: creating and editing the starting points the Add asset form offers |

It sits with `categories.manage` rather than with the administrative
permissions: a template is reference data an operation maintains for itself.
Browsing the media library needs only `assets.view`, since what it holds is
descriptions of the things in the register.

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

## Permissions for one person

A role is the **baseline**, not the whole answer. Any account can be given a
permission on top of its role, or have one withheld despite it — so somebody who
needs a little more or a little less than their role does not need a role
inventing for them.

On the user's page, under **Permissions for this account**, every permission has
three choices:

| Choice | Meaning |
|---|---|
| **Role** | Follow whatever the role says, now and in future |
| **Allow** | This person has it, whether or not the role gives it |
| **Deny** | This person does not have it, even though the role gives it |

Deny beats allow, and both beat the role. An account left on **Role** keeps
following the role, so changing the role later still reaches them — which is
what you want unless there is a reason otherwise.

**Use it for the exception, not the pattern.** If three people need the same
extra permission, that is a role. The overrides are for the one technician who
is also the competent person for lifting examinations, or the one account that
should not be able to delete anything.

Every change is written to the activity log (**Settings → Activity log**) with
what each permission was before and what it became, because "permissions
changed" tells nobody anything six months later.

Setting this needs *Manage roles* — deciding what somebody may do is the same
kind of act as editing a role, not the same kind as fixing their phone number.
The section is not shown to anybody who could not save it.

**A superuser is not affected.** The built-in Administrator role holds every
permission, and withholding one is deliberately impossible: denying an
administrator `users.manage` and `roles.manage` would lock the installation out
of its own administration, and nothing reachable from a browser could undo it.
The page says so instead of offering controls that do nothing. Move the account
to another role first if it should not have everything.
---

## Password policy

Two settings, at two levels. The application sets a policy for everybody, and
any individual account can be given its own.

### For everybody

**Settings → Passwords**, administrator only.

| Setting | Default | What it does |
|---|---|---|
| Require a new password after (days) | **0 — never** | Somebody signing in with a password older than this sets a new one before they can go any further |
| Minimum length | **12** | Characters. Below 8 is not offered |
| Different character types required | **3 of 4** | Out of upper case, lower case, numbers and symbols |

Three of four types is the usual answer: it rules out a plain word without
forcing the unmemorable muddle that ends up written on a note under the
keyboard. Length is the part that actually costs an attacker time, which is why
the floor is 12 rather than 8.

**Changing the policy does not invalidate anybody's current password.** It
applies the next time one is set — at an invitation, a reset, or a change. The
rule is enforced on the server at every one of those points, and the sentence
under each password box is generated from the policy, so what somebody is told
is always what will actually be checked.

### For one account

The same two settings appear on each user's page, under **Password policy for
this account**, and what is set there wins.

| Choice | Meaning |
|---|---|
| **Site policy** | Follow whatever the application says, now and in future |
| **Never expires** | This account is exempt, and stays exempt if the site-wide figure changes later |
| **Expires after…** | A number of days for this account alone |

"Site policy" and "never expires" are not the same answer. An account left on
site policy follows the site the next time somebody edits it; an account set to
never expire has been *decided about*, and a later tightening of the site-wide
rule leaves it alone. That distinction is the reason the per-account setting
exists: a shared rig or service account that nobody can be asked to log in and
rotate should not quietly start expiring the day somebody sets a 90-day policy.

Minimum complexity works the same way, and can go either direction — stricter
for an administrator's account, more lenient for a device account that has to
match something else's rules.

### When a password expires

The person **signs in normally** and is then sent to a page asking them to set
a new one. Nothing else in the application opens until they have. They are not
locked out, no code is needed, and there is nothing for an administrator to
undo.

They have to give the current password again, and the new one cannot be the one
that just expired.
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

**See also:** [Documentation index](README.md) · [Two-factor authentication](two-factor-authentication.md) · [Teams](teams.md) · [Maintenance routines](routines.md) · [Security](security.md)
