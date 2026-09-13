# Contacts Hub

A Nextcloud app that turns your Nextcloud address books into a contacts hub.

Contacts Hub syncs any Nextcloud address book with external CardDAV server
— one-way, in either direction.
Because every sync job runs through a Nextcloud address
book, you can also use Nextcloud as a bridge between two services that have
no way of talking to each other directly.

Your contacts stay where they belong: in Nextcloud's own address books,
editable in the Contacts app and reachable from every CardDAV client you
already use. This app adds no contact storage of its own.

## What it does

- **One-to-many.** Push one address book out to as many services as you
  like — one sync job per destination.
- **Bridge two services.** iCloud ↔ Nextcloud ↔ Infomaniak, as two jobs
  sharing one address book.
- **Duplicate detection.** A new contact that looks like an existing one —
  same name plus a shared email or phone, under a different ID — is flagged
  for you rather than guessed at. Nothing is changed until you decide.
- **Deletions are your call.** Mirror them to the other side, or keep the
  contact and tag it as archived instead.
- **Groups travel both ways.** Nextcloud stores groups as categories on each
  contact; services like iCloud want separate group records. Contacts Hub
  translates between the two automatically, in both directions.
- **Photos survive intact.** Including iCloud's habit of storing a photo as
  a reference to an authenticated URL rather than embedding it — those are
  fetched and re-embedded so the picture is not broken on the other end.
- **Snapshots before every run that writes.** Kept 30 days, and saved into
  **Contacts Hub/Snapshots** in your own files, so you can find and download
  them from the Files app. Restoring takes its own snapshot first, so a
  restore is itself undoable.
- **Export and import your setup.** Endpoints and sync jobs, written to
  **Contacts Hub/Settings** as readable JSON. Passwords are left out unless
  you tick the box.
- **Interrupted runs resume themselves** rather than losing track of where
  they were.
- **Illogical setups are explained**, not silently mishandled — two jobs
  pushing different address books into the same remote collection, for
  instance.

## Requirements

- Nextcloud 34
- PHP 8.3 or newer

No Composer install and no build step on the server: the app ships as plain
files, and it has no runtime dependencies beyond Nextcloud itself.

## Installing

Until it is on the app store, install from source:

```bash
cd /path/to/nextcloud/custom_apps
git clone https://github.com/gerardkieffer/nextcloud-contacts-hub.git contacthub
cd contacthub
npm ci && npm run build       # only if you did not clone the built js/
sudo -u www-data php ../../occ app:enable contacthub
```

Then open **Contacts Hub** from the app menu.

If that last command answers `Could not download app contacthub, it was not
found on the appstore`, the app is somewhere Nextcloud does not scan —
`custom_apps` only counts if it is listed in `apps_paths`. See
**[docs/install.md](docs/install.md)** for the full procedure, including
building a minimal package for a server without Git, and what to set so the
background sync actually runs on a schedule.

## Setting it up

### 1. Add an endpoint

An *endpoint* is the external service you want to sync with. Pick the
service, fill in the URL and credentials, and save.

Saving contacts the server and checks the credentials before storing
anything, so it takes a moment and a wrong password is refused there and
then rather than failing silently on a scheduled run days later. If the
server is simply unreachable the error says so, and blames the URL rather
than your password.

Each preset carries a hint about which credentials that service actually
wants — several of them do not accept your normal account password:

- **iCloud** needs an app-specific password from appleid.apple.com, never
  your Apple ID password.
- **Infomaniak** wants the short login on its own (`AB12345`), not the
  full `AB12345@sync.infomaniak.com` form, even though Infomaniak's own
  documentation shows the long one.
- **Mailo** uses your normal email address and password.
- **Google Contacts** cannot be used. It requires OAuth 2.0, which this
  app does not support; Google stopped accepting passwords for CardDAV in
  March 2025 and does not accept app passwords for it either. The preset
  is listed so it can say so instead of leaving you guessing. See
  [docs/presets.md](docs/presets.md#google-contacts).

### 2. Run the capability test

This asks the server what it actually supports and suggests matching
settings — most importantly which address book to use and how that server
represents groups. Guessing this wrong is the usual cause of groups not
syncing, so it is worth running.

The optional *write probe* is what makes the group answer reliable: it
creates and deletes a throwaway contact and group to see what survives a
round trip. It cleans up after itself, and tells you if it could not.

Review the findings, then apply them.

### 3. Create a sync job

A job connects one Nextcloud address book to one endpoint, with a direction:

- **Nextcloud to endpoint** — push
- **Endpoint to Nextcloud** — pull

For one-to-many, create several jobs from the same address book. To bridge
two services, create two jobs that share one address book.

Jobs run on a schedule through Nextcloud's background jobs, and you can run
one by hand any time from the Sync screen. **Preview** shows what would
happen without writing anything — worth using the first time.

### 4. Keep a copy of your setup

**Export and import** writes your endpoints and sync jobs to
`Contacts Hub/Settings` as readable JSON, ready to download from the Files
app. Use it before changing something, or to move a working setup to another
server.

Passwords are **left out by default**. Without them the file is harmless if
it leaks, and importing it asks you to type each password once. Tick the box
only if you are moving servers, and delete the file afterwards.

Importing never overwrites: anything whose name already exists is skipped,
each password is checked against its server before the endpoint is created,
and imported jobs arrive switched off so you can look at them before they
run. You can import a file from your computer or one already in your
Nextcloud files.

## Before you turn on mirror deletions

With the mirror deletion policy, a delete on one side becomes a delete on
the other. Contacts Hub takes a snapshot of the address book before any run
that writes, so a bad run is undoable, and the archive policy lets you keep
a tagged copy instead of deleting. Even so: preview the first run.

## Resolving conflicts

When a new contact looks like one you already have — same name plus a
shared email or phone, under a different ID — it lands on the **Conflicts**
screen rather than being guessed at. You get the two copies side by side
with the differing fields highlighted, and choices to merge them into one,
keep both as separate contacts, archive the existing copy first, or cancel.
Contacts Hub never merges these on its own.

Anything archived while resolving a conflict is written as a plain `.vcf`
file into **Contacts Hub / Archived contacts** in your own Files, not back
into an address book. You can open it, send it on, or import it again if you
decide you wanted that copy after all; nothing syncs it anywhere. A name
already taken is never overwritten — the second file gets a timestamp.

## Automation

Scheduled runs need Nextcloud's background jobs to be working, and **system
cron** is strongly recommended over the alternatives. With *AJAX*, jobs run
only when somebody has a browser open. With *Webcron*, Nextcloud executes a
single background job per call to `cron.php`, so a busy job queue can starve
scheduled syncs indefinitely — see
[docs/install.md](docs/install.md#4-after-enabling) for how to tell whether
that is what is happening.

A run is bounded by a time budget so it cannot be cut off mid-write by a web
server timeout. A large address book may finish over several runs — that is
normal, and it picks up where it stopped without redoing work.

## The Force option, and why it may keep finding the same contacts

**Force — ignore stored state and re-compare everything** exists because an
ordinary one-way run cannot see everything. It notices that the destination
still *has* each contact, but not whether the destination's copy still says
what it said when it was written. Edit a contact directly on the endpoint and
a normal run will never notice. A forced run compares the content and pushes
the source's version back over anything that drifted — which is what one-way
means: the source side wins.

So a forced run reporting updates is not a bug, and reporting none is the
normal outcome for an address book nobody has touched.

One case makes it look broken. **Some servers do not store what they were
given** — re-encoding or shrinking photos on upload is by far the most
common. For those contacts the comparison fails again on the very next
forced run, and the one after that, because the copy on the server will never
match the copy that was sent. Nothing is wrong and nothing is lost; the same
contacts simply come up every time.

A forced run says when it sees this, in one line:

```
Forced run: 118 contacts in Infomaniak no longer match what was last
written there (all of them have photos, which normally means that server
re-encodes photos on upload). They have been re-sent.
```

When the count matches the number of contacts with photos, that is the
explanation and there is nothing to do about it — it is the endpoint's
behaviour, not something this app can change. When it does not, the contacts
that drifted were most likely edited on the endpoint directly, which is worth
knowing: a one-way job will keep overwriting those edits.

Ordinary scheduled runs are unaffected either way. They never compare the
destination's content, so they neither notice this nor rewrite anything
because of it.

## Syncing with a server on your own network

Nextcloud refuses outbound requests to local and private addresses by
default, which blocks a CardDAV server on your own LAN (Baikal or Radicale
on a NAS, say). An administrator can allow it:

```bash
sudo -u www-data php occ config:system:set allow_local_remote_servers --value=true --type=boolean
```

This lowers a protection against server-side request forgery, so enable it
only if you need it.

## Configuration

Two values have no settings screen yet:

```bash
occ config:app:set contacthub http_timeout_seconds --value=30
occ config:app:set contacthub backup_retention_days --value=30
```

## Known limitations

- **One collection per group** (`collections` group strategy) is detected by
  the capability test but not implemented for live sync. Jobs targeting such
  an endpoint warn and skip group changes.
- **Category renames on passthrough endpoints (iCloud-style group vCards)
  read as delete-plus-create.** Renaming a group in Nextcloud reads as
  delete-plus-create on services that use separate group records, because
  the group's identity on that side is derived from its name. Membership
  survives; the group's identity on that side does not.
- **A contact photo stored as a non-image data URI** is dropped by
  Nextcloud's own storage layer on the way in.
- **The Mailo preset is user-reported**, not verified against a live account
  by this project. iCloud and Infomaniak have been tested directly.
- **Google Contacts is not supported.** Only HTTP Basic authentication is
  implemented, and Google's CardDAV requires OAuth 2.0.
- **Snapshots and endpoint backups are one-shot**, not resumable like sync
  runs. Very large address books over a slow connection could hit a PHP
  execution limit.

## Roadmap

Not yet built, in rough order of how much a real project each one is:

- **Admin settings UI.** `http_timeout_seconds` and `backup_retention_days`
  are only configurable via `occ config:app:set` today (see
  [Configuration](#configuration)); a proper admin settings screen would
  replace that.
- **Google Contacts support (OAuth 2.0).** Blocked on real work, not a
  missing preset: an OAuth client registration with Google, the
  authorisation-code flow through a browser, refresh-token storage
  alongside the existing encrypted password column, and a `Bearer`
  authentication path in the transport layer, which today only speaks HTTP
  Basic. See [docs/presets.md](docs/presets.md#google-contacts) for why
  Basic auth alone can never reach Google's CardDAV interface.

## Your data

- Contacts live in Nextcloud's address books. This app stores none.
- Endpoint passwords are encrypted with Nextcloud's own crypto, keyed off
  the instance secret.
- Snapshots and settings exports are files in your own Nextcloud files,
  under **Contacts Hub**. Your quota, trash and sync client all apply to
  them, and you can delete them yourself.
- Endpoints, jobs and snapshots are per-user, and everything a user owns is
  deleted with their account.
- Warnings and errors from a sync go to the Nextcloud log, tagged
  `contacthub`, so they are still findable after the run panel has moved on.

## Development

See [CLAUDE.md](CLAUDE.md) for architecture, the gotchas worth knowing
before touching the sync engine, and how to run the dev environment and the
two test suites.

## Author

Built by [Gérard Kieffer](https://www.gerardkieffer.net).

## Licence

[AGPL-3.0-or-later](LICENSE).
