<?php

declare(strict_types=1);

namespace OCA\ContactHub\Sync;

use OCA\ContactHub\Db\JobMapper;
use OCP\IDBConnection;

/**
 * Per-job mutual exclusion, and the liveness signal the resume logic needs.
 *
 * The standalone app used MySQL's GET_LOCK, which bought two things at once:
 * exclusion, and a *proof* of liveness -- MySQL drops a session lock when the
 * holding connection dies, so a run row stuck at 'running' whose lock was free
 * was provably owned by a dead process, and could be safely repaired and
 * resumed.
 *
 * Nextcloud must run on MySQL, PostgreSQL and SQLite. Postgres advisory locks
 * have different semantics, SQLite has no advisory locking at all, and a
 * background job does not hold one long-lived connection the way a single
 * PHP-FPM request did. So exclusion becomes a compare-and-set on
 * contacthub_jobs.locked_until, refreshed by a heartbeat while a run works.
 *
 * The trade is real and worth stating plainly: GET_LOCK proved the owner was
 * gone, a TTL only makes it overwhelmingly likely. A lock whose locked_until
 * has passed means the holder either died or has been wedged for longer than
 * TTL_SECONDS without completing a single item. Both warrant taking the job
 * over, but the second is a stolen lock rather than a reclaimed one -- which
 * is why TTL_SECONDS is generous (five minutes) rather than tuned tight. The
 * cost of waiting is bounded: a crashed run resumes on the next cron tick
 * after the TTL expires, and cron runs every five minutes anyway.
 *
 * A holder must heartbeat more often than TTL_SECONDS. Runner does so between
 * every run_items row, and its per-item work is bounded by a handful of HTTP
 * calls at a 30s timeout each -- comfortably inside the window.
 */
class JobLock
{
    /**
     * How long an acquired lock stays valid without a heartbeat. Long enough
     * that no legitimately-working run can lose it mid-item, short enough that
     * a killed run is reclaimed within one cron interval.
     */
    public const int TTL_SECONDS = 300;

    public function __construct(
        private readonly IDBConnection $db,
    ) {
    }

    /**
     * Take the lock if it is free or expired.
     *
     * Returns an opaque token identifying this holder, or null if another
     * process holds a live lock. The token matters: without it a process whose
     * lock had expired and been taken over could still release or heartbeat
     * the *new* holder's lock.
     */
    public function acquire(int $jobId): ?JobLockHandle
    {
        $token = bin2hex(random_bytes(16));
        $now = $this->now();

        $qb = $this->db->getQueryBuilder();
        $qb->update(JobMapper::TABLE)
            ->set('locked_until', $qb->createNamedParameter($this->expiry()))
            ->set('lock_token', $qb->createNamedParameter($token))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($jobId)))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('locked_until'),
                $qb->expr()->lt('locked_until', $qb->createNamedParameter($now)),
            ));

        // A single conditional UPDATE is the whole mutex: whichever process
        // the database serialises first sees 1 affected row, the other sees 0.
        return $qb->executeStatement() === 1
            ? new JobLockHandle($this, $jobId, $token)
            : null;
    }

    /**
     * Push the expiry out. Returns false if the lock was lost -- taken over
     * after expiring, or released elsewhere -- which the caller must treat as
     * "stop working, someone else owns this job now".
     */
    public function heartbeat(int $jobId, string $token): bool
    {
        $qb = $this->db->getQueryBuilder();
        $qb->update(JobMapper::TABLE)
            ->set('locked_until', $qb->createNamedParameter($this->expiry()))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($jobId)))
            ->andWhere($qb->expr()->eq('lock_token', $qb->createNamedParameter($token)));

        return $qb->executeStatement() === 1;
    }

    /** Release only if we still hold it; releasing someone else's lock is a bug. */
    public function release(int $jobId, string $token): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->update(JobMapper::TABLE)
            ->set('locked_until', $qb->createNamedParameter(null))
            ->set('lock_token', $qb->createNamedParameter(null))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($jobId)))
            ->andWhere($qb->expr()->eq('lock_token', $qb->createNamedParameter($token)));
        $qb->executeStatement();
    }

    /**
     * Whether some live process currently holds this job.
     *
     * This is what replaces "ask MySQL whether the lock is free" in the
     * stale-run repair path: a run row still marked 'running' while this
     * returns false means its owner is gone and the run can be resumed.
     */
    public function isHeld(int $jobId): bool
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from(JobMapper::TABLE)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($jobId)))
            ->andWhere($qb->expr()->isNotNull('locked_until'))
            ->andWhere($qb->expr()->gte('locked_until', $qb->createNamedParameter($this->now())))
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $held = $result->fetch();
        $result->closeCursor();

        return $held !== false;
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    private function expiry(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('+' . self::TTL_SECONDS . ' seconds')
            ->format('Y-m-d H:i:s');
    }
}
