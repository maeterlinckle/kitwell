# Import and export

Loading an existing register or a contractor's PAT results from CSV, and getting data back out.

**On this page**

- [CSV import and export](#csv-import-and-export)

---

## CSV import and export

### Importing

Two importers ship — **Assets** and **PAT records** — reachable from
**Import** in the admin menu, or from the register.

The flow is upload → preview → confirm. **Nothing is written until you have
seen the preview and pressed the button**, and a row with a problem is skipped
and reported rather than stopping the batch.

Headings are matched loosely: case, spaces, underscores and bracketed hints are
ignored, and each column accepts aliases — so `Asset Tag`, `asset_tag` and
`Tag` all land in the same place, and column order does not matter. A file
saved from Excel works: the byte-order mark is stripped and comma, semicolon
and tab delimiters are all detected. Every importer has a **downloadable
template** with one example row.

Spreadsheets are untidy, so values are parsed forgivingly and the preview says
what it did: `19/03/2024`, `3 Feb 2022` and `2024-03-19` are all dates;
`£1,250.00` is a number; `Yes`, `Y`, `true` and `1` are all true. Anything not
understood becomes a warning and the field is left blank rather than the row
being rejected.

**Assets** — only *Name* is required. A blank tag is generated for you; a tag
that already exists is skipped, so re-running the same file cannot create
duplicates. Categories and locations are matched by name and created on demand
(a checkbox turns that off).

**PAT records** — matched to existing assets by tag, and a tag that matches
nothing is reported plainly rather than silently dropped. `1`, `I` and
`Class 1` all mean Class I. Earth continuity is discarded for anything that is
not Class I. Over-range readings (`>299`, `OL`) are recognised. The same rule
the on-screen form enforces applies here: a failed visual inspection cannot
carry an overall Pass. A test already recorded for that asset on that date is
rejected, so importing the same sheet twice is safe.

### Exporting

Exporting starts from **Settings → Export data**, and only from there. The
register page carries no export button at all: exporting is an occasional,
deliberate job, not something to meet while browsing.

The Export page lists everything that can leave the system as a file — the
asset register, and each report — with the same "how it works" explanation the
Import page has. For the register you choose which assets (by search, category,
location, status) and which optional extra columns, then download. To take a
hand-picked set instead, **Pick individual assets** opens its own searchable,
tickable list: choosing rows and choosing columns are two different jobs and
neither is clear when they share a screen.

The core columns are the same shape the importer accepts, so an
export can be edited in a spreadsheet and fed straight back in; the test suite
verifies that round trip.

Three optional column groups can be appended: **latest PAT result**, **current
hire**, and **next maintenance**. These are derived data rather than asset
fields, so they are ignored on re-import.

Every import and export is recorded in the activity log with who, which file,
how many rows, and how many were skipped.

### Column reference — assets

The template at `/import/assets/template` is always authoritative; this is the
same list for reference. Headings are matched loosely, so the "also accepted"
spellings work too, and any column can be left out.

| Column | Required | Notes | Also accepted |
|--------|----------|-------|---------------|
| Asset tag | | Blank generates one. Must be unique | tag, asset id, asset number, barcode |
| **Name** | **Yes** | What the item is | item, title |
| Description | | Free text | details |
| Category | | Matched by name, created on demand | group |
| Location | | Matched by name, created on demand | where, site |
| Condition | | Excellent / Good / Fair / Poor / Out of Service. Default Good | state |
| Status | | In Stock / In Maintenance / Faulty / Retired. Default In Stock | |
| Purchase date | | `2024-03-19`, `19/03/2024`, `19 Mar 2024` | bought, acquired |
| Purchase cost | | Symbols and commas ignored | cost, price |
| Current value | | | replacement value |
| Supplier | | | bought from, vendor |
| Serial number | | Duplicates warn, they do not block | serial, sn |
| Manufacturer | | | make, brand |
| Model | | | model number |
| Manufacturer URL | | Must start `http://` or `https://` | website, product page |
| Plug fuse rating (A) | | Amps, e.g. 3, 5, 13 | fuse, fuse rating |
| Cable CSA (mm2) | | Square millimetres, e.g. 0.75, 1.5 | csa, cable size |
| Requires PAT | | Yes/No, True/False, 1/0. Default No | pat, needs pat |
| PAT interval (months) | | Blank uses the site default | retest interval |
| Available for hire | | Yes/No. Default Yes | hireable |
| Notes | | Free text | comments |
| Secondary barcode | | A barcode the item already carries | other barcode |
| Warranty expires | | Date | warranty |

*Status `On Hire` is rejected with a warning — a hire needs a hirer, so
check the item out afterwards instead. `Faulty` is accepted, and the asset then
appears on the dashboard tile and the faulty report with "no fault report on
record" against it, which is honest: nobody filled the form in.* Three further columns (`Part of`,
`Relationship`, `Added`) appear in exports and are recognised but ignored on
import, so an exported file re-imports without complaint.

### Column reference — PAT records

| Column | Required | Notes | Also accepted |
|--------|----------|-------|---------------|
| **Asset tag** | **Yes** | Must match an existing asset's tag or barcode | tag, appliance id |
| **Test date** | **Yes** | Not in the future | date tested, tested on |
| **Overall result** | **Yes** | Pass or Fail | result, outcome |
| Retest due | | Blank is calculated from the asset's interval | next test |
| Tester name | | Person or contractor | tester, tested by |
| Tester ID | | Competency reference | tester reference |
| Test equipment | | Make, model, serial of the tester | instrument |
| Appliance class | | `Class I`, `1`, `Class II`, `2`… Default Class I | class |
| Visual inspection | | Pass/Fail. Default Pass | visual |
| Earth continuity (ohms) | | Ω. **Class I only** — discarded otherwise | earth, continuity |
| Insulation resistance (Mohms) | | MΩ. `>299` and `OL` understood | insulation, ir |
| Leakage current (mA) | | mA | leakage |
| Load (VA) | | Volt-amps | load, power |
| Functional check | | Pass/Fail, blank if not performed | function |
| PAT label | | Serial on the sticker | label |
| Fuse fitted (A) | | Amps found or fitted | fuse |
| Remedial action | | What was done on a failure | remedial |
| Notes | | Free text | comments |

Rows are rejected — not silently altered — when the tag matches nothing, the
test date is missing or in the future, the overall result is neither Pass nor
Fail, a visual failure claims an overall Pass, or that asset already has a test
on that date.

### Column reference — asset export

The export's core columns are exactly the asset import format above, so a file
can be exported, edited in a spreadsheet and imported back. Ticking **Extra
columns when exporting** appends any of:

| Group | Columns |
|-------|---------|
| Latest PAT result | PAT status, last tested, result, retest due, PAT label |
| Current hire | Hire status, on hire to, out since, due back |
| Next maintenance | Next job, next due, last done |

These are derived from other records rather than asset fields, so they are
ignored if the file is imported again.

---

**See also:** [Documentation index](README.md) · [Assets](assets.md) · [PAT testing](pat-testing.md) · [Reports](reports.md)
