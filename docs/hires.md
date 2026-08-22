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

**Overdue is worked out from the due date every time it is shown**, so a hire is
never stale and nothing has to run on a schedule to keep it right.

**Double-booking is not possible.** An asset that is already out, retired, not
hireable, or in maintenance is refused, with the reason given, and two people
scanning the same item at the same moment cannot both succeed. On checkout the
asset moves to *On Hire*; on return it goes back to *In Stock*, or straight into
maintenance if it came back needing work.

**While an item is out, only booking it in changes its status.** Its edit page
shows *On Hire*, who has it and when it is due, and a **Book in** button in
place of the usual status dropdown. Setting the status by hand used to be
possible and it left the register saying an item was available while the hire
was still open — so it says *In Stock* on the screen and sits in the back of
somebody's van. The same applies the other way: *On Hire* cannot be chosen from
the dropdown, because it is a fact about a hire record and only Check out can
create one.

The default hire period is {{setting:hire_default_days}} days, and an item
counts as due back soon {{setting:hire_due_soon_days}} days before its due date.
Both are set under **Settings → Application settings**. Hire references are
generated as {{setting:hire_reference_prefix}} followed by the year and a
number.

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
