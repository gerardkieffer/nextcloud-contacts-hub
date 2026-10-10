# Contacts Hub

A Nextcloud app that turns your Nextcloud address books into a contacts hub.

Contacts Hub syncs any Nextcloud address book with an external CardDAV
server — one-way, in either direction. Because every sync job runs through a
Nextcloud address book, you can also use Nextcloud as a bridge between two
services that have no way of talking to each other directly.

Your contacts stay where they belong: in Nextcloud's own address books,
editable in the Contacts app and reachable from every CardDAV client you
already use. This app adds no contact storage of its own.

## What it does

- **One-to-many.** Push one address book out to as many services as you
  like — one sync job per destination.
- **Bridge two services.** iCloud and Infomaniak, say, as two jobs sharing
  one Nextcloud address book.
- **Duplicates are flagged, never guessed at.** See
  [Resolving conflicts](#resolving-conflicts).
- **Deletions are your call.** When a contact is deleted from a job's source,
  delete it from the destination too, or keep it there tagged as archived. A
  group that disappears from the source is removed under either policy.
- **Groups survive the trip.** Nextcloud stores groups as categories on each
  contact; services like iCloud want separate group records. Contacts Hub
  translates between the two automatically. See
  **[docs/groups.md](docs/groups.md)** for how, and what does not survive.
- **Photos survive intact**, including iCloud's photos stored as links to an
  authenticated URL, which are fetched and embedded.
- **Snapshots of the Nextcloud address book before every run that writes**,
  saved into **Contacts Hub/Snapshots** in your own files. They cover the
  Nextcloud side only; see
  [Before you turn on mirror deletions](#before-you-turn-on-mirror-deletions).
- **Export and import your setup** as readable JSON.
- **Interrupted runs resume themselves** rather than losing track of where
  they were.

## Requirements

- Nextcloud 34 or 35
- PHP 8.3 or newer

No Composer install and no build step on the server: the app ships as plain
files, and it has no runtime dependencies beyond Nextcloud itself.

## Installing

Until it is on the app store, install from source. First check that Nextcloud
scans the apps folder you are about to use:

```bash
sudo -u www-data php occ config:system:get apps_paths
```

If that prints nothing, only the stock `apps/` folder is scanned, and an app
placed in `custom_apps/` is silently ignored. Either use `apps/`, or declare
`custom_apps` first as shown in
**[docs/install.md](docs/install.md#2-check-where-nextcloud-looks-for-apps)**.
The official Nextcloud Docker image already declares it.

Then:

```bash
cd /path/to/nextcloud/custom_apps
git clone https://github.com/gerardkieffer/nextcloud-contacts-hub.git contacthub
sudo -u www-data php ../occ app:enable contacthub
```

and open **Contacts Hub** from the app menu. If enabling answers `Could not
download app contacthub, it was not found on the appstore`, the app is
somewhere Nextcloud does not scan.

[docs/install.md](docs/install.md) has the full procedure: installing from a
small package instead of a clone, updating, and what to set so the background
sync actually runs on a schedule.

## Setting it up

### 1. Add an endpoint

An *endpoint* is the external service you want to sync with. Pick the
service, fill in the URL and credentials, and save. Saving checks the
credentials against the server, so a wrong password is refused there and
then rather than failing on a scheduled run days later.

Several services do not accept your normal account password:

- **iCloud** needs an app-specific password from appleid.apple.com, never
  your Apple ID password.
- **Infomaniak** wants the short login on its own (`AB12345`), not the
  full `AB12345@sync.infomaniak.com` form, even though Infomaniak's own
  documentation shows the long one.
- **Mailo** uses your normal email address and password.
- **Google Contacts** cannot be used: it requires OAuth 2.0, which this app
  does not support. See [docs/presets.md](docs/presets.md#google-contacts).

### 2. Run the capability test

This asks the server what it actually supports and suggests matching
settings — most importantly which address book to use and how that server
represents groups. Guessing this wrong is the usual cause of groups not
syncing.

The optional *write probe* makes the group answer reliable: it creates and
deletes a throwaway contact and group to see what survives a round trip, and
tells you if it could not clean up.

### 3. Create a sync job

A job connects one Nextcloud address book to one endpoint, with a direction:
**Nextcloud to endpoint** (push) or **Endpoint to Nextcloud** (pull).

Jobs run on a schedule through Nextcloud's background jobs, and you can run
one by hand any time from the Sync screen. **Preview** shows what would
happen without writing anything — worth using the first time.

### 4. Keep a copy of your setup

**Export and import** writes your endpoints and sync jobs to
**Contacts Hub/Settings** as JSON, ready to download from the Files app. Use
it before changing something, or to move a working setup to another server.

Passwords are **left out by default**, and importing then asks you to type
each one. Tick the box only if you are moving servers, and delete the file
afterwards. Importing never overwrites anything with the same name, checks
each password against its server, and creates jobs switched off so you can
look at them before they run. You can import a file from your computer or
one already in your Nextcloud files.

## Before you turn on mirror deletions

With the mirror deletion policy, a contact deleted from a job's source is
deleted from its destination too. How undoable that is depends on the
direction:

- **Pull (endpoint to Nextcloud).** The destination is the Nextcloud address
  book, which is snapshotted before every run that writes, so a bad run can
  be restored.
- **Push (Nextcloud to endpoint).** The destination is the endpoint, and
  **nothing snapshots it** — this app has no way to back up an endpoint.
  Export the endpoint's contacts with that service's own tools first, or use
  the archive policy, which keeps a tagged copy instead of deleting.

Two safeguards apply in both directions:

- **A run whose source suddenly reads as completely empty is refused** when
  it would remove contacts synced from it before. An address book that
  empties all at once has far more often been deleted, unshared or
  misconfigured than really emptied. If you did empty it on purpose, delete
  the contacts on the destination yourself, or delete the job and create it
  again.
- **A job whose Nextcloud address book has been deleted or unshared does not
  run at all**, and its history says why.

Even so: preview the first run.

### Restoring a snapshot

Open **Snapshots**, pick the address book, and click **Restore…** next to the
snapshot you want. You choose between:

- **Put back what the snapshot has** — deleted contacts come back and changed
  ones are reverted; contacts added since are kept.
- **Make it exactly as it was** — the same, and contacts added since the
  snapshot are deleted.

Sync jobs using that book carry on from the restored state: a pull job puts
back the endpoint's version of every contact the endpoint still has, and a
push job sends the restored book on to its endpoint, deletions included under
the mirror policy. The screen spells this out and offers to switch those jobs
off first (ticked by default); switch them back on from **Sync jobs** once you
have checked the result.

A restore takes a snapshot of its own first, so it can itself be undone.
**Take a snapshot now** makes one by hand. Snapshots are kept 30 days by
default (see [Server settings](#server-settings)). A book shared with you
read-only can't be restored.

## Changing what a job syncs

A job remembers where every contact it synced lives on each side. Moving a
job to **another address book or endpoint**, or an endpoint to **another
collection, server or account**, makes that wrong, so the affected jobs
**start over as if they had never run**. That first run deletes nothing, and
contacts already on the destination under the same ID are updated in place
rather than created twice. Changing only a job's **direction** keeps what it
knows.

Changing an endpoint's server address or username also clears its chosen
collection, so run the capability test again. None of these changes can be
saved while an affected job is in the middle of a run.

## Shared address books

Address books other people share with you can be used like your own, with
one rule: a book shared **read-only** can only be a source. A job writing
into it is refused when you save it, and again at run time if the share has
become read-only since. If a share is withdrawn, its jobs stop running and
say why.

## Resolving conflicts

When a new contact looks like one you already have — the same email
address, or the same name plus a shared phone number or email, under a
different ID — it lands on the **Conflicts** screen rather than being
guessed at. An email address several contacts share (a household's, a
company's `info@`) only counts together with the name. You get the two
copies side by side with the differences highlighted, and can merge them,
keep both, archive the existing copy first, or cancel.

A contact with the **same ID** on both sides is not a conflict: it is the
same contact, and the destination copy is updated like any other. This is
what happens when you import a `.vcf` into Nextcloud and only then set up a
job pulling the same contacts.

Choices act on the contacts **as they are when you click**, not as they were
when the conflict was found. A conflict that no longer applies — typically
because you deleted the duplicate yourself — is closed without changing
anything.

Anything archived while resolving a conflict is written as a plain `.vcf`
file into **Contacts Hub/Archived contacts** in your own files, not back into
an address book, and nothing syncs it anywhere.

### When a scheduled run finds conflicts

A scheduled run that leaves unresolved conflicts **pauses its job** until
every conflict for that job is resolved; then it resumes by itself. Manual
runs are not blocked.

You get **one email** when a job pauses. Where it goes is set in **Personal
settings → Contacts Hub**: your profile address (the default, on as soon as
your profile has one), a different address, or off. The email goes through
Nextcloud's own outgoing mail, so an administrator has to have set that up;
if it looks unconfigured, or a recent notification failed to send, both the
settings page and the Sync jobs screen say so.

## Automation

Scheduled runs need Nextcloud's background jobs, and **system cron** is
strongly recommended: with *AJAX* or *Webcron*, scheduled syncs can run
erratically or not at all. See
[docs/install.md](docs/install.md#4-after-enabling) for how to check.

A run is bounded by a time budget so it cannot be cut off mid-write by a web
server timeout. A large address book may finish over several runs — that is
normal, and it picks up where it stopped without redoing work.

## The Force option

**Force — ignore stored state and re-compare everything** checks what an
ordinary one-way run cannot see: whether the destination's copy of each
contact still says what was written there. Edit a contact directly on the
endpoint and a normal run will never notice; a forced run puts the source's
version back, because in a one-way job the source wins. So updates from a
forced run are not a bug.

**Some servers do not store what they were given**, most often by
re-encoding photos on upload. Those contacts never match, so every forced run
re-sends them, and the run report says so in one line, with how many of them
have photos. If that count equals the number re-sent, it is the server's
behaviour and nothing is wrong. If not, the others were most likely edited on
the endpoint directly — and a one-way job will keep overwriting such edits.
Ordinary scheduled runs are unaffected either way.

## Server settings

Nextcloud refuses outbound requests to local and private addresses, which
blocks a CardDAV server on your own network (Baikal or Radicale on a NAS,
say). An administrator can allow it, at the cost of a protection against
server-side request forgery:

```bash
sudo -u www-data php occ config:system:set allow_local_remote_servers --value=true --type=boolean
```

Three values have no settings screen yet; these are the defaults:

```bash
occ config:app:set contacthub http_timeout_seconds --value=30
occ config:app:set contacthub backup_retention_days --value=30
occ config:app:set contacthub web_time_budget_seconds --value=20
```

The last bounds a run started from the browser, and is clamped to 5–120
seconds; see [docs/timeouts.md](docs/timeouts.md).

## Known limitations

- **One collection per group** (`collections` group strategy) is detected by
  the capability test but not implemented for live sync. Jobs targeting such
  an endpoint warn and skip group changes.
- **Renaming a group in Nextcloud** deletes and recreates it on services that
  keep separate group records, because its identity there is derived from
  its name. Membership survives. More in [docs/groups.md](docs/groups.md).
- **A contact photo stored as a non-image data URI** is dropped by
  Nextcloud's own storage layer on the way in.
- **The Mailo preset is user-reported**, not verified against a live account
  by this project. iCloud and Infomaniak have been tested directly.
- **Endpoints are never backed up** (see
  [Before you turn on mirror deletions](#before-you-turn-on-mirror-deletions)).
- **Snapshots are taken in one go**, not resumable like sync runs. A very
  large address book over a slow connection could hit a PHP execution limit.

## Roadmap

- **An admin settings screen** for the values above.
- **Google Contacts**, which needs OAuth 2.0 support.

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
