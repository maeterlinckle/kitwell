# PAT testing

Portable appliance testing: what is fixed about the appliance, what belongs to a test, the guided flow, and how the status is worked out.

**On this page**

- [Fixed values live on the asset, not the test](#fixed-values-live-on-the-asset-not-the-test)
- [Recording a test is a guided flow](#recording-a-test-is-a-guided-flow)
- [Guideline pass ranges](#guideline-pass-ranges)
- [Assets that need details filling in](#assets-that-need-details-filling-in)
- [Status at a glance](#status-at-a-glance)

---

Tick **Requires PAT** on an asset (on the asset form, or one-click from the PAT
banner) and it joins the PAT register. Recording a test against an unflagged
asset flags it automatically.

Every test is stored as its own record, so an asset accumulates a full history
rather than just the latest sticker on the plug. Each record captures test date,
retest due date, tester (a user *or* a named contractor) with competency
reference, test equipment, appliance class, visual inspection, earth continuity,
insulation resistance, leakage, load, polarity, functional check, overall
result, PAT label serial, fuse fitted, remedial action and notes.

## Fixed values live on the asset, not the test

Appliance class, load rating, whether the plug is fused and the fuse rating are
properties of the *appliance*. They are set once on the asset under **Electrical
& PAT** and shown automatically each time a PAT test is recorded.

The plug fuse rating is a four-way choice (3 A, 5 A, 10 A, 13 A) rather than
free numeric entry. An existing non-standard value is kept and shown flagged for
correction rather than silently discarded.

Each test still stores a **snapshot** of the appliance class it was performed
under, so correcting an asset later never rewrites its history.

## Recording a test is a guided flow

**Record a test** walks through the job in the order it is actually done, one
step per screen — designed for a phone in a workshop:

1. **This appliance** — the fixed values, shown alongside every later step.
2. **Visual inspection** — plug, cable, case, and (only if the asset is fused)
   a fuse check that asks you to *confirm the fitted fuse matches the recorded
   rating* rather than to type a value in again.
3. **Electrical tests** — only the ones the class calls for. Class I gets earth
   continuity, insulation resistance and leakage; Class II gets insulation and
   leakage, because there is no earth path to test. Each has its reading, its
   unit, and its own pass/fail.
4. **Functional check** — does it work when you switch it on.
5. **Result** — derived, not declared.

**One failed check fails the test.** You never separately declare a failure: if
anything in steps 2–4 is marked fail, the record saves as a Fail with the failed
checks listed automatically, and the flow asks what was wrong. A Pass only
becomes available once every applicable check has passed.

The browser enforces this as you go, but the server derives the result
independently — posting the form by hand with a smuggled `overall_result=Pass`
and a failed cable still saves a Fail.

Every individual step result is stored, not just the overall verdict, so the
history stays inspectable. Tests imported from CSV, and anything predating this
flow, show "not recorded" for the per-check verdicts rather than claiming a pass
nobody gave.

Editing an existing record stays a flat form — correcting a typo in a tester's
name should not mean walking six steps.

## Guideline pass ranges

Each electrical reading shows typical guidance beside it:

| Reading | Default guidance |
|---------|------------------|
| Insulation resistance | 1 MΩ or more |
| Earth continuity | under 0.1 Ω for the appliance, plus 0.1 Ω per 7.5 m of extension lead |
| Leakage current | under 3.5 mA (Class I) or 0.25 mA (Class II) |

Enter the length of any extension lead under test and the earth continuity
guideline recalculates live. The leakage guideline follows the asset's class
automatically.

**These are guidance, not a rule.** Nothing compares a reading against them to
decide anything — your pass/fail choice is what records the result. All six
values are editable under **Admin → Settings → PAT guideline pass ranges**, so
they can be tuned to your own policy without a code change.

## Assets that need details filling in

The migration backfills appliance class, load and fuse details from each asset's
most recent test. Anything never tested has nothing to copy from, and the guided
flow will not start without an appliance class — it cannot tell which tests
apply. List the gaps with:

```bash
php bin/console.php pat:missing-details
```

**Units are explicit everywhere** — in the column names, the form labels and the
displayed values:

| Reading | Unit | Column |
|---------|------|--------|
| Earth continuity | Ω (ohms) | `earth_continuity_ohms` |
| Insulation resistance | MΩ (megohms) | `insulation_resistance_mohms` |
| Leakage current | mA (milliamps) | `leakage_current_ma` |
| Load / power | VA (volt-amps) | `load_test_va` |
| Fuse fitted | A (amps) | `fuse_fitted_amps` |

Earth continuity is only shown — and only stored — for **Class I**; a Class II
appliance is double-insulated and has no earth to test, so a stray reading is
discarded rather than saved as if it meant something.

Two rules are enforced rather than left to the operator:

- **A failed visual inspection cannot pass overall.** The form flips the result
  to Fail when the visual check is unticked, and the server rejects the
  contradiction if it is submitted anyway.
- **A failing test can withdraw the item from use** in the same action — moving
  it to *In Maintenance* and optionally marking the condition *Out of Service*.
  Both are tick-boxes, on by default for the first.

## Status at a glance

The asset page carries a colour-coded banner answering "is this thing in date?":

| Status | Meaning |
|--------|---------|
| `Current` | Last test passed and the retest date has not arrived |
| `Due soon` | Retest falls within the configurable window |
| `Overdue` | Retest date has passed |
| `Failed` | Most recent test failed — flagged **regardless of the retest date** |
| `Never tested` | Flagged as requiring PAT with no record |
| `No retest date` | Tested, but no retest date was set |

`Failed` outranks the date: an item that failed last week is not "in date"
because its retest is not due until next year.

Retest dates are suggested from the asset's own `pat_interval_months` where set,
otherwise from **Settings → PAT testing → Default retest interval** (12 months
out of the box). The right interval depends on the equipment and its
environment, so it is a site decision rather than a hard-coded rule, and any
asset can override it.

As with maintenance, status is computed in SQL. `PatRecord::summary()` and
`PatRecord::assetSearch($filters)` are what the reports module (stage 7) calls.

---

**See also:** [Documentation index](README.md) · [Assets](assets.md) · [Reports](reports.md) · [Import and export](import-export.md)
