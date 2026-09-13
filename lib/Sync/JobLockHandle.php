<?php

declare(strict_types=1);

namespace OCA\ContactHub\Sync;

/**
 * Proof that this process holds a job's lock, and the only way to renew or
 * release it.
 *
 * The token inside matters: a process whose lock expired and was taken over
 * must not be able to release or extend the new holder's lock. Every
 * operation here is conditional on still owning it.
 */
final class JobLockHandle
{
    public function __construct(
        private readonly JobLock $lock,
        public readonly int $jobId,
        public readonly string $token,
    ) {
    }

    /**
     * Push the expiry out. False means the lock was lost -- expired and taken
     * over by someone else -- and the caller must stop working on this job.
     */
    public function heartbeat(): bool
    {
        return $this->lock->heartbeat($this->jobId, $this->token);
    }

    public function release(): void
    {
        $this->lock->release($this->jobId, $this->token);
    }
}
