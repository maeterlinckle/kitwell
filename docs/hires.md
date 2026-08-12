# Hires and hirers

Checking equipment out and booking it back in, the people and companies it goes to, and the self-service view a hirer gets.

**On this page**

- [Hires](#hires)
- [The Hirer role](#the-hirer-role)

---

## Hires

Check an asset out to a person or company, set a due-back date, and book it
back in. Photos can be taken at both ends, so the condition going out and
coming back is evidenced rather than argued about.

**Overdue is derived from the due date in SQL**, so it is always correct with
nothing running on a schedule — no cron job to forget. The stored `status`
column is kept in step by `Hire::refreshOverdue()` (two cheap indexed updates,
run when the hires list or dashboard loads), purely so that anything reporting
straight off the database sees the same thing.

**Double-booking is not possible.** An asset that is already out, retired, not
hireable, or in maintenance is refused, with the reason given. The check runs
twice: once for the form, and again inside the checkout transaction with the
asset row locked (`SELECT … FOR UPDATE`), so two people scanning the same item
at the same moment cannot both succeed. On checkout the asset moves to *On
Hire*; on return it goes back to *In Stock* or straight into maintenance if it
came back needing work.

## The Hirer role

Setting one up is three ordinary steps: create the user with the **Hirer**
role, create (or open) the hirer record, and pick the login under
**Self-service login**. One login links to one hirer record; that link is
what scopes everything they see.

A hirer signing in lands on **My hires** — a card per item with its photo,
tag, name, checkout date, due date, and any overdue clearly flagged. Opening
one shows the description, condition, manuals (view or download), the
manufacturer link and the **latest** PAT result if the item is tested.

Everything else is closed to them, and the restriction is *structural* rather
than a list of things remembered to be hidden:

- The Hirer role holds exactly one permission, `hires.view_own`. It no
  longer has `assets.view`, which would have opened the whole register.
- The portal is a separate controller that never calls the asset controllers.
  Assets are reduced to an allow-list of visible fields, so a column added to
  `assets` in future cannot leak into it by accident.
- Every query is scoped through the hirer record linked to the signed-in
  user. Another hirer's hire returns **404, not 403** — no confirmation that
  it exists.

They cannot see other people's hires, other assets, maintenance, full PAT
history, financial fields, internal notes, supplier, serial numbers or the
storage location, and there is no add/edit/delete anywhere. Their navigation
contains only *My hires* and their own profile.

---

**See also:** [Documentation index](README.md) · [Assets](assets.md) · [Barcode scanning and labels](barcode-scanning.md) · [Users, roles and permissions](users-roles-permissions.md)
