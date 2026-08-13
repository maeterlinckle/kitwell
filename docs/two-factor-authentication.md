# Two-factor authentication

An authenticator app or a code by email, per user or required for everyone, with trusted devices that expire.

**On this page**

- [Turning it on for yourself](#turning-it-on-for-yourself)
- [Requiring it for everyone](#requiring-it-for-everyone)
- [Trusted devices](#trusted-devices)
- [If somebody loses their phone](#if-somebody-loses-their-phone)
- [What is stored](#what-is-stored)

---

A second check at sign-in, so a stolen password is not enough on its own.

## Turning it on for yourself

**My account → Security**. Two ways in:

- **An authenticator app** (recommended) — Google Authenticator, Authy,
  1Password, Bitwarden, or whatever your phone already has. Scan the QR code,
  type the code it shows to prove it worked, and you are done. Nothing is saved
  until that code matches, so an abandoned setup leaves no trace.
- **A code by email** — one click, no app, but it needs SMTP configured and a
  code by email is only as safe as the mailbox it lands in.

Either way you get **ten backup codes**, shown once. Each works once, in place
of a code from your app. Print them, or put them somewhere that is not your
phone — because the day you need one is the day you cannot get in to make more.

## Requiring it for everyone

**Settings → Application settings → Two-factor authentication**. When it is on,
anybody without a second factor is walked through setting one up at their next
sign-in.

The control is **disabled until email is configured**, and says so. With no SMTP
and no authenticator app enrolled, a user would have no way to receive a code
and no way to sign in — including the administrator who switched it on.

## Trusted devices

After a successful check you can tick **"Don't ask again on this computer"**.
That lasts **{{setting:trusted_device_days}}**, and stops sooner if any of
these happen:

- the device is not used for **{{setting:trusted_device_idle_days}}**;
- the browser changes — an update to the browser counts;
- you sign in from a noticeably different network;
- you change your password, or an administrator deactivates the account;
- you forget it yourself, from **My account → Security**.

Never tick it on a shared or public machine.

## If somebody loses their phone

An administrator opens their user page and uses **Remove two-factor
authentication**. That clears the secret, the backup codes and the trusted
devices; the person can then sign in with their password and set it up again.

There is no way to *read* somebody's second factor — the secret only exists on
their device — so this is a removal, not a reset. It is also the step that turns
a stolen password into an account, so check who you are talking to first.

## What is stored

The TOTP secret is **encrypted at rest** with `APP_KEY`. Backup codes are stored
with `password_hash()`, never in the clear. A trusted device is 32 random bytes
in a cookie, of which the database holds only a SHA-256. Wrong codes are counted
against the same lockout as wrong passwords, so a six-digit code cannot be
guessed at leisure.

---

**See also:** [Documentation index](README.md) · [Users, roles and permissions](users-roles-permissions.md) · [Security](security.md)
