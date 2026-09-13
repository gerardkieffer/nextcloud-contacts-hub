<?php

declare(strict_types=1);

namespace OCA\ContactHub\BackgroundJob;

use OCA\ContactHub\Db\JobMapper;
use OCA\ContactHub\Db\StateMapper;
use OCA\ContactHub\Service\RunService;
use OCA\ContactHub\Sync\BackupService;
use OCA\ContactHub\Sync\CronBudget;
use OCA\ContactHub\Sync\RunAlreadyActive;
use OCA\ContactHub\Sync\SyncJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Runs due sync jobs, and continues paused ones.
 *
 * This replaces both of the old app's automation paths: the system crontab
 * calling bin/sync-run.php, and the URL-triggered public cron endpoint that
 * existed because shared hosting often had no shell. Nextcloud's job runner
 * covers both, and drops the unauthenticated trigger URL along the way.
 *
 * Two rules govern which jobs get picked up:
 *
 *  * A job whose interval has elapsed runs. That is the ordinary case.
 *
 *  * A job with an *open* run is continued regardless of its interval. This
 *    is what makes pausing safe. A run that hits its time budget with work
 *    still queued leaves the queue in run_items, and something has to come
 *    back for it -- otherwise a job with a 24 hour interval would sit
 *    half-applied for a day.
 *
 * Each job gets its own time budget so one slow endpoint cannot starve the
 * rest of the tick, and the whole tick has a deadline of its own. Anything
 * not reached simply waits for the next tick; nothing is lost, because the
 * work lives in the database rather than in this process. How long those
 * budgets are depends on how cron was invoked, which is not a detail --
 * see {@see CronBudget}.
 *
 * The job is deliberately *time sensitive*, which is the default and used not
 * to be. Marking it TIME_INSENSITIVE reads like good manners -- contact sync
 * is not urgent -- but it means something specific to Nextcloud: an instance
 * with `maintenance_window_start` configured runs insensitive jobs only
 * inside that four hour window, once a day. A job whose whole purpose is to
 * honour a schedule the user chose cannot opt into being deferred to the
 * small hours; a job set to run hourly would have run overnight and then
 * not again until the next night.
 */
class SyncTimedJob extends TimedJob
{
    /** How often Nextcloud should consider running this. */
    private const int INTERVAL_SECONDS = 300;

    public function __construct(
        ITimeFactory $time,
        private readonly JobMapper $jobs,
        private readonly StateMapper $state,
        private readonly RunService $runs,
        private readonly BackupService $backups,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($time);

        $this->setInterval(self::INTERVAL_SECONDS);
        // Time sensitivity is left at the default (TIME_SENSITIVE) on purpose;
        // see the class docblock for what the insensitive flag actually costs.
    }

    protected function run($argument): void
    {
        $deadline = microtime(true) + CronBudget::tick(PHP_SAPI === 'cli');

        foreach ($this->jobs->allEnabled() as $job) {
            $budget = CronBudget::forJob($deadline - microtime(true));
            if ($budget === null) {
                $this->logger->debug('Contacts Hub: tick budget reached, remaining jobs wait for the next tick.');
                break;
            }
            $this->runOne($job, $budget);
        }

        $this->prune($deadline);
    }

    private function runOne(SyncJob $job, int $budgetSeconds): void
    {
        $openRun = $this->state->findOpenRun($job->id);

        // An open run is continued whatever the interval says; see the class
        // docblock. Without this a paused run waits a whole interval.
        if ($openRun === null && !$job->isDue()) {
            return;
        }

        try {
            $result = $this->runs->runFromCron($job, $budgetSeconds);

            if ($result->status === 'paused') {
                // Normal, not a failure: the next tick continues the queue.
                $this->logger->debug('Contacts Hub: job {job} paused with work remaining.', [
                    'job' => $job->id,
                ]);
            }
            if ($result->errors !== []) {
                $this->logger->warning('Contacts Hub: job {job} finished with {count} error(s).', [
                    'job' => $job->id,
                    'count' => count($result->errors),
                    'errors' => $result->errors,
                ]);
            }
        } catch (RunAlreadyActive) {
            // Someone else holds the job: a browser mid-run, or an overlapping
            // tick. Whoever holds it is making progress, so leave it alone.
        } catch (\Throwable $e) {
            // One broken job must not stop the others. A dead endpoint would
            // otherwise block every sync on the instance.
            $this->logger->error('Contacts Hub: job {job} failed.', [
                'job' => $job->id,
                'exception' => $e,
            ]);
        }
    }

    /**
     * Housekeeping, and the last thing a tick does.
     *
     * Skipped when the tick is already spent, because it is file I/O against
     * a user's home and the tick budget exists to keep a webcron request
     * inside the reverse proxy's patience -- overrunning it here costs the
     * same twelve hour reservation as overrunning it in a sync (see
     * Sync\CronBudget). Snapshots expire by age, so a tick that skips this
     * loses nothing; the next one prunes them.
     */
    private function prune(float $deadline): void
    {
        if (microtime(true) > $deadline) {
            return;
        }

        try {
            $removed = $this->backups->prune();
            if ($removed > 0) {
                $this->logger->debug('Contacts Hub: pruned {count} expired snapshot(s).', ['count' => $removed]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Contacts Hub: pruning snapshots failed.', ['exception' => $e]);
        }
    }
}
