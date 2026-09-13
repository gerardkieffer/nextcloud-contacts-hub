<?php

declare(strict_types=1);

namespace OCA\ContactHub\Sync;

/**
 * How much wall-clock time a cron tick, and each job inside it, may spend.
 *
 * Separate from {@see TimeBudget} because the constraint is different:
 * TimeBudget bounds one *user-triggered* request, this bounds a whole
 * background tick that may walk several jobs. Pure functions, so the
 * arithmetic is testable without a Nextcloud runtime.
 *
 * The only thing that really matters here is that the tick budget depends on
 * how cron was invoked, and the original version of this code got that wrong
 * by assuming cron always means CLI:
 *
 *  * **System cron** (`php -f cron.php`) has no browser and no reverse proxy
 *    in front of it, and Nextcloud's own runner keeps handing out jobs for
 *    fourteen minutes. Ten minutes of tick sits comfortably inside that.
 *
 *  * **Webcron** -- an external service fetching `/cron.php` over HTTP, which
 *    is the only option on hosting with no shell -- is an ordinary web
 *    request. Every wall-clock limit that bounds "Apply now" bounds it too:
 *    the reverse proxy in front of PHP will cut the request off at somewhere
 *    between 185s (measured on the target hosting, see docs/timeouts.md) and
 *    Apache's stock 300s, and unlike the CLI path Nextcloud registers no
 *    shutdown handler there to release the job. A tick killed that way leaves
 *    `reserved_at` set in `oc_jobs`, and Nextcloud then skips the job for a
 *    full twelve hours -- a ten minute tick budget under webcron buys one
 *    dead run and half a day of silence.
 *
 * So under a web SAPI the whole tick gets the same ceiling a web-triggered
 * run gets, and the worst case stays the one docs/timeouts.md already argues
 * is safe: the budget plus a single item's overrun.
 */
final class CronBudget
{
    /** Whole-tick deadline under system cron, inside Nextcloud's own 14 minute runner window. */
    public const int CLI_TICK_SECONDS = 600;

    /**
     * Whole-tick deadline under webcron/ajax. Deliberately the same number as
     * TimeBudget::MAX_SECONDS: it is the ceiling that was reasoned about
     * against real reverse-proxy limits, and a tick is no safer than a run.
     */
    public const int WEB_TICK_SECONDS = TimeBudget::MAX_SECONDS;

    /**
     * Per-job ceiling. Bounded so one enormous address book cannot consume a
     * whole tick; anything it does not finish stays queued in `run_items`.
     */
    public const int PER_JOB_SECONDS = 120;

    public static function tick(bool $isCli): int
    {
        return $isCli ? self::CLI_TICK_SECONDS : self::WEB_TICK_SECONDS;
    }

    /**
     * The budget for the next job, given what is left of the tick, or null
     * when the tick is too far gone to start another one.
     *
     * Capping by the remaining tick is what actually keeps the tick honest.
     * Checking the deadline before each job but then handing that job a full
     * per-job budget lets a tick overshoot by an entire job -- harmless at
     * 600s on the CLI, and exactly the overrun that gets a webcron request
     * killed.
     *
     * Below TimeBudget::MIN_SECONDS a job is not started at all. It would not
     * fail, it would fetch the whole address book, plan it, and then pause
     * before the first write -- paying a run's most expensive phase to do no
     * work. The next tick is a better place for that.
     */
    public static function forJob(float $secondsLeftInTick): ?int
    {
        $budget = (int) floor(min((float) self::PER_JOB_SECONDS, $secondsLeftInTick));

        return $budget >= TimeBudget::MIN_SECONDS ? $budget : null;
    }
}
