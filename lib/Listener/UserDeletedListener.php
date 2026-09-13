<?php

declare(strict_types=1);

namespace OCA\ContactHub\Listener;

use OCA\ContactHub\Db\EndpointMapper;
use OCA\ContactHub\Db\JobMapper;
use OCA\ContactHub\Db\StateMapper;
use OCA\ContactHub\Sync\BackupService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * Removes a deleted user's endpoints, jobs and sync state.
 *
 * Not optional housekeeping. An endpoint row holds an encrypted CardDAV
 * password, and leaving one behind after the account is gone means retaining
 * a credential for an account nobody administers any more. The jobs would
 * also keep running: allEnabled() is deliberately unscoped, so the background
 * job would carry on syncing a departed user's address book until someone
 * noticed.
 *
 * @template-implements IEventListener<UserDeletedEvent>
 */
class UserDeletedListener implements IEventListener
{
    public function __construct(
        private readonly EndpointMapper $endpoints,
        private readonly JobMapper $jobs,
        private readonly StateMapper $state,
        private readonly BackupService $backups,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void
    {
        if (!$event instanceof UserDeletedEvent) {
            return;
        }

        $userId = $event->getUser()->getUID();

        // Credentials go FIRST, and every step is isolated.
        //
        // One try/catch around the lot meant a throw in an earlier step --
        // a transient database error, or hydrating a job whose endpoint row
        // was already stale -- skipped everything after it, leaving the
        // encrypted CardDAV password behind for an account that no longer
        // exists. That is the one outcome this listener exists to prevent,
        // so it runs before the bookkeeping rather than after it.
        $this->step($userId, 'endpoints', fn() => $this->endpoints->deleteAllForUser($userId));

        // State, runs and conflicts hang off a job, and there are no
        // database-level cascades, so each job is cleared before the job
        // rows themselves go.
        $this->step($userId, 'sync state', function () use ($userId): void {
            // idsForUser(), not allForUser(): the latter hydrates each job
            // and throws once its endpoint row is gone, which it now is.
            foreach ($this->jobs->idsForUser($userId) as $jobId) {
                $this->state->deleteEverythingForJob($jobId);
            }
        });

        $this->step($userId, 'jobs', fn() => $this->jobs->deleteAllForUser($userId));
        $this->step($userId, 'snapshots', fn() => $this->backups->deleteAllForUser($userId));
    }

    /**
     * Run one cleanup step, logging and swallowing its failure.
     *
     * Never let cleanup abort the user deletion itself, and never let one
     * failed step prevent the others.
     */
    private function step(string $userId, string $what, callable $action): void
    {
        try {
            $action();
        } catch (\Throwable $e) {
            $this->logger->error('Contacts Hub: could not remove {what} for deleted user {user}.', [
                'what' => $what,
                'user' => $userId,
                'exception' => $e,
            ]);
        }
    }
}
