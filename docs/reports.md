# Reports

Six reports ship with the application, and anybody with the right permission can
define more. A saved report appears in the same list, opens through the same
page, and prints and exports the same way as a built-in one.

**On this page**

- [The built-in reports](#the-built-in-reports)
- [Custom reports](#custom-reports)
- [Who can do what](#who-can-do-what)
- [Adding a built-in report in code](#adding-a-built-in-report-in-code)
- [CSV exports](#csv-exports)

---

## The built-in reports

Grouped on `/reports`:

| Report | Answers |
|--------|---------|
| **All assets** | The whole register, filterable, with cost and value totals |
| **Faulty equipment** | What is broken now, most urgent first, and who is responsible |
| **Assets needing maintenance** | What is overdue or due soon, and who it is assigned to |
| **Assets needing PAT** | Overdue, failed, never tested, or due soon |
| **Assets currently on hire** | What is out, with whom, and when it is due |
| **Assets due back** | The chase list — overdue and imminent, with hirer phone and email |

Every report has filters, headline figures, a **print view** and a **CSV
export**. Reports run the same queries as the screens they mirror
(`MaintenanceSchedule::searchAll()`, `PatRecord::assetSearchAll()`,
`Hire::searchAll()`, `FaultReport::currentFaults()`), so a figure in a report
always matches the same figure on its screen.

## Custom reports

**Reports → New report** builds one from a question you have asked before.

A definition is four things:

1. **What to report on** — assets, maintenance schedules, PAT testing, hires or
   faulty equipment. Only the ones your role can already read are offered.
2. **How to narrow it** — the filters that source's own list page offers. There
   is no separate query language: a saved report can express exactly what the
   corresponding screen can.
3. **Which columns to show** — in the source's declared order, which is also the
   CSV column order.
4. **How to sort it** — any column the report shows, ascending or descending.
   Rows with nothing in the sort column always come last, in either direction.

Saved reports appear on `/reports` under **Saved reports** with a badge saying
so, and open at `/reports/custom-<name>`. From there they behave like any other
report: print, export, and a link back.

Two differences from a built-in:

- **The filters are fixed at save time and not offered on the page.** The
  criteria are described in words under the title, so a reader can see what they
  are looking at.
- **A definition holds column keys, not a copy of the schema.** Add a field to a
  data source and every saved report built on it can use it; remove one and
  reports drop it rather than rendering an empty column.

Editing and deleting are both on the report itself (**Edit report**) and on the
form. Deleting removes only the definition; no asset, test or hire is touched.
Unticking **List it on the Reports page** keeps the definition without offering
it.

The URL never changes after the first save, even when the report is renamed, so
a link pasted into an email keeps working.

## Who can do what

| | Permission |
|---|---|
| Open the reports section | `reports.view` |
| Open a particular report | that report's own data permission as well — someone who cannot see PAT records cannot reach the PAT report |
| Export to CSV | the report's export permission (`assets.export` on the register report) |
| Create, edit and delete saved reports | `reports.manage` — **Administrator and Manager / Staff** |

`reports.manage` grants nothing new to *see*. A saved report is refused at the
moment it is opened unless the reader also holds its data source's permission,
exactly as a built-in is: a colleague who cannot see hires cannot see a hires
report somebody saved.

## Adding a built-in report in code

Reports are a registry rather than a set of pages. To add one:

1. Write a class in `src/Reports/` extending `Report`, declaring its key, name,
   description, permission, columns, filters and rows.
2. Add it to the `REPORTS` list in `src/Reports/ReportRegistry.php`.

That is the whole change: routing, filtering, the table, the print view and the
CSV export are generic and driven by the class's own declarations, so no
controller, route or template is touched. Columns declare a type (`text`,
`date`, `datetime`, `money`, `number`, `badge`, `bool`), optional alignment, an
optional link target and an optional sub-line, and the renderer does the rest.

A **custom** report is the same thing built from a database row:
`App\Reports\StoredReport` is a `Report`, so the controller, table, print view
and export treat it exactly like a built-in. To offer a new *data source* for
custom reports, add one entry to `src/Reports/DataSourceRegistry.php` declaring
its filters (mapped to the model's own filter keys), its columns and one closure
that calls the model.

## CSV exports

CSV exports carry a UTF-8 byte-order mark so spreadsheets read `£`, `Ω` and
`mm²` correctly, dates are ISO, and any cell beginning `=`, `+`, `-` or `@` is
prefixed with an apostrophe so a spreadsheet cannot treat asset data as a
formula.

---

**See also:** [Documentation index](README.md) · [Import and export](import-export.md) · [API](api.md) · [Assets](assets.md)
