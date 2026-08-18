# Maintenance routines

Procedures a technician steps through and fills in — checks, readings, photographs — rather than one free-text box, with every completed one kept exactly as it was asked.

**On this page**

- [What a routine is](#what-a-routine-is)
- [Building one](#building-one)
- [Which assets a routine is for](#which-assets-a-routine-is-for)
- [Publishing, and who may](#publishing-and-who-may)
- [Carrying one out](#carrying-one-out)
- [Checklist routines: any step, any order, anybody](#checklist-routines-any-step-any-order-anybody)
- [Attaching one to a scheduled job](#attaching-one-to-a-scheduled-job)
- [Scanning to a routine](#scanning-to-a-routine)
- [Versions](#versions)
- [Reading a completed routine](#reading-a-completed-routine)

---

## What a routine is

A **routine** is a form built once and filled in every time a particular job is
done: a forklift pre-use check, a quarterly press service, a hoist inspection.
It is a list of **pages**, each holding an ordered list of **steps**, and each
step asks for one answer of one kind.

Anything filled in is recorded against the asset in the ordinary maintenance
history — a routine is a better way of recording work, not a second place to
look for it. See [Maintenance](maintenance.md) for how that history is arranged.

**Maintenance → Routines** lists what a site has. Anyone who can see maintenance
can read the list and preview any routine; changing one is a separate
permission, covered below.

## Building one

**New routine** asks for a name and a description, then drops you in the editor.
There you can:

- **add pages**, and move them up and down;
- **add steps to a page**, move them, and remove them;
- give each step a question, optional help text, an answer type, and whether it
  is required.

The answer types are:

| Type | What the technician gets |
|---|---|
| Short text | One line |
| Notes | A multi-line box |
| Number | A numeric box, with an optional unit shown beside it |
| Date | A date picker |
| Yes / no | Two large buttons |
| Choose one | The options you list, as buttons, or a dropdown past six of them |
| Choose any | The options you list, as tick boxes |
| Photo | Camera on a phone, or a file from anywhere |
| Document (PDF) | A file picker |

Choices are typed one per line. Blank lines and duplicates are dropped, and a
choice may not contain a line break.

Each page is saved as a whole: everything typed into it is written before the
button you pressed is acted on, so adding a step never loses an edit made
further up the page. **Preview** shows the routine exactly as somebody carrying
it out will meet it, with every control switched off.

Two settings sit beside the pages: **Applies to**, covered next, and **Allow
steps to be completed out of order**, covered under
[Checklist routines](#checklist-routines-any-step-any-order-anybody).

Layout is not configurable beyond the order of pages and steps. What a routine
says is yours; how it is drawn is the application's, so every routine on the
shop floor looks and behaves the same way.

## Which assets a routine is for

**Applies to** on the routine names one category. The routine is then offered
only for assets in that category **and every category nested beneath it** —
categories are a tree, and a routine written for *Access equipment* is meant
for the ladders and the podium steps alike without being listed against each.

Left as **Any asset**, the routine is unrestricted. That is the default: only a
routine that has deliberately been given a category is limited by one.

The restriction is a rule, not a tidy-up of the list. A routine that does not
apply is absent from the picker, and reaching its address directly gives a
**not found** naming the category it is for.

Moving an asset into a different category changes which routines it is offered,
immediately. Records already made against it are unaffected — they say what was
asked and answered, whatever the asset is filed under now.

## Publishing, and who may

A routine cannot be run until it is **published**. Until then it is a draft:
invisible to anyone starting work, and not offered when attaching a routine to a
scheduled job.

Two rights are deliberately separate:

| Permission | Held by | Allows |
|---|---|---|
| `maintenance.complete` | Administrator, Manager / Staff | Carrying out a routine against an asset |
| `routines.manage` | Administrator | Creating, editing, publishing and archiving routine definitions |

Most staff should be able to work through a procedure without being able to
redesign what it asks — that is the whole reason the second permission exists.
It is an ordinary permission like any other, so a site that wants a senior
technician to maintain the routines can add it to a role, or make a role of its
own, from **Settings → Roles**. See
[Users, roles and permissions](users-roles-permissions.md).

**Archiving** a routine takes it off the list a technician can start from.
Nothing already recorded against it changes, and it can be restored. A routine
is never deleted, because the records that followed it have to keep working.

## Carrying one out

Three ways in, and they end in the same record:

1. **From an asset** — *Run a routine* on the asset's Maintenance card, then
   pick from the routines that apply to it.
2. **From the maintenance log page** — **Run routine instead** at the top of
   *Record work*, which appears exactly when at least one routine applies to
   that asset. It is the way out of the free-text form for somebody who
   started there and would rather follow a procedure.
3. **From a scheduled job** — see [Attaching one to a scheduled
   job](#attaching-one-to-a-scheduled-job).

Either way it is a guided form: one page at a time, a progress rail across the
top, and a required step that has not been answered stops you moving on. The
last page is not part of the routine — it is the maintenance record the
completion produces: the date it was done, who did it, the result, the condition
afterwards and anything to add.

Photographs taken here belong to **this record alone**. They are not added to
the shared [media library](media-library.md), for the same reason a condition
photo is not: they are a claim about one item on one day.

With JavaScript switched off every page is simply visible at once and the form
still submits. The required steps are checked by the server either way — the
one-page-at-a-time flow is a convenience, never the control.

## Checklist routines: any step, any order, anybody

A routine built with **Allow steps to be completed out of order** behaves as a
checklist rather than a one-way form. It suits work that passes between
stations: a machine is built at one bench, tested at another and finished at a
third, and no one person is at the item for the whole job.

Starting one **opens a run** rather than filling a form in. The run has a page
of its own — its **contents** — listing every page and step with:

- whether it has been answered;
- what the answer says;
- who gave it, and when.

Any unanswered step can be opened straight from there, whatever page it is on
and whatever order the pages are in. Answering a step records **your** name
against that step. Somebody else answering a different step records theirs.
Changing an answer moves the name to whoever changed it, because the record has
to say who stands behind what it now says.

An asset carries at most one open run at a time. Anybody who reaches the routine
for an asset that already has one is taken to the run in progress rather than
starting a second.

**The run stays open until it is signed off.** Signing off is its own step, from
the contents page: it asks for the date, the result and anything to add, writes
the maintenance entry against the asset, and closes the run. That is recorded
separately from the individual answers, so a routine carried out by five people
at five stations ends with a clear record of who did which part **and** who
closed it out.

Required steps are enforced at sign-off, not on the way through. A run with a
required step still blank cannot be signed off — the page names the outstanding
steps and links to each — but the steps before and after it can be answered
freely in the meantime.

**Discard this run** on the contents page abandons it: every answer and
photograph on it goes, and nothing is recorded against the asset.

A routine without the option is unchanged: one form, filled in from top to
bottom in a single sitting, recorded when it is submitted.

## Attaching one to a scheduled job

A maintenance schedule can name a routine, on the schedule form under
**Routine to fill in**. It sits beside the free-text instructions rather than
instead of them: a job can have both a procedure to follow and a line of context
about this particular machine.

Once a schedule names a routine, **Complete** on that job opens the routine
instead of the free-text form. Completing it satisfies the job exactly as
before — the completion is recorded, and a recurring schedule rolls forward to
its next due date from the day the work was actually done.

A job assigned to a team is picked up by whoever gets to it, as it always was.
Assignment decides who is *reminded*, never who *may* complete. See
[Teams](teams.md).

Only a published routine can be attached, since a schedule pointing at a draft
would send whoever picked the job up to a form that does not exist yet.

## Scanning to a routine

**Routine** is one of the modes on the Scan page. Scanning a tag with it
selected goes straight to whichever of these applies, in this order:

| The asset has | You land on |
|---|---|
| A run open on it | That run's contents, ready for your part of it |
| No open run, and a routine completed in the last 7 days | That record, with a note saying when it was done |
| Neither | Its maintenance log page, where **Run routine instead** offers to start one |

The middle case is the one worth knowing about: it exists so that somebody
scanning an item does not start the work again on something that was finished
this week. Seven days is fixed and not a setting.

The mode needs the same permission as recording any other maintenance.

## Versions

Every completed routine names the version it followed, and that version can no
longer be changed. Open a record from two years ago and it shows the questions
that were asked then, not the ones asked now.

That works out in practice like this:

- A routine nobody has used yet can be edited freely; changes take effect
  straight away.
- The moment it has been carried out **once**, the editor stops offering to
  change it and offers to **start the next version** instead.
- Starting version *n+1* copies everything as it stands into a draft. What is
  live carries on being used until the draft is published.
- Publishing makes the draft the version every new run follows. Records already
  recorded keep theirs for good.
- A draft can be thrown away without touching what is live.

The version number is shown wherever a routine or a completion of it appears:
in the routines list, on the routine's own page, on the wizard while the work is
being done, on the completed record, in the asset's maintenance history, and
twice on the PDF.

## Reading a completed routine

A completed routine has its own page, reachable from the asset's maintenance
history, from the scheduled job, and from the routine itself. It is laid out as
it was filled in: grouped by page, each step showing its original question
alongside the recorded answer, photographs inline, documents offered as a
download. A step that was left blank says **Not answered** rather than leaving a
gap to interpret.

Each answer carries the name of whoever gave it and when. A run that was worked
through as a checklist also names who started it and who signed it off.

**Download PDF** produces the same record as a document, with the organisation's
masthead and logo, the asset and the work in a summary block, every question
beside its answer, photographs printed large enough to judge, and a signature
line. It is meant to be readable by somebody who has never seen Kitwell — a
client, an inspector, or a filing cabinet.

---

**See also:** [Documentation index](README.md) · [Maintenance](maintenance.md) · [Teams](teams.md) · [Users, roles and permissions](users-roles-permissions.md)
