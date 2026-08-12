# Faults and the responsible party

Naming who looks after an asset, reporting a fault against it with a photograph, and who gets told when something breaks.

**On this page**

- [Faults and the responsible party](#faults-and-the-responsible-party)

---

## Faults and the responsible party


### Saying who looks after an asset

Every asset has a **Responsible party** field on its edit form: one person, or
one team, or nobody. It is optional, and it can be changed at any time. It
appears on the asset page and on the printed record.

Being named here does not assign anybody a job and does not grant any access.
It answers one question — *who should hear about it when this breaks* — and it
is the only thing that decides who gets a fault email.

Naming a **team** tells every member. That is the point of a team: the news does
not stop because one person is on holiday.

### Reporting a fault

Anyone with **Report faults** sees **Mark as faulty** at the top of the
**Manage** card in the right-hand column of the asset page. It opens a page of
its own — not a dialog, because somebody filling it in is standing next to a
broken machine with a phone, and the form asks for a photograph. It asks for:

- **what is wrong**, in whatever words the person has;
- **when it was noticed**, which defaults to today and can be back-dated if the
  fault has been there a while;
- **a photograph**, at least one, through the same camera control the condition
  photos and maintenance evidence use — "Take photo" opens the camera on a phone;
- **the condition** now, on the asset's usual scale, which is saved to the asset
  as well as to the report;
- **how urgent** it is: Low, Medium, High or Critical, each with a line saying
  what it is for.

Submitting sets the asset's status to **Faulty** — so it stops being offered for
hire — and files a fault report.

### Reports are kept, not overwritten

An asset can break more than once. Each report is its own record, with its own
photograph, urgency and date, and the **Fault history** page lists them all. The
asset page shows the most recent one across the top while the status is Faulty,
and the sidebar carries the count.

An item reported faulty four times in a year is telling you to replace it, and
that is only visible because nothing was overwritten.

### Who is emailed, and when

**Immediately.** The moment a fault is filed, the responsible party is emailed —
the named person, or every member of the named team. The confirmation on screen
says who was told.

**Then in the digest.** Everything still marked faulty goes out on the same
scheduled run as the PAT, maintenance and hire reminders. Each person gets **one
message listing every faulty asset of theirs**, whether they are named on it
directly or reach it through a team — not one email per machine.

**An asset with nobody responsible emails nobody.** Not an administrator, not
the notify list, nobody. The report is still filed and still appears on the
dashboard and in the report; the screen says plainly that no email went out.
This is deliberate: mail addressed to "whoever is around" is mail everybody
learns to filter, and then the properly addressed messages go unread too. The
faulty-assets report has a **Nobody responsible** figure so these are easy to
find and fix.

A recipient still needs permission to see assets. Being named as responsible is
not itself a grant, and the run re-checks at send time — the same rule the other
reminders follow.

Both are configured under **Settings → Email → Reminders**, in a *Faulty
equipment* card: switch the digest on, choose how often it repeats, and switch
the immediate message off if you only want the round-up. The wording of both
lives in **Settings → Email → Templates** as "Asset reported faulty" and
"Faulty equipment digest".

### Finding faulty equipment

The dashboard gains an **Assets faulty** tile, plus a second one for Critical and
High when there are any. Both open the **Faulty equipment** report, which is
sorted most urgent first and can be filtered by urgency, by who is responsible,
or by a search across the fault text.

### Clearing a fault

Change the asset's status — either by recording the maintenance that fixed it,
or by editing the asset. There is no separate "resolve" button, because there is
no separate open/closed flag: the status is the single answer to "is this faulty
now?", and everything reads it. The banner on the asset page offers both routes.

---

---

**See also:** [Documentation index](README.md) · [Assets](assets.md) · [Maintenance](maintenance.md) · [Email and notifications](email-and-notifications.md)
