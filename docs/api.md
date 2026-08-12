# API

A REST interface over the register, at `/api/v1`. It is off until an
administrator turns it on.

The full endpoint reference is **generated from the running application** and
lives at **`/api/docs`**, with a working "try it out" for every operation. This
page covers turning the API on, getting a key, and the conventions every
endpoint follows.

**On this page**

- [Turning it on](#turning-it-on)
- [Issuing a key](#issuing-a-key)
- [What a key can do](#what-a-key-can-do)
- [Conventions](#conventions)
- [What is exposed](#what-is-exposed)
- [Errors](#errors)
- [Rate limiting](#rate-limiting)
- [The specification](#the-specification)
- [Adding a resource](#adding-a-resource)

---

## Turning it on

**Settings → API keys**, tick *Answer API requests*, save.

While it is off, every endpoint answers **503** with a message saying where to
turn it on, so a client can tell "not enabled" from "not there".

## Issuing a key

Same page. A key needs three things:

- **a name** — what will hold it, so the list means something a year later;
- **a user to act as** — the key inherits that person's role exactly;
- **an access level** — *read only* (GET and nothing else) or *full*.

An optional expiry date is worth setting for anything temporary.

**The key is shown once**, on the page immediately after it is created. Only a
SHA-256 of it is stored, so there is nothing to show again and no "reveal"
button to look for. If it is lost, issue another and revoke the old one.

```bash
curl -H "Authorization: Bearer ark_your_key_here" \
     "https://register.example.com/api/v1/assets?status[]=In+Stock&per_page=10"
```

`X-API-Key: ark_…` is accepted as well, for clients that cannot set an
`Authorization` header.

## What a key can do

**Nothing its owner could not.** An API request adopts the key's user and then
runs the same `Auth::can()` the web interface runs, against the same roles and
grants. There is no separate list of what an API may reach.

That has consequences worth relying on:

- Change somebody's role and every key they hold changes with it, in the same
  instant, with nothing to update.
- Deactivate the user and all their keys stop working, without touching the keys.
- Delete the user and their keys go with them.
- Revoking is immediate — no cache, no token lifetime to wait out.
- A key issued for the read-only role is refused `POST /assets` with a message
  naming the permission it lacks, exactly as that person would be refused the
  button.

A key may do **less** than its owner: a read-only key refuses everything except
GET, whatever the user could otherwise do. Start there unless the thing holding
the key needs to write.

A signed-in **browser session** is also accepted, and may only read. That is what
makes the "Send" buttons on `/api/docs` work without minting a key first; it
also means a cross-site form post cannot reach a writing endpoint, because
writing requires a key and a key is not a cookie.

## Conventions

The same shapes across every resource — a resource cannot invent its own,
because one generic controller serves all of them.

| | |
|---|---|
| **List** | `{"data": [...], "meta": {page, per_page, total, pages}, "links": {self, next, prev}}` |
| **One record** | `{"data": {...}}` |
| **Error** | `{"error": {status, code, message, details}}` |
| **Pagination** | `?page=` and `?per_page=`, clamped to the configured maximum rather than refused |
| **Sorting** | `?sort=field` ascending, `?sort=-field` descending. Empty values sort last either way |
| **Filtering** | each resource's own filters, listed on its endpoint in `/api/docs` |
| **Updating** | `PATCH` changes the fields you send; `PUT` replaces, resetting writable fields you omit |

Two refusals worth knowing about:

- **An unknown filter is a 400, not a no-op.** `?statuz=Retired` is refused
  rather than returning the whole register. The error names the filters that do
  exist.
- **An unknown field in a body is a 400, not a silent drop.** Sending
  `assetTag` when the field is `asset_tag` fails rather than returning 200 and
  changing nothing.

`PUT` resets an omitted writable field to its default, not to null, where the
column has one — an asset's status goes back to *In Stock*, not to nothing. The
defaults are in the specification.

## What is exposed

| Resource | Methods |
|---|---|
| `assets` | GET, POST, PATCH, PUT, DELETE |
| `categories`, `locations` | GET, POST, PATCH, PUT, DELETE |
| `hirers` | GET, POST, PATCH, PUT, DELETE |
| `maintenance-schedules` | GET, POST, PATCH, PUT, DELETE |
| `teams` | GET, POST, PATCH, PUT |
| `maintenance-logs`, `pat-records`, `hires`, `faults`, `users` | GET |

The read-only resources carry a workflow that a plain insert would not run, and
each says so in its own description. Checking a hire out moves the asset's
status, allocates a reference and refuses a double booking; recording
maintenance rolls the schedule forward, carries the condition onto the asset and
may create a follow-up job. Create those through the interface.

`users` is read-only because an account is created by invitation, so the person
chooses their own password. No password material is ever returned.

## Errors

```json
{
  "error": {
    "status": 422,
    "code": "validation_failed",
    "message": "Asset tag is required.",
    "details": { "asset_tag": "Asset tag is required." }
  }
}
```

`code` is stable and machine-readable — branch on it, not on the wording.
`details` appears only when there is something field-shaped to say.

| Status | When |
|---|---|
| 400 | Unknown filter, unsortable field, unknown or read-only field in a body, malformed JSON |
| 401 | No key, an unrecognised key, or one that is revoked or expired — the message says which |
| 403 | The user lacks the permission, or a read-only key tried to write |
| 404 | No such endpoint, resource or record |
| 405 | The method is not available; `Allow` says what is |
| 409 | Refused because it would destroy history — deleting an asset with PAT records, for instance |
| 422 | Validation failed |
| 429 | Rate limited; `Retry-After` says when |
| 500 | Something unexpected. The message carries a reference to quote; the detail is in the server log, never in the response |
| 503 | The API is switched off |

## Rate limiting

Per key, per minute, configurable under Settings (120 by default). Every
response carries `X-RateLimit-Limit`, `X-RateLimit-Remaining` and
`X-RateLimit-Reset`, so a client can slow down before it is refused.

The window is **fixed** rather than sliding: one counter on the key's own row,
reset each minute. A burst straddling a window boundary can therefore briefly
reach twice the limit.

## The specification

`GET /api/v1/openapi.json` — OpenAPI 3.1, generated from the same declarations
the endpoints are served from. A field that appears in the document appears in
the response, and a filter it documents is one the router accepts, because they
are the same array.

`/api/docs` renders it with a runnable request builder for every operation. The
viewer is first-party: the Content-Security-Policy here is `default-src 'self'`
and permits no off-origin scripts, so nothing loads from a CDN.

The spec is behind the same authentication as everything else.

## Adding a resource

One entry in `src/Api/ResourceRegistry.php`, declaring:

- the path segment and the permissions for list/read/create/update/delete;
- the fields, each with a type, whether it is writable, and any enum or default;
- the filters, mapped onto the model's **own** filter keys;
- closures that call the model methods the corresponding screen already calls.

Routes, pagination, sorting, the permission check, the writable allow-list, the
error handling and the OpenAPI document all follow. No new controller, no new
route, no new template — and the permission check is written once, so every
resource gets the same one.

`tests/api-contract.php` proves the arrangement holds — it fetches the spec from
a running server and calls every operation it advertises, including checking that
no response carries a field the specification does not declare.

---

**See also:** [Documentation index](README.md) · [Security](security.md) · [Users, roles and permissions](users-roles-permissions.md) · [Reports](reports.md)
