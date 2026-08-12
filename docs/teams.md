# Teams

Groups that work can be assigned to, so a job does not go quiet because one person is away.

**On this page**

- [Teams](#teams)

---

## Teams


A **team** is a group work can be assigned to instead of one person. Manage them
under **Settings → Teams** (`teams.manage`, Administrator only).

Assigning a maintenance schedule to a team rather than an individual changes
three things:

- **Everyone in it is reminded.** The maintenance reminder that would have gone
  to one named person goes to every member — so a job does not sit untouched
  because the one name on it is on holiday.
- **Anyone in it can action it.** Recording a completion has always needed
  `maintenance.complete` and never a match against the assignee, so this needs
  nothing loosened; it is worth saying only because it is the behaviour teams
  depend on.
- **The screens say so.** Wherever an assignment is shown — the maintenance
  list, a schedule's own page, the asset's maintenance card — a team assignment
  carries a **Team** badge. Where there is only text (the Maintenance due
  report, its CSV, the calendar feed, the reminder emails) it reads
  "Bench fitters (team)", because a name alone does not tell you whether it is a
  person or a group.

A schedule is assigned to a person **or** a team **or** nobody. There is one
control on the form with the teams and the people in separate groups, and the
application writes exactly one of the two columns — the two cannot contradict
each other.

**Membership grants nothing.** It says who is *expected* to do the work. A
member still needs `maintenance.view` to be reminded and `maintenance.complete`
to record the work; the reminder run re-checks both at send time, exactly as it
does for the notify list.

Teams are **archived, not deleted**. An archived team keeps the jobs already
assigned to it, its members go on being reminded about them, and it stops being
offered for anything new — because "who was this assigned to last year?" should
still have an answer.

PAT has no assignment to extend. Its due dates come from each asset's own retest
interval rather than from a scheduled job, and `pat_records.tester_user_id`
records who *did* a test rather than who owes one.

---

---

**See also:** [Documentation index](README.md) · [Maintenance](maintenance.md) · [Faults](faults.md) · [Users, roles and permissions](users-roles-permissions.md)
