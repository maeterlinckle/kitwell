# Assets and sub-assets

The register itself: tagging, searching, photographs, manuals, sub-assets, copying, and the difference between archiving and deleting.

**On this page**

- [Asset tags and barcodes](#asset-tags-and-barcodes)
- [Sub-assets, accessories and related items](#sub-assets-accessories-and-related-items)
- [Search and filters](#search-and-filters)
- [Condition photos](#condition-photos)
- [Manuals](#manuals)
- [Copying assets](#copying-assets)
- [Categories, locations and settings](#categories-locations-and-settings)
- [The asset page](#the-asset-page)
- [Archiving vs deleting](#archiving-vs-deleting)

---

## Asset tags and barcodes

New assets are tagged automatically as `<prefix><number>` — `AST-0001` by
default. Both parts are configurable under **Settings**, and the number is
derived from the tags already in the database, so importing older records or
changing the prefix can never strand the sequence. Overwrite the suggested tag
to record a tag an item already carries.

Each asset also has an optional second `barcode` field for a manufacturer or
previous-system barcode. Scanning either value finds the asset.

## Sub-assets, accessories and related items

A sub-asset is a normal asset with a parent, so a battery, charger or carry case
keeps its own tag, detail page, PAT history and search entry while still being
listed under the tool it belongs to. Add one from the parent's page, or set
**Parent asset** on any asset form. Nesting is one level deep: an asset that
already has children cannot itself become a sub-asset.

Archiving a parent archives its attached items with it.

## Search and filters

Keyword search matches asset tag, secondary barcode, name, description, serial
number, manufacturer, model, supplier, notes, category and location. Multiple
words are ANDed — "makita drill" finds rows matching both. Filters cover
category, location, status, condition, PAT requirement and item type, and
retired assets are hidden unless you ask for them.

Search matches on substrings, so a partial tag or serial (`884213`) finds its
asset, and short words are not ignored.

## Condition photos

Photos can be added to any asset — including sub-assets — at any point in its
life, building a dated visual record. A photo taken when an item goes out on
hire and another when it comes back is the usual reason to reach for it.

The gallery on the asset page shows the 12 most recent, newest first, each with
its date, caption and who uploaded it. **Full history** opens
`/assets/{id}/photos`, which groups everything by month and lets captions and
dates be corrected. Tapping a photo opens a keyboard-navigable lightbox.

One photo per asset is the **main** image, shown as a thumbnail in the register.
The first upload claims it automatically; **Set as main** changes it, and if the
main photo is deleted the next most recent takes over.

On upload, each image is:

1. **Straightened** using its EXIF orientation tag, so a photo taken on a phone
   is not shown sideways.
2. **Scaled down** to a 2400px longest edge, and a 480px thumbnail is generated,
   which keeps galleries quick to load over a workshop 4G connection.
3. **Dated** from the camera's EXIF capture time, unless you set the date
   yourself. Implausible dates (a camera with a flat battery reporting 1970) are
   ignored.

Both steps need the GD extension. Without it uploads still work — the original
is stored and served as-is, and `thumbnail_path` stays NULL. Nothing breaks on a
host without GD.

On a phone, **Take photo** opens the camera directly (`capture="environment"`)
while **Choose files** opens the gallery; one combined input makes the phone ask
every time. Both accept multiple files. Before uploading, the browser shows
thumbnails of what is selected with each file's size, and flags anything over
the server limit rather than letting you wait for a rejection.

Uploads are validated by extension *and* by content sniffing, so a PHP script
renamed `photo.jpg` is refused. Files are stored outside the document root and
streamed through PHP.

## Manuals

Any number of PDFs per asset. Files are written to
`storage/uploads/assets/{id}/manuals/` with generated names, outside the
document root, and streamed back through PHP — so a manual is only reachable by
someone signed in with permission to view that asset. Each is viewable in the
browser or downloadable. Uploads are checked by extension *and* by content
sniffing, not by the browser-supplied content type.

## Copying assets

Two distinct workflows, both driven by explicit tick-boxes:

**Copy asset** (`assets.create`) creates 1–50 new assets from an existing one.
Pick which details carry over; each copy gets its own generated tag. Asset tag,
secondary barcode, serial number, status, photos, PAT results, maintenance and
hire history are never copied — they belong to one physical item. Serial numbers
are only carried over for a single copy, never a batch. A batch of more than one
lands on the label sheet, ready to print.

**Copy details to…** (`assets.edit`) pushes selected fields from one asset onto
other existing assets — for example applying a manual and manufacturer URL
across every unit of the same model already in the register. The candidate list
defaults to assets matching the source's make and model. Only the ticked fields
are written; everything else on the targets is untouched. Manuals are added,
skipping any the target already has, so repeat runs do not pile up duplicates.

Both workflows write to the activity log, on the source and on every asset
touched.

## Categories, locations and settings

Categories and locations are managed under **Admin** (`categories.manage` /
`locations.manage`) and nest, e.g. Main Workshop → Bench 3.

Each is shown as a **tree**: one compact row per entry, branches you can collapse,
and three buttons on every row — *Add inside*, *Edit*, *Delete*. Editing has its
own page, which is what keeps the list readable when there are fifty of them.

Nothing can be deleted while assets still reference it (the button is disabled
and says why) — make it inactive instead, which hides it from the pickers but
leaves existing assets intact. Nothing can be moved inside one of its own
children either; that would make a loop, and a loop in a tree is a page that
never finishes drawing.

## The asset page

Across the top: **Check out** or **Book in** (whichever applies), **Edit** and
**Print** — the errands somebody usually arrives at the page with.

Everything else that acts on the record is in the right-hand column:

| Card, top to bottom | What is on it |
|---|---|
| **Barcode** | The asset's Code 128 barcode, and **Print label** |
| **Faults** | Only once a fault has been reported: the count, and a link to the history |
| **Record** | When it was added and last updated, and by whom |
| **Manage** | **Mark as faulty**, **Copy**, **Copy details to…**, then a ruled line, then **Archive asset** and **Delete permanently** |

The line inside **Manage** separates what can be undone from what cannot. Only
*Delete permanently* is red.

When the asset is faulty, the current fault report sits across the top of the
page above everything else, with its photographs. See [Faults](faults.md).

## Archiving vs deleting

Archiving sets an asset to *Retired*, keeps every record, and is reversible.
Permanent deletion is available to `assets.delete` holders but is refused
whenever the asset has hire, PAT or maintenance history, or attached items.

A fault report on its own does **not** block a delete: fault reports and their
photographs are removed with the asset.

---

**See also:** [Documentation index](README.md) · [Faults](faults.md) · [Barcode scanning and labels](barcode-scanning.md) · [Maintenance](maintenance.md)
