# LOLER thorough examination

Recording an in-house report of thorough examination under the Lifting Operations and Lifting Equipment Regulations 1998.

**On this page**

- [What this does, and what it does not](#what-this-does-and-what-it-does-not)
- [Marking an asset as lifting equipment](#marking-an-asset-as-lifting-equipment)
- [How often](#how-often)
- [Who may examine](#who-may-examine)
- [Carrying one out](#carrying-one-out)
- [Photographic evidence](#photographic-evidence)
- [Recording defects](#recording-defects)
- [The report](#the-report)

---

## What this does, and what it does not

Kitwell records the report a competent person makes after a thorough
examination, and produces it as a document. It holds the equipment's fixed
details so they are confirmed rather than retyped, works out when the next
examination falls due, and refuses a report that contradicts itself or leaves
out something the regulations require.

It does not carry out an examination, exercise any judgement, or certify
anything. Whether equipment is safe to operate, whether a defect is a danger,
and what follows from that are the competent person's decisions. The duties
that regulation 10 places on them — notifying the employer, sending the report
where it has to go — remain theirs; the application prompts, and sends nothing
to anybody.

## Marking an asset as lifting equipment

On the asset's edit page, **In-house LOLER inspection** works like *Requires
PAT* above it: tick it, and the rest of the section appears.

| Field | What it is for |
|---|---|
| Type | The category of lifting equipment or accessory. It decides the interval the regulations set. |
| Examination interval | In months. Leave it blank to use the interval the type implies. |
| SWL / WLL | The safe working load or working load limit, with its unit. |
| Date of manufacture | Or ticked as not known — see below. |

The serial number is the asset's own, under **Make & model**; there is no
second one. All of these are the equipment's fixed characteristics, so an
examiner confirms them at each examination rather than typing them again, and
any correction made there is written straight back to the asset.

## How often

Regulation 9(3)(a) sets the interval by what the equipment is:

| The equipment | At least every |
|---|---|
| Lifting equipment **for lifting persons** | 6 months |
| An **accessory for lifting** — anything for attaching loads to machinery for lifting, such as a sling, shackle, eyebolt or lifting beam | 6 months |
| Other lifting equipment | 12 months |

A written **examination scheme** drawn up by a competent person may set a
different interval, which is why the interval is a field on each asset rather
than a site-wide rule. An examination is also required **after exceptional
circumstances** liable to jeopardise the equipment's safety.

The type you choose says which of these applies, and the report names the one
it was carried out under.

## Who may examine

| Permission | Held by | Allows |
|---|---|---|
| `maintenance.view` | Administrator, Manager / Staff, Read-only | Reading reports |
| `loler.inspect` | Nobody, until granted | Carrying out an examination and submitting the report |

`loler.inspect` is granted to **no role on a fresh install**. That is
deliberate: regulation 9 requires a competent person — somebody with the
practical and theoretical knowledge to find defects and judge what they mean —
and who that is at a site is not something an installation can assume. Grant it
from **Settings → Roles**, to an existing role or to one made for the purpose.

Only accounts holding it are offered as the examiner, and the server refuses
any other whatever the form was made to say.

## Carrying one out

**Examine** on the asset's LOLER card, or **New examination** from its history.
Three pages:

**1. The equipment.** Everything held against the asset, shown to be checked
against the equipment in front of you. Each has its own *Confirmed* tick, and
none may be skipped — the point is that somebody looked. Correct anything that
is wrong and the correction goes back to the asset.

*Not known or not marked* against the date of manufacture is there because
Schedule 1(3) asks for it "where known", and older or unbranded equipment
frequently carries none.

**2. The examination.** Which statutory basis it is being carried out under,
whether it is the first examination after installation, what the examination
found, whether it included testing, at least one photograph of the equipment,
and — as your own judgement — whether the equipment is safe to operate.

**3. The report.** The employer it was made for, the premises, the owner or
whoever the equipment is hired from, who examined it and their qualifications,
and the dates. **Use our details** beside each address fills it in from
**Settings → Application settings**, which is usually right for an in-house
examination and can be overridden wherever it is not.

The next examination date is worked out from the date of examination and the
interval, and stays editable — a written scheme or the condition of the
equipment may call for sooner.

## Photographic evidence

**At least one photograph is required**, and the report cannot be submitted
without one. Add as many as it takes: the whole item, and a close-up of
anything you are reporting.

It is the same control used everywhere else in Kitwell. **Take photo** opens
the camera straight away on a phone or tablet; **Choose files** opens the
gallery for shots taken earlier. What you pick is previewed with its size
checked against the server's limit before anything is uploaded.

Underneath there is a box for **what the photographs show** — one line per
photograph, in the order you added them. Each line is optional; leave it blank
for anything self-evident. A line that is filled in appears under its
photograph on the report.

These belong to the examination and not to the
[media library](media-library.md), which describes what an asset *is* rather
than one day's inspection of it — the same treatment PAT, fault and maintenance
evidence gets.

The stage indicator at the top of the form stays neutral for the examination
page until a photograph has been attached. Everything else on that page arrives
filled in; the photograph is the part that can only come from somebody standing
in front of the equipment.

> **If a submission is refused, the photographs are not kept.** A browser will
> not let a page put files back into a file box, so they have to be attached
> again along with whatever was corrected.

## Recording defects

**None** means no defect was found. Otherwise each defect is recorded as one of
two things, and the difference matters:

| Category | What it means | What follows |
|---|---|---|
| **Is a danger to persons** | The defect is a danger now | The employer must be notified forthwith, and the equipment must not be used before it is rectified |
| **Not yet a danger, but could become one** | It will become dangerous if left | Give the date by which it could. The equipment must not be used after that date until it is rectified |

Both need the part identified and the defect described. A defect that is a
danger also needs the repair, renewal or alteration required; one that could
become a danger needs both that and the date.

A report cannot say the equipment is safe to operate while also recording a
defect that is a danger to persons — the form refuses the combination.
Recording one also offers to mark the asset faulty so it is not issued, which
is a record and not a physical control: take the equipment itself out of use.

### Serious personal injury

Separately from the two categories, a defect may involve an **existing or
imminent risk of serious personal injury**. Ticking that says so on the report
and shows the duty it carries: a copy of the report must be sent to the
relevant enforcing authority as soon as is practicable — the HSE where the
equipment is hired or leased, otherwise the enforcing authority for the
premises.

**Kitwell does not send it.** Ticking the box records that the duty applies and
prints it on the report. Sending the copy is yours to do.

## The report

A completed examination has its own page and a **Download PDF**. The report
itself is one page for an ordinary examination, however many photographs follow
it: each photograph gets a page of its own after the report, with its
description underneath. Somebody printing only the first page still has a
complete report. Both carry everything Schedule 1
requires: the employer and the premises, particulars identifying the equipment
including its date of manufacture, the date of the last examination, the safe
working load and the configuration it applies to, which statutory basis this
examination was on, any defects with their categories and remedy dates, the
latest date the next examination must be carried out by, particulars of any
test, the date of the examination, the examiner with their qualifications and
employment, who authenticated the report, and the date of the report.

Regulation 10(1)(b) requires the report to be authenticated by signature **or
equally secure means**. Submitting it records the signed-in account that did
so, with the date and time, and the PDF says as much — with a ruled signature
line as well, for a site that wants ink on paper.

**Maintenance → LOLER** lists every report, filterable by outcome, examiner and
date. Each asset's own history is on its LOLER card.

---

**See also:** [Documentation index](README.md) · [Assets and sub-assets](assets.md) · [Maintenance](maintenance.md) · [Users, roles and permissions](users-roles-permissions.md)
