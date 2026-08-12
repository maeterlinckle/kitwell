# Teams

Groups that work can be assigned to, so a job does not go quiet because one person is away.

**On this page**

- [What a team does](#what-a-team-does)
- [Membership](#membership)
- [Archiving a team](#archiving-a-team)

---

## What a team does

A **team** is a group work can be assigned to instead of one person. Manage them
under **Settings → Teams** (`teams.manage`, Administrator only).

Assigning a maintenance schedule to a team rather than an individual changes
three things:

- **Everyone in it is reminded.** The maintenance reminder that would have gone
  to one named person goes to every member.
- **Anyone in it can action it.** Recording a completion needs
  `maintenance.complete` and is never matched against the assignee.
- **The screens say so.** Wherever an assignment is shown — the maintenance
  list, a schedule's own page, the asset's maintenance card — a team assignment
  carries a **Team** badge. Where there is only text (the Maintenance due
  report, its CSV, the calendar feed, the reminder emails) it reads
  "Bench fitters (team)", so a name alone never leaves it ambiguous.

A schedule is assigned to a person **or** a team **or** nobody. One control on
the form lists teams and people in separate groups, and exactly one of the two
columns is written.

An asset's **responsible party** works the same way, and naming a team there
tells every member when the asset is reported faulty. See
[Faults](faults.md).

## Membership

**Membership grants nothing.** It says who is *expected* to do the work. A
member still needs `maintenance.view` to be reminded and `maintenance.complete`
to record the work; the reminder run re-checks both at send time, exactly as it
does for the notify list.

## Archiving a team

Teams are **archived, not deleted**. An archived team keeps the jobs already
assigned to it, its members go on being reminded about them, and it stops being
offered for anything new — so "who was this assigned to last year?" still has an
answer.

PAT has no assignment to extend. Its due dates come from each asset's own retest
interval rather than from a scheduled job, and `pat_records.tester_user_id`
records who *did* a test rather than who owes one.

---

**See also:** [Documentation index](README.md) · [Maintenance](maintenance.md) · [Faults](faults.md) · [Users, roles and permissions](users-roles-permissions.md)
