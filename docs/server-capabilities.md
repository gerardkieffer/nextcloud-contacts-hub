# Server capability notes

Findings from direct, hands-on protocol testing of the real servers this
project has actually been run against. Purpose: record what each server
does on the wire so we never have to re-derive this empirically again.

**This app is a contact hub: it holds address books of its own and
syncs each of them with CardDAV endpoints**, pushing to them, pulling
from them, or both. Any server below can be an endpoint in any of those
directions. The notes in sections 1-3 describe each server's own protocol
behavior, independent of which direction a given job runs in.

Testing history: Phase 0 testing against Infomaniak on 2026-07-19 (test
account, cleaned up afterwards); read-only testing against a real iCloud
account, also 2026-07-19 (no writes, no test contacts created -- reading
real data was sufficient); Mailo tested directly on 2026-07-28 (see
section 3). This testing began for the one-way, iCloud-only-as-origin
Python CLI that predates the current app; the protocol findings
themselves don't depend on which app is doing the syncing, which is why
this doc outlived that rewrite.

## 1. iCloud (`contacts.icloud.com`)

**Discovery flow**

- Initial `PROPFIND` to `https://contacts.icloud.com/` (asking for
  `current-user-principal`) returns a redirect to a per-account host, e.g.
  `https://p123-contacts.icloud.com:443/`. Must follow redirects manually
  and keep using the resolved host for the rest of the session -- do not
  hardcode `contacts.icloud.com` past the first request.
- Principal href resolves to something like `/10000001/principal/`
  (`10000001` here is the account's internal numeric ID, stable across
  requests).
- `addressbook-home-set` resolves to `/10000001/carddavhome/`.
- The actual collection is reachable by convention at `.../carddavhome/card/`.
  Confirmed: `https://p123-contacts.icloud.com:443/10000001/carddavhome/card/`.

**Collection model**

- Exactly **one** flat CardDAV collection per account. iCloud does not
  expose server-side sub-folders/collections -- this is a known, permanent
  limitation, not a bug on our end.
- Contact groups (the "lists"/folders you see in Contacts.app) are **not**
  separate collections. Each group is a companion vCard living in the same
  collection, using Apple's pre-vCard4 convention:
  ```
  X-ADDRESSBOOKSERVER-KIND:group
  X-ADDRESSBOOKSERVER-MEMBER:urn:uuid:<member-contact-uid>
  ```
  one `X-ADDRESSBOOKSERVER-MEMBER` line per member. Confirmed on a real
  11-group account.

**Fetching contacts**

- A single `REPORT` `addressbook-query` with `<C:address-data/>` and
  **no `<C:filter>` element** returns every vCard (contacts + group vCards)
  in one response. Technically `<C:filter>` is required by RFC 6352 --
  iCloud tolerates its absence and just returns everything. This matches
  long-standing community documentation and worked cleanly against a real
  250-resource account (239 contacts + 11 groups).
- vCard version returned: `3.0`.

**PHOTO property -- confirmed quirk (important)**

- Tested against a real account with 99 photographed contacts: **100% of
  them** (99/99, zero exceptions) return PHOTO as a CloudKit asset
  *reference*, not inline data:
  ```
  PHOTO;VALUE=uri;TYPE=JPEG:https://gateway.icloud.com/contacts/<account-id>/ck/card/<hash>
  ```
  This is standards-compliant vCard 3.0 syntax (`VALUE=uri` is the correct,
  documented way to say "this is a URI, not inline data"). The problem is
  downstream: several third-party consumers (confirmed: Infomaniak's own
  web contacts UI) don't check the `VALUE` parameter and assume every
  `PHOTO` value is base64, producing a broken image when it's actually a URL.
- The referenced URL **is resolvable with a plain authenticated GET using
  the exact same CardDAV Basic Auth credentials** (Apple ID + app-specific
  password) -- no separate CloudKit auth flow needed. Confirmed empirically:
  fetched a real photo, got 144,233 bytes starting with the JPEG magic
  number (`\xff\xd8\xff\xe0`).
- PHOTO commonly carries an additional `X-ABCROP-RECTANGLE` parameter
  (Apple's UI crop-rectangle metadata, e.g.
  `X-ABCROP-RECTANGLE=ABClipRect_1&0&0&882&882&<hash>`). Harmless to leave
  in place or drop; not needed by any non-Apple client.
- Our fix (the current PHP app: `VCard\Transform::resolvePhotoUri()` +
  `CardDav\Client::fetchBinary()`; originally shipped in the Python CLI
  as `vcard_transform.resolve_photo_uri` + `ICloudReadOnlyClient.fetch_binary`):
  detect the `VALUE=uri`/URL-shaped PHOTO, fetch the real bytes via an
  authenticated GET (using whichever endpoint's own credentials own the
  reference -- this works for iCloud on either side of a sync job now,
  not just as a fixed "origin"), and re-embed as proper inline
  `ENCODING=b` base64 before writing to the other side. Fetching the
  referenced bytes is a plain GET -- doesn't touch or need any write
  capability.
- vobject-specific gotcha: when a PHOTO property has `ENCODING=b`, vobject
  auto-decodes `.value` to raw `bytes` on parse and expects raw `bytes`
  (not a pre-encoded base64 string) when *writing* it back out --
  vobject does the base64 encoding itself at serialize time. Setting
  `.value` to an already-base64-encoded string produces double-encoded
  garbage.

**Incremental sync support (not yet used, future optimization)**

- `supported-report-set` on the collection includes both `sync-collection`
  and `addressbook-multiget`. A `sync-token` and `getctag` are also exposed.
  This means a `sync-collection` REPORT with a stored sync-token (or a
  cheap `getctag` comparison to skip the fetch entirely when nothing
  changed) is available for a future optimization -- we currently do a
  full REPORT fetch on every run, which is correct but not maximally
  efficient for large address books on frequent schedules.

**Auth & safety**

- HTTP Basic with Apple ID + app-specific password (2FA-safe; never the
  main account password).
- The original Python CLI enforced iCloud as permanently read-only at
  the type level (`ICloudReadOnlyClient` had no PUT/DELETE/PROPPATCH/
  MKCOL methods at all). **This is no longer true of the current PHP
  app** -- there is a single universal `CardDav\Client` with full
  read/write capability, and iCloud absolutely gets written to when
  it's configured as a `to_endpoint` job's destination. Whether a given
  endpoint is actually written to on a given run is entirely a property
  of the sync job's direction, decided by `Sync\Planner`/`Sync\Runner`
  -- not by any protocol-level restriction on iCloud itself.

## 2. Infomaniak (`sync.infomaniak.com`)

**Backend**

- SabreDAV 4.3.1 (`x-sabre-version` header) -- same DAV engine family as
  Nextcloud/ownCloud/Baikal. Standard RFC 6352 behavior, no iCloud-style
  per-account host redirect needed.

**Discovery flow**

- Standard `current-user-principal` -> `addressbook-home-set` -> collection
  enumeration. No redirects encountered.
- `current-user-privilege-set` nests privilege names **two levels deep**:
  `<current-user-privilege-set><privilege><write/></privilege></...>`,
  not as a direct child of the property. Real gotcha we hit: our own DAV
  XML helper (`dav_xml.DAVResponse.has_child`) originally only checked
  direct children and always reported "not writable" as a result -- fixed
  to search all descendants via `element.iter()`.

**Collection model**

- The tested account has **two** address-book collections:
  1. Personal collection (e.g. "My Contacts") -- full read/write. This is the
     sync target (`pick_default_collection` resolves to this one, since
     it's the only one with `write` in its privilege set).
  2. "Annuaire de l'organisation - <name>" (an org/company directory) --
     **read-only**, pre-existing, entirely unmanaged by our sync (we never
     have write access to it, confirmed via privilege set).
  - Open item: if Infomaniak's own web UI presents both collections merged
    into a single "all contacts" view, and some contacts already
    independently exist in the org directory, that would look exactly like
    duplicate contacts (matching name + phone/email) with zero involvement
    from our sync engine. The app's own `Sync\DuplicateMatcher` is unrelated
    to this -- it only compares contacts across the two sides of a sync job,
    not within a single endpoint's own collections.

**vCard support**

- Content negotiation confirmed for `text/vcard` version 3.0, version 4.0,
  and `application/vcard+json` version 4.0.

**Group vCard passthrough (Phase 0 test, confirmed)**

- A hand-crafted Apple-style group vCard
  (`X-ADDRESSBOOKSERVER-KIND:group` + `X-ADDRESSBOOKSERVER-MEMBER:urn:uuid:...`)
  was PUT and GET back **byte-for-byte identical**. Infomaniak's CardDAV
  layer does not need any translation for this convention -- hence
  `group_strategy: passthrough` is the correct configuration for this
  destination (confirmed at the protocol level; how it renders in
  Infomaniak's own web UI was deliberately not checked).

**Inline photo passthrough (Phase 0 test, confirmed)**

- A hand-crafted inline base64 `PHOTO;ENCODING=b;TYPE=JPEG:...` was PUT and
  GET back byte-for-byte identical. Storage layer is not the problem here
  -- see section on the iCloud PHOTO quirk above for the actual root cause
  of the broken-image symptom.

## 3. Mailo (`carddav.mailo.com`)

Tested directly against a live account on **2026-07-28** (766 contacts,
~17 MB of vCard data). Until then Mailo's preset was configured purely
from user reports; the findings below are from real protocol traffic.

**The whole-collection REPORT does not work on a book this size**

- `addressbook-query` REPORT for the entire collection returns **zero
  bytes** and dies on the client timeout. Reproduced repeatedly at both
  25s and 30s limits: `Operation timed out after 30002 milliseconds
  with 0 bytes received`. Nothing arrives at all — this is not a slow
  transfer that a longer timeout would rescue, the response never
  starts.
- The same account, same credentials, same collection:
  - `PROPFIND` etag listing of all 766 resources: **~1s**.
  - `addressbook-multiget` REPORT for 50 hrefs: **~3s**, ~550 KB, all
    50 carrying `address-data`.
- So the server is fine; it just cannot materialize the full book in a
  single response. This is what motivated the chunked-multiget fallback
  in `Client::fetchAllVCards()` (see `docs/timeouts.md`). End-to-end
  through the fallback: all 766 cards fetched in **~91s**, 766/766
  parseable by `Model::parse()`.

**Practical consequence for job configuration**

- A Mailo endpoint's first fetch of a run is inherently slow (~91s at
  this book size). Web-triggered runs pause once the time budget
  expires and auto-resume; resumes are cheap because they only
  `listEtags` rather than re-fetching. A **dry-run preview has no
  budget and cannot resume**, so it pays the full fetch cost in one
  request — expect ~1.5 minutes of apparent hang before the plan
  appears, and prefer CLI cron for the initial population of a large
  Mailo book.

**Still unverified for Mailo**: group vCard passthrough and inline
photo round-tripping were *not* tested here (the account was only read
from, never written to). `docs/presets.md` still treats its
`group_strategy` as unconfirmed, correctly.
