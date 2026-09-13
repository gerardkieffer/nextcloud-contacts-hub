<?php

declare(strict_types=1);

namespace OCA\ContactHub\Db;

use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IParameter;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Per-job sync bookkeeping: contact/group state, runs, run items, conflicts.
 *
 * All state is scoped per job id -- the same uid on two different jobs is
 * tracked independently. There is no user_id here: these rows hang off a job,
 * and the job carries the ownership. Callers must therefore have already
 * resolved the job through JobMapper (which does filter by user) before
 * touching anything in this class.
 *
 * Column names follow the *roles* (a hub_ set and an endpoint_ set), never
 * the Planner's generic a/b sides. Sync\SideMap owns that translation, and
 * the reason is in its docblock: which role plays side A flips with a job's
 * direction, so raw a/b would invert the meaning of every stored row the
 * moment a direction was edited.
 */
class StateMapper extends Mapper
{
    public const string CONTACT_STATE = 'contacthub_cstate';
    public const string GROUP_STATE = 'contacthub_gstate';
    public const string RUNS = 'contacthub_runs';
    public const string RUN_ITEMS = 'contacthub_run_items';
    public const string CONFLICTS = 'contacthub_conflicts';

    // Anything not listed here is silently dropped by upsert(), so a new
    // state column has to be added in both places or its writes vanish
    // without an error.
    private const array STATE_COLUMNS = [
        'hub_href', 'hub_etag', 'hub_hash', 'hub_rev',
        'endpoint_href', 'endpoint_etag', 'endpoint_hash', 'endpoint_rev',
        'archived_hub', 'archived_endpoint', 'cancelled', 'payload_json',
    ];

    private const array BOOL_COLUMNS = ['archived_hub', 'archived_endpoint', 'cancelled'];

    // ---------------------------------------------------------------- state

    /** @return array<string, array<string, mixed>> keyed by uid */
    public function allContacts(int $jobId): array
    {
        return array_column($this->fetchAllFor(self::CONTACT_STATE, $jobId), null, 'uid');
    }

    /** @return array<string, array<string, mixed>> keyed by uid */
    public function allGroups(int $jobId): array
    {
        return array_column($this->fetchAllFor(self::GROUP_STATE, $jobId), null, 'uid');
    }

    /** @return list<array<string, mixed>> */
    private function fetchAllFor(string $table, int $jobId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($table)
            ->where($qb->expr()->eq('job_id', $qb->createNamedParameter($jobId)));

        return $this->rows($qb);
    }

    /** @param array<string, mixed> $fields role-named columns */
    public function upsertContact(int $jobId, string $uid, array $fields): void
    {
        $this->upsert(self::CONTACT_STATE, $jobId, $uid, $fields);
    }

    /** @param array<string, mixed> $fields role-named columns plus payload_json */
    public function upsertGroup(int $jobId, string $uid, array $fields): void
    {
        $this->upsert(self::GROUP_STATE, $jobId, $uid, $fields);
    }

    public function deleteContact(int $jobId, string $uid): void
    {
        $this->deleteState(self::CONTACT_STATE, $jobId, $uid);
    }

    public function deleteGroup(int $jobId, string $uid): void
    {
        $this->deleteState(self::GROUP_STATE, $jobId, $uid);
    }

    private function deleteState(string $table, int $jobId, string $uid): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($table)
            ->where($qb->expr()->eq('job_id', $qb->createNamedParameter($jobId)))
            ->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));
        $qb->executeStatement();
    }

    /**
     * Partial upsert: only the columns present in $fields are written, so a
     * caller that knows about one side's href/etag need not restate the
     * other's. Omitting a column must never blank it.
     *
     * MySQL's INSERT ... ON DUPLICATE KEY UPDATE is not portable, so this is
     * update-then-insert. The subtlety is that "0 rows affected" from the
     * UPDATE has two meanings: on MySQL it also means "the row exists and the
     * new values were identical" (MySQL reports changed rows, not matched
     * ones). So a following INSERT can legitimately collide, and a unique
     * violation there is the expected outcome rather than an error -- it
     * proves the row was already in the state we wanted.
     *
     * @param array<string, mixed> $fields
     */
    private function upsert(string $table, int $jobId, string $uid, array $fields): void
    {
        $fields = array_intersect_key($fields, array_flip(self::STATE_COLUMNS));
        $fields['updated_at'] = $this->now();

        // Two distinct field sets, and keeping them apart is the whole point.
        //
        // $updatable is what an UPDATE may write: only what the caller
        // actually passed. $insertable adds the payload_json default, which
        // exists purely to satisfy the NOT NULL column on a first insert.
        //
        // They used to be one array, mutated in place before the insert and
        // then reused by the retry below. On MySQL that is reachable and
        // destructive: an UPDATE that matches a row without changing it
        // reports 0 affected, so the insert runs, collides, and the retry
        // wrote payload_json = '{}' over an existing group's real payload.
        $updatable = $fields;

        if ($this->updateState($table, $jobId, $uid, $updatable) > 0) {
            return;
        }

        $insertable = $updatable;
        if ($table === self::GROUP_STATE && !array_key_exists('payload_json', $insertable)) {
            $insertable['payload_json'] = '{}';
        }

        try {
            $qb = $this->db->getQueryBuilder();
            $values = [
                'job_id' => $qb->createNamedParameter($jobId),
                'uid' => $qb->createNamedParameter($uid),
            ];
            foreach ($insertable as $column => $value) {
                $values[$column] = $this->param($qb, $column, $value);
            }
            $qb->insert($table)->values($values);
            $qb->executeStatement();
        } catch (DbException $e) {
            if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                throw $e;
            }
            // Raced, or the UPDATE matched but changed nothing. Either way
            // the row exists, so write what we were asked to -- and nothing
            // more, which is why this uses $updatable and not $insertable.
            $this->updateState($table, $jobId, $uid, $updatable);
        }
    }

    /** @param array<string, mixed> $fields @return int rows affected */
    private function updateState(string $table, int $jobId, string $uid, array $fields): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->update($table);
        // Every field passed is written. Which fields those are is the
        // caller's decision; upsert() is careful never to hand this the
        // payload_json default.
        foreach ($fields as $column => $value) {
            $qb->set($column, $this->param($qb, $column, $value));
        }
        $qb->where($qb->expr()->eq('job_id', $qb->createNamedParameter($jobId)))
            ->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));

        return $qb->executeStatement();
    }

    /** Booleans need an explicit type or Postgres rejects an int for a bool column. */
    private function param(IQueryBuilder $qb, string $column, mixed $value): IParameter|string
    {
        return in_array($column, self::BOOL_COLUMNS, true)
            ? $qb->createNamedParameter((bool) $value, IQueryBuilder::PARAM_BOOL)
            : $qb->createNamedParameter($value);
    }

    // ----------------------------------------------------------------- runs

    public function startRun(int $jobId, bool $dryRun, string $triggerSource, string $progressToken = ''): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->insert(self::RUNS)->values([
            'job_id' => $qb->createNamedParameter($jobId),
            'started_at' => $qb->createNamedParameter($this->now()),
            'dry_run' => $qb->createNamedParameter($dryRun, IQueryBuilder::PARAM_BOOL),
            'trigger_source' => $qb->createNamedParameter($triggerSource),
            'status' => $qb->createNamedParameter('running'),
            'progress_token' => $qb->createNamedParameter($progressToken !== '' ? $progressToken : null),
        ]);
        $qb->executeStatement();

        return $qb->getLastInsertId();
    }

    /**
     * @param string[] $warnings
     * @param string[] $errors
     *
     * $status/$pausedReason let a systemic failure be recorded as 'failed'
     * with a reason instead of the default 'completed'. Either way this sets
     * finished_at, so the run is no longer open (see findOpenRun). A
     * cooperative pause does NOT come here -- see setRunStatus, which leaves
     * finished_at null.
     */
    public function finishRun(
        int $runId,
        int $created,
        int $updated,
        int $deleted,
        int $archived,
        int $conflicts,
        array $warnings,
        array $errors,
        string $status = 'completed',
        ?string $pausedReason = null,
    ): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update(self::RUNS)
            ->set('finished_at', $qb->createNamedParameter($this->now()))
            ->set('status', $qb->createNamedParameter($status))
            ->set('paused_reason', $qb->createNamedParameter($pausedReason))
            ->set('created', $qb->createNamedParameter($created, IQueryBuilder::PARAM_INT))
            ->set('updated', $qb->createNamedParameter($updated, IQueryBuilder::PARAM_INT))
            ->set('deleted', $qb->createNamedParameter($deleted, IQueryBuilder::PARAM_INT))
            ->set('archived', $qb->createNamedParameter($archived, IQueryBuilder::PARAM_INT))
            ->set('conflicts', $qb->createNamedParameter($conflicts, IQueryBuilder::PARAM_INT))
            ->set('errors', $qb->createNamedParameter(count($errors), IQueryBuilder::PARAM_INT))
            ->set('warnings_json', $qb->createNamedParameter(json_encode($warnings)))
            ->set('errors_json', $qb->createNamedParameter(json_encode($errors)))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($runId)));
        $qb->executeStatement();
    }

    /** @return array<string, mixed>|null */
    public function getRun(int $runId): ?array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from(self::RUNS)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($runId)));

        return $this->row($qb);
    }

    /**
     * The job's most recent *real* run still awaiting completion.
     *
     * Dry runs are excluded deliberately. A preview opens its run row before
     * fetching so it has somewhere to report progress, which on a slow
     * endpoint leaves a 'running' row sitting there for a minute or more.
     * Without this filter a real run starting in that window would treat the
     * preview as a queue to resume and find no run_items behind it.
     *
     * @return array<string, mixed>|null
     */
    public function findOpenRun(int $jobId): ?array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from(self::RUNS)
            ->where($qb->expr()->eq('job_id', $qb->createNamedParameter($jobId)))
            ->andWhere($qb->expr()->eq('dry_run', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
            ->andWhere($qb->expr()->in('status', $qb->createNamedParameter(['running', 'paused'], IQueryBuilder::PARAM_STR_ARRAY)))
            ->orderBy('id', 'DESC')
            ->setMaxResults(1);

        return $this->row($qb);
    }

    /**
     * The open run for each of $jobIds, keyed by job id.
     *
     * Same filter as findOpenRun(), batched: the jobs list needs this for
     * every row, and asking per job turns opening a screen into one query
     * per sync job. Newest first with a first-wins merge, so a job with more
     * than one open row (a crash mid-write, before the repair path gets to
     * it) reports the same row findOpenRun() would pick.
     *
     * @param list<int> $jobIds
     * @return array<int, array<string, mixed>>
     */
    public function openRunsFor(array $jobIds): array
    {
        if ($jobIds === []) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from(self::RUNS)
            ->where($qb->expr()->in('job_id', $qb->createNamedParameter($jobIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->andWhere($qb->expr()->eq('dry_run', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
            ->andWhere($qb->expr()->in('status', $qb->createNamedParameter(['running', 'paused'], IQueryBuilder::PARAM_STR_ARRAY)))
            ->orderBy('id', 'DESC');

        $open = [];
        foreach ($this->rows($qb) as $row) {
            $open[(int) $row['job_id']] ??= $row;
        }

        return $open;
    }

    /** @return list<array<string, mixed>> the job's runs, newest first */
    public function runsForJob(int $jobId, int $limit = 50): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from(self::RUNS)
            ->where($qb->expr()->eq('job_id', $qb->createNamedParameter($jobId)))
            ->orderBy('id', 'DESC')
            ->setMaxResults($limit);

        return $this->rows($qb);
    }

    /** Marks a run paused or running -- never touches finished_at. */
    public function setRunStatus(int $runId, string $status, ?string $pausedReason = null): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->update(self::RUNS)
            ->set('status', $qb->createNamedParameter($status))
            ->set('paused_reason', $qb->createNamedParameter($pausedReason))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($runId)));
        $qb->executeStatement();
    }

    /**
     * User-initiated stop of an open run. Whatever was applied stays applied
     * -- the state tables are the source of truth for what is synced -- this
     * just frees the job to start fresh instead of resuming a queue the user
     * no longer wants continued.
     */
    public function abandonRun(int $runId, string $reason): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->update(self::RUNS)
            ->set('status', $qb->createNamedParameter('failed'))
            ->set('paused_reason', $qb->createNamedParameter($reason))
            ->set('finished_at', $qb->createNamedParameter($this->now()))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($runId)));
        $qb->executeStatement();
    }

    public function updateRunProgress(int $runId, int $totalItems, int $processedItems): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->update(self::RUNS)
            ->set('total_items', $qb->createNamedParameter($totalItems, IQueryBuilder::PARAM_INT))
            ->set('processed_items', $qb->createNamedParameter($processedItems, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($runId)));
        $qb->executeStatement();
    }

    /** Live progress of a run in flight (see Sync\Progress). */
    public function writeProgress(int $runId, string $phase, int $current, int $total, string $message): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->update(self::RUNS)
            ->set('progress_phase', $qb->createNamedParameter($phase))
            ->set('progress_current', $qb->createNamedParameter($current, IQueryBuilder::PARAM_INT))
            ->set('progress_total', $qb->createNamedParameter($total, IQueryBuilder::PARAM_INT))
            // The column is 255 chars and contact names are user data of
            // unknown length: cut on bytes, then repair any multibyte
            // character the cut landed inside.
            ->set('progress_message', $qb->createNamedParameter(mb_strcut($message, 0, 255)))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($runId)));
        $qb->executeStatement();
    }

    /** Late-bind a browser's progress token to a run it resumed rather than started. */
    public function attachProgressToken(int $runId, string $token): void
    {
        if ($token === '') {
            return;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->update(self::RUNS)
            ->set('progress_token', $qb->createNamedParameter($token))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($runId)));
        $qb->executeStatement();
    }

    /** @return array<string, mixed>|null */
    public function findRunByProgressToken(string $token): ?array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from(self::RUNS)
            ->where($qb->expr()->eq('progress_token', $qb->createNamedParameter($token)))
            ->orderBy('id', 'DESC')
            ->setMaxResults(1);

        return $this->row($qb);
    }

    /**
     * Runs stuck at 'running' past a threshold.
     *
     * With JobLock this is now only a UI hint -- Sync\JobLock::isHeld() is the
     * authoritative "is anyone actually working on this" signal, and the
     * Runner uses that to repair and resume. This remains for the status page
     * to flag a run as possibly stalled during the window before its lock TTL
     * expires.
     *
     * @return list<array<string, mixed>>
     */
    public function staleRunning(int $thresholdSeconds): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from(self::RUNS)
            ->where($qb->expr()->eq('status', $qb->createNamedParameter('running')));

        $now = time();

        return array_values(array_filter(
            $this->rows($qb),
            static function (array $r) use ($now, $thresholdSeconds): bool {
                $started = strtotime((string) $r['started_at']);
                return $started !== false && ($now - $started) >= $thresholdSeconds;
            },
        ));
    }

    // ------------------------------------------------------------ run items

    /**
     * Materialises a run's whole plan up front. Called once for a freshly
     * started real run; a resumed run reuses these rows. 'noop'/'conflict'
     * rows are inserted already-terminal, everything else starts 'pending'.
     *
     * @param list<array{kind: string, uid: string, action: string, source_href: ?string,
     *     source_snapshot: ?string, categories_json?: ?string, status?: string}> $items
     */
    public function materializeRunItems(int $runId, array $items): void
    {
        if ($items === []) {
            return;
        }

        $this->db->beginTransaction();
        try {
            foreach ($items as $item) {
                $status = $item['status'] ?? 'pending';
                $qb = $this->db->getQueryBuilder();
                $qb->insert(self::RUN_ITEMS)->values([
                    'run_id' => $qb->createNamedParameter($runId),
                    'kind' => $qb->createNamedParameter($item['kind']),
                    'uid' => $qb->createNamedParameter($item['uid']),
                    'action' => $qb->createNamedParameter($item['action']),
                    'source_href' => $qb->createNamedParameter($item['source_href'] ?? null),
                    'source_snapshot' => $qb->createNamedParameter($item['source_snapshot'] ?? null),
                    'categories_json' => $qb->createNamedParameter($item['categories_json'] ?? null),
                    'status' => $qb->createNamedParameter($status),
                    'processed_at' => $qb->createNamedParameter($status !== 'pending' ? $this->now() : null),
                ]);
                $qb->executeStatement();
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** @return list<array<string, mixed>> */
    public function pendingRunItems(int $runId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from(self::RUN_ITEMS)
            ->where($qb->expr()->eq('run_id', $qb->createNamedParameter($runId)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('pending')))
            ->orderBy('id');

        return $this->rows($qb);
    }

    /** @return list<array<string, mixed>> everything this run saw, in materialisation order */
    public function runItemsForRun(int $runId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from(self::RUN_ITEMS)
            ->where($qb->expr()->eq('run_id', $qb->createNamedParameter($runId)))
            ->orderBy('id');

        return $this->rows($qb);
    }

    public function markRunItem(int $id, string $status, ?string $errorMessage = null): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->update(self::RUN_ITEMS)
            ->set('status', $qb->createNamedParameter($status))
            ->set('error_message', $qb->createNamedParameter($errorMessage))
            ->set('processed_at', $qb->createNamedParameter($this->now()))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
        $qb->executeStatement();
    }

    // ------------------------------------------------------------ conflicts

    /**
     * No state is seeded when a duplicate is detected, so every run
     * re-detects a pending one; this dedup is what swallows the repeat.
     * Seeding eagerly would let an edit to the source silently overwrite the
     * matched copy before a human decided.
     */
    public function recordDuplicateConflict(
        int $jobId,
        string $uid,
        ?string $hubSnapshot,
        ?string $endpointSnapshot,
        string $duplicateJson,
    ): void {
        if ($this->hasUnresolvedConflict($jobId, $uid, 'contact')) {
            return;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->insert(self::CONFLICTS)->values([
            'job_id' => $qb->createNamedParameter($jobId),
            'uid' => $qb->createNamedParameter($uid),
            'kind' => $qb->createNamedParameter('contact'),
            'hub_snapshot' => $qb->createNamedParameter($hubSnapshot),
            'endpoint_snapshot' => $qb->createNamedParameter($endpointSnapshot),
            'detected_at' => $qb->createNamedParameter($this->now()),
            'duplicate_json' => $qb->createNamedParameter($duplicateJson),
        ]);
        $qb->executeStatement();
    }

    private function hasUnresolvedConflict(int $jobId, string $uid, string $kind): bool
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')->from(self::CONFLICTS)
            ->where($qb->expr()->eq('job_id', $qb->createNamedParameter($jobId)))
            ->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
            ->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter($kind)))
            ->andWhere($qb->expr()->isNull('resolved_at'))
            ->setMaxResults(1);

        return $this->row($qb) !== null;
    }

    /** @return list<array<string, mixed>> */
    public function unresolvedConflicts(int $jobId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from(self::CONFLICTS)
            ->where($qb->expr()->eq('job_id', $qb->createNamedParameter($jobId)))
            ->andWhere($qb->expr()->isNull('resolved_at'))
            ->orderBy('detected_at');

        return $this->rows($qb);
    }

    /** @return array<string, mixed>|null */
    public function findConflict(int $conflictId): ?array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from(self::CONFLICTS)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($conflictId)));

        return $this->row($qb);
    }

    public function resolveConflict(int $conflictId, string $resolution): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->update(self::CONFLICTS)
            ->set('resolved_at', $qb->createNamedParameter($this->now()))
            ->set('resolution', $qb->createNamedParameter($resolution))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($conflictId)));
        $qb->executeStatement();
    }

    // ------------------------------------------------------------- teardown

    /**
     * Remove every trace of a job: state, conflicts, runs and their items.
     *
     * Nextcloud migrations do not declare foreign keys, so there are no
     * database-level cascades to lean on. Deleting a job without this leaves
     * orphan rows that nothing will ever clean up, and -- worse -- a later
     * job reusing the id would inherit them.
     *
     * Run items hang off runs rather than the job, so they go first.
     */
    public function deleteEverythingForJob(int $jobId): void
    {
        $runIds = array_map(
            static fn(array $row): int => (int) $row['id'],
            $this->runsForJob($jobId, PHP_INT_MAX),
        );

        if ($runIds !== []) {
            $qb = $this->db->getQueryBuilder();
            $qb->delete(self::RUN_ITEMS)
                ->where($qb->expr()->in('run_id', $qb->createNamedParameter($runIds, IQueryBuilder::PARAM_INT_ARRAY)));
            $qb->executeStatement();
        }

        foreach ([self::RUNS, self::CONTACT_STATE, self::GROUP_STATE, self::CONFLICTS] as $table) {
            $qb = $this->db->getQueryBuilder();
            $qb->delete($table)
                ->where($qb->expr()->eq('job_id', $qb->createNamedParameter($jobId)));
            $qb->executeStatement();
        }
    }

    // --------------------------------------------------------------- helpers

    /** @return list<array<string, mixed>> */
    private function rows(IQueryBuilder $qb): array
    {
        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        return $rows;
    }

    /** @return array<string, mixed>|null */
    private function row(IQueryBuilder $qb): ?array
    {
        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        return $row === false ? null : $row;
    }
}
