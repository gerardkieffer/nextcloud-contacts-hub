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

It began as a Python CLI (`python-baseline` branch), became a standalone PHP
app with its own address book storage, auth, router and photo store
(`php-rewrite` branch), and is now a Nextcloud app. All active work happens
on **`main`**. The other two branches are historical baselines; don't merge
between them unless asked.

That branch was called `nextcloud-app` until 2026-09-11, while `main` still
pointed at the Python baseline's single commit. Renaming cost nothing --
that commit is an ancestor of this branch anyway, so it was a label, not a
line of development -- and it stopped `main` meaning "the oldest thing here"
in a repository where every other tool assumes it means "the current thing".
Older commit messages and notes still say `nextcloud-app`; they mean this
branch.

The migration deleted ~2,600 lines that existed only because there was no
platform underneath: `Auth/`, `Http/`, `View/`, `Support/`, `Hub/`, the
CSRF/headers code, the PDO wrapper, the SQL migrator and the hand-rolled
autoloader. If you find yourself re-adding any of that, stop and check
whether Nextcloud already provides it.

### Still zero runtime dependencies

There is no Composer autoloader. Nextcloud resolves `OCA\ContactHub\Foo\Bar`
to `lib/Foo/Bar.php` by convention, and Composer stays a development-only
tool for PHPUnit. The vCard parser and CardDAV client are still hand-rolled
(see the gotchas for why the parser was not replaced with sabre/vobject).
npm exists only to build the frontend, and its output is committed.

## Where things are

```
appinfo/
  info.xml            app metadata, background job, navigation
  routes.php          SPA shell + 28 OCS actions
lib/
  AppInfo/Application.php    IBootstrap; the three registrations that
                             cannot be autowired
  Controller/         thin HTTP layer over Service/; ApiController maps
                      service exceptions onto status codes
  Files/              HubFolder: the "Contacts Hub" folder in a user's own
                      files, where snapshots and settings exports live
  Service/            validation and orchestration
                      AddressBookService  the ONLY doorway to CardDavBackend
                                          besides NextcloudSide
                      EndpointService     CRUD + the capability-test flow
                      JobService, RunService, ConflictService, BackupPresenter
                      SettingsTransfer    export/import of endpoints and jobs
  Db/                 hand-written mappers over IQueryBuilder (not QBMapper
                      -- see gotchas). Every query filters by user_id.
  Migration/          one IMigrationStep; portable across MySQL/PG/SQLite
  BackgroundJob/      SyncTimedJob
  Listener/           UserDeletedListener
  Sync/               the engine: Planner (diff), Runner (execute,
                      resumable), SideMap, SideFactory, NextcloudSide,
                      RemoteSide, CategoryGroups, ConflictApplier,
                      Archiver, DuplicateMatcher, JobValidator, JobLock,
                      BackupService, EndpointBackup
  VCard/              hand-rolled RFC 6350: LineCodec (fold/unfold),
                      PropertyLine, Document, Model (parse), Transform
  CardDav/            Client (discovery + CRUD), DavXml, AbstractTransport,
                      NextcloudHttpTransport (over IClientService)
  Presets/            Preset + Registry (icloud/infomaniak/mailo/google/generic)
  CapabilityTest/     Tester (discover / writeProbe / mkcolProbe)
src/                  Vue 3 SPA (App, views/, components/, api.js, store.js)
js/                   BUILT output; committed, because Nextcloud installs
                      apps as plain files with no build step on the server
templates/main.php    SPA mount point
tests/                unit (no DB, no Nextcloud) + Integration/ (both)
dev/                  docker-compose.yml and the php/npm/occ/lint/
                      phpunit-integration wrappers
docs/                 install.md, presets.md, server-capabilities.md, timeouts.md
```

## Running things

Nothing is installed on the host, deliberately, even though a given dev
machine may well have Node and PHP available locally (an Apple Silicon
Homebrew ships PHP as a bottle; Intel Homebrew no longer bottles it and
compiles from source instead, an hour or more). This project is developed
from more than one Mac -- Intel and Apple Silicon -- and whichever binaries
Homebrew happens to have on a given machine can drift in version between
them. Docker keeps the toolchain identical everywhere: same PHP 8.3, same
Node 22, same MariaDB, regardless of which machine or architecture is
running it. Everything runs in containers instead:

```bash
cd dev && docker compose up -d     # throwaway Nextcloud 34 on :8080
./dev/occ app:enable contacthub    # admin/admin
./dev/npm install && ./dev/npm run build
./dev/lint                         # php -l over lib/ and tests/, one container
./dev/php tools/phpunit.phar       # unit suite, no DB, no Nextcloud
./dev/phpunit-integration          # inside the container, real CardDavBackend
./dev/package                      # dist/contacthub-<version>.tar.gz to install
```

`./dev/package` is the only one of those that needs nothing installed at all.
It names the tarball from `<version>` in `info.xml` and refuses to build over
a `js/` bundle older than anything in `src/`, because a package built from a
stale bundle installs cleanly and then serves the old UI. `docs/install.md` is
the procedure it feeds.

PHPUnit is not committed; fetch it once per environment:

```bash
mkdir -p tools && curl -sL https://phar.phpunit.de/phpunit-11.phar -o tools/phpunit.phar
```

The repository root is bind-mounted into `custom_apps/contacthub`, so host
edits are live. `docker compose down -v` throws the instance away.

## Testing

Two suites, and the split is deliberate.

**Unit (`phpunit.xml`, ~145 tests)** needs no database and no Nextcloud, and
runs in well under a second. It covers the vCard layer, the Planner,
SideMap, CategoryGroups, presets, conflict resolution, duplicate matching
and the CardDAV client against a fake transport. Keep new logic testable
here where you can; it is the loop you will actually run.

**Integration (`phpunit.integration.xml`, ~82 tests)** boots Nextcloud
itself (`require lib/base.php`, as occ does) because the official Docker
image ships no `tests/` directory, so the server's `\Test\TestCase` does not
exist. It drives the real Runner, mappers and `CardDavBackend`, with the
endpoint faked at the HTTP transport. These do **not** skip themselves when
the environment is missing -- a silent skip is how a green run comes to mean
nothing.

`IntegrationTestCase` gives each test a fresh address book and wipes only
the test user's rows. That scoping matters: it used to clear the tables
wholesale and silently deleted data someone had set up by hand in the same
instance.

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

### Nextcloud rewrites card data on read

`CardDavBackend::getCards()`/`getCard()` pass stored bytes through a private
`readBlob()`, which strips PHOTO properties carrying **non-image** `data:`
URIs and rejoins lines with CRLF. Bytes out are not always bytes in.

The whole diff survives that on two rules, both load-bearing:

1. `NextcloudSide::putVCard()` **re-reads the card after writing** and
   returns what Nextcloud actually stored, never the input. Hashing the
   input instead makes every affected contact look modified on every run and
   push forever.
2. All hashing goes through `Model::textHash()`, which normalises line
   endings first. `readBlob()` is deterministic, so the hash is stable run
   over run even though it differs from the hash of the uploaded bytes.

Pinned by `NextcloudSideTest`, including a 200 KB photo and the stripped
case. A visible consequence: a contact arriving with a non-image `data:`
PHOTO loses it on the way in, looks changed once, then settles.

### An empty `PHOTO;VALUE=URI` must be skipped, not fetched

Real address books contain them -- Nextcloud's own example contact ships two
alongside a perfectly good inline PNG. `Transform::resolvePhotoUri()` skips
empty values, because fetching `""` cannot succeed and **one failed
reference aborts the whole transform**, so the contact loses the real photo
it did have. This shipped as a live bug; regression tests in `TransformTest`.

### `SyncSide::putVCard()` returns the text the side actually stored

Not the text it was handed. `Runner` records a hash of the *returned* text.
Any new write path must do the same.

### Roles, not A/B, in anything persisted

The Planner only ever pushes A -> B. Rather than teach it a reversed mode,
a `from_endpoint` job puts the endpoint in slot A:

    to_endpoint    A = hub       B = endpoint
    from_endpoint  A = endpoint  B = hub

`Sync\SideMap` is the only place that translation lives. State columns,
conflict snapshots and conflict *resolutions* are all named for roles
(`hub_hash`, `endpoint_snapshot`, resolution `'hub'`/`'endpoint'`). Storing
raw a/b would invert the meaning of every existing row the moment someone
edited a job's direction, and a button reading "keep A's version" would mean
opposite things on two otherwise identical jobs. "hub" now means the
Nextcloud address book, which is what it always meant. Coverage: `SideMapTest`.

### A REPORT round trip normalises CRLF to LF; a GET does not

The vCard travels inside a `<card:address-data>` element, and XML
line-ending handling (mandatory per spec, not a quirk) turns every `\r\n`
into `\n`. Everything destined for state goes through `Model::textHash()`;
**never** call `hash('sha256', ...)` on vCard text directly. This bit three
separate times.

### Never substring-match raw vCard text

Long lines (a member UID, a base64 photo, a CATEGORIES list) get RFC-folded,
which splits values across fold boundaries. Parse (`Model::parse`,
`Document::parse`) and compare structured fields. This shipped as a real bug
once in `Tester::writeProbe()`, and was then made *again* while writing the
hub repository tests.

### Every PUT is conditional

`Client::putVCard()` sends `If-Match` with an etag and `If-None-Match: *`
without one. There is no unconditional-overwrite PUT. A caller meaning
"overwrite whatever is there" must fetch the live etag first. `NextcloudSide`
enforces the same semantics against Nextcloud's etag.

### CategoryGroups: the archive category is not a group

Nextcloud models groups as `CATEGORIES`, so `NextcloudSide` reports the
`categories` strategy and `Sync\CategoryGroups` projects those into `Group`
objects for passthrough endpoints. Derived UIDs are UUIDv5 of the normalised
name, so they are stable across runs without a mapping table. Renaming a
category therefore reads as delete-plus-create on the far side.

`project()` takes an ignore list and `Runner` passes the job's archive
category. Without it, projecting the archive category would manufacture a
"Deleted" group and push it to the other side. The passthrough side never
had this problem because its archive group uses a `__archive_` UID that
`Planner::planGroups()` already skips, and a derived UUID cannot use that
convention.

### JobLock is a TTL, not MySQL's GET_LOCK

`GET_LOCK` *proved* liveness: MySQL drops a session lock when the connection
dies, so a `running` row whose lock was free provably belonged to a dead
process. No portable equivalent exists (Postgres differs, SQLite has none),
so exclusion is a compare-and-set on `locked_until`, refreshed by a
heartbeat between run items. That trades proof for high probability inside a
bounded window; `TTL_SECONDS` is a generous five minutes rather than tuned
tight, and the cost of waiting is one cron tick. Runner stops cleanly if it
loses the lock mid-run rather than double-dispatching.

### There are no foreign keys, so cascades are explicit

Nextcloud migrations do not declare them. `JobService::delete()` and
`StateMapper::deleteEverythingForJob()` clear state, runs, run items and
conflicts by hand. Without that a deleted job leaves orphans nothing cleans
up, and a later job reusing the id inherits them.

### The portable upsert has a MySQL-shaped trap

`StateMapper::upsert()` is update-then-insert, because
`ON DUPLICATE KEY UPDATE` is not portable. "0 rows affected" from the UPDATE
has two meanings on MySQL, which reports *changed* rows rather than matched
ones: the row may exist with identical values. So the following INSERT can
legitimately collide, and a unique-constraint violation there is the
expected outcome, not an error. Postgres and SQLite never reach that path.

It is also a *partial* upsert: only columns the caller passed are written.
The `payload_json` default exists solely to satisfy the NOT NULL column on
first insert and must stay out of the update path, or a later call omitting
it would wipe an existing group's payload.

### `IClientService` refuses local and private addresses

Unless an administrator sets `allow_local_remote_servers`. Sound SSRF
default, but it blocks a legitimate setup -- Baikal or Radicale on a NAS --
and the raw message explains nothing. `NextcloudHttpTransport` catches
`LocalServerException` and names the setting. The curl client this replaced
had no such restriction, so this is a behavioural change from the migration.

### Not every CardDAV server can answer a whole-collection REPORT

Mailo returns zero bytes and times out on `addressbook-query` for the full
book, while answering PROPFIND in ~1s and 50-href `addressbook-multiget` in
~3s. `Client::fetchAllVCards()` catches `DavTimeout` **specifically** and
falls back to chunked multiget. Keep that catch narrow: a 4xx/5xx is a
refusal, and retrying a refusal in 16 pieces is still a refusal.

Guzzle exposes no typed timeout, so `NextcloudHttpTransport::looksLikeTimeout()`
reads the exception message chain for curl's error 28. Misreading it in
either direction is expensive; `TimeoutDetectionTest` pins both.

### Runner backups happen between planning and the first write

And only when the plan has work. Planning writes bookkeeping only, so
nothing restorable has changed yet. The empty-plan skip matters: most
scheduled runs find nothing to do, and a half-hourly cron over 30 days would
otherwise bury the useful restore points under ~1400 identical files.
Resumed runs never snapshot.

### Resumable runs execute from persisted rows, never live memory

`Runner::run()` materialises the whole plan into `run_items` *before*
processing anything. Every push after that reads only from that row, because
a resumed run is a fresh process with no memory of the original fetch. New
per-item push paths must persist what they need at materialisation time.

`Runner::archiveContact()` reads the archive group's state fresh from the
database, not from the per-run snapshot: a second archival in the same batch
must see the group the first just created. Regression:
`RunnerTest::testTwoArchivalsInOneRunShareTheArchiveGroup`.

### Duplicate detection is manual by design

`Sync/DuplicateMatcher` hooks into the Planner's create branches only: a new
*untracked* contact matching an *untracked* one on the other side becomes a
conflict with `duplicate_json` set. Three things to know: one-way jobs fully
fetch side B (the matcher needs its N/EMAIL/TEL) but B's uids stay out of
the diff; no state is seeded at detection time, so every run re-detects a
pending duplicate and `recordDuplicateConflict()`'s dedup swallows it;
contacts already in `contact_state` are excluded as candidates, so a
near-copy of an already-synced contact just syncs across.

### Why the vCard parser was not replaced with sabre/vobject

Nextcloud bundles it, and it was tempting. The entire diff rests on
byte-exact round-tripping, and vobject re-serialises on parse, which would
reintroduce exactly the phantom-update class of bug the hashing discipline
exists to prevent. The hand-rolled parser is ~600 lines pinned by 40-odd
tests. Leave it.

### Saving an endpoint contacts the server, and must never 500

`EndpointService::create()`/`update()` verify credentials with one principal
PROPFIND before storing anything, because an endpoint saved with a typo looks
healthy in the list and then fails on a scheduled background run, where the
only trace is a log line.

Three rules hold that together:

1. **Catch `\Throwable`, not `DavException`.** This runs on a form-save path,
   so nothing that happens while talking to a stranger's server may reach the
   user as a fault. A transport throwing something unwrapped still means
   "could not connect", which is a field error. The first version caught only
   `DavException` and a raw transport failure escaped as a 500.
2. **A 401 goes on the password; anything else goes on the URL.** Telling
   someone their password is wrong when the host was unreachable sends them
   off resetting credentials that were never the problem. `DavException`
   carries a real `status` property for this; do not read the status back out
   of the message string.
3. **Only re-verify when a connection field changed** (`base_url`, `username`,
   `password`, `preset`). Otherwise a rename cannot be saved while the far
   side happens to be down.

Pinned by `EndpointCredentialCheckTest`, including that nothing is stored when
verification fails and that a rejected new password does not replace a working
stored one.

Watch the test double here: `FakeClientFactory` hands back a fresh healthy
transport for any endpoint id it doesn't know, so a test that pins specific
ids silently asserts nothing -- an endpoint is verified as id 0 before it
exists and under its real id afterwards. Pass the `$default` transport.

### Cron is not always the CLI, and three things follow from that

`SyncTimedJob` is a Nextcloud `TimedJob`, and Nextcloud runs those very
differently depending on how `cron.php` was invoked. All three of these cost
real time, and none of them logs anything against this app:

1. **Webcron executes exactly one job per request.** `CronService::runWeb()`
   calls `getNext()` once and stops; only `runCli()` loops for fourteen
   minutes. The queue is ordered by `last_checked` ascending, so a backlog of
   always-due `QueuedJob` rows (a classic: `UpdateSingleMetadata` piling up
   for a deleted user) sits in front of everything forever and this app never
   gets a turn. Diagnose with `occ background-job:list -c '...SyncTimedJob'`:
   a `Last run` that never advances means the job is not being reached, and
   nothing in the sync code is at fault.

2. **The web path registers no shutdown handler**, so a tick the reverse
   proxy cuts off leaves `reserved_at` set and `getNext()` skips the job for
   **twelve hours**. This is why `Sync\CronBudget` gives a webcron tick the
   same 120s ceiling a web-triggered run gets instead of the CLI's 600s, and
   caps each job by what is left of the tick rather than handing out a full
   per-job budget the tick cannot afford.

3. **`TIME_INSENSITIVE` means "once a night", not "whenever convenient".**
   With `maintenance_window_start` set, `runCli()` asks only for time
   sensitive jobs outside that four hour window. A sync job honouring a
   schedule the user picked must not opt into that, so the flag is gone.

The sting in 3 is that `oc_jobs.time_sensitive` is written **once and never
written back**: `JobList::setLastRun()` only ever moves it towards
insensitive. Removing the call from the class fixes new installs and does
nothing for existing ones, which is what `Migration\Version000104...` is
for -- a migration with no schema change at all, whose entire job is to
repair `time_sensitive`, `reserved_at` and `last_checked` on that one row.
Watch for the constant values while reading this: `IJob::TIME_SENSITIVE` is
**1** and `TIME_INSENSITIVE` is **0**, which is the opposite of what the
names suggest, and the column defaults to 1.

### Routes are cached, so a new route 404s until something clears it

Adding an entry to `appinfo/routes.php` is not enough. Nextcloud caches the
route collection, so a brand-new route answers OCS 998 ("Invalid query...
check the syntax") while every existing route keeps working -- which reads
like a mistake in the new route and is not one. `./dev/occ maintenance:repair`
did **not** clear it; `docker compose restart app` did.

On a real install the same applies: bump `<version>` in `info.xml`, or restart
PHP-FPM. It is the same trap as migrations only running on a version change,
and it bites during development rather than at deploy time.

### A rebuilt bundle does not reach an already-open browser

Same family, different cache, and this one is worse because nothing looks
wrong: `./dev/npm run build` writes the new `js/contacthub-main.mjs`, the
container serves the new bytes, and the page goes on running the old ones.
Nextcloud appends a `?v=<hash of the app version>` to the script URL and
serves it `max-age=15778463, immutable`, so the browser will not revalidate
that URL for six months. `fetch(url, {cache: 'reload'})` does not help --
it proves the server is serving the new file while the document keeps
executing the cached module.

The only thing that gives it a fresh URL is a changed app version, which is
exactly what a real user gets on upgrade. To see a UI change during
development without one: a private window, a different browser, or bump
`<version>`, run `./dev/occ upgrade`, and put it back afterwards (also reset
`config:app:set contacthub installed_version`, since occ will not downgrade).

Recognise it by the symptom: the new string is in `js/` and in the
container, and absent from the page.

### Anything a user should keep goes in their Files, not IAppData

`Files\HubFolder` owns the "Contacts Hub" folder in each user's home:
`Snapshots/` for address book snapshots, `Settings/` for configuration
exports, `Archived contacts/` for the single `.vcf` a conflict resolution
backs up before overwriting. IAppData is invisible -- outside every home, unreachable from the
Files app, undownloadable without a bespoke route -- which is right for a
cache and wrong for a backup, whose whole value is getting at it when
something has gone wrong.

Consequences worth holding onto:

* **The user can delete or edit these files.** A missing snapshot is an
  ordinary outcome, not a broken invariant; `read()` says so in the error.
* **Quota applies.** A snapshot that cannot be written now fails the run
  deliberately, because `beforeSync()` is called between planning and the
  first write precisely so there is a way back. Syncing anyway would remove
  the protection silently.
* **Snapshots taken before the move stay in IAppData** and are still
  restorable: `BackupService::read()` falls back to the old folder. Verified
  against a real pre-move snapshot. They age out through normal retention,
  and nothing new is ever written there.
* **Anything touching Files needs a real Nextcloud user**, unlike the rest of
  the suite, which gets by on a synthetic principal string. `IRootFolder`
  resolves a home directory and there is no home without an account, so
  `IntegrationTestCase::ensureRealUser()` creates one on demand.

### A conflict's archive choice is a file, never a second address-book entry

`ConflictApplier::archiveContactToFile()` backs up a duplicate's existing
copy before overwriting it. An earlier, since-removed same-UID conflict
kind did something else entirely -- wrote the copy back to the endpoint
under a synthetic uid and tagged it into an archive group -- and that
difference was never a design, just the order the two features were built
in. It cost a one-sided `contact_state` row per backup purely so later runs
would not treat it as a contact to propagate (a bug found by hand, not by
tests), and it put an unreadable uid next to the live contact in the user's
Contacts app.

`Runner` still writes an archive copy *into* the address book on a
mirror/archive deletion, and that one stays: it is the deletion policy,
where the whole point is that the contact remains where contacts are.
Archiving before an overwrite and archiving instead of a delete are
different features that happened to share a verb.

### A conflict resolution asks for one ETag, not the whole collection

`SyncSide::etagFor()` exists because `listEtags()` is the right call per
*run* and the wrong one per *item*: resolving conflicts one at a time paid
for a full-collection PROPFIND each, which batch resolution turned from a
curiosity into minutes against a server like Mailo. `Client::etagFor()`
treats only 404/410 as "not there" -- reading any other failure as absence
would turn the next write into a create.

### Everything a run reports must also reach the Nextcloud log

`Runner::warn()` and `Runner::fail()` append to the `RunResult` *and* log,
and every append goes through them. Both, always: a run report is per-run and
transient -- a paused run's report is replaced when the next segment starts,
and nobody is watching a scheduled run's report at all -- so anything landing
only there is invisible a minute later. This is why a user could watch an
error appear during a sync and then fail to find it anywhere.

The one path that used to bypass it was `preparePhoto()`, which took the
warnings array by reference. It takes the `RunResult` now.

The corollary, learned the expensive way: **a warning has to be bounded by
something that does not scale with the address book.** Because every warning
is both a log line and a note card in the run panel, one emitted per contact
turns a routine sync into hundreds of log lines several times an hour. The
dangling-member check in `Model::buildAddressBook()` did exactly that -- one
line per member, per group, per run -- and a user reported roughly five
hundred per sync. It aggregates per group now. Anything counting per contact
should aggregate the same way before it ships.

A warning also has to say **which address book it is about**. Both sides of a
job can hold groups, and the same message without a side label named a UID
and left the reader no way to tell whether to look in Nextcloud or at the
endpoint. `fetchBothSides()` passes `$side->label()` down for this.

The browser side of the same bug: `RunPanel` carries warnings and errors
across auto-resumed segments rather than letting each fresh result replace
the last.

### Secrets may not be public properties, because logging walks objects

Nextcloud's `ExceptionSerializer::encodeArg()` expands every object in every
frame of a serialized stack trace using `get_object_vars()` called from
outside the class -- so public properties, and only those. `Sync\Endpoint`
is a constructor argument of most of the engine, and a CardDAV run throws
for entirely ordinary reasons (a 412, an unreachable host), so its public
`$password` was written to `nextcloud.log` in clear text, once per frame
holding an Endpoint or a SyncJob. Found in a log excerpt a user pasted while
reporting an unrelated bug; the log is readable from the admin UI and goes
wherever logs go.

It is private with a `password()` accessor now, plus `__debugInfo()` for the
`var_dump` path, and `EndpointSecrecyTest` pins the actual mechanism rather
than the style. Anything added later that a server would not want written
down goes the same way. `NextcloudHttpTransport` already had it right.

### The archive group is the one resource a run writes more than once

Every other write in a run owns its href and happens once, so the per-run
ETag snapshot taken at fetch time is still the live value when the write
happens. The archive group is not: every archival in the run rewrites that
single vCard, so the first PUT invalidates the ETag the second one was
holding, and a server enforcing If-Match (sabre, so Infomaniak and iCloud
both) answers 412 with a body built from a perfectly fresh GET -- which is
what made it read as a server problem.

`Archiver::addToArchiveGroup()` therefore guards its write with the ETag from
its *own* GET a line earlier, and takes no ETag from its caller at all. That
is not weaker: the window If-Match has to close is between that GET and that
PUT. `Runner::archiveContact()` no longer receives `$liveTargetEtags`, so
there is nothing stale left to reach for. Pinned by
`RunnerTest::testTwoArchivalsIntoAnAlreadyExistingArchiveGroup` -- note that
its older sibling passes either way, because a group *created* during the
same run was never in the snapshot to begin with.

### An inlined photo needs a media type or the avatar comes out blank

`Transform::resolvePhotoUri()` turns a `PHOTO;VALUE=uri` reference into
inline base64, and the media type that was in the HTTP response is lost in
the process. Nextcloud's `PhotoCache::getBinaryType()` reads `TYPE` or
`MEDIATYPE` off a binary PHOTO and returns `''` without either, at which
point the avatar is served as `application/octet-stream` and no browser
renders it. The photo is in the address book, intact, and every affected
contact shows blank -- reported from an iCloud pull, since iCloud serves
photos as authenticated https references rather than inline.

The label has to be sniffed from the bytes (the source property has no type
parameter to copy, which is the point of it being a reference), and is
written as `TYPE=JPEG` on a 3.0 card and `MEDIATYPE=image/jpeg` on a 4.0 one.
An unrecognised format is left unlabelled rather than guessed at.

While editing those signatures: **write the `\xFF` escapes as escapes.** A
scripted edit that lets them become literal high bytes leaves a source file
that still parses, still lints, and silently never matches a JPEG or a PNG
while the plain-ASCII signatures (`GIF89a`, `RIFF`) go on passing -- which is
precisely the shape the test failures took.

### A failure the user cannot see is the same as no failure

Two independent bugs made every error in this app invisible in the browser,
and they have to be fixed together or each one hides the other.

1. **`@nextcloud/dialogs` needs its stylesheet imported.** `showError()` runs,
   inserts its element, and renders as nothing without
   `@nextcloud/dialogs/style.css`. Nine call sites had been reporting
   failures to a user who saw an empty screen. It is imported in
   `src/main.js` now; `createAppConfig`'s `inlineCSS` folds it into the
   bundle, so there is no extra file to ship. Check it survived a build with
   `grep -c _toastContainer js/contacthub-main.mjs` -- 2 means the rule is in
   there, 1 means only the class reference is and the import is gone.

2. **Every action goes through `ApiController::respond()`.** It used to map
   only `ValidationException` and `NotFoundException`, and
   `JobController::run()` did not use it at all -- it caught its own
   `RunAlreadyActive` and `NotFoundException` and nothing else. So a run that
   failed the way runs actually fail (a 401 from the endpoint, an unreachable
   host) threw straight past, Nextcloud produced an OCS envelope with no
   message, and `api.js` had nothing to put in the toast. `respond()` now
   also catches `\Throwable`, logs it with the exception, and returns the raw
   message with a 500.

The message is deliberately not rewritten for the user. A sync talks to a
server this app knows nothing about, so a curated message would be a guess,
and "something went wrong" is what sends people to the server log. `RunPanel`
also keeps it on screen in a note card rather than only in a toast, because
the cause is usually a setting the user has to go and change somewhere else.

### Force means re-compare, and used to mean assume-everything-changed

`PlanInput::$force` short-circuited both change comparisons in the Planner
(`$in->force || Model::contactContentHash(...) !== ...`), so a forced run
called every tracked contact on side A changed. It had no tests and no
documentation, and the cost was not cosmetic: a user forcing an
iCloud -> Nextcloud run was told 597 contacts were updated when nothing had
been touched, and those were 597 real writes to Nextcloud.

It compares now, which is both what the checkbox promises and what is
actually useful, because both sides are fetched in full regardless. The A
comparison is the ordinary one; what force *adds* is the check a one-way run
skips -- whether B's copy still matches what this app recorded writing there.
Normal one-way runs see only that B's resource exists (`liveBHrefs`), never
that its content drifted, so an edit made directly on the endpoint is
invisible until someone forces a run. That gap is the whole point of the flag.

Two consequences worth keeping straight:

* **A server that rewrites what it stores drifts permanently.** Re-encoding
  photos on upload is the common one. `RemoteSide::putVCard()` records the
  hash of the text it *sent*, on the documented assumption that a CardDAV
  server stores bytes verbatim -- true for sabre, not true for everyone. Such
  contacts fail the comparison on every forced run forever. `Plan`'s
  `contactsDriftedOnB` exists so `Runner::reportEndpointDrift()` can say so in
  one line, with a count of how many of them have photos, instead of silently
  redoing the work. Documented in the README as user-facing behaviour.

* **`contactsDriftedOnB` is diagnostic, not an action bucket.** It is always
  a subset of `contactsUpdateAToB`, so it changes nothing about `isEmpty()`
  or about what the run does.

### A view's local state is gone the moment the user changes tab

`JobsView` kept the "currently syncing" job in a local `ref`, so switching to
Endpoints and back showed an idle screen even with a run still open. The run
had not stopped -- a run that pauses on its time budget is waiting for
somebody to ask for the next segment, and cron would have got there
eventually -- but the user was shown nothing and reasonably concluded it had.

The jobs listing carries `open_run` now, so the screen can reopen it on
mount, and `RunPanel` adopts it with the same visible countdown a paused
segment gets rather than silently resuming. `StateMapper::openRunsFor()` is
the batched form of `findOpenRun()` and has to keep agreeing with it exactly
-- same dry-run and status filter, same newest-first tie-break -- or the
screen reopens a run the resume path does not recognise. Pinned by asserting
both against each other rather than against literals.

### A short fetch is indistinguishable from a smaller address book

`Client::collectAddressData()` skips any multistatus entry carrying no
`address-data` -- a resource the server refused, or a response it truncated.
Nothing downstream can tell that apart from the address book simply being
smaller, and the consequences are not symmetrical:

* the **plan** reads presence from `listEtags()`, a separate and complete
  listing, so it stays correct;
* **everything else** reads the fetched book, so a contact missing from it
  looks deleted -- and on a `from_endpoint` job that means mirroring a
  deletion nobody made into the side that still has the contact.

`Runner::assertWholeBookFetched()` counts what came back against that
listing and **throws** rather than warning. The listing is being fetched
anyway, so this costs nothing extra. `FakeHttpTransport`
has `dropFromAddressData` to reproduce the shape: a valid 207 that is simply
missing some entries.

Surfaced by a user's log where a group vCard on the endpoint listed dozens of
members "not in that address book", including a contact the previous run had
just archived there.

### A tracked contact with no href on the far side used to be invisible

The out-of-band-deletion check in `Planner::planContacts()` required
`$state['b_href'] !== null`, so a contact that was tracked but had *never*
successfully landed on B was skipped entirely -- and the content comparison
above it sees A unchanged since the row was written. The contact then sat
tracked, present on A, absent from B, and no run ever planned anything for
it. Nothing looked wrong, because a plan reporting no work is precisely what
being in sync looks like.

Both shapes are the same question -- "does B have a live copy?" -- so the
guard now covers a null href as well as a listed-but-gone one.

### A group's member list is not validated by anything, so don't copy it blind

A group vCard is a list of contact UIDs and nothing anywhere enforces that
those contacts exist. Real address books accumulate references to contacts
deleted years ago. `Runner` used to push a group's `rawText` verbatim, so the
destination inherited every dangling reference the source had -- a user's
endpoint ended up with groups listing 397 members that were not in that
address book, across thirteen groups.

`Runner::withResolvableMembers()` drops them from **both books, right after
they are built**, and that placement is the whole correctness of it. The
first version filtered the text on its way out and left the Planner diffing
the unfiltered book, so every group carrying one stale reference hashed
differently from the copy just written from it and was re-pushed on *every
run, forever* -- the exact phantom-update class the hashing discipline exists
to prevent, shipped and caught only by testing the cleanup advice before
sending it. Plan, hash, snapshot and push have to see one version of a group.

It resolves against both books: a member may live on either side (a
`from_endpoint` job puts the endpoint in slot A), and `bookB` is fetched in
full anyway. A group whose members all resolve is passed through as the
identical object, so untouched groups keep their exact bytes.

The useful side effect: a group pushed before the filter existed has a stored
hash computed from its dangling members, so it differs once, is pushed once,
and settles. That is what cleans up references already on an endpoint -- no
wipe and no forced run.

Writing a reference that cannot resolve is not preserving information, it is
copying a defect.

### Telling apart the groups this app made from the ones it found

Derived group UIDs are UUIDv**5** (`CategoryGroups::uidFor()`), so the version
nibble -- the first character of the third dash-separated field -- says
whether a group came from a Nextcloud category or from somewhere else.
Reading a user's log, that one character split 24 groups into 13 this app had
created and 11 that predated it on their endpoint, which reframed half the
report as data the app had never touched.

The same split shows the cost of deriving UIDs from names: ten of their group
names existed **twice** on the endpoint, once under an Apple UID that was
already there and once under this app's derived one. Nextcloud shows one
group; the endpoint has two cards. `CategoryGroups::project()`'s "a real group
wins" rule only fires on a UID *clash*, and these do not clash -- they are
different identities for the same name. Fixing that needs a name-to-UID
mapping table and a decision about which identity is authoritative; it is a
real design change, not a patch.

### Groups need every reconciliation contacts have, and kept missing one

`planGroups()` had no out-of-band-deletion check. `planContacts()` has always
had one -- content unchanged on A, but B has no live copy, so re-push -- and
groups simply never got the equivalent. Emptying a push destination therefore
brought every contact back on the next run and no groups at all: each group
sat with an unchanged hub-side hash and nothing to trigger on, so membership
was permanently lost on that side while the plan reported no work to do.

Found by checking whether a user could wipe a push-only endpoint and let the
next sync rebuild it. They could not, and nothing said so. Pinned by
`RunnerTest::testWipingThePushDestinationRebuildsContactsAndGroups`.

The general point: the two planners are near-mirror images and drift apart
quietly, because a group bug shows up as *absence* -- a group that is not
there looks like a group nobody made. When adding a reconciliation to one,
check whether the other wants it.

### Syntax traps that cost real time

* **XML comments may not contain `--`.** The house style of using `--` as an
  em dash made `info.xml` unparseable, and Nextcloud reports that only as
  "appinfo file cannot be read", which sounds like a permissions problem.
* **PHP docblocks may not contain `*/`.** Writing `hub_*/endpoint_*` closed a
  comment early and surfaced as a `ParseError` inside a DI stack trace.
* **`@nextcloud/vue` 9 is Vue 3.** `:value.sync` is Vue 2 and binds nothing;
  fields use `v-model`. Vue 3 treats the modifier as part of an ordinary
  attribute name, so it fails **silently** -- no warning, no error.
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

* **`group_strategy: collections`** (one real collection per group) is probed
  by the capability tester but **not implemented for live sync** --
  `Runner::pushGroupOne()` warns and skips. It needs per-(job, uid,
  collection) multi-location state, which the per-side single-href schema
  does not support. Flag before starting; it is a real schema change.
* **Category renames read as delete-plus-create** on passthrough endpoints,
  because the derived UID changes. Preserving identity would need a mapping
  table.
* **Mailo's preset is user-reported**, not verified against a live account by
  this project (unlike iCloud and Infomaniak). Don't upgrade that framing
  without someone actually testing it.
* **Only HTTP Basic authentication exists.** That is what rules out Google
  Contacts, whose CardDAV interface requires OAuth 2.0 and answers Basic with
  an unconditional 401. The `google` preset is therefore listed but carries an
  `unsupportedReason`, which `EndpointService::validate()` refuses on -- a
  preset nobody can save, on purpose, because the alternative is a user
  concluding it was forgotten. Adding OAuth is a real project: client
  registration, the authorisation-code flow, refresh-token storage next to the
  encrypted password, and a Bearer path in the transport. Flag before starting.
* **Endpoint backup/restore and address book snapshots are synchronous and
  one-shot**, not chunked like sync runs. Fine for the address books this
  targets; a very large one over a slow connection could hit an execution
  ceiling. Flag before adding chunking.
* **No admin settings UI.** Two values are read from `IAppConfig` with
  sensible defaults and no way to change them but `occ config:app:set`:
  `http_timeout_seconds` (30) and `backup_retention_days` (30).
* **Not published to the app store yet.** That needs signing, screenshots and
  a decision about `CardDavBackend` being an internal DAV-app class. Its use
  is confined to `AddressBookService` and `NextcloudSide`, so the blast
  radius of a future change is two files.

## Git hygiene

* Commit messages go long and explain *why*, including bugs found and how
  they were caught. This has been genuinely useful for reconstructing
  context across sessions -- keep it.
* Don't rebase or rewrite `main`; a human follows it commit by commit.
* `python-baseline` (Python CLI) and `php-rewrite` (standalone PHP) are
  historical. Leave them alone.
* `main` itself still has no remote and is never pushed anywhere -- it stays
  the private, full-detail development history.
* **Publishing to GitHub changed on 2026-09-13.** Before that date, every
  publish was a fresh orphan commit force-pushed over the last one, sharing
  no history with this repo at all. From 0.1.14 on, the public repo at
  github.com/gerardkieffer/nextcloud-contacts-hub gets *real*, ordinary
  commits with real history -- no more squashing, no more force-push, one
  new commit per publish. That real history starts at the 0.1.14 snapshot
  (`Contacts Hub 0.1.14`, 15e7212), which is the last thing force-pushed
  under the old scheme; nothing before it was carried over, by the user's
  explicit choice, so the public repo's history is shorter than this one's
  and that gap is permanent, not a bug.
* The local branch `github-main` tracks `origin/main` and is the only thing
  ever pushed. It does not share `main`'s commit objects -- to publish a
  change, replay it onto `github-main` as an ordinary commit (`git
  cherry-pick`, or a hand-written commit if a private commit bundles
  something that shouldn't cross over) and `git push origin github-main:main`
  as a normal fast-forward. Do the personal-data sweep (emails outside the
  example.com/placeholder set, `/Users/`+`kDrive` paths in `js/` and
  `package-lock.json`, `config/`/`data/`/`backup/`/`node_modules/`/`tools/`/
  `dist/`/`.claude/` absent from the tree) before every push, same as under
  the old scheme -- nothing about that changed, only how the push itself is
  shaped.
* `data/` and `config/local.php` in the working tree are leftovers from the
  standalone app and hold real credentials and real user data. They are
  gitignored. Never commit them, and never delete `data/` while cleaning up.
