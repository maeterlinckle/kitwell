# Photos

Condition photos on an asset: taking them on a phone, what happens on upload, and the dated history they build up.

**On this page**

- [Adding photos](#adding-photos)
- [The gallery and the main image](#the-gallery-and-the-main-image)
- [What happens on upload](#what-happens-on-upload)
- [Photos elsewhere in the application](#photos-elsewhere-in-the-application)

---

## Adding photos

Photos can be added to any asset — including sub-assets — at any point in its
life, building a dated visual record. A photo taken when an item goes out on
hire and another when it comes back is the usual reason to reach for it.

On the asset page, the **Photos** card has two buttons:

- **Take photo** opens the camera directly on a phone or tablet.
- **Choose files** opens the gallery or file picker.

Both accept several files at once. Before anything is uploaded the browser shows
a thumbnail of each file with its size, and flags anything over the site's
upload limit ({{setting:upload_max_photo_mb}} MB) so you are not left waiting
for a rejection.

Each photo can carry a **caption** and a **date**. Leave the date blank and it
is taken from the camera.

## The gallery and the main image

The gallery on the asset page shows the 12 most recent, newest first, each with
its date, caption and who uploaded it. **Full history** opens the asset's own
photo page, which groups everything by month and lets captions and dates be
corrected. Tapping a photo opens a lightbox you can also move through with the
keyboard.

One photo per asset is the **main** image, shown as the thumbnail in the
register. The first upload claims it automatically; **Set as main** changes it,
and if the main photo is deleted the next most recent takes over.

Uploading needs `media.photo.upload`, and deleting needs `media.photo.delete`.

## What happens on upload

1. **Straightened** using the photo's orientation tag, so a picture taken on a
   phone is not shown sideways.
2. **Scaled down** to a 2400px longest edge, with a 480px thumbnail generated
   alongside it, which keeps galleries quick to load over a workshop 4G
   connection.
3. **Dated** from the camera's capture time, unless you set the date yourself.
   An implausible date — a camera with a flat battery reporting 1970 — is
   ignored.

Every upload is checked by its actual content rather than its file name, so
renaming a file to end in `.jpg` does not get it accepted. Photos are stored
outside the web root and served back through the application, so a photo is
only reachable by someone signed in who may view that asset.

## Photos elsewhere in the application

The same camera control appears wherever evidence is worth attaching:

| Where | What it records |
|---|---|
| An asset | Its condition over time. See [Assets](assets.md) |
| A maintenance record | What was found and what was done. See [Maintenance](maintenance.md) |
| A fault report | What is wrong. At least one photo is required. See [Faults](faults.md) |
| A hire | Condition at checkout and at return. See [Hires](hires.md) |

---

**See also:** [Documentation index](README.md) · [Assets and sub-assets](assets.md) · [Faults](faults.md) · [Maintenance](maintenance.md)
