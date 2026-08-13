# Assets and sub-assets

The register itself: tagging, searching, sub-assets, the files attached to an asset, copying, and the difference between archiving and deleting.

**On this page**

- [Asset tags and barcodes](#asset-tags-and-barcodes)
- [Sub-assets, accessories and related items](#sub-assets-accessories-and-related-items)
- [Search and filters](#search-and-filters)
- [Condition photos](#condition-photos)
- [Manuals and shared photos](#manuals-and-shared-photos)
- [Copying assets](#copying-assets)
- [Categories, locations and settings](#categories-locations-and-settings)
- [The asset page](#the-asset-page)
- [Archiving vs deleting](#archiving-vs-deleting)

---

## Asset tags and barcodes

New assets are tagged automatically as `<prefix><number>`, which on this site
means {{setting:asset_tag_prefix}} followed by a number padded to a width
of {{setting:asset_tag_pad}} digits. Both parts are configurable under
**Settings → Application settings**. The number is derived from the tags already
in the register, so changing the prefix starts a new sequence and leaves
existing tags alone. Overwrite the suggested tag to record one an item already
carries.

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

Every asset carries a dated photographic record — the gallery on its page, and
a full history grouped by month. See [Photos](photos.md).

## Manuals and shared photos

An asset's **Shared photos & documents** card holds the files that describe the
model: a manual, a wiring diagram, a manufacturer's product shot. Each is held
once in the media library and attached to every asset that needs it, so a
workshop with ten identical drills stores one manual rather than ten. See
[Media library and templates](media-library.md).

Documents are viewable in the browser or downloadable, up to a limit
of {{setting:upload_max_pdf_mb}} MB each. Adding one needs `media.manual.upload`,
and attaching or removing one needs `assets.edit`. Every file is only reachable
by someone signed in with permission to view the asset, and every upload is
checked by its actual content rather than its file name.

## Copying assets

Two distinct workflows, both driven by explicit tick-boxes:

An asset can also be started from a template, which fills the form in and
brings its photos and documents with it — see
[Media library and templates](media-library.md).

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
are written; everything else on the targets is untouched. Shared files are
attached by reference rather than copied, so running it twice changes nothing
the second time.

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

**See also:** [Documentation index](README.md) · [Photos](photos.md) · [Media library and templates](media-library.md) · [Barcode scanning and labels](barcode-scanning.md)
