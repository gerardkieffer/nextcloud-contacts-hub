# Timeouts: every layer, and why they can't conflict

A sync run makes many slow HTTP calls to a CardDAV server, so several
independent time limits apply to it at once. This page lists each one, what
clock it actually counts, and how the app guarantees that the limits it
controls always sit safely inside the ones it doesn't.

## The layers

| # | Limit | Value | Clock | Set by |
|---|-------|-------|-------|--------|
| 1 | `web_time_budget_seconds` | 20 (default) | wall | app config, clamped by `Sync\TimeBudget` to 5-120 |
| 2 | Per-request HTTP timeout | 30 (`http_timeout_seconds`) | wall | app config, via `IClientService`/Guzzle |
| 3 | Per-job cron budget | 120, capped by what is left of the tick | wall | `Sync\CronBudget::forJob()` |
| 4 | Whole-tick cron deadline | 600 on system cron, 120 under webcron | wall | `Sync\CronBudget::tick()` |
| 5 | `JobLock` TTL | 300 | wall | `Sync\JobLock::TTL_SECONDS` — how long a lock survives without a heartbeat |
| 6 | PHP `max_execution_time` | server-controlled | **CPU, not wall, on Linux** (see below) | hosting |
| 7 | Reverse proxy / web server timeout | hosting-dependent | wall | hosting |

Rows 6-7 are outside this app's control and vary by hosting, unlike the old
standalone app which shipped its own Apache config. Whatever numbers apply on
a given Nextcloud instance, row 3's 120s ceiling (plus the worst-case overrun
of a few HTTP calls at 30s each) needs to stay comfortably under them — a
config typo pushing the web budget toward its 120s clamp should never be able
to produce a request the proxy kills mid-write.

## The non-obvious part: PHP's execution limit measures CPU seconds, not wall seconds — on Linux

On Linux, PHP implements `max_execution_time` with `setitimer(ITIMER_PROF)`,
which counts CPU time consumed by the process — time blocked on I/O (an HTTP
call to a CardDAV server, a database query) does not count. A sync run is
almost pure I/O wait, so this limit practically never fires for one on Linux,
which is what every deployment target for this app actually runs.

Consequences:

- Comparing the app's wall-clock budget (row 1) against
  `ini_get('max_execution_time')` would be comparing apples to oranges; the
  app deliberately doesn't. `Sync\TimeBudget::clamp()` is a fixed ceiling
  derived from realistic worst cases, not a live comparison against server
  config it cannot meaningfully read.
- The limit that *can* actually kill a run mid-item is the reverse
  proxy/web-server layer (row 7), which PHP cannot read at runtime. That is
  why the guard is a conservative fixed ceiling rather than a dynamic check.
- `max_execution_time` still bites on CPU-heavy phases (parsing a very large
  address book, base64 photo work). Those phases are bounded and fast in
  practice.

**Platform caveat**: this CPU-vs-wall distinction is Linux-specific — on
macOS, `max_execution_time` behaves as a wall-clock limit instead. It has no
practical effect on this project's own workflow because every PHP execution
path (`./dev/php`, the dev Nextcloud container, `./dev/phpunit-integration`)
runs inside a Linux container regardless of host OS (see [CLAUDE.md](../CLAUDE.md)).
It would only matter if PHP ran directly on a macOS host.

## Job locking: a TTL, not a database advisory lock

`Sync\JobLock` (row 5) is a compare-and-set on `locked_until`, refreshed by a
heartbeat between every `run_items` row a run processes. This exists because
Nextcloud must run on MySQL, PostgreSQL or SQLite, and only MySQL has
`GET_LOCK`-style advisory locks with a hard liveness guarantee (the lock is
provably dropped when the holding connection dies). A TTL trades that proof
for high probability: a lock whose `locked_until` has passed means the holder
either died or has been stuck for longer than the TTL without completing a
single item, and both cases warrant taking the job over. See the full
reasoning in `Sync\JobLock`'s own docblock, and [CLAUDE.md](../CLAUDE.md)'s
"JobLock is a TTL, not MySQL's GET_LOCK" gotcha.

Two consequences worth knowing:

- **A second trigger hitting a locked job gets `RunAlreadyActive`** and
  treats it as "come back later" — the web UI keeps polling, a cron tick
  just skips that job for this pass — instead of two processes
  double-dispatching the same queue.
- **A process killed mid-run** (OOM, `kill -9`) leaves its lock to expire on
  its own after `TTL_SECONDS` (five minutes). The next trigger that finds the
  run row still `running` but the lock no longer held (`JobLock::isHeld()`
  returns false) repairs it to `paused` and resumes in the same breath. The
  item the dead process was inside stays `pending` and is simply
  re-dispatched — safe, because every push is a conditional PUT against
  freshly listed etags (see [CLAUDE.md](../CLAUDE.md)'s "Every PUT is
  conditional"), so a half-applied item is either cleanly re-applied or
  surfaces as a per-item error rather than a silent duplicate.

## How a web-triggered run stays inside the server's limits

`Sync\TimeBudget::clamp()` bounds the configured budget into **[5, 120]**
seconds, and every web-triggered run (the "Apply now" button) goes through
it:

- **120 max** because the worst case for one request is *budget + one full
  item*: the deadline is only checked between `run_items`, and a single item
  can span a few HTTP calls of up to 30s each (row 2). Whatever wall-clock
  ceiling the hosting reverse proxy applies, 120s plus that worst-case
  overrun needs to stay under it, so a config typo of `3600` cannot produce
  requests the proxy would kill.
- **5 min** because a zero/negative budget wouldn't fail loudly — it would
  silently degrade every run into a one-item-per-request crawl.

The budget clock starts **at the beginning of the request, before the
fetch** — not after planning. This matters more than it sounds: the initial
fetch can dominate a run, as it does against Mailo (see
[server-capabilities.md](server-capabilities.md#3-mailo-carddavmailocom) and
"chunked fetch" below). A deadline started after planning would let a
"20 second" budget produce a request far longer than the budget promises —
precisely the overrun the budget exists to prevent.

The cost of counting the fetch lands only on a run's *first* request, and
that is the right place for it: that request has to fetch and materialize
before it can do anything, so it pauses after one item and hands the rest to
the resume path — which re-fetches nothing (just a cheap `listEtags`) and
therefore gets the whole budget for real work. Pinned by
`RunnerTest::testTheTimeBudgetCoversTheFetchPhaseNotJustTheItemLoop`.

## Cron is not always the CLI, and the difference is a whole layer

**Cron-triggered runs get a per-job budget of 120s** (row 3) regardless of
the web budget config, and the whole tick is bounded separately (row 4) so
one slow job cannot starve the rest of the due jobs in the same tick.
Anything not reached simply waits for the next tick, since all work lives in
`run_items` rather than in the process.

The tick bound, though, depends on **how cron was invoked**, and the first
version of this code assumed cron always meant the CLI:

* **System cron** (`*/5 * * * * php -f cron.php`) has no browser and no
  reverse proxy in front of it, and `CronService::runCli()` keeps handing out
  jobs for fourteen minutes. 600s of tick sits comfortably inside that.

* **Webcron** — an external service fetching `/cron.php` over HTTP, the only
  option on hosting with no shell — is an ordinary web request. Every
  wall-clock limit in rows 6-7 applies to it exactly as it applies to "Apply
  now". So under a web SAPI the tick gets the **same 120s ceiling** a
  web-triggered run gets, and the worst case is the one this page already
  argues is safe: the budget plus one item's overrun.

Getting that wrong is not a slow sync, it is a silent one. `cron.php`'s web
path — unlike `runCli()` — registers no shutdown handler to release the job,
so a tick the proxy cuts off leaves `reserved_at` set in `oc_jobs`, and
`JobList::getNext()` then skips the job until that reservation is **twelve
hours** old. One over-long tick bought half a day of nothing, with no log
line anywhere to say so.

The per-job budget is capped by the remaining tick for the same reason.
Checking the deadline before each job and then handing that job a full 120s
lets the tick overshoot by an entire job — harmless at 600s on the CLI,
and exactly the overrun that gets a webcron request killed. Below
`TimeBudget::MIN_SECONDS` no further job is started at all: it would fetch
and plan the whole address book, then pause before the first write.

Pinned by `tests/Sync/CronBudgetTest.php`.

## What happens when a limit is hit anyway

Two distinct cases, handled differently:

**The cooperative pause (normal, invisible).** The budget expires between
two items → the run is marked `paused` with its remaining queue persisted in
`run_items`. Resumption is automatic, from whichever of these fires first:

- the run panel shown after "Apply now" auto-resubmits the run every couple
  of seconds until the queue drains (`RunPanel.vue`);
- the next `SyncTimedJob` tick resumes any open run **regardless of the
  job's interval** — see `SyncTimedJob`'s own docblock: the interval governs
  how often a run *starts*, not how long one may sit half-finished;
- a manual click on Resume, which still exists but should never be
  *required*.

**The hard kill (abnormal, self-healing).** Something non-cooperative kills
the process mid-item — OOM, `kill -9`, a proxy-adjacent failure. See "Job
locking" above: the next trigger, finding the lock's TTL expired, repairs the
run row to `paused` and resumes it. The item the dead process was inside is
simply re-dispatched, safe for the same conditional-PUT reason.

**When is a human actually needed?** Only when a run ends up `failed`: a
systemic error (endpoint unreachable, auth broken) aborts the run and closes
it with the reason recorded. Auto-resume stops — deliberately, since
retrying into a broken endpoint forever helps nobody — and the jobs page
shows the error with the manual Run controls. Per-item errors do *not* fail
the run; they're recorded on the item and the run completes with an error
list.

## When a single request is too slow for the server: chunked fetch

The per-request HTTP timeout (row 2) bounds one HTTP request. The one
request that can legitimately need a long time is the whole-collection
`addressbook-query` REPORT that starts every run — it asks the server to
serialize every vCard in the book into one response.

Some servers cannot do that at all. **Mailo** returns *zero bytes* and times
out on a book of any real size, while the same server answers a `PROPFIND`
etag listing and `addressbook-multiget` chunks quickly — see
[server-capabilities.md §3](server-capabilities.md#3-mailo-carddavmailocom)
for the measured numbers. Raising the timeout does not help there — the
response never starts arriving.

So `Client::fetchAllVCards()` catches `DavTimeout` specifically and falls
back to: list hrefs by PROPFIND, then pull bodies in `addressbook-multiget`
chunks of 50.

Two deliberate constraints:

- **Only a timeout triggers the fallback.** A 4xx/5xx means the server
  refused, and refusing in 16 smaller pieces is still refusing. Pinned by
  `ClientTest::testFetchAllDoesNotFallBackOnAServerError`.
- **The fallback is automatic, not configured.** Nothing about an endpoint
  says "this server needs chunking" — it is discovered per request, so a
  server that gets slower (or faster) needs no config change.

Raising `http_timeout_seconds` is therefore rarely the right response to a
timeout. It helps only when a server is *uniformly* slow but does eventually
answer; when a specific large response is the problem, the fallback already
handles it.

## Showing the user that something is happening

A run reports what it is doing through `Sync\Progress`, a plain callback the
web layer turns into an UPDATE on the `runs` row (cron runs pass nothing and
so write no progress traffic at all). Phases: `fetch_a`, `fetch_b`,
`planning`, `applying`, `resuming` — plus a per-item message naming the
contact (`Creating contact: Ada Lovelace`) and a `current / total` count.

The browser mints a `progress_token`, stored on the run row, so a polling
page follows *its own* run rather than whatever ran last. The frontend polls
`GET /api/v1/progress` (`RunController::progress()`) roughly once a second
while a run is in flight; `Progress` throttles how often it actually writes,
so the polling frequency does not translate into a write on every tick.

## Interval vs. budget

`interval_seconds` (per job) and the time budgets above are orthogonal: the
interval decides when a run may *start*, the budgets decide how much of it
one request or one cron tick may execute. The interaction worth knowing is
the deliberate bypass described above: an open run is always eligible to
continue, even when the interval says the *next* run isn't due yet.
