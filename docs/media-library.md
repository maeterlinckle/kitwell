# Media library and asset templates

Photos and documents that describe a model rather than one item, held once and shared — and the templates that bring them along when you register something.

**On this page**

- [What belongs in the library](#what-belongs-in-the-library)
- [Adding and attaching files](#adding-and-attaching-files)
- [Removing a file](#removing-a-file)
- [Asset templates](#asset-templates)
- [Starting an asset from a template](#starting-an-asset-from-a-template)
- [Copying between assets](#copying-between-assets)

---

## What belongs in the library

A workshop with ten identical drills has one manual, not ten. The **media
library** holds that manual once and attaches it to every asset that needs it,
so adding the eleventh drill costs nothing but a link.

Put something in the library when it is true of the **model**:

- a manufacturer's product photo
- a user manual, wiring diagram or datasheet
- a specification sheet or parts list

Keep it out of the library when it is true of **one physical item**:

| This | Belongs to | Where it lives |
|---|---|---|
| A photo of the scratch on *this* drill | one asset | Its **Photos** card. See [Photos](photos.md) |
| A photo taken during a repair | one maintenance record | The maintenance record. See [Maintenance](maintenance.md) |
| A photo of what is broken | one fault report | The fault report. See [Faults](faults.md) |
| A PAT certificate for one test | one PAT record | The PAT record. See [PAT testing](pat-testing.md) |

Those four are never shared, and nothing in this page changes them. A condition
photo is a claim about one item on one day; attaching it to a second item would
make that claim false.

**Settings → Media library** lists everything held, what each file is, and how
many assets are using it. Anyone who can see assets can browse it.

## Adding and attaching files

There are three ways in, and they all end in the same place:

1. **Settings → Media library → Add to the library** — for something you want
   available before any asset needs it.
2. **An asset's Shared photos & documents card** — *Attach from the library*
   picks something already held; the upload form below it adds something new
   and attaches it in one step.
3. **The Add asset form** — see [Starting an asset from a
   template](#starting-an-asset-from-a-template).

**Uploading a file that is already in the library attaches the copy that is
there.** Files are matched on their contents, not their names, so re-uploading
the same manual under a different name costs nothing and creates no duplicate.

Adding a photo needs `media.photo.upload`; adding a document needs
`media.manual.upload`. Attaching something to an asset needs `assets.edit`.

## Removing a file

**Remove** on an asset takes the file off *that asset*. Everything else using it
keeps it, and the message says how many other places that is.

A file is only deleted outright when nothing references it at all — from
**Settings → Media library**, where the Delete button appears on unused items
only. Deleting an asset behaves the same way: its own files go, and anything
shared with another asset stays.

## Asset templates

**Settings → Asset templates** holds the starting points for equipment you
register often. A template stores:

- what to call it in the picker, and a description;
- defaults for the asset's name, description, category, location, condition,
  make, model, supplier and manufacturer website;
- the electrical detail: appliance class, load rating, whether the plug is
  fused and at what rating, cable CSA, whether PAT applies and at what interval;
- whether the item is available for hire;
- the library photos and documents that come with it.

**A template can never set an asset tag, a barcode or a serial number.** Those
identify one physical item, so they are generated or typed per asset however it
was started.

The three yes/no fields — requires PAT, plug carries a fuse, available for
hire — have a third option: *say nothing*. A template about a cordless drill has
no opinion on whether your site hires things out, and choosing to say nothing
leaves the form's own default alone rather than quietly switching it off.

Managing templates needs `templates.manage`, held by Administrator and
Manager / Staff — the same people who maintain categories and locations.

Deleting a template affects nothing already created from it. An asset built from
a template is an ordinary asset from the moment it exists.

## Starting an asset from a template

On **Add asset**, choose one under *Start from template*. The form reloads with
its fields filled in and its files pre-ticked. Nothing has been created yet:
every value is an ordinary form field you can change, and any file you do not
want can be unticked.

Below that, **Photos & documents** offers three things, and the difference
between them is the one thing worth reading carefully:

| Control | What happens |
|---|---|
| **Attach from the library** | Links a file already held. Nothing is stored. |
| **Shared document / Shared photo** | Uploads into the library and attaches it. Other assets can then use the same file. |
| **Condition photos of this item** | Stored against this asset alone, as the start of its photographic history. Never added to the library. |

Ten assets created from one template share one manual: one row in the library,
one file on disk, ten links.

## Copying between assets

**Copy asset** and **Copy details to…** both offer *Shared photos & documents*.
Ticking it attaches the source's library files to every target — a link each,
not a copy each. Running either twice changes nothing the second time.

Condition photos are not offered by either, for the same reason they are not
library items.

---

**See also:** [Documentation index](README.md) · [Assets and sub-assets](assets.md) · [Photos](photos.md) · [Barcode scanning and labels](barcode-scanning.md)
