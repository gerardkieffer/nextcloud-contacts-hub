# Installing manually

Until Contacts Hub is on the app store, it is installed by putting its files
in an apps directory and enabling it. There is no Composer install and no
build step on the server.

Nextcloud's own reference: [Apps
management](https://docs.nextcloud.com/server/stable/admin_manual/apps_management.html).

## Requirements

- Nextcloud **34 or 35**. On a newer major, Nextcloud disables the app during
  the upgrade, because it has not been tested there yet. You can turn it back
  on at your own risk with `php occ app:enable --force contacthub`; Nextcloud
  remembers that until the next major upgrade.
- PHP 8.3 or newer.

## 1. Get the app

Either clone the repository (see the [README](../README.md#installing)), or
use a package: a `contacthub-<version>.tar.gz` holding only what the server
needs. Build one from a checkout with

```bash
./dev/package                 # -> dist/contacthub-<version>.tar.gz
./dev/package /somewhere/else # or name the output directory
```

which needs nothing installed: no PHP, no Node, no Docker.

## 2. Check where Nextcloud looks for apps

**Do this before copying anything.** It is the step that most often goes
wrong, and it fails in a way that points at the wrong culprit.

```bash
cd /path/to/nextcloud
sudo -u www-data php occ config:system:get apps_paths
```

If that prints nothing, `apps_paths` is unset and **only the stock `apps/`
directory is scanned**. An app in `custom_apps/` is then never found, and
`occ app:enable contacthub` reports:

```
Could not download app contacthub, it was not found on the appstore
```

which reads like a network problem and is not one: `occ` did not find the app
locally and fell through to the app store.

Either use `apps/`, or declare the extra path in `config/config.php`:

```php
'apps_paths' => [
    [ 'path' => OC::$SERVERROOT.'/apps',        'url' => '/apps',        'writable' => false ],
    [ 'path' => OC::$SERVERROOT.'/custom_apps', 'url' => '/custom_apps', 'writable' => true  ],
],
```

The array **replaces** the default rather than extending it, so `apps` has to
stay in the list. Create `custom_apps` if it does not exist, and make it
writable by the web server user, or the Apps page fails with "Cannot write
into apps directory". The official Nextcloud Docker image already declares
`custom_apps` (in `config/apps.config.php`).

## 3. Unpack and enable

```bash
cd /path/to/nextcloud/custom_apps
tar xzf ~/contacthub-<version>.tar.gz
chown -R www-data:www-data contacthub
sudo -u www-data php ../occ app:enable contacthub
```

The directory must be named exactly **`contacthub`**, with
`appinfo/info.xml` one level inside it. A `contacthub-0.2.4/` wrapper, or a
`contacthub/contacthub/` nesting from unpacking into a folder of that name you
had already created, will not be found.

Run `occ` as the user PHP runs as: `www-data` on Debian and Ubuntu; on shared
hosting usually your own account, so plain `php occ`. Enabling creates the
app's `oc_contacthub_*` tables.

Over FTP: unpack locally, upload the `contacthub` folder, then enable it from
**Apps → Disabled apps** in the web interface.

Then open **Contacts Hub** from the app menu.

## 4. After enabling

Set **Administration settings → Basic settings → Background jobs** to
**Cron**, and make sure the system crontab entry exists for the web server
user:

```
*/5 * * * * php -f /path/to/nextcloud/cron.php
```

The other two modes do not suit a scheduled sync. **AJAX** runs background
jobs only when somebody has a page open, so syncs become erratic. **Webcron**
runs exactly one background job per call, across the whole instance, so a
backlog of other jobs can keep this app from ever getting a turn — and
nothing about that is logged against this app.

If scheduled syncs do not happen, check whether the job is being reached at
all:

```bash
sudo -u www-data php occ background-job:list -c 'OCA\ContactHub\BackgroundJob\SyncTimedJob'
```

A `Last run` stuck at 1970, or one that never advances, means the job is not
being executed, and the answer is in the cron mode rather than in the app.
To prove the sync itself works, run it by hand with the id from that listing:

```bash
sudo -u www-data php occ background-job:execute <id> --force-execute
```

If the instance is stuck behind a backlog, `occ background-job:list` shows
what is ahead of it, and `occ background-job:delete <id>` removes a job that
can never succeed.

## Updating

Replace the folder with the new version, then run the upgrade, which applies
any database changes:

```bash
cd /path/to/nextcloud/custom_apps
rm -rf contacthub && tar xzf ~/contacthub-<version>.tar.gz
chown -R www-data:www-data contacthub
sudo -u www-data php ../occ upgrade
```

From a clone, `git pull` replaces the first two lines.

## When enabling fails

`php occ app:list | grep contacthub` separates the two failure modes.

- **Not listed at all** — a discovery problem: wrong apps path (step 2),
  wrong directory name or nesting (step 3), or permissions. Confirm with
  `sudo -u www-data ls <apps dir>/contacthub/appinfo/info.xml`.
- **Listed under disabled, but enabling still fails** — usually a damaged
  `appinfo/info.xml`, which Nextcloud reports only as "appinfo file cannot be
  read". Check the Nextcloud log for the cause.
