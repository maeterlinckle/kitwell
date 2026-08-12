# Maintenance

Scheduled and unplanned work: the four shapes a schedule can take, recording a completion with its evidence, and how "due" is decided.

**On this page**

- [Recording unplanned work](#recording-unplanned-work)
- [Evidence: photos and paperwork](#evidence-photos-and-paperwork)
- [Correcting a record](#correcting-a-record)
- [Follow-up checks](#follow-up-checks)
- [Overdue and upcoming](#overdue-and-upcoming)

---

Maintenance comes in four shapes. The first three are *schedules* — an asset can
carry any number of them — and the fourth is not scheduled at all.

| Kind | Recurs | For | Where |
|------|--------|-----|-------|
| **Routine** | Yes, on a standard cadence picked from a list (weekly → annual) | Things that must happen on a regular basis | Maintenance → New schedule |
| **Periodic** | Yes, on any interval you type (e.g. every 18 months) | Regular but on an unusual cycle | Maintenance → New schedule |
| **One-off** | No | A single *planned* job; it closes itself once completed | Maintenance → New schedule |
| **Unplanned work** | Never | The repair nobody saw coming — a broken part, something you noticed and dealt with | Maintenance → **Record work** |

The fourth is the important distinction: it is **recorded, not scheduled**.
There is no schedule behind it, because there never was one. It goes straight
into the asset's history and into Maintenance → History.

Completing a job records the date, who did it (a user *or* a named external
contractor), the work done, parts, cost, downtime, result and optional photos.
Two side effects, both opt-in and visible on the form: setting **condition
afterwards** updates the asset's condition, and an asset sitting *In
Maintenance* can be put back in stock in the same action.

For recurring schedules the next due date is calculated from **the date the work
was actually done**, not the date it was meant to happen — a six-monthly service
completed two weeks late is next due six months from that day. The form shows
the calculated date and lets you override it before saving.

Deleting a schedule keeps every completion already logged against the asset:
`maintenance_logs.schedule_id` is set to NULL rather than cascading, because
history should outlive the plan that produced it.

## Recording unplanned work

Three ways in, because this is how most workshop repairs actually get recorded:

- **Maintenance → Record work** — scan the label, or search the register.
- **Scan → Record work** — scanning takes you straight to the form.
- **Record work** on the asset's own page, when you are already looking at it.

None of them needs a schedule to exist first.

## Evidence: photos and paperwork

The Evidence section of the completion form takes both.

**Photos** use the same control as the asset's own condition photos: **Take
photo** opens the camera straight away on a phone or tablet, **Choose files**
opens the gallery for shots taken earlier, and what you pick is previewed with
its size checked against the server's limit before anything is uploaded. Two
inputs rather than one, because a single combined input makes the phone ask
every time.

**Documents** take the paperwork a visit produces — a contractor's service
report, a calibration certificate, an invoice. PDF only, validated exactly as an
asset manual is: byte size, extension, and the MIME sniffed with `finfo` rather
than trusted from the browser. They are stored against the *maintenance record*
rather than the asset, because a service report belongs to the visit it
describes; filing it against the machine would lose which visit produced it.

Both appear under the completion in the asset's maintenance history and on the
schedule's own page, and both are streamed through PHP from outside the document
root like every other upload.

## Correcting a record

People write records on the day, in a workshop, sometimes on a phone. Dates get
mistyped and a contractor's invoice turns up a week later. **Edit** on any
completed record — on the asset's page or in the maintenance history — opens
the record for correction.

Corrections are not silent. Every save writes an entry to the activity log
holding **what changed, field by field, with the old and new value**, who
changed it, when, and the reason if one was given. That trail is shown on the
edit page itself, so the person making the correction can see the record's whole
history, and it also appears under **Settings → Activity log**.

Recording maintenance needs *Record maintenance*; correcting a record afterwards
needs *Manage maintenance* — a step up from recording one, because rewriting history is
a bigger act than writing it. The asset and the schedule a record belongs to
cannot be changed: moving a record to a different machine is not a correction,
it is a different record.

## Follow-up checks

Any completion — scheduled or unplanned — can schedule a **follow-up check**:
tick the box, say *3 weeks*, and a one-off job appears in the maintenance list
and in the reminder emails when it falls due. The work you described is copied
into its instructions, and it is assigned to whoever did the original.

It is a **one-off**: "check the belt again in three weeks" closes
itself once done rather than quietly becoming a recurring job nobody meant to
create. If it turns out the thing does need checking regularly, make it a
routine or periodic schedule.

## Overdue and upcoming

Due status is computed **in SQL**, not in PHP, so it can be filtered, sorted and
counted by the database:

| Status | Meaning |
|--------|---------|
| `Overdue` | `next_due_date` is in the past |
| `Due soon` | Falls within the configurable window |
| `Scheduled` | Beyond the window |
| `Unscheduled` | Active, but no date set yet |
| `Inactive` | Closed schedule |

The window is **Settings → Maintenance → "Due soon" window**, default 30 days.
One setting drives the dashboard tiles, the maintenance list and the reports
module, so every screen agrees on what "due soon" means.

`MaintenanceSchedule::summary()` returns the counts and
`MaintenanceSchedule::search($filters)` returns rows with `due_status` and
`days_until_due` already computed — the reports module (stage 7) calls these
rather than re-implementing the rules. Retired assets and closed schedules are
excluded from the summary by default.

---

**See also:** [Documentation index](README.md) · [Assets](assets.md) · [Teams](teams.md) · [Email and notifications](email-and-notifications.md)
