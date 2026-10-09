<?php

declare(strict_types=1);

namespace OCA\ContactHub\Tests\Integration;

use OCA\ContactHub\BackgroundJob\SendConflictPauseNotification;
use OCA\ContactHub\BackgroundJob\SyncTimedJob;
use OCA\ContactHub\Service\AddressBookService;
use OCA\ContactHub\Service\ConflictService;
use OCA\ContactHub\Service\JobService;
use OCA\ContactHub\Service\RunService;
use OCA\ContactHub\Sync\ConflictApplier;
use OCA\ContactHub\Sync\SyncJob;
use OCA\ContactHub\Tests\Support\FakeNotificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * A scheduled (cron-triggered) run that raises unresolved duplicate-match
 * conflicts pauses the job and queues a notification for the owner exactly
 * once; the job resumes on its own once every conflict is resolved.
 *
 * Drives the real SyncTimedJob (via its private runOne(), reached through
 * reflection -- run() itself iterates every enabled job across the whole
 * instance, which in a shared dev database can include real jobs pointing
 * at real external services, so it must never be invoked from a test).
 *
 * The actual notification send happens in a separate, one-shot
 * SendConflictPauseNotification queued job (see its docblock for why: SMTP
 * has no timeout this app controls, so it must never run inline inside
 * SyncTimedJob's per-tick loop). This test verifies SyncTimedJob queues that
 * job correctly via the real IJobList; SendConflictPauseNotification's own
 * behavior is verified separately below with a fake NotificationService.
 */
final class ConflictPauseTest extends IntegrationTestCase
{
    private function personVcard(string $uid, string $first, string $last, string $email, string $emailType = 'HOME'): string
    {
        return "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:{$uid}\r\nFN:{$first} {$last}\r\n"
            . "N:{$last};{$first};;;\r\nEMAIL;TYPE={$emailType}:{$email}\r\nEND:VCARD\r\n";
    }

    private function conflictService(): ConflictService
    {
        $addressBooks = new AddressBookService($this->backend);
        $jobService = new JobService($this->jobs, $this->state, $addressBooks, $this->endpoints, $this->stateReset());

        return new ConflictService($this->state, $this->conflictApplier(), $jobService);
    }

    private function timedJob(): SyncTimedJob
    {
        $addressBooks = new AddressBookService($this->backend);
        $jobService = new JobService($this->jobs, $this->state, $addressBooks, $this->endpoints, $this->stateReset());
        $runService = new RunService(
            $this->runner(withBackups: true),
            $this->state,
            $jobService,
            \OCP\Server::get(IAppConfig::class),
        );

        return new SyncTimedJob(
            \OCP\Server::get(ITimeFactory::class),
            $this->jobs,
            $this->state,
            $runService,
            $this->backupService(),
            \OCP\Server::get(IJobList::class),
            \OCP\Server::get(LoggerInterface::class),
        );
    }

    private function runOne(SyncTimedJob $timedJob, SyncJob $job, int $budgetSeconds = 60): void
    {
        $method = new \ReflectionMethod($timedJob, 'runOne');
        $method->setAccessible(true);
        $method->invoke($timedJob, $job, $budgetSeconds);
    }

    /** Seed a near-identical pair under different UIDs so the duplicate matcher flags them. */
    private function seedDuplicateConflict(SyncJob $job): void
    {
        $this->seedHub($this->personVcard('hub-new', 'Alice', 'Martin', 'alice@x.com'));
        $this->seedEndpoint('ep-old.vcf', $this->personVcard('ep-old', 'Alice', 'Martin', 'ALICE@x.com', 'WORK'));
    }

    /**
     * interval_seconds: 0 so isDue() stays true immediately after a run --
     * needed to isolate "still blocked by conflictPaused" from "not blocked,
     * just not due yet on the default interval", which the default-interval
     * makeJob() would otherwise conflate.
     */
    private function makeAlwaysDueJob(): SyncJob
    {
        $id = $this->jobs->create(self::USER_ID, [
            'name' => 'Test Job',
            'address_book_id' => $this->addressBookId,
            'endpoint_id' => $this->endpointId,
            'direction' => SyncJob::TO_ENDPOINT,
            'deletion_policy' => 'mirror',
            'interval_seconds' => 0,
        ]);

        return $this->jobs->find($id, self::USER_ID);
    }

    /** Cleans up a queued notification this test caused, so it never runs for real against the dev instance. */
    private function forgetQueuedNotification(int $jobId, int $conflictCount): void
    {
        \OCP\Server::get(IJobList::class)->remove(SendConflictPauseNotification::class, [
            'jobId' => $jobId,
            'userId' => self::USER_ID,
            'conflictCount' => $conflictCount,
        ]);
    }

    public function testCronRunWithConflictsPausesTheJobAndQueuesNotificationOnce(): void
    {
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);
        $this->seedDuplicateConflict($job);

        $jobList = \OCP\Server::get(IJobList::class);
        $argument = ['jobId' => $job->id, 'userId' => self::USER_ID, 'conflictCount' => 1];
        self::assertFalse($jobList->has(SendConflictPauseNotification::class, $argument), 'precondition');

        $this->runOne($this->timedJob(), $job);

        $refetched = $this->jobs->find($job->id, self::USER_ID);
        self::assertTrue($refetched->conflictPaused);
        self::assertNotNull($refetched->conflictPausedAt);

        self::assertTrue($jobList->has(SendConflictPauseNotification::class, $argument));

        $this->forgetQueuedNotification($job->id, 1);
    }

    public function testAPausedJobIsNotPickedUpAgainUntilResolved(): void
    {
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);
        $this->seedDuplicateConflict($job);

        $timedJob = $this->timedJob();
        $this->runOne($timedJob, $job);

        $paused = $this->jobs->find($job->id, self::USER_ID);
        self::assertTrue($paused->conflictPaused);
        $runsAfterFirstTick = $this->runCountFor($job->id);

        // A further tick must not start a new run -- the job is paused
        // until a human resolves the conflict.
        $this->runOne($timedJob, $paused);

        self::assertSame($runsAfterFirstTick, $this->runCountFor($job->id));

        $this->forgetQueuedNotification($job->id, 1);
    }

    public function testResolvingTheConflictClearsThePauseAndAllowsAFutureRun(): void
    {
        $job = $this->makeAlwaysDueJob();
        $this->seedDuplicateConflict($job);

        $timedJob = $this->timedJob();
        $this->runOne($timedJob, $job);

        $paused = $this->jobs->find($job->id, self::USER_ID);
        self::assertTrue($paused->conflictPaused);

        $row = $this->state->unresolvedConflicts($job->id)[0];
        $this->conflictService()->resolve((int) $row['id'], self::USER_ID, ConflictApplier::CANCEL);

        $resumed = $this->jobs->find($job->id, self::USER_ID);
        self::assertFalse($resumed->conflictPaused);

        // The job is due again (never run to completion) and no longer
        // paused, so a further tick works it as an ordinary job.
        $runsBefore = $this->runCountFor($job->id);
        $this->runOne($timedJob, $resumed);
        self::assertGreaterThan($runsBefore, $this->runCountFor($job->id));

        $this->forgetQueuedNotification($job->id, 1);
    }

    public function testSendConflictPauseNotificationRunsTheRealNotification(): void
    {
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);

        $notifications = new FakeNotificationService();
        $job2 = new SendConflictPauseNotification(
            \OCP\Server::get(ITimeFactory::class),
            $this->jobs,
            $notifications,
        );

        $method = new \ReflectionMethod($job2, 'run');
        $method->setAccessible(true);
        $method->invoke($job2, ['jobId' => $job->id, 'userId' => self::USER_ID, 'conflictCount' => 3]);

        self::assertSame(1, $notifications->calls);
        self::assertSame($job->id, $notifications->lastJob->id);
        self::assertSame(3, $notifications->lastConflictCount);
    }

    public function testSendConflictPauseNotificationIsANoOpWhenTheJobIsGone(): void
    {
        $notifications = new FakeNotificationService();
        $job2 = new SendConflictPauseNotification(
            \OCP\Server::get(ITimeFactory::class),
            $this->jobs,
            $notifications,
        );

        $method = new \ReflectionMethod($job2, 'run');
        $method->setAccessible(true);
        $method->invoke($job2, ['jobId' => 999999999, 'userId' => self::USER_ID, 'conflictCount' => 1]);

        self::assertSame(0, $notifications->calls);
    }
}
