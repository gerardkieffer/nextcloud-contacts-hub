# CLAUDE.md

Guidance for continuing work on this project in a fresh session. The
[README](README.md) is the user-facing doc; this file is about how to keep
*building* it.

## What this project is

A **Nextcloud app** (`contacthub`, "Contacts Hub") that turns Nextcloud's own
address books into a contacts hub: it syncs any Nextcloud address book with
external CardDAV services (iCloud, Infomaniak, Mailo, generic), one-way in
either direction.

Two-way sync existed early on and was removed: it was unreliable and never
had real test coverage. The engine and everything that served it --
`SyncJob::TWO_WAY`, the Planner's B -> A diff, `ConflictResolver`, the
`conflict_strategy` setting, and the same-UID edit-conflict UI -- are gone.
If you find yourself reaching for any of that, stop; it isn't coming back
without a deliberate decision to rebuild and test it properly.

Because every job runs through a Nextcloud address book, the two headline
features need no special machinery:

    one-to-many          N jobs, one address book, N endpoints
    bridge two services  two jobs sharing one address book

**It is not a CardDAV server, and it does not store contacts.** Nextcloud
does both. This app owns only the sync configuration and the bookkeeping
that describes what it did.

### History

It began as a Python CLI, became a standalone PHP app with its own address
book storage, auth, router and photo store, and is now a Nextcloud app.

The migration deleted ~2,600 lines that existed only because there was no
platform underneath: `Auth/`, `Http/`, `View/`, `Support/`, `Hub/`, the
CSRF/headers code, the PDO wrapper, the SQL migrator and the hand-rolled
autoloader. If you find yourself re-adding any of that, stop and check
whether Nextcloud already provides it.

### Still zero runtime dependencies

There is no Composer autoloader. Nextcloud resolves `OCA\ContactHub\Foo\Bar`
to `lib/Foo/Bar.php` by convention, and Composer stays a development-only
tool for PHPUnit. The vCard parser and CardDAV client are hand-rolled (see
the gotchas for why the parser was not replaced with sabre/vobject). npm
exists only to build the frontend, and its output is committed.

## Where things are

```
appinfo/
  info.xml            app metadata, background job, navigation
  routes.php          SPA shell + the OCS actions
lib/
  AppInfo/Application.php    IBootstrap; the three registrations that
                             cannot be autowired
  Controller/         thin HTTP layer over Service/; ApiController maps
                      service exceptions onto status codes
  Files/              HubFolder: the "Contacts Hub" folder in a user's own
                      files, where snapshots and settings exports live
  Service/            validation and orchestration
                      AddressBookService  access checks for address books;
                                          CardDavBackend is otherwise only
                                          touched by NextcloudSide,
                                          SideFactory and BackupService
                      EndpointService     CRUD + the capability-test flow
                      JobService, RunService, ConflictService, BackupPresenter
                      SyncStateReset      forgets a job's sync state when
                                          what it syncs is moved elsewhere
                      SettingsTransfer    export/import of endpoints and jobs
                      NotificationPreferences, NotificationService
                                          who gets the conflict-pause email
  Db/                 hand-written mappers over IQueryBuilder (not QBMapper
                      -- see gotchas). Every query filters by user_id.
  Migration/          IMigrationSteps, portable across MySQL/PG/SQLite; some
                      only repair data (see the cron gotcha)
  BackgroundJob/      SyncTimedJob, SendConflictPauseNotification
  Settings/           Personal + PersonalSection: the "Contacts Hub"
                      entry in Personal settings (notification address)
  Listener/           UserDeletedListener
  Sync/               the engine: Planner (diff), Runner (execute,
                      resumable), SideMap, SideFactory, NextcloudSide,
                      RemoteSide, CategoryGroups, ConflictApplier,
                      Archiver, DuplicateMatcher, Normalize, JobValidator,
                      JobLock, CronBudget, DestinationRenderer,
                      BackupService, EndpointBackup (unreachable: no route
                      uses it)
  VCard/              hand-rolled RFC 6350: LineCodec (fold/unfold),
                      PropertyLine, Document, Model (parse), Transform
  CardDav/            Client (discovery + CRUD), Href, DavXml,
                      AbstractTransport, NextcloudHttpTransport (over
                      IClientService)
  Presets/            Preset + Registry (icloud/infomaniak/mailo/google/generic)
  CapabilityTest/     Tester (discover / writeProbe / mkcolProbe)
src/                  Vue 3 SPA (App, views/, components/, api.js, store.js)
                      plus settings.js, a second entry for Personal settings
js/                   BUILT output; committed, because Nextcloud installs
                      apps as plain files with no build step on the server
templates/main.php    SPA mount point; settings-personal.php likewise
img/                  app-dark.svg, the Personal settings section icon
tests/                unit (no DB, no Nextcloud) + Integration/ (both)
dev/                  docker-compose.yml (NC 34), docker-compose.nc35.yml,
                      and the php/npm/occ/lint/phpunit-integration/package
                      wrappers
docs/                 install.md, presets.md, groups.md, server-capabilities.md,
                      timeouts.md
```

## Running things

Nothing is installed on the host, deliberately: Docker keeps the toolchain
identical on every machine and architecture. There are
two PHPs, though: `./dev/php` and the unit suite run PHP 8.3 (the minimum
`info.xml` declares), while the Nextcloud container, and so the integration
suite, runs whatever its image ships.

```bash
cd dev && docker compose up -d     # throwaway Nextcloud 34 on :8080
./dev/occ app:enable contacthub    # admin/admin
./dev/npm install && ./dev/npm run build
./dev/lint                         # php -l over lib/ and tests/, one container
./dev/php tools/phpunit.phar       # unit suite, no DB, no Nextcloud
./dev/phpunit-integration          # inside the container, real CardDavBackend
./dev/package                      # dist/contacthub-<version>.tar.gz to install
```

`./dev/package` needs nothing installed at all. It names the tarball from
`<version>` in `info.xml` and refuses to build over a `js/` bundle older than
anything in `src/`, because a package built from a stale bundle installs
cleanly and then serves the old UI. `docs/install.md` is the procedure it
feeds. `docker-compose.nc35.yml` is a second, independent instance on :8035
for testing against the next major.

PHPUnit is not committed; fetch it once per environment:

```bash
mkdir -p tools && curl -sL https://phar.phpunit.de/phpunit-11.phar -o tools/phpunit.phar
```

The repository root is bind-mounted into `custom_apps/contacthub`, so host
edits are live. `docker compose down -v` throws the instance away.

## Testing

Two suites, and the split is deliberate.

**Unit (`phpunit.xml`)** needs no database and no Nextcloud, and runs in well
under a second. It covers the vCard layer, the Planner, SideMap,
CategoryGroups, presets, conflict resolution, duplicate matching and the
CardDAV client against a fake transport. Keep new logic testable here where
you can; it is the loop you will actually run.

**Integration (`phpunit.integration.xml`)** boots Nextcloud itself
(`require lib/base.php`, as occ does) because the official Docker image ships
no `tests/` directory, so the server's `\Test\TestCase` does not exist. It
drives the real Runner, mappers and `CardDavBackend`, with the endpoint faked
at the HTTP transport. These do **not** skip themselves when the environment
is missing -- a silent skip is how a green run comes to mean nothing.

`IntegrationTestCase` gives each test a fresh address book and wipes only
the test user's rows; clearing the tables wholesale once deleted data someone
had set up by hand in the same instance.

**The highest-confidence check is a real server.** Point an endpoint at
Nextcloud's own DAV endpoint (`http://localhost/remote.php/dav/`, with
`allow_local_remote_servers` enabled) and sync one address book into
another. Same sabre/dav family as Infomaniak. The checks worth repeating:

* push a contact **with a photo**, confirm the bytes on the far side are
  byte-identical, then run twice more and confirm **zero writes and an
  unchanged ETag**;
* create a group, confirm it arrives as a real `KIND:group` vCard;
* set a 5s budget against a slow endpoint and confirm the run pauses and
  resumes without duplicating work.

When you fix a bug found this way, add the test that would have caught it.

## Non-obvious gotchas

### Hash what was stored, and only through `Model::textHash()`

Bytes out are not always bytes in, in two ways:

* `CardDavBackend::getCards()`/`getCard()` pass stored bytes through a
  private `readBlob()`, which strips PHOTO properties carrying **non-image**
  `data:` URIs and rejoins lines with CRLF.
* A REPORT carries each vCard inside a `<card:address-data>` element, and XML
  line-ending handling (mandatory per spec, not a quirk) turns every `\r\n`
  into `\n`. A GET does not.

The whole diff survives that on two rules, both load-bearing:

1. **`SyncSide::putVCard()` returns the text the side actually stored**, not
   the text it was handed, and `Runner` records a hash of the *returned* text.
   `NextcloudSide::putVCard()` re-reads the card after writing to get it.
   Hashing the input instead makes every affected contact look modified on
   every run, and push forever. Any new write path must do the same.
2. **All hashing goes through `Model::textHash()`**, which normalises line
   endings first. **Never** call `hash('sha256', ...)` on vCard text
   directly; this bit three separate times.

Pinned by `NextcloudSideTest`, including a 200 KB photo and the stripped
case. A visible consequence: a contact arriving with a non-image `data:`
PHOTO loses it on the way in, looks changed once, then settles.

### An empty `PHOTO;VALUE=URI` must be skipped, not fetched

Real address books contain them -- Nextcloud's own example contact ships two
alongside a perfectly good inline PNG. `Transform::resolvePhotoUri()` skips
empty values, because fetching `""` cannot succeed and **one failed
reference aborts the whole transform**, so the contact loses the real photo
it did have. Regression tests in `TransformTest`.

### Roles, not A/B, in anything persisted

The Planner only ever pushes A -> B. Rather than teach it a reversed mode,
a `from_endpoint` job puts the endpoint in slot A:

    to_endpoint    A = hub       B = endpoint
    from_endpoint  A = endpoint  B = hub

`Sync\SideMap` is the only place that translation lives. State columns,
conflict snapshots and conflict *resolutions* are all named for roles
(`hub_hash`, `endpoint_snapshot`, resolution `'hub'`/`'endpoint'`), where
"hub" is the Nextcloud address book. Storing raw a/b would invert the meaning
of every existing row the moment someone edited a job's direction.
Coverage: `SideMapTest`.

### Never substring-match raw vCard text

Long lines (a member UID, a base64 photo, a CATEGORIES list) get RFC-folded,
which splits values across fold boundaries. Parse (`Model::parse`,
`Document::parse`) and compare structured fields. This shipped as a real bug
in `Tester::writeProbe()`, and was then made again in tests.

### Every PUT is conditional, and a same-UID copy is the same contact

`Client::putVCard()` sends `If-Match` with an etag and `If-None-Match: *`
without one. There is no unconditional-overwrite PUT; a caller meaning
"overwrite whatever is there" must fetch the live etag first. `NextcloudSide`
enforces the same semantics against Nextcloud's etag.

For a Runner *create*, the push passes the live ETag of its target href
whenever the listing has one, so a planned create whose href already exists
overwrites it (guarded by that ETag) rather than failing.
`Runner::adoptExistingOnB()` extends that to a same-UID copy at a *different*
href -- an imported `.vcf` names its files at random, and Nextcloud refuses a
second card with one UID. `If-None-Match: *` only guards against something
appearing between the listing and the PUT.

The same rule binds duplicate detection: when B holds a contact's UID, the
contact is never a duplicate candidate -- not even of *other* cards there
sharing its name and address, which once turned every namesake into a
conflict. It is adopted in place.

### CategoryGroups: derived identities, and the archive category is not a group

Nextcloud models groups as `CATEGORIES`, so `NextcloudSide` reports the
`categories` strategy and `Sync\CategoryGroups` projects those into `Group`
objects for passthrough endpoints. Derived UIDs are UUIDv5 of the normalised
name (`CategoryGroups::uidFor()`), so they are stable across runs without a
mapping table, with two costs:

* Renaming a category reads as delete-plus-create on the far side.
* A name the endpoint already has under its own UID exists there **twice**,
  once per identity. `project()`'s "a real group wins" rule only fires on a
  UID *clash*, and these do not clash. Nextcloud shows one group.

Fixing either needs a name-to-UID mapping table and a decision about which
identity is authoritative -- a design change, not a patch. When reading a
log, the UUID version nibble (first character of the third dash-separated
field) tells the groups this app made (`5`) from the ones it found.

Archiving into a categories side *adds* the archive category
(`Archiver::writeArchiveTaggedContact()`); replacing the contact's categories
with it made an archived contact silently leave all its groups. And
`project()` takes an ignore list, to which `Runner` passes the job's archive
category; otherwise it would manufacture a "Deleted" group and push it. The
passthrough side's archive group uses a `__archive_` UID that
`Planner::planGroups()` skips, a convention a derived UUID cannot follow.

### JobLock is a TTL, not MySQL's GET_LOCK

`GET_LOCK` *proved* liveness: MySQL drops a session lock when the connection
dies. No portable equivalent exists (Postgres differs, SQLite has none), so
exclusion is a compare-and-set on `locked_until`, refreshed by a heartbeat
between run items. That trades proof for high probability inside a bounded
window; `TTL_SECONDS` is a generous five minutes, and the cost of waiting is
one cron tick. Runner stops cleanly if it loses the lock mid-run rather than
double-dispatching.

### There are no foreign keys, so cascades are explicit

Nextcloud migrations do not declare them. `JobService::delete()` and
`StateMapper::deleteEverythingForJob()` clear state, runs, run items and
conflicts by hand. Without that a deleted job leaves orphans, and a later
job reusing the id inherits them.

### The portable upsert has a MySQL-shaped trap

`StateMapper::upsert()` is update-then-insert, because
`ON DUPLICATE KEY UPDATE` is not portable. MySQL reports *changed* rows
rather than matched ones, so "0 rows affected" may mean the row exists with
identical values, the following INSERT can legitimately collide, and a
unique-constraint violation there is the expected outcome, not an error.
Postgres and SQLite never reach that path.

It is also a *partial* upsert: only columns the caller passed are written.
The `payload_json` default exists solely to satisfy the NOT NULL column on
first insert and must stay out of the update path, or a later call omitting
it would wipe an existing group's payload.

### `IClientService` refuses local and private addresses

Unless an administrator sets `allow_local_remote_servers`. Sound SSRF
default, but it blocks a legitimate setup -- Baikal or Radicale on a NAS --
and the raw message explains nothing. `NextcloudHttpTransport` catches
`LocalServerException` and names the setting.

### Not every CardDAV server can answer a whole-collection REPORT

Mailo returns zero bytes and times out on `addressbook-query` for the full
book, while answering PROPFIND in ~1s and 50-href `addressbook-multiget` in
~3s. `Client::fetchAllVCards()` catches `DavTimeout` **specifically** and
falls back to chunked multiget. Keep that catch narrow: a 4xx/5xx is a
refusal, and retrying a refusal in 16 pieces is still a refusal.

Guzzle exposes no typed timeout, so `NextcloudHttpTransport::looksLikeTimeout()`
reads the exception message chain for curl's error 28. Misreading it in
either direction is expensive; `TimeoutDetectionTest` pins both.

### Endpoint hrefs have one spelling, and some UIDs cannot be file names

The Planner decides whether B still has a contact by looking its stored href
up in the live listing, as a string. Equivalent spellings -- `@` or `%40`, a
space raw or as `%20`, `:443` or no port -- read as "missing", and a missing
contact is re-created on every run and refused with a 412 every time.
`CardDav\Href::canonical()` is the one spelling: `Client` canonicalises
every href it hands out, `RemoteSide::hrefFor()` builds canonical ones, and
`Runner::plannerStates()` canonicalises *stored* hrefs on load, which heals
state written by older versions. `SyncSide::normalizeHref()` is identity on
the Nextcloud side, whose URIs are read back verbatim.

`hrefFor()` escapes the UID, because `#`, `?`, `/` and `../` otherwise name
some other resource -- `../` one outside the collection. Escaping is not
enough for everything: **Apache refuses an encoded slash (`%2F`) with a 404**
before the DAV server sees the request (its default, verified against
Nextcloud's own endpoint). So a UID with a slash, a backslash or a control
character, or one over 200 bytes, gets a stable derived name
(`uid-<sha1>.vcf`) instead; the UID is in the vCard, and the file name never
had to be it. Verified against the real server for slashes, `#?`, `../`,
spaces, `%`, punctuation and non-ASCII.

`FakeHttpTransport::$listDecodedHrefs` makes the fake spell its listings
differently from what was written, the way real servers may. It decodes only
what decodes without changing meaning; decoding `%2F` would be another path.

### Runner backups happen between planning and the first write

And only when the plan has work: planning writes bookkeeping only, and most
scheduled runs find nothing to do, so snapshotting every run would bury the
useful restore points under identical files. Resumed runs never snapshot.
That makes "the plan has work" load-bearing -- a plan that is never empty
snapshots on every run (see the categories-destination gotcha for one that
was).

The snapshot is always of the **Nextcloud** address book. On a pull that is
the destination, so a bad run is restorable. On a push it is the source,
which the run never writes, and the endpoint it *does* write gets no
snapshot at all -- hence the empty-source refusal below.

Restoring under a **pull** job used to be half undone: the next run
re-created the contacts the restore had deleted (the out-of-band check sees
them missing) but left the reverted ones reverted (a normal run never
compares destination content). `BackupService::restore()` reports the card
URIs it touched, and `BackupPresenter` forgets the source hash of those
contacts for every pull job on that book, so the rule is one: what the
endpoint still has, it wins; what it no longer has stays restored. For a
push job a restore is just an edit of its source, and needs nothing.

### Resumable runs execute from persisted rows, never live memory

`Runner::run()` materialises the whole plan into `run_items` *before*
processing anything. Every push after that reads only from that row, because
a resumed run is a fresh process with no memory of the original fetch. New
per-item push paths must persist what they need at materialisation time.

`Runner::archiveContact()` reads the archive group's state fresh from the
database, not from the per-run snapshot: a second archival in the same batch
must see the group the first just created. Regression:
`RunnerTest::testTwoArchivalsInOneRunShareTheArchiveGroup`.

### A resumed run starts from nothing, so its tally has to be written down

`Runner::runLocked()` builds a fresh `RunResult` for every segment of a run
that pauses on its time budget. The counters therefore ride along on the
per-item `updateRunProgress()` UPDATE, warnings and errors are written as
JSON (`saveRunReport()`) after planning and whenever an item adds one, and a
resumed segment reads all of it back before it starts. Without that, the
response, the stored run row and the history report only the last segment.
Pinned by `RunnerTest::testARunThatPausedReportsWhatEverySegmentDidNotJustTheLast`,
which splits a run into one segment per item.

A preview counts what a run would write, so into a categories destination
(every pull into Nextcloud) neither counts groups, which are not resources
there.

### Duplicate detection is manual by design

`Sync/DuplicateMatcher` hooks into the Planner's create branches only: a new
*untracked* contact matching an *untracked* one on the other side becomes a
conflict with `duplicate_json` set. The rule: an exact shared email alone,
or a matching name (structured N, else FN) plus a shared phone or email. A
same-UID copy is never a candidate (see "Every PUT is conditional").

"Email alone" excludes addresses that do not belong to one person:
`DuplicateMatcher::sharedEmails()` collects those carried by two or more
contacts *within* either book (counted per book, because a synced contact is
in both). Without that, a household's address or a company's `info@` made
every new contact behind it a duplicate of the first one synced -- and a
conflict pauses the job's scheduled runs, so a false match is expensive.

Things to know: one-way jobs fully fetch side B (the matcher needs its
N/EMAIL/TEL) but B's uids stay out of the diff; no state is seeded at
detection time, so every run re-detects a pending duplicate, and
`recordDuplicateConflict()` *refreshes* the open row's snapshots rather than
keeping the first sighting; contacts already in `contact_state` are excluded
as candidates, so a near-copy of an already-synced contact just syncs
across.

A conflict can stop standing on its own -- most often the user deletes the
duplicate they just made. Two things close such rows as `obsolete`, and both
are needed:

1. **Every full plan** (`StateMapper::closeConflictsNotIn()`, called from
   `materializeNewRun`) closes open conflicts it did not re-detect, and lifts
   a conflict pause left with nothing to wait for.
2. **`ConflictApplier` re-reads both contacts live** and closes the row
   instead of acting when either is gone or replaced. A paused job does not
   run, so (1) alone cannot help a user clicking on a stale row.

Acting on snapshots was the bug: "merge" on a conflict whose new contact had
been deleted overwrote the existing copy with it, and the next run mirrored
the deletion. What resolution writes goes through `DestinationRenderer`, the
same pipeline as a run; pushing the raw snapshot ignored the photo setting,
left iCloud photo URLs unresolved and dropped categories.
`duplicate_json.categories` carries what a run would fold in, because
resolution has no address book in hand to derive it from.

### A conflict's archive choice is a file, never a second address-book entry

`ConflictApplier::archiveContactToFile()` backs up a duplicate's existing
copy as a `.vcf` in the user's files before overwriting it. Don't write it
back into an address book: the since-removed same-UID conflict kind did that,
under a synthetic uid in an archive group, which needed a one-sided
`contact_state` row per backup so later runs would not propagate it, and put
an unreadable uid next to the live contact in the user's Contacts app.

`Runner` still writes an archive copy *into* the address book on an archive
deletion, and that one stays: it is the deletion policy, where the whole
point is that the contact remains where contacts are. Archiving before an
overwrite and archiving instead of a delete are different features that
happen to share a verb.

### A conflict resolution asks for one ETag, not the whole collection

`SyncSide::etagFor()` exists because `listEtags()` is the right call per
*run* and the wrong one per *item*: a full-collection PROPFIND per resolved
conflict takes minutes in a batch against a server like Mailo.
`Client::etagFor()` treats only 404/410 as "not there" -- reading any other
failure as absence would turn the next write into a create. Resolution GETs
the existing copy to act on live content and guards its PUT with the ETag of
that GET. `ConflictApplierTest` pins one targeted lookup per conflict.

### Why the vCard parser was not replaced with sabre/vobject

Nextcloud bundles it, and it was tempting. The entire diff rests on
byte-exact round-tripping, and vobject re-serialises on parse, which would
reintroduce exactly the phantom-update class of bug the hashing discipline
exists to prevent. The hand-rolled parser is small and pinned by the tests in
`tests/VCard`. Leave it.

### Saving an endpoint contacts the server, and must never 500

`EndpointService::create()`/`update()` verify credentials with one principal
PROPFIND before storing anything, because an endpoint saved with a typo looks
healthy in the list and then fails on a scheduled background run, where the
only trace is a log line. Three rules:

1. **Catch `\Throwable`, not `DavException`.** This is a form-save path, so
   nothing that happens while talking to a stranger's server may reach the
   user as a fault; a transport throwing something unwrapped still means
   "could not connect", which is a field error.
2. **A 401 goes on the password; anything else goes on the URL.** Telling
   someone their password is wrong when the host was unreachable sends them
   off resetting credentials that were never the problem. Use
   `DavException`'s `status` property, never the message string.
3. **Only re-verify when a connection field changed** (`base_url`, `username`,
   `password`, `preset`). Otherwise a rename cannot be saved while the far
   side happens to be down.

Pinned by `EndpointCredentialCheckTest`, including that nothing is stored when
verification fails and that a rejected new password does not replace a
working stored one.

Watch the test double: `FakeClientFactory` hands back a fresh healthy
transport for any endpoint id it doesn't know, so a test that pins specific
ids silently asserts nothing -- an endpoint is verified as id 0 before it
exists and under its real id afterwards. Pass the `$default` transport.

### Cron is not always the CLI, and three things follow from that

`SyncTimedJob` is a Nextcloud `TimedJob`, and Nextcloud runs those very
differently depending on how `cron.php` was invoked. None of these logs
anything against this app:

1. **Webcron executes exactly one job per request.** `CronService::runWeb()`
   calls `getNext()` once and stops; only `runCli()` loops for fourteen
   minutes. The queue is ordered by `last_checked` ascending, so a backlog of
   always-due `QueuedJob` rows (a classic: `UpdateSingleMetadata` piling up
   for a deleted user) sits in front of everything forever and this app never
   gets a turn. Diagnose with `occ background-job:list -c '...SyncTimedJob'`:
   a `Last run` that never advances means the job is not being reached.

2. **The web path registers no shutdown handler**, so a tick the reverse
   proxy cuts off leaves `reserved_at` set and `getNext()` skips the job for
   **twelve hours**. So `Sync\CronBudget` gives a webcron tick the same 120s
   ceiling a web-triggered run gets instead of the CLI's 600s, and caps each
   job by what is left of the tick.

3. **`TIME_INSENSITIVE` means "once a night", not "whenever convenient".**
   With `maintenance_window_start` set, `runCli()` asks only for time
   sensitive jobs outside that four hour window. A sync job honouring a
   schedule the user picked must not opt into that, so the flag is gone.

The sting in 3 is that `oc_jobs.time_sensitive` is written **once and never
written back**: `JobList::setLastRun()` only ever moves it towards
insensitive. So `Migration\Version000104...` is a migration with no schema
change, whose entire job is to repair `time_sensitive`, `reserved_at` and
`last_checked` on that one row. Watch the constant values:
`IJob::TIME_SENSITIVE` is **1** and `TIME_INSENSITIVE` is **0**, the opposite
of what the names suggest, and the column defaults to 1.

### Routes are cached, so a new route 404s until something clears it

Nextcloud caches the route collection, so a brand-new entry in
`appinfo/routes.php` answers OCS 998 ("Invalid query... check the syntax")
while every existing route keeps working -- which reads like a mistake in the
new route and is not one. `./dev/occ maintenance:repair` does **not** clear
it; `docker compose restart app` does. On a real install: bump `<version>`
in `info.xml`, or restart PHP-FPM.

### A rebuilt bundle does not reach an already-open browser

Nothing looks wrong: `./dev/npm run build` writes the new
`js/contacthub-main.mjs`, the container serves the new bytes, and the page
goes on running the old ones. Nextcloud appends `?v=<hash of the app
version>` to the script URL and serves it `max-age=15778463, immutable`, so
the browser will not revalidate that URL for six months. A hard reload does
not help, nor does `fetch(url, {cache: 'reload'})`, which only proves the
server has the new file. Recognise it by the symptom: the new string is in
`js/` and in the container, and absent from the page.

Only a changed app version gives the script a fresh URL, which is what a real
user gets on upgrade. To see a UI change during development: a private
window, a different browser, or bump `<version>`, run `./dev/occ upgrade`,
and put it back afterwards (also reset `config:app:set contacthub
installed_version`, since occ will not downgrade). When checking, reload the
document itself: navigating to the same URL with only a different
`#fragment` is not a reload, and makes a working version bump look failed.

`SnapshotsView` restores against whatever book is selected, so never test it
on a book holding data you care about. Create a throwaway book for the test
and delete it after.

### Anything a user should keep goes in their Files, not IAppData

`Files\HubFolder` owns the "Contacts Hub" folder in each user's home:
`Snapshots/`, `Settings/` (configuration exports) and `Archived contacts/`.
IAppData is invisible -- outside every home, unreachable from the Files app,
undownloadable without a bespoke route -- which is right for a cache and
wrong for a backup. Consequences:

* **The user can delete or edit these files.** A missing snapshot is an
  ordinary outcome, not a broken invariant; `read()` says so in the error.
* **Quota applies.** A snapshot that cannot be written fails the run
  deliberately: `beforeSync()` runs between planning and the first write
  precisely so there is a way back, and syncing anyway would silently remove
  it.
* **Snapshots taken before the move stay in IAppData** and are still
  restorable: `BackupService::read()` falls back to the old folder. They age
  out through normal retention, and nothing new is written there.
* **Anything touching Files needs a real Nextcloud user**, unlike the rest of
  the suite, which gets by on a synthetic principal string.
  `IntegrationTestCase::ensureRealUser()` creates one on demand.

### Everything a run reports must also reach the Nextcloud log

`Runner::warn()` and `Runner::fail()` append to the `RunResult` *and* log,
and every append goes through them. A run report is transient -- a paused
run's report is replaced when the next segment starts, and nobody watches a
scheduled run's report at all -- so anything landing only there is gone a
minute later. (`preparePhoto()` used to bypass this by taking the warnings
array by reference; it takes the `RunResult` now.)

**A warning has to be bounded by something that does not scale with the
address book.** Every warning is both a log line and a note card, so one per
contact turns a routine sync into hundreds of log lines several times an
hour; the dangling-member check in `Model::buildAddressBook()` once did
exactly that. Aggregate per group or per reason before it ships.

A warning also has to say **which address book it is about**: both sides of
a job can hold groups, and a UID alone does not say where to look.
`fetchBothSides()` passes `$side->label()` down for this. In the browser,
`RunPanel` carries warnings and errors across auto-resumed segments rather
than letting each fresh result replace the last.

### Secrets may not be public properties, because logging walks objects

Nextcloud's `ExceptionSerializer::encodeArg()` expands every object in every
frame of a serialized stack trace using `get_object_vars()` called from
outside the class -- so public properties, and only those. `Sync\Endpoint`'s
public `$password` was therefore written to `nextcloud.log` in clear text
whenever a run threw, once per frame holding an Endpoint or a SyncJob.

It is private with a `password()` accessor now, plus `__debugInfo()` for the
`var_dump` path, and `EndpointSecrecyTest` pins the mechanism rather than the
style. Anything a server would not want written down goes the same way.

### The archive group is the one resource a run writes more than once

Every other write in a run owns its href and happens once, so the per-run
ETag snapshot taken at fetch time is still live when the write happens. The
archive group is not: every archival rewrites that single vCard, so the
first PUT invalidates the ETag the second one was holding, and a server
enforcing If-Match (sabre, so Infomaniak and iCloud) answers 412.

`Archiver::addToArchiveGroup()` therefore guards its write with the ETag from
its *own* GET a line earlier, and takes no ETag from its caller -- the window
If-Match has to close is between that GET and that PUT. Pinned by
`RunnerTest::testTwoArchivalsIntoAnAlreadyExistingArchiveGroup`; its older
sibling passes either way, because a group *created* in the same run was
never in the snapshot.

### An inlined photo needs a media type or the avatar comes out blank

`Transform::resolvePhotoUri()` turns a `PHOTO;VALUE=uri` reference (how
iCloud serves photos) into inline base64, losing the media type the HTTP
response had. Nextcloud's `PhotoCache::getBinaryType()` reads `TYPE` or
`MEDIATYPE` off a binary PHOTO and returns `''` without either, so the avatar
is served as `application/octet-stream` and renders blank although the photo
is intact.

The type is sniffed from the bytes and written as `TYPE=JPEG` on a 3.0 card
and `MEDIATYPE=image/jpeg` on a 4.0 one; an unrecognised format is left
unlabelled rather than guessed at. **Write the `\xFF` escapes as escapes**: a
scripted edit that turns them into literal high bytes still parses and lints,
and silently never matches a JPEG or PNG while `GIF89a` and `RIFF` go on
passing.

### A failure the user cannot see is the same as no failure

Two independent bugs made every error invisible in the browser; each hides
the other, so both must hold:

1. **`@nextcloud/dialogs` needs its stylesheet imported.** Without
   `@nextcloud/dialogs/style.css`, `showError()` renders as nothing. It is
   imported in `src/main.js`, and `createAppConfig`'s `inlineCSS` folds it
   into the bundle. Check it survived a build with
   `grep -c _toastContainer js/*.mjs`: one file reporting 2 means the rule is
   in there, 1 means only the class reference is. Since the settings page
   became a second entry, that file is the shared `*.chunk.mjs`.

2. **Every action goes through `ApiController::respond()`**, which catches
   `\Throwable` too, logs it with the exception, and returns the raw message
   with a 500. Anything that bypasses it (as `JobController::run()` once did)
   lets a run failing the way runs actually fail -- a 401, an unreachable
   host -- reach the browser as an OCS envelope with no message, leaving
   `api.js` nothing to show.

The message is deliberately not rewritten for the user: a curated message
about a server this app knows nothing about would be a guess. `RunPanel`
keeps it on screen in a note card rather than only in a toast, because the
cause is usually a setting the user has to go and change elsewhere.

### Force means re-compare, not assume-everything-changed

A forced run does the ordinary A-side comparison, plus the check a one-way
run skips: whether B's copy still matches what this app recorded writing
there. Normal runs see only that B's resource exists (`liveBHrefs`), so an
edit made directly on the endpoint is invisible until someone forces a run.
Never short-circuit the comparisons on `$in->force`: that once made a forced
no-op run rewrite every contact.

`RemoteSide::putVCard()` records the hash of the text it *sent*, on the
assumption that a CardDAV server stores bytes verbatim -- true for sabre, not
for everyone. A server that rewrites what it stores (re-encoded photos,
typically) therefore drifts on every forced run forever.
`Plan::$contactsDriftedOnB` lets `Runner::reportEndpointDrift()` say so in one
line, with how many have photos. It is diagnostic only: always a subset of
`contactsUpdateAToB`, so it changes nothing about `isEmpty()` or what the run
does. The README documents this as user-facing behaviour.

### The run panel must not depend on a view's local state

`JobsView` used to keep the running job in a local `ref`, so changing tab and
coming back showed an idle screen with a run still open. The jobs listing
carries `open_run`, so the screen reopens it on mount, and `RunPanel` adopts
it with the same visible countdown a paused segment gets.
`StateMapper::openRunsFor()` is the batched form of `findOpenRun()` and must
keep agreeing with it exactly -- same dry-run and status filter, same
newest-first tie-break -- or the screen reopens a run the resume path does
not recognise. Pinned by asserting both against each other.

`RunPanel` is keyed by job id; reused across jobs, it showed one job's
result, warnings and failure under another's heading.

### A card with no UID is a card, not an error -- and check it really has none

UID is optional in vCard (RFC 6350 section 6.7.6). **Before assuming a server
has no identity for a card, ask it for that card with GET.** Mailo's
whole-collection `addressbook-query` REPORT leaves UID out of every contact
(groups keep theirs) while GET and `addressbook-multiget` return it; it was
first misdiagnosed as a server that keeps no UIDs. `Client::completeCards()`
re-fetches by multiget any card the REPORT delivered without a UID; a
well-behaved server never triggers it. Pinned by `ClientTest` and
`RunnerTest::testAServerWhoseFullAnswerOmitsUidsStillMatches...`, with
`FakeHttpTransport::$reportOmitsUid`.

For a card that really has none, three rules:

1. **Identity comes from where the server keeps the card.**
   `Model::derivedUid($href)` is a UUIDv5 of the canonical href, passed to
   `parse()` as a fallback and flagged `uidDerived` on the result. It is never
   written into a card on its own side. State rows already record the endpoint
   href, so once a card is linked its identity survives edits to name, email
   and phone. `buildAddressBook()` and `Runner::hrefMap()` both take the hrefs
   for this, and must derive alike.
2. **A source card that has no UID leaves with one.** `pushContactOne()` and
   `pushGroupOne()` add the derived UID to the copy they write, or the next
   run would read it back as a different contact. The endpoint's own card is
   never touched.
3. **One fuzzy match is trusted without asking, and only that one.**
   `DuplicateMatcher::findIdentityMatches()` is rule two of `findMatches()` --
   the same name *and* a shared email or phone, never an address alone --
   restricted to destination cards with no UID. `Planner::identityMatches()`
   accepts a match only when it is one to one; anything else is left to the
   ordinary duplicate conflict. A match is planned as a create, carried in
   `Plan::$contactsAdoptAToB`, and turned into an in-place update by
   `adoptExistingOnB()`. The matched card is **overwritten** by the hub's
   version, with no endpoint snapshot. Cards with a UID of their own still go
   through a conflict.

`buildAddressBook()` aggregates what it still cannot read into one warning
per reason with a count.

### A copy that differs only in its modification date is linked, not written

`Runner::identicalOnB()` compares what a run *would write* (the source card
rendered for the destination) with B's card, ignoring `REV` and line endings
(`Model::contentHashIgnoringRev()`), and links the equal ones with both
sides' hashes and no write -- rather than adopting with a write, on a server
no snapshot covers, that only changes a date. The preview counts only what a
run would write.

Deliberately narrow: destinations that keep groups as cards only, never a
card whose photo is a URL (rendering that needs a fetch), never a card that
had no UID of its own (writing the UID is the point). Anything it cannot
decide is an ordinary update. Servers that rewrite what they store never
match (Mailo drops `PRODID`, relabels `TEL`/`EMAIL`/`ADR` types, re-encodes
photos and adds `X-EA-GROUPS`); comparing by meaning would catch them, and
was left out deliberately as a fuzzier rule that would hide label differences
on first link.

**It is for first sight only.** Every stored hash is `Model::textHash()` over
the raw text, `REV` included. Ignoring `REV` in the ordinary change check
would change what every stored hash means, and every tracked contact would
look edited at once, so every job would push its whole book. If REV-only
changes ever need ignoring there, it needs a second stored hash and a
migration.

### A short fetch is indistinguishable from a smaller address book

`Client::collectAddressData()` skips any multistatus entry carrying no
`address-data` -- a resource the server refused, or a response it truncated.
The **plan** reads presence from `listEtags()`, a separate and complete
listing, so it stays correct; **everything else** reads the fetched book, so
a missing contact looks deleted -- on a `from_endpoint` job, a deletion
nobody made, mirrored into the side that still has the contact.

`Runner::assertWholeBookFetched()` counts what came back against that
listing and **throws** rather than warning; the listing is fetched anyway.
`FakeHttpTransport::$dropFromAddressData` reproduces the shape: a valid 207
that is simply missing some entries.

### Out-of-band reconciliation: both planners, both shapes

When content on A is unchanged but B has no live copy, the planner re-pushes.
"No live copy" has two shapes and both count: a stored href gone from the
listing, and a stored href that is null because the contact never landed on
B. `Planner::planContacts()` once skipped the null case, and such a contact
sat absent from B forever while every plan reported no work -- which is
exactly what being in sync looks like.

`planGroups()` has the same check; it was missing, so emptying a push
destination brought every contact back on the next run and no groups. Pinned
by `RunnerTest::testWipingThePushDestinationRebuildsContactsAndGroups`. The
two planners are near-mirror images and drift apart quietly, because a group
bug shows up as *absence*. When adding a reconciliation to one, check
whether the other wants it. (A categories destination is the exception; see
below.)

### A group's member list is not validated by anything, so don't copy it blind

A group vCard is a list of contact UIDs, and nothing enforces that those
contacts exist; real address books accumulate references to contacts
deleted years ago. `Runner::withResolvableMembers()` drops them from **both
books, right after they are built** -- resolving against both, since a
member may live on either side -- and that placement is the whole
correctness of it. Filtering only the outgoing text left the Planner diffing
the unfiltered book, so every group with a stale reference hashed differently
from the copy just written and was re-pushed on every run, forever. Plan,
hash, snapshot and push have to see one version of a group.

A group whose members all resolve is passed through as the identical object,
so untouched groups keep their exact bytes. A group pushed before the filter
existed differs once, is pushed once, and settles, which is what cleans up
references already on an endpoint.

### Conflict notifications: no stored preference means "on, to the profile"

A scheduled run that leaves unresolved conflicts pauses its job
(`paused_for_conflicts`, separate from the user-owned `enabled`) and queues
`SendConflictPauseNotification`, a one-shot `QueuedJob`. SMTP has no timeout
this app controls, so sending inline from the cron tick would let one slow
mail server stall every other due job; see that class's docblock.

Where the mail goes is `NotificationPreferences`, stored per user in
`IUserConfig` (`notify_mode`, `notify_email`), edited in Personal settings →
Contacts Hub. The rules that make "on by default as soon as the profile has
an address" true:

* **Absence of a row is the profile mode.** Nothing is written on first
  read, so a user who adds a profile address later starts getting mail
  without revisiting this app.
* **An unrecognised stored mode falls back to profile, not off.** Silently
  dropping notifications is the worse failure.
* **A custom address is validated whenever it is non-empty**, not only
  while selected, and is kept when the user switches to off; otherwise a
  stored-but-invalid address would become the recipient the moment someone
  picked "custom" again.
* **Opted out is silent; opted in with no usable address logs a warning.**

`NotificationService::mailStatus()` is a guess, because `IMailer` has no
"is mail configured" query. It mirrors core's `EmailTestSuccessful` setup
check exactly: an empty `core.emailTestSuccessful` counts as configured when
`mail_domain` is set, because mail configured through occ never runs the
admin test. `last_mail_failure_at`, set by a failed send and cleared by the
next good one, keeps it honest.

### Two bundles share a chunk, so the package ships all of `js/`

The Personal settings form is a second Vite entry (`src/settings.js` →
`js/contacthub-settings.mjs`). Rollup moves what the two entries share (most
of `@nextcloud/vue`) into a hashed `*.chunk.mjs` that both import at runtime,
so `./dev/package` copies every `js/*.mjs` and its `.license`; copying only
the entry point gives a package that installs cleanly and loads neither page.
Vite empties `js/` on each build, so no stale chunks pile up.

### CardDavBackend enforces no permissions, so this app has to

Nextcloud checks share permissions in the DAV layer *above*
`CardDavBackend`; the backend writes to any book id it is handed. Everything
here goes straight to the backend, so `AddressBookService` makes the checks
DAV would have made:

* **Read-only shares.** A read-only share is in the user's book list, so
  being listed is not permission to write. `requireWritable()` reads
  `{owncloud}read-only`, and everything that writes to a book uses it: job
  validation, every run (`SideFactory`), snapshot restore.
* **Ownership** comes from `{owncloud}owner-principal`. Nextcloud rewrites
  `principaluri` to the sharee for a shared book, so comparing that calls
  every shared book "owned".
* **Access is re-checked on every run**, in `SideFactory::forJob()`, which
  every run, preview and conflict resolution goes through. Checked only at
  save time, a revoked share kept syncing, and a **deleted** book read as an
  empty one -- `getCards()` on a dead id returns nothing -- so a push job
  deleted everything on the endpoint. The refusal is `HubUnavailable`,
  recorded as a failed run so it reaches the history and not only the log.

### A source that reads as empty is refused, not mirrored

`Runner::emptySourceRefusal()`: a plan whose side A has **no contacts at
all** but would remove tracked contacts from B throws before anything is
recorded (a preview warns instead). A book that empties all at once has
been deleted, unshared, swapped or answered for by a misbehaving server far
more often than really emptied, and on a push nothing snapshots the
destination. Deliberately narrow -- empty only, no percentage threshold,
since partial deletions are ordinary and a threshold would be a guess. The
price is that deleting the last contact of a book does not propagate; the
message says what to do. Tests that delete a book's only contact need a
second "keeper" contact for this reason.

### Sync state is bound to a location, so moving a job resets it

State rows record where each contact lives; endpoint hrefs are absolute
URLs. Kept across a move -- a job to another book or endpoint, an endpoint
to another collection, server or account -- they steer the next run's writes
and deletions at the *old* location, carrying the *new* endpoint's
credentials to the old server.

`Service\SyncStateReset` applies such a change and resets the affected
jobs under their job locks (refusing the save while one is mid-run): open
runs closed, conflicts closed as obsolete, contact and group state dropped,
conflict pause lifted. The next run is a first run, which deletes nothing
and adopts same-UID copies. A **direction** change is not a move: both
locations stay valid, and role-named state exists precisely to survive it.

`EndpointService::update()` also clears `collection_href` when the server
or username changes, forcing a new capability test. Defence in depth for
state written before any of this: `SyncSide::owns()` says whether an href is
a location on that side, `Runner` treats a foreign one as "no copy here",
and `RemoteSide` refuses any put/get/delete/PROPFIND outside its collection.
Photo fetches are exempt on purpose -- iCloud serves them from another host,
authenticated with these same credentials.

### A categories destination has no group resources, and that changes three things

When B keeps groups as CATEGORIES (every pull into Nextcloud),
`PlanInput::$bGroupsAsCategories` is set and:

1. **Group state is still recorded**, with no `b_href`. Without a row every
   group was planned as a create on every run, so every run had work and
   took a snapshot.
2. **The out-of-band check is skipped** for groups -- a group there never
   has a resource, so "missing" would re-plan all of them forever -- and so
   is the name-collision warning, which compared against B's projected
   categories and fired on every run.
3. **A group change on A re-pushes its members.** Adding someone to a group
   on iCloud edits only the group vCard, so the contact itself never
   re-synced. `Planner::planCategoryMembership()` re-pushes members old and
   new, but only those whose live categories on B differ from what A implies,
   which keeps a first sighting free.

### Derived groups are recognised by their UID, because they have no href

A group projected from Nextcloud categories has no resource on A, so its
`a_href` is null and "gone from A" (`aHadIt`) never fired: a removed or
renamed category left its group on the endpoint for ever.
`Planner::isDerivedCategoryGroup()` recognises the row by its UID being
`uidFor()` of the stored name. Not "any row with a `b_href`": after a
direction flip, rows for the endpoint's *own* groups have one too, and that
test would delete them.

### Migrations cannot type-hint a schema class

`ISchemaWrapper::createTable()`/`getTable()` return Doctrine's `Table` on
Nextcloud 34 and an `OC\DB\Schema\Table` wrapper (implementing a new
`OCP\DB\Schema\ITable`) on 35, and 34 has no `ITable` at all. So a helper
that takes a table names neither class and takes `object`. Naming Doctrine's
made the app impossible to install on 35, and only a fresh install shows it,
because an instance upgraded from 34 has already run its migrations. A new
major is only "supported" after a fresh install, an upgrade from the previous
major, and the integration suite on both.

### Syntax traps that cost real time

* **XML comments may not contain `--`.** The house style of using `--` as an
  em dash made `info.xml` unparseable, and Nextcloud reports that only as
  "appinfo file cannot be read", which sounds like a permissions problem. A
  `<background-jobs>` or `<settings>` entry naming a class that does not
  exist breaks enabling the same way.
* **PHP docblocks may not contain `*/`.** Writing `hub_*/endpoint_*` closed a
  comment early and surfaced as a `ParseError` inside a DI stack trace.
* **`@nextcloud/vue` 9 is Vue 3.** `:value.sync` is Vue 2 and binds nothing;
  fields use `v-model`. Vue 3 treats the modifier as part of an ordinary
  attribute name, so it fails **silently**.
* **Pin TypeScript to `^5`.** `@nextcloud/vite-config` pulls in
  vite-plugin-dts; TypeScript 7's rewritten API breaks `@volar/typescript`
  and the build dies on `useCaseSensitiveFileNames`.
* **Vite's `publicDir`** defaults to `<root>/public` and copies it into
  `outDir`. Here both are the app directory, so it dumped a copied tree into
  the repository root. `vite.config.js` forces `publicDir: false`.
* **`NcAppNavigationItem` renders an anchor.** Its default navigation fires
  *after* a click handler, so handling clicks to set the hash does not work;
  give it a real `href`.

## Known scope boundaries

Flag before starting any of these; each is a design change, not a patch.

* **`group_strategy: collections`** (one real collection per group) is probed
  by the capability tester but **not implemented for live sync** --
  `Runner::pushGroupOne()` warns and skips. It needs per-(job, uid,
  collection) multi-location state, which the per-side single-href schema
  does not support.
* **Group identity follows the category name** -- renames are
  delete-plus-create, and a name can exist twice on an endpoint (see
  CategoryGroups). Fixing it needs a name-to-UID mapping table.
* **Endpoints are never backed up.** `Sync\EndpointBackup` is left over from
  the standalone app and no route reaches it. A pre-push snapshot of the
  endpoint, and a way to restore it, is a feature to design, not a route to
  add.
* **Mailo's preset is user-reported**, not verified against a live account by
  this project (unlike iCloud and Infomaniak). Don't upgrade that framing
  without someone actually testing it.
* **Only HTTP Basic authentication exists**, which rules out Google Contacts:
  its CardDAV interface requires OAuth 2.0 and answers Basic with an
  unconditional 401. The `google` preset is listed with an
  `unsupportedReason`, which `EndpointService::validate()` refuses on -- a
  preset nobody can save, so users see it was not forgotten. OAuth needs
  client registration, the authorisation-code flow, refresh-token storage
  next to the encrypted password, and a Bearer path in the transport.
* **Address book snapshots are synchronous and one-shot**, not chunked like
  sync runs. A very large book over a slow connection could hit an execution
  ceiling.
* **No admin settings UI.** Three values are read from `IAppConfig` with
  defaults and no way to change them but `occ config:app:set`:
  `http_timeout_seconds` (30), `backup_retention_days` (30) and
  `web_time_budget_seconds` (20, clamped to 5-120).
* **Not published to the app store yet.** That needs signing, screenshots and
  a decision about `CardDavBackend` being an internal DAV-app class. Its use
  is confined to `AddressBookService`, `NextcloudSide`, `SideFactory` and
  `BackupService`, so the blast radius of a future change is four files.

## Git hygiene

* Commit messages go long and explain *why*, including bugs found and how
  they were caught. That has been genuinely useful for reconstructing
  context across sessions -- keep it.
