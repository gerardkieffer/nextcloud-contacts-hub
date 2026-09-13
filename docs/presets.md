# Preset notes

What each built-in preset assumes, where that assumption comes from,
and what's actually been verified against a live account versus what's
taken on faith from documentation/user reports. Presets only ever
pre-fill the endpoint form (`lib/Presets/Registry.php`) -- the
capability test (`lib/CapabilityTest/Tester.php`) always re-verifies
live and can override anything here, per endpoint.

## iCloud

- Discovery: `https://contacts.icloud.com/`, redirects to a
  per-account host (e.g. `p123-contacts.icloud.com`) on the first
  request. Confirmed by direct protocol testing -- see
  [server-capabilities.md](server-capabilities.md) §1.
- Auth: an **app-specific password**
  (appleid.apple.com → Sign-In and Security → App-Specific Passwords),
  never the main Apple ID password.
- Exactly one flat address-book collection per account -- no
  server-side sub-folders.
- Groups and `CATEGORIES` -- worth spelling out because it trips
  people up (this is the actual mechanism, not a bug in this tool or a
  missing feature):
  - A CardDAV **group** is not a property on a contact. It's a
    separate vCard of its own, marked `X-ADDRESSBOOKSERVER-KIND:group`,
    that lists its members by UID via one
    `X-ADDRESSBOOKSERVER-MEMBER:urn:uuid:<uid>` line per member. To add
    someone to a group you fetch that group's vCard and add another
    member line to it -- you don't touch the contact's own vCard at
    all.
  - Apple's **Contacts.app**, when you export a vCard from the app
    (File → Export), fabricates a `CATEGORIES` property on the
    *exported* contact from whatever groups it's a member of, purely
    for interop with software that doesn't understand Apple's group
    convention. That's an export-time-only convenience: the CardDAV
    server itself never returns `CATEGORIES` for group membership, only
    the separate group vCard described above. A vCard exported by hand
    from Contacts.app will look different (has `CATEGORIES`) from the
    same contact fetched over CardDAV (has no `CATEGORIES`, but a
    matching group vCard exists elsewhere in the collection) -- both
    are correct, they're just two different representations for two
    different purposes.
  - This app's `group_strategy: passthrough` reads and writes the real
    group vCards directly, so it isn't affected by the export-only
    `CATEGORIES` behavior either way. `group_strategy: categories`
    (used for destinations that don't support Apple's group vCard
    convention) is *this app* choosing to fold membership into
    `CATEGORIES` on write -- a deliberate translation for a specific
    destination, not a reflection of how iCloud stores anything.

## Infomaniak

- Discovery: `https://sync.infomaniak.com/`, standard `current-user-
  principal` → `addressbook-home-set` flow, no redirects. SabreDAV
  backend. Confirmed by direct protocol testing -- see
  [server-capabilities.md](server-capabilities.md) §2.
- **Username format**: an Infomaniak account has both a short login
  (e.g. `AB12345`) and the same thing qualified as a full address (e.g.
  `AB12345@sync.infomaniak.com`). Use the **short login** here. Only
  that form authenticates against this app; the qualified form is what
  Infomaniak's own documentation shows and what other clients accept,
  which makes this a genuinely surprising failure and the reason the
  preset hint says so explicitly. Corrected after a real login failure
  -- the hint previously recommended the full form.
- `current-user-privilege-set` nests privilege names two levels deep
  (`<privilege><write/></privilege>`, not `<write/>` as a direct
  child) -- handled correctly by `CardDav\DavResponse::hasChild()`,
  which searches all descendants rather than just direct children (see
  `tests/CardDav/DavXmlTest.php` for the regression test).
- Accounts may expose more than one address-book collection (a
  personal one plus a read-only shared/org directory) -- set
  `collection_name` explicitly rather than letting the app guess.
- Confirmed to preserve Apple-style group vCards byte-for-byte
  (`passthrough` works).

## Mailo

Partly verified. Read access was tested directly against a live Mailo
account on 2026-07-28 (see `docs/server-capabilities.md` section 3) --
discovery, etag listing and vCard fetching are confirmed working, with
one significant finding: **Mailo times out on the whole-collection
`addressbook-query` REPORT** for a book of any size (766 contacts
returned zero bytes in 30s), so this app fetches from it via chunked
`addressbook-multiget` instead. That fallback is automatic; no
configuration is needed, but expect a slow first fetch (~91s for 766
contacts).

*Write* behavior -- group vCard passthrough, inline photo
round-tripping -- is still unverified, since the tested account was
only read from. Those settings below still come from the service's own
documentation and user reports rather than protocol testing. Run the
capability test (including the write probe) after adding a Mailo
endpoint and treat its report as the source of truth for that account.

- Discovery URL: `https://carddav.mailo.com`.
- **Fallback discovery URL**: if root/well-known discovery against
  that URL doesn't work, Mailo's documented per-account path is
  `https://carddav.mailo.com/adbook/<email>/1`. This app tries that
  automatically -- if the capability test's first attempt against the
  plain URL fails outright, it retries once against this per-account
  path (substituting the endpoint's username) before reporting an
  error. If that fallback succeeds, the test result says so and
  offers to update the endpoint's base URL to the one that actually
  worked.
- Auth: username is the full Mailo e-mail address (e.g.
  `jane.doe@mailo.com`), password is the normal Mailo account password.
- Default group strategy: `categories` (Mailo's own group-vCard
  support, if any, hasn't been confirmed -- run the write probe to
  check before relying on `passthrough`).

## Google Contacts

**Listed but not usable.** Selecting it in the endpoint form shows the
reason and refuses to save, and `EndpointService::validate()` refuses
it server-side too, so the block cannot be sidestepped through the API.

It is present rather than omitted on purpose: someone who wants to sync
Google Contacts needs to be told *why* they cannot, or they will assume
the preset was forgotten and go looking for a URL that will never work.

- **Requires OAuth 2.0.** Google's own CardDAV documentation states
  that its interface requires OAuth 2.0 and that any attempt to connect
  with Basic authentication returns `401 Unauthorized`. This app
  authenticates with HTTP Basic only, so every request would be refused
  regardless of the credentials supplied.
- Legacy passwords stopped working for Gmail, Calendar and Contacts on
  **14 March 2025**, and app-specific passwords are not accepted for
  CardDAV either -- so there is no password-shaped credential that
  would work.
- **No group support at all.** Google's CardDAV interface does not
  expose Contacts labels, neither as group vCards nor as `CATEGORIES`;
  labels live only in the People API. This is why the preset's default
  strategy is `categories` rather than `passthrough`: passthrough would
  push group vCards that the server discards silently. It is what other
  clients (DAVx⁵ among them) settle on for the same reason.
- vCard 3.0 only.
- Documented collection URL:
  `https://www.googleapis.com/carddav/v1/principals/<email>/lists/default`,
  though Google asks that it be discovered rather than hardcoded since
  it may change. It is configured here as the discovery fallback, so
  that if OAuth support is ever added the URL handling is already
  right.

Supporting it means real work, not a preset: an OAuth client
registration with Google, the authorisation-code flow through a
browser, refresh-token storage alongside the existing encrypted
password column, and a `Bearer` code path in the transport. Flag before
starting.

Sources: [Google People API: Manage contacts with the CardDAV
protocol](https://developers.google.com/people/carddav),
[Transition from less secure apps to
OAuth](https://support.google.com/a/answer/14114704),
[DAVx⁵: tested with Google](https://www.davx5.com/tested-with/google).
