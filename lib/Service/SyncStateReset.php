<?php

declare(strict_types=1);

namespace OCA\ContactHub\Service;

use OCA\ContactHub\Db\JobMapper;
use OCA\ContactHub\Db\StateMapper;
use OCA\ContactHub\Sync\JobLock;
use OCA\ContactHub\Sync\JobLockHandle;

/**
 * Applies a configuration change that alters *what* one or more jobs sync,
 * and makes those jobs forget their sync state in the same breath.
 *
 * What counts as such a change: a job moved to another address book or
 * another endpoint, or an endpoint moved to another collection, server or
 * account. State records where every synced contact lives -- endpoint
 * locations as absolute URLs -- so surviving any of those, it sent the next
 * run's writes and deletions to the *old* location, with the *new*
 * endpoint's credentials. See StateMapper::resetSyncState().
 *
 * Changing a job's direction is deliberately not one of these: both
 * locations stay valid, and state is stored by role precisely so that it
 * survives a direction change (see SideMap).
 *
 * The job locks are held across the change and the reset so a run cannot
 * be halfway through writing state for the old target while it is wiped. A
 * job that is mid-run refuses the save rather than waiting for it.
 */
class SyncStateReset
{
    public function __construct(
        private readonly JobMapper $jobs,
        private readonly StateMapper $state,
        private readonly JobLock $locks,
    ) {
    }

    /**
     * @param list<int> $jobIds
     * @param string $field the form field to report a refusal against
     * @param callable(): void $change the configuration write itself
     */
    public function applyAndReset(array $jobIds, string $userId, string $reason, string $field, callable $change): void
    {
        /** @var JobLockHandle[] $held */
        $held = [];
        try {
            foreach ($jobIds as $jobId) {
                $lock = $this->locks->acquire($jobId);
                if ($lock === null) {
                    throw ValidationException::field(
                        $field,
                        'A sync run is in progress for a job using this. Wait for it to finish, then save again.',
                    );
                }
                $held[] = $lock;
            }

            $change();

            foreach ($jobIds as $jobId) {
                $this->state->resetSyncState($jobId, $reason);
                // Every conflict was just closed, so a conflict pause has
                // nothing left to wait for.
                $this->jobs->setConflictPaused($jobId, $userId, false);
            }
        } finally {
            foreach ($held as $lock) {
                $lock->release();
            }
        }
    }
}
