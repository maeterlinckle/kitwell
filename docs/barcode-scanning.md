# Barcode scanning and labels

Scanning with a camera or a hardware reader, and printing the labels that get scanned.

**On this page**

- [Scanning](#scanning)
- [Printing labels](#printing-labels)
- [Printing records](#printing-records)
- [Your logo](#your-logo)

---

## Scanning

A **Scan** button sits in the header on every page, and a small **Scan** button
sits at the end of every field that takes an asset tag or barcode:

| Where | Field | After a scan |
|-------|-------|--------------|
| Add / edit asset | Asset tag | fills the field |
| Add / edit asset | Existing barcode | fills the field |
| Asset register | Search | fills and searches |
| PAT register | Search | fills and searches |
| Record a PAT test | Asset lookup | fills and jumps to the asset |
| Hire checkout | "Which asset?" | fills and finds the asset |

Where the field is the whole question — a search or a lookup — a successful scan
submits it rather than making you press another button.

This is one partial and one shared decoder, so adding it to a new field is a
single line and no JavaScript:

```php
<?= partial('partials/scan-button', ['target' => 'asset_tag']) ?>
```

The button is hidden until its script loads: without JavaScript there is no
camera. Typing the tag and USB scanners work regardless. The quick-scan page
has no such button —
it already *is* a camera scanner, and two would fight over the camera.

Three ways in, all landing at the same lookup:

| Input | Notes |
|-------|-------|
| **USB barcode scanner** | Works with no setup — these act as keyboards. The input keeps focus so you can scan one item after another |
| **Device camera** | Uses the browser's native `BarcodeDetector` where available (Chrome/Edge). Where it is not — Safari, including every iPhone — falls back to the reader written for this project |
| **Typing** | Always available |

Scan modes: **Look up** jumps to the asset, **Check out** goes straight to the
checkout form, **Book in** goes to that item's return form, **Record work**
goes to the maintenance form, **PAT test** goes to the test form. A successful
read beeps and vibrates, so you are not staring at the screen.

**A good scan does not ask you to confirm it.** An asset tag identifies exactly
one asset, so there is nothing to choose between: the scan goes straight to
wherever the mode leads. You only see a result panel when the scan did not
resolve to one asset — nothing matched, or it would not decode — which is the
case where there is actually a decision to make. Every route in, whether the
camera, a USB scanner or typing, goes through the same server-side decision, so
they cannot drift apart.

### What it reads

Three symbologies, in `public/js/barcode.js`:

| Format | Why |
|--------|-----|
| **Code 128** | What this system prints. `src/Core/Barcode.php` is the encoder; the decoder shares its pattern table |
| **Code 39** | Still the default on older label printers, and on tags that arrived stuck to the equipment |
| **QR** | What is on the plate of most machinery bought this decade |

A scan is looked up against both the asset tag *and* the barcode field, so a
Code 39 or QR label already on a machine can be recorded against the asset and
then scanned like any other.

There is no third-party scanning library: the CSP only permits scripts from
this origin, and a reader is not worth a vendored blob nobody can audit. The
1D half reads a scan line, run-length encodes it, normalises against the module
width and matches the pattern table. The QR half is the standard pipeline from
ISO/IEC 18004 — locate the three finder patterns, find the alignment pattern,
correct the perspective, read the format information, undo the data mask,
de-interleave the blocks and Reed-Solomon correct them.

**A misread must not become the wrong asset.** Code 128 has a checksum and QR
has Reed-Solomon, so garbage does not decode, it fails — and where damage
exceeds the correction capacity the QR decoder refuses rather than guesses.
Code 39 has no checksum at all, so it is only believed when the same value
comes off two different scan lines of the same frame, with a clear quiet zone
either side.

Its limits, stated plainly: 1D barcodes want to be roughly horizontal (QR does
not care, and reads upside down); the QR reader handles all 40 versions but not
Micro QR; and EAN/UPC product barcodes are read only where the browser has a
native detector. If a code will not read, type it — nothing is lost.

`tests/barcode-decode.html` is the check: open it in any browser and it
renders known values through an independent encoder and reads them back, plus
the specification's own worked example and a set of frames that must *not*
decode.

## Printing labels

`/assets/{id}/label` prints one asset (add `?copies=6` for a strip of the same
label); ticking rows in the register and pressing **Print labels** prints a
sheet. Labels are Code 128, rendered as inline SVG — no image library, and
crisp at any printer resolution. Three sizes are available via `?size=`:

| Preset | Label | Narrow bar |
|--------|-------|-----------|
| `small` | 50 × 19 mm | 0.26 mm |
| `medium` (default) | 62 × 25 mm | 0.33 mm |
| `large` | 76 × 32 mm | 0.42 mm |

Print at 100% scale — "fit to page" changes the bar widths. Long asset tags are
scaled to fit the label and the print view warns when that happens.

## Printing records

Two documents, both written as paper rather than as a screen with the furniture
hidden:

- **Print** on an asset page gives that asset's full record — identification,
  purchase and value, PAT status with recent tests, maintenance schedules and
  recent work, current hire, sub-assets and notes — with its barcode at the top.
  This is the sheet to put in a folder, hand to an engineer, or take to the
  machine when the system is not in front of you.
- **Print list** on the register gives the assets you are currently looking at,
  six columns wide: tag, name, category, location, condition and status. It
  honours your filters, or your ticked rows if you have ticked any, and it says
  which at the top of the page. Long lists repeat the column headings on every
  sheet.

Both carry your logo, the organisation name, and who printed them and when.

## Your logo

**Settings → Logo** takes a PNG, JPEG or WebP up to 2 MB, with separate light
and dark mode versions — upload either, or both, or neither. The active one
replaces the **KW** box in the top-left corner; the wordmark beside it stays
either way. Upload only one and it is used for both themes.

The logo is scaled by height and never stretched, so any shape works; make it at
least 72px tall so it stays crisp on a high-resolution screen. A very wide
banner is capped in width and padded rather than squashed.

On a desktop the wordmark sits **under** the logo rather than beside it: a
wordmark image next to a wordmark in text is the widest the branding can be, and
every pixel it takes is one the menu cannot have. On a phone the two sit side by
side, sharing a baseline.

It appears in the
site header, on the sign-in page, on both printed documents above, and in the
header of outbound email — where it is embedded in the message rather than
linked, so it still shows when the mail is read from outside your network.

Printed pages and email always use the **light** version: paper is white, and so
is a mail client's message pane.

---

**See also:** [Documentation index](README.md) · [Assets](assets.md) · [Hires and hirers](hires.md)
