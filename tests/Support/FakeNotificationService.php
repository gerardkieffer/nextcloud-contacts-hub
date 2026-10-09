<?php

declare(strict_types=1);

namespace OCA\ContactHub\Tests\Support;

use OCA\ContactHub\Service\NotificationService;
use OCA\ContactHub\Sync\SyncJob;

/**
 * Records calls instead of touching a real IMailer. Deliberately does not
 * call the parent constructor -- this fake never uses any of the real
 * dependencies, so none of them need to exist.
 */
final class FakeNotificationService extends NotificationService
{
    public int $calls = 0;
    public ?SyncJob $lastJob = null;
    public ?int $lastConflictCount = null;

    public function __construct()
    {
    }

    public function notifyJobPaused(SyncJob $job, int $conflictCount): void
    {
        $this->calls++;
        $this->lastJob = $job;
        $this->lastConflictCount = $conflictCount;
    }
}
