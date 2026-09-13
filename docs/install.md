# Installing manually

Until Contacts Hub is on the app store, it is installed by putting its files
in an apps directory and enabling it. There is no Composer install and no
build step on the server: the app ships as plain files with no runtime
dependencies beyond Nextcloud itself.

Nextcloud's own reference: [Apps
management](https://docs.nextcloud.com/server/stable/admin_manual/apps_management.html).

## Requirements

- Nextcloud **34** exactly. `appinfo/info.xml` declares
  `min-version="34" max-version="34"`, and that gate is enforced at install.
- PHP 8.3 or newer.

## 1. Build the package

From a checkout:

```bash
./dev/package                 # -> dist/contacthub-<version>.tar.gz
./dev/package /somewhere/else # or name the output directory
```

It reads the version out of `appinfo/info.xml`, so the filename always
matches what the app will report once installed. Needs nothing installed:
no PHP, no Node, no Docker.

About 300 KB, containing only the four directories the server reads:
`appinfo/`, `lib/`, `templates/` and `js/`. Deliberately excluded are
`js/*.map` (6.4 MB and regenerable, more than twenty times the rest of the
package put together), plus `src/`, `tests/`, `dev/` and `node_modules/`.

The script refuses to build if `js/contacthub-main.mjs` is missing, or if
anything under `src/` is newer than it. That second check matters more than
it looks: a package built from a stale bundle installs perfectly cleanly and
then serves the old interface, which is a slow thing to work out from the
symptom. Rebuild with `./dev/npm run build` and run it again. The server has
no Node, so whatever is in `js/` at packaging time is what ships.

## 2. Check where Nextcloud looks for apps

**Do this before copying anything.** It is the step that most often goes
wrong, and it fails in a way that points at the wrong culprit.

```bash
cd /path/to/nextcloud
php occ config:system:get apps_paths
```

If that prints nothing, `apps_paths` is unset and **only the stock `apps/`
directory is scanned**. Putting the app in `custom_apps/` then leaves it
somewhere Nextcloud never looks, and `occ app:enable contacthub` reports:

```
Could not download app contacthub, it was not found on the appstore
```

which reads like a network problem and is not one: `occ` simply did not find
the app locally and fell through to the appstore.

Either use `apps/`, or declare the extra path in `config/config.php`:

```php
'apps_paths' => [
    [ 'path' => OC::$SERVERROOT.'/apps',        'url' => '/apps',        'writable' => false ],
    [ 'path' => OC::$SERVERROOT.'/custom_apps', 'url' => '/custom_apps', 'writable' => true  ],
],
```

Note that the array **replaces** the default rather than extending it, so
`apps` has to be listed explicitly alongside the new entry.

A directory outside the Nextcloud root survives server upgrades, which
matters for an app you will be replacing by hand.

## 3. Unpack and enable

```bash
cd /path/to/nextcloud/custom_apps
tar xzf ~/contacthub-<version>.tar.gz
chown -R <web-user>:<web-group> contacthub
php ../occ app:enable contacthub
```

The directory must be named exactly **`contacthub`**, matching `<id>` in
`info.xml`, with `appinfo/info.xml` one level inside it. A
`contacthub-0.1.0/` wrapper, or a `contacthub/contacthub/` nesting from
unpacking into a folder of that name you had already created, will not be
found.

Run `occ` as the user PHP runs as (`sudo -u www-data php occ ...` on a
normal install; on shared hosting that is usually your own account, so plain
`php occ` is right). Enabling runs the migration that creates the eight
`oc_contacthub_*` tables.

Over FTP: unpack locally, upload the `contacthub` folder, then enable from
**Settings → Apps → Disabled apps** in the web UI, which does the same thing
as `occ app:enable`.

## 4. After enabling

Set **Settings → Administration → Basic settings → Background jobs** to
**Cron**, and make sure the system entry exists:

```
*/5 * * * * php -f /path/to/nextcloud/cron.php
```

This matters more for this app than for most, because of how the other two
modes run jobs:

* **AJAX** fires `SyncTimedJob` only when somebody happens to load a page, so
  scheduled syncs become erratic rather than absent — harder to notice.

* **Webcron** — an external service fetching `/cron.php` over HTTP — runs
  **exactly one background job per request**, whatever the request costs you.
  Nextcloud's `CronService::runWeb()` calls `getNext()` once and stops; only
  the CLI path loops for fourteen minutes. A quarter-hourly webcron therefore
  drains four jobs an hour across the *whole instance*, and the queue is
  ordered by `last_checked`, oldest first. Any backlog of always-due
  `QueuedJob` rows — the classic one is `UpdateSingleMetadata` piling up for
  a user who no longer exists — sits permanently in front of everything else,
  and Contacts Hub simply never gets a turn. Nothing about that is logged
  against this app, because this app is never reached.

If scheduled syncs do not happen, check the job itself before suspecting the
sync:

```bash
sudo -u www-data php occ background-job:list -c 'OCA\ContactHub\BackgroundJob\SyncTimedJob'
```

`Last run` stuck at 1970 (or at a date that never advances) means the job is
not being executed at all, and the answer is in the cron mode rather than in
the app. To prove the sync itself works, run it by hand with the id from that
listing:

```bash
sudo -u www-data php occ background-job:execute <id> --force-execute
```

If the instance is stuck behind a queued-job backlog, `occ background-job:list`
shows what is ahead of it, and `occ background-job:delete <id>` removes a row
that can never succeed.

Then open **Contacts Hub** from the app menu. There is no app icon yet, so it
gets the default placeholder.

## Updating

Replacing the files is **not** enough on its own. Nextcloud runs an app's
migrations only when `<version>` in `info.xml` changes, so any release that
touches `lib/Migration/` needs the version bumped first:

```bash
# bump <version> in appinfo/info.xml, rebuild the package
rm -rf contacthub && tar xzf ~/contacthub-<version>.tar.gz
php ../occ upgrade
```

For a code-only change, dropping the files in place is enough; clear the
opcode cache if the server has one.

## When enabling fails

`php occ app:list | grep contacthub` separates the two failure modes.

- **Not listed at all** — a discovery problem: wrong apps path (step 2),
  wrong directory name or nesting (step 3), or permissions. Confirm with
  `sudo -u www-data ls <apps dir>/contacthub/appinfo/info.xml`.
- **Listed under disabled, but enabling still fails** — a manifest problem.
  Usually `info.xml`: an unparseable file is reported only as "appinfo file
  cannot be read", and a `<background-jobs>` or `<settings>` entry naming a
  class that does not exist breaks the enable too.
