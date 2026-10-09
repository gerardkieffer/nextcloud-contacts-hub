<?php

declare(strict_types=1);

namespace OCA\ContactHub\BackgroundJob;

use OCA\ContactHub\Db\JobMapper;
use OCA\ContactHub\Service\NotificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

/**
 * Sends the "job paused for conflicts" email as its own one-shot queued job,
 * decoupled from SyncTimedJob's per-tick loop over every enabled job.
 *
 * SMTP is a real network round trip with no timeout this app controls --
 * `IMailer` exposes none, unlike the CardDAV path's `http_timeout_seconds`.
 * `CronBudget`'s per-job budgeting only bounds the CardDAV I/O in each sync;
 * calling the mailer inline from inside that same per-tick loop (as an
 * earlier version of this feature did) let one slow or unreachable mail
 * server stall every other due job for the rest of the tick -- exactly the
 * failure mode `CronBudget` exists to prevent, reintroduced through a
 * different, unbounded I/O path. Queuing a one-shot job instead means the
 * sync tick only ever pays for a cheap row insert; the send itself happens
 * in its own job slot, on its own time.
 *
 * $argument: {jobId: int, userId: string, conflictCount: int}
 */
class SendConflictPauseNotification extends QueuedJob
{
    public function __construct(
        ITimeFactory $time,
        private readonly JobMapper $jobs,
        private readonly NotificationService $notifications,
    ) {
        parent::__construct($time);
    }

    protected function run($argument): void
    {
        $jobId = (int) ($argument['jobId'] ?? 0);
        $userId = (string) ($argument['userId'] ?? '');
        $conflictCount = (int) ($argument['conflictCount'] ?? 0);

        // Deleted (or somehow reassigned) between being queued and running --
        // nothing to notify about any more.
        $job = $this->jobs->find($jobId, $userId);
        if ($job === null) {
            return;
        }

        $this->notifications->notifyJobPaused($job, $conflictCount);
    }
}
