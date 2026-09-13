<?php

declare(strict_types=1);

namespace OCA\ContactHub\Sync;

use OCA\ContactHub\Db\JobMapper;
use OCA\ContactHub\Db\StateMapper;
use OCA\ContactHub\VCard\AddressBook;
use OCA\ContactHub\VCard\Contact;
use OCA\ContactHub\VCard\Document;
use OCA\ContactHub\VCard\Group;
use OCA\ContactHub\VCard\Model;
use OCA\ContactHub\VCard\Transform;
use Psr\Log\LoggerInterface;

/**
 * Executes a sync job. Used identically by the web "Run now" action and by
 * the background job -- neither contains sync logic itself, only this class
 * does.
 *
 * Every job connects the hub (a Nextcloud address book) to one endpoint.
 * Which of the two plays the
 * Planner's "side A" depends on the direction (see SideMap): a
 * from_endpoint job puts the endpoint in slot A so the Planner's single
 * one-way A -> B path covers both pull and push without needing a
 * mirror-image mode. State columns are named for the roles, so flipping
 * a job's direction does not reinterpret existing rows.
 *
 * Whether a run is actually *due* (per the job's configured interval) is
 * the caller's responsibility, not this class's -- an explicit "Run now"
 * click always runs; a cron/HTTP trigger checks SyncJob::isDue()
 * first and skips calling run() at all if it isn't time yet.
 *
 * A real (non-dry-run) run is resumable: the full plan is materialized
 * into `run_items` up front (see materializeNewRun/materializeItems),
 * and everything after that point is driven purely from that persisted
 * queue -- never from the in-memory AddressBook, which doesn't survive
 * across a resumed request. If $timeBudgetSeconds is given and runs
 * out with items still pending, the run is marked 'paused' (not
 * 'completed') and returned as-is; the next call to run() for the same
 * job (manual "Apply", cron, or HTTP cron -- whichever fires next)
 * detects that open run via StateMapper::findOpenRun() and
 * continues its queue instead of re-fetching/re-planning from scratch.
 */
class Runner
{
    public function __construct(
        private readonly StateMapper $state,
        private readonly JobMapper $jobs,
        private readonly JobLock $locks,
        private readonly SideFactory $sides,
        private readonly ?BackupService $backups = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Record something that went wrong, both for the run's own report and for
     * the Nextcloud log.
     *
     * Both, always. The run report is per-run and transient -- a paused run's
     * report is replaced when the next segment starts, and nobody is watching
     * a scheduled run's report at all -- so anything that only lands there is
     * effectively invisible a minute later. The log is where an administrator
     * looks after the fact, and is the only place a background failure can be
     * found. Every append goes through here so the two cannot drift apart.
     */
    private function warn(RunResult $result, SyncJob $job, string $message): void
    {
        $result->warnings[] = $message;
        $this->logger?->warning('Contacts Hub: {message}', [
            'message' => $message,
            'job' => $job->id,
            'job_name' => $job->name,
            'user' => $job->userId,
            'app' => 'contacthub',
        ]);
    }

    /**
     * Fold warnings raised before the RunResult existed into it.
     *
     * @param string[] $warnings
     */
    private function carry(RunResult $result, SyncJob $job, array $warnings): void
    {
        foreach ($warnings as $warning) {
            $this->warn($result, $job, $warning);
        }
    }

    /**
     * Explain, once, why a forced run keeps finding the same contacts.
     *
     * A forced one-way run compares the endpoint's copy against what this
     * app recorded writing there, and anything that no longer matches is
     * re-pushed. That is the point of the flag. But some servers do not
     * store what they were handed -- re-encoding a photo on upload is the
     * common one -- and for those the comparison fails again on the very
     * next forced run, forever, through no fault of either side.
     *
     * There is nothing to fix in that case, so this says so rather than
     * silently doing the work again. The photo count is what makes it
     * actionable: a drift set that is almost all photo-bearing contacts is
     * a re-encoding server, and a drift set that is not is somebody editing
     * contacts directly on the endpoint, which is worth knowing too.
     *
     * One line, not one per contact -- see CLAUDE.md on bounded warnings.
     */
    private function reportEndpointDrift(RunResult $result, SyncJob $job, AddressBook $bookB, SyncSide $sideB): void
    {
        $drifted = $result->plan->contactsDriftedOnB;
        if ($drifted === []) {
            return;
        }

        $withPhotos = 0;
        foreach ($drifted as $uid) {
            if ($bookB->contacts[$uid]?->hasPhoto ?? false) {
                $withPhotos++;
            }
        }

        $count = count($drifted);
        $detail = $withPhotos === $count
            ? 'all of them have photos, which normally means that server re-encodes photos on upload'
            : "{$withPhotos} of them have photos";

        $this->warn($result, $job, sprintf(
            'Forced run: %d contact%s in %s no longer match what was last written there (%s). '
                . 'They have been re-sent. Expect the same %s on every forced run if the server rewrites what it stores.',
            $count,
            $count === 1 ? '' : 's',
            $sideB->label(),
            $detail,
            $count === 1 ? 'contact' : 'contacts',
        ));
    }

    /**
     * Refuse to diff against a partly-read address book.
     *
     * Every fetch path can come back short without saying so.
     * `Client::collectAddressData()` skips any multistatus entry that
     * carries no address-data -- a resource the server refused, or a
     * response it truncated -- so a partial answer is indistinguishable from
     * a small address book. Nothing downstream can tell the difference
     * either, and the consequences are not symmetrical:
     *
     *  * the *plan* for a one-way job reads presence from listEtags(), which
     *    is a separate and complete listing, so it stays correct;
     *  * every other consumer reads the fetched book, so a contact missing
     *    from it looks deleted. On a from_endpoint job that means mirroring
     *    a deletion nobody made, into the side that still has the contact.
     *
     * The mismatch is cheap to see, because the complete listing is already
     * being fetched for its own reasons: count what came back against what
     * the collection says it holds. Short means stop -- an incomplete view
     * of an address book is the one input this engine must never guess at.
     *
     * Deliberately not a warning. A warning here would be read as noise
     * exactly when the run is about to delete things.
     *
     * There is a narrow race in it: another client deleting a contact
     * between the fetch and the listing makes a complete fetch look short.
     * Both orderings have an equivalent race, the window is one request
     * wide, and the outcome is a run that stops and says to try again --
     * which is the right way round. Guessing is the alternative.
     *
     * @param list<array{href: string, vcard: string}> $fetched
     * @param array<string, string> $etags
     */
    private static function assertWholeBookFetched(SyncSide $side, array $fetched, array $etags): void
    {
        $got = count($fetched);
        $expected = count($etags);
        if ($got >= $expected) {
            return;
        }

        throw new \RuntimeException(sprintf(
            'Reading %s returned %d of %d entries. Refusing to sync against a partly-read address book, '
                . 'because the missing ones are indistinguishable from deleted ones. '
                . 'This is usually a server truncating a large response; try again, and if it persists '
                . 'the address book may be too large for that server to return in one request.',
            $side->label(),
            $got,
            $expected,
        ));
    }

    private function fail(RunResult $result, SyncJob $job, string $message, ?\Throwable $e = null): void
    {
        $result->errors[] = $message;
        $this->logger?->error('Contacts Hub: {message}', [
            'message' => $message,
            'job' => $job->id,
            'job_name' => $job->name,
            'user' => $job->userId,
            'app' => 'contacthub',
            'exception' => $e,
        ]);
    }

    public function run(
        SyncJob $job,
        bool $dryRun,
        bool $force,
        string $triggerSource,
        ?int $timeBudgetSeconds = null,
        ?Progress $progress = null,
    ): RunResult {
        $progress ??= Progress::none();
        [$sideA, $sideB] = $this->sides->forJob($job);
        $map = $job->sideMap();

        if ($dryRun) {
            return $this->preview($job, $map, $force, $triggerSource, $sideA, $sideB, $progress);
        }

        // One process per job. Without this, two concurrent triggers -- the
        // browser's auto-resume racing a cron tick -- would double-dispatch
        // items off the same run_items queue.
        //
        // The lock is a TTL refreshed by a heartbeat, not MySQL's GET_LOCK
        // (which is not portable across the databases Nextcloud supports).
        // See JobLock: an expired lock means the holder died or has been
        // wedged for longer than the whole TTL, and either way the job is
        // safe to take over -- which is also how a 'running' row left by a
        // hard-killed process gets repaired and resumed below.
        $lock = $this->locks->acquire($job->id);
        if ($lock === null) {
            throw RunAlreadyActive::forJob($job);
        }

        try {
            return $this->runLocked($job, $map, $force, $triggerSource, $sideA, $sideB, $timeBudgetSeconds, $progress, $lock);
        } finally {
            $lock->release();
        }
    }

    private function runLocked(
        SyncJob $job,
        SideMap $map,
        bool $force,
        string $triggerSource,
        SyncSide $sideA,
        SyncSide $sideB,
        ?int $timeBudgetSeconds,
        Progress $progress,
        JobLockHandle $lock,
    ): RunResult {
        // The clock starts *here*, before the fetch -- not after
        // planning. What the server-side limits bound is the whole
        // request, and on some endpoints the initial fetch dominates it
        // (measured: 766 contacts off Mailo takes ~91s, because their
        // whole-collection REPORT times out and Client falls back to
        // chunked multiget). A deadline started after that would let a
        // "20 second" budget produce a 110+ second request.
        //
        // The cost lands only on the first request of a run, and it is
        // the right place for it: that request must fetch and
        // materialize before it can do anything else, so it pauses
        // after one item and hands the rest to the resume path -- which
        // re-fetches nothing (only a cheap listEtags) and so gets the
        // full budget for real work.
        $deadline = $timeBudgetSeconds !== null ? microtime(true) + $timeBudgetSeconds : null;

        $openRun = $this->state->findOpenRun($job->id);
        $runId = null;
        $result = null;

        // We hold the job lock, which means no live process owns this job.
        // So a row still saying 'running' belongs to a process that died
        // mid-item without getting to mark
        // itself paused. Repair it to 'paused' and fall through to the
        // normal resume path -- the queue in run_items is exactly the
        // work it hadn't finished. (The item it died inside stays
        // 'pending' and is re-dispatched; pushes are conditional PUTs
        // against freshly listed etags, so a half-applied item is
        // re-applied or surfaces as a per-item error, never silently
        // duplicated.)
        if ($openRun !== null && $openRun['status'] === 'running') {
            $this->state->setRunStatus(
                (int) $openRun['id'],
                'paused',
                'The process working on this run was interrupted; resuming from where it stopped.',
            );
            $openRun['status'] = 'paused';
        }

        try {
            if ($openRun !== null) {
                $runId = (int) $openRun['id'];
                $this->state->attachProgressToken($runId, $progress->token());
                $progress->phase('resuming', 'Continuing where the last run stopped');
                $result = new RunResult(false, new Plan());
                $result->totalItems = (int) $openRun['total_items'];
                $result->processedItems = (int) $openRun['processed_items'];
                $liveEtagsB = $sideB->listEtags();
            } else {
                [$runId, $result, $liveBHrefsFromPlanning] = $this->materializeNewRun(
                    $job,
                    $map,
                    $force,
                    $triggerSource,
                    $sideA,
                    $sideB,
                    $progress,
                );

                // Snapshot between planning and the first write. Planning
                // only touches bookkeeping (run rows, conflict records,
                // state for uids already gone from both sides), so nothing
                // a restore would want back has changed yet.
                //
                // Skipped when the plan is empty: most scheduled runs find
                // nothing to do, and snapshotting each one would bury the
                // genuinely interesting restore points under thousands of
                // identical files. Not taken on resume either -- a resumed
                // run continues work the original snapshot already covers.
                if ($result->totalItems > 0) {
                    $this->backups?->beforeSync($job);
                }

                $liveEtagsB = $liveBHrefsFromPlanning;
            }

            $completed = $this->processPending($job, $map, $runId, $sideA, $sideB, $liveEtagsB, $deadline, $result, $progress, $lock);

            if (!$completed) {
                return $result;
            }

            $this->jobs->touchLastRun($job->id);
            $this->state->finishRun(
                $runId,
                $result->created,
                $result->updated,
                $result->deleted,
                $result->archived,
                $result->conflicts,
                $result->warnings,
                $result->errors,
            );
            $result->status = 'completed';
            return $result;
        } catch (\Throwable $e) {
            // The run is about to be re-thrown to whatever triggered it. A
            // manual run surfaces it in the browser, but a scheduled one is
            // swallowed by the background job, so without this the only
            // record is a 'failed' row nobody reads.
            $this->logger?->error('Contacts Hub: sync job "{job_name}" failed: {message}', [
                'message' => $e->getMessage(),
                'job' => $job->id,
                'job_name' => $job->name,
                'user' => $job->userId,
                'app' => 'contacthub',
                'exception' => $e,
            ]);

            if ($runId !== null) {
                $this->state->finishRun(
                    $runId,
                    $result?->created ?? 0,
                    $result?->updated ?? 0,
                    $result?->deleted ?? 0,
                    $result?->archived ?? 0,
                    $result?->conflicts ?? 0,
                    $result?->warnings ?? [],
                    $result?->errors ?? [],
                    'failed',
                    $e->getMessage(),
                );
            }
            throw $e;
        }
    }

    private function preview(
        SyncJob $job,
        SideMap $map,
        bool $force,
        string $triggerSource,
        SyncSide $sideA,
        SyncSide $sideB,
        Progress $progress,
    ): RunResult {
        // The run row is created *before* the fetch, not after it, so
        // the progress a browser polls for has somewhere to live during
        // the phase that actually takes the time. findOpenRun() filters
        // dry runs out, so this longer-lived 'running' row can never be
        // mistaken for a real run awaiting resumption.
        $runId = $this->state->startRun($job->id, true, $triggerSource, $progress->token());

        [$bookA, $bookB, $warnings, , , $liveBHrefs] = $this->fetchBothSides($job, $sideA, $sideB, $progress);

        $progress->phase('planning', 'Comparing both sides');

        $plan = (new Planner())->plan(new PlanInput(
            $bookA,
            $bookB,
            $map->toPlannerAll($this->state->allContacts($job->id)),
            $map->toPlannerAll($this->state->allGroups($job->id)),
            $liveBHrefs,
            $force,
        ));

        // Seeded through warn() rather than the constructor, so parse warnings
        // from buildAddressBook reach the log like every other warning. Passing
        // them straight in was the last path that bypassed it.
        $result = new RunResult(true, $plan);
        $this->carry($result, $job, $warnings);
        $this->reportEndpointDrift($result, $job, $bookB, $sideB);
        $result->conflicts = count($plan->contactDuplicates);
        $result->created = count($plan->contactsCreateAToB) + count($plan->groupsCreateAToB);
        $result->updated = count($plan->contactsUpdateAToB) + count($plan->groupsUpdateAToB);
        $result->deleted = count($plan->contactsRemoveOnB) + count($plan->groupsRemoveOnB);
        $this->state->finishRun($runId, $result->created, $result->updated, $result->deleted, 0, $result->conflicts, $warnings, []);
        return $result;
    }

    /**
     * Both sides are always fetched and parsed in full: B's parsed
     * contacts (N/EMAIL/TEL) are needed for duplicate detection, an
     * accepted cost over a cheap href/etag-only destination listing.
     *
     * @return array{0: AddressBook, 1: AddressBook, 2: string[], 3: array<string, string>,
     *               4: array<string, string>, 5: array<string, string>}
     */
    private function fetchBothSides(SyncJob $job, SyncSide $sideA, SyncSide $sideB, ?Progress $progress = null): array
    {
        $progress ??= Progress::none();

        $progress->phase('fetch_a', "Reading {$sideA->label()}");
        $fetchedA = $sideA->fetchAll($progress);
        $etagsA = $sideA->listEtags();
        self::assertWholeBookFetched($sideA, $fetchedA, $etagsA);
        [$bookA, $warningsA] = Model::buildAddressBook(array_column($fetchedA, 'vcard'), $sideA->label(), count($etagsA));
        $bookA = self::withProjectedGroups($bookA, $sideA, $job);

        $progress->phase('fetch_b', "Reading {$sideB->label()}");
        $fetchedB = $sideB->fetchAll($progress);
        $etagsB = $sideB->listEtags();
        self::assertWholeBookFetched($sideB, $fetchedB, $etagsB);
        [$bookB, $warningsB] = Model::buildAddressBook(array_column($fetchedB, 'vcard'), $sideB->label(), count($etagsB));
        $bookB = self::withProjectedGroups($bookB, $sideB, $job);

        // Dropped here rather than at push time, and this placement is the
        // whole correctness of it -- see withResolvableMembers(). Filtering
        // only what gets written, while the Planner went on diffing the
        // unfiltered book, made every group carrying a dangling reference
        // differ from its own stored hash on the next run and re-push
        // forever.
        [$bookA, $bookB] = [
            self::withResolvableMembers($bookA, $bookB),
            self::withResolvableMembers($bookB, $bookA),
        ];

        return [
            $bookA,
            $bookB,
            [...$warningsA, ...$warningsB],
            $this->hrefMap($fetchedA),
            $this->hrefMap($fetchedB),
            $etagsB,
        ];
    }

    /**
     * A categories-strategy side expresses group membership as CATEGORIES on
     * each contact rather than as discrete group vCards, so it would
     * otherwise contribute zero groups and the Planner would have nothing to
     * diff. Deriving them here means every side hands the Planner the same
     * shape, and the diff engine needs no idea which convention it came from.
     */
    private static function withProjectedGroups(AddressBook $book, SyncSide $side, SyncJob $job): AddressBook
    {
        return $side->groupStrategy() === 'categories'
            // The archive category is a marker this app writes, not a group
            // the user made; see CategoryGroups::project().
            ? CategoryGroups::project($book, [$job->archiveGroupName])
            : $book;
    }

    /** @return array{0: int, 1: RunResult, 2: array<string, string>} runId, result, B's live href/etag map */
    private function materializeNewRun(
        SyncJob $job,
        SideMap $map,
        bool $force,
        string $triggerSource,
        SyncSide $sideA,
        SyncSide $sideB,
        Progress $progress,
    ): array {
        // Created before the fetch for the same reason as in preview():
        // the fetch is the slow part, and it needs a row to report into.
        $runId = $this->state->startRun($job->id, false, $triggerSource, $progress->token());

        [$bookA, $bookB, $warnings, $hrefsA, $hrefsB, $liveBHrefs] = $this->fetchBothSides($job, $sideA, $sideB, $progress);

        $progress->phase('planning', 'Comparing both sides');

        $plan = (new Planner())->plan(new PlanInput(
            $bookA,
            $bookB,
            $map->toPlannerAll($this->state->allContacts($job->id)),
            $map->toPlannerAll($this->state->allGroups($job->id)),
            $liveBHrefs,
            $force,
        ));

        $result = new RunResult(false, $plan);
        $this->carry($result, $job, $warnings);
        $this->reportEndpointDrift($result, $job, $bookB, $sideB);
        $result->conflicts = count($plan->contactDuplicates);

        // Conflict snapshots are stored by role, not by A/B, so the UI can
        // always say "this is the hub's copy" regardless of direction.
        $hubIsA = $map->hubIsA();
        $roleSnapshot = static function (?string $aText, ?string $bText) use ($hubIsA): array {
            return $hubIsA ? [$aText, $bText] : [$bText, $aText];
        };

        foreach ($plan->contactDuplicates as $dup) {
            $sourceIsA = $dup->sourceSide === 'a';
            $aSnapshot = $sourceIsA ? $bookA->contacts[$dup->uid]?->rawText : $bookA->contacts[$dup->destUid]?->rawText;
            $bSnapshot = $sourceIsA ? $bookB->contacts[$dup->destUid]?->rawText : $bookB->contacts[$dup->uid]?->rawText;
            [$hubText, $endpointText] = $roleSnapshot($aSnapshot, $bSnapshot);
            $this->state->recordDuplicateConflict($job->id, $dup->uid, $hubText, $endpointText, json_encode([
                'source_side' => $dup->sourceSide,
                'source_role' => $map->roleFor($dup->sourceSide),
                'dest_uid' => $dup->destUid,
                'dest_href' => $sourceIsA ? ($hrefsB[$dup->destUid] ?? null) : ($hrefsA[$dup->destUid] ?? null),
                'source_href' => $sourceIsA ? ($hrefsA[$dup->uid] ?? null) : ($hrefsB[$dup->uid] ?? null),
            ]));
        }
        foreach ($plan->contactsDropState as $uid) {
            $this->state->deleteContact($job->id, $uid);
        }

        $totalActionable = $this->materializeItems($runId, $plan, $bookA, $hrefsA);
        $this->state->updateRunProgress($runId, $totalActionable, 0);
        $result->totalItems = $totalActionable;

        return [$runId, $result, $liveBHrefs];
    }

    /**
     * Turns a computed Plan into persisted run_items rows: one per uid
     * the plan actually saw (either present in a freshly-fetched book,
     * or the target of a remove/conflict action), skipping the
     * synthetic archive-bookkeeping group and uids whose bookkeeping
     * was already dropped immediately above (they have no action and
     * appear in neither book, so they're naturally excluded by the
     * "fetched or acted-on" union below). Returns the count of rows
     * that represent real work ('noop' rows are informational only).
     */
    private function materializeItems(int $runId, Plan $plan, AddressBook $bookA, array $hrefsA): int
    {
        $actions = $this->buildActionMap($plan);
        $rows = [];
        $total = 0;

        foreach (['contact', 'group'] as $kind) {
            $itemsA = $kind === 'contact' ? $bookA->contacts : $bookA->groups;
            $actedUids = [];
            foreach ($actions as $key => $action) {
                if (str_starts_with($key, "{$kind}:")) {
                    $actedUids[] = substr($key, strlen($kind) + 1);
                }
            }
            $uids = array_unique(array_merge(array_keys($itemsA), $actedUids));

            foreach ($uids as $uid) {
                if ($kind === 'group' && str_starts_with($uid, Planner::ARCHIVE_GROUP_PREFIX)) {
                    continue;
                }
                $action = $actions["{$kind}:{$uid}"] ?? 'noop';
                [$snapshot, $href] = $this->snapshotFor($uid, $action, $itemsA, $hrefsA);

                $categoriesJson = null;
                if ($kind === 'contact' && in_array($action, ['create_a_to_b', 'update_a_to_b'], true)) {
                    // B's book is only fetched for duplicate matching --
                    // keep it out of category derivation, as before.
                    $categoriesJson = json_encode(Planner::groupNamesForContact($uid, $bookA, null));
                }

                $status = match ($action) {
                    'noop' => 'done',
                    'conflict' => 'conflict',
                    default => 'pending',
                };

                $rows[] = [
                    'kind' => $kind,
                    'uid' => $uid,
                    'action' => $action,
                    'source_href' => $href,
                    'source_snapshot' => $snapshot,
                    'categories_json' => $categoriesJson,
                    'status' => $status,
                ];
                if ($action !== 'noop') {
                    $total++;
                }
            }
        }

        $this->state->materializeRunItems($runId, $rows);
        return $total;
    }

    /** @return array<string, string> "contact:$uid"/"group:$uid" => action */
    private function buildActionMap(Plan $plan): array
    {
        $map = [];
        $assign = function (array $uids, string $kind, string $action) use (&$map): void {
            foreach ($uids as $uid) {
                $map["{$kind}:{$uid}"] = $action;
            }
        };
        $assign($plan->contactsCreateAToB, 'contact', 'create_a_to_b');
        $assign($plan->contactsUpdateAToB, 'contact', 'update_a_to_b');
        $assign($plan->contactsRemoveOnB, 'contact', 'remove_on_b');
        $assign(array_map(static fn(DuplicateMatch $d): string => $d->uid, $plan->contactDuplicates), 'contact', 'conflict');

        $assign($plan->groupsCreateAToB, 'group', 'create_a_to_b');
        $assign($plan->groupsUpdateAToB, 'group', 'update_a_to_b');
        $assign($plan->groupsRemoveOnB, 'group', 'remove_on_b');

        return $map;
    }

        /**
     * A copy of $book whose groups reference only contacts that exist.
     *
     * A group vCard is a list of contact UIDs and nothing anywhere enforces
     * that those contacts exist. Real address books accumulate references to
     * contacts deleted years ago, and pushing a group verbatim gave the
     * destination every dangling reference the source had -- one user's
     * endpoint ended up with thirteen groups listing 397 members that were
     * not in that address book. Writing a reference that cannot resolve is
     * not preserving information, it is copying a defect.
     *
     * **Applied to the books, not to the push.** The first version of this
     * filtered the text on its way out and left the Planner diffing the
     * unfiltered book, so every group with a dangling member hashed
     * differently from the copy that had just been written from it, and was
     * re-pushed on every single run -- the exact phantom-update class the
     * hashing discipline exists to prevent. Filtering here means plan, hash,
     * snapshot and push all see one version of the group, which is the only
     * arrangement that settles.
     *
     * Resolved against both books: a member may legitimately live on either
     * side (a from_endpoint job puts the endpoint in slot A), and bookB is
     * fetched in full anyway.
     *
     * A group whose members all resolve is passed through as the identical
     * object, so untouched groups keep their exact bytes and cannot look
     * changed.
     *
     * One-time consequence worth knowing: a group that was pushed *before*
     * this existed has a stored hash computed from its dangling members, so
     * it differs from the filtered version once, gets pushed once, and
     * settles. That is what cleans up the references already out there.
     */
    private static function withResolvableMembers(AddressBook $book, AddressBook $other): AddressBook
    {
        $groups = [];
        foreach ($book->groups as $uid => $group) {
            $groups[$uid] = self::groupWithResolvableMembers($group, $book, $other);
        }

        return new AddressBook($book->contacts, $groups);
    }

    private static function groupWithResolvableMembers(Group $group, AddressBook $book, AddressBook $other): Group
    {
        $keep = [];
        $dropped = [];
        foreach ($group->memberUids as $memberUid) {
            $resolvable = isset($book->contacts[$memberUid]) || isset($book->groups[$memberUid])
                || isset($other->contacts[$memberUid]) || isset($other->groups[$memberUid]);
            $resolvable ? $keep[] = $memberUid : $dropped[] = $memberUid;
        }
        if ($dropped === []) {
            return $group;
        }

        $text = $group->rawText;
        foreach ($dropped as $memberUid) {
            $text = Transform::withMemberRemoved($text, $memberUid);
        }

        return new Group($group->uid, $group->name, $keep, $text, $group->rev);
    }

    private function snapshotFor(string $uid, string $action, array $itemsA, array $hrefsA): array
    {
        if ($action === 'create_a_to_b' || $action === 'update_a_to_b' || $action === 'noop') {
            return [$itemsA[$uid]?->rawText, $hrefsA[$uid] ?? null];
        }
        return [null, null];
    }

    /**
     * Processes every 'pending' run_items row for $runId, stopping
     * early (without finishing the run) if $deadline is reached with
     * work still left. Returns whether the run is now fully processed.
     */
    private function processPending(
        SyncJob $job,
        SideMap $map,
        int $runId,
        SyncSide $sideA,
        SyncSide $sideB,
        array $liveEtagsB,
        ?float $deadline,
        RunResult $result,
        ?Progress $progress = null,
        ?JobLockHandle $lock = null,
    ): bool {
        $progress ??= Progress::none();
        $items = $this->state->pendingRunItems($runId);
        $run = $this->state->getRun($runId);
        $total = (int) ($run['total_items'] ?? 0);
        $processed = (int) ($run['processed_items'] ?? 0);
        $contactStates = $map->toPlannerAll($this->state->allContacts($job->id));
        $groupStates = $map->toPlannerAll($this->state->allGroups($job->id));

        $progress->phase('applying', 'Applying changes', $total);

        foreach ($items as $item) {
            $progress->step($processed, $total, self::describeItem($item));
            $this->dispatchItem($job, $map, $item, $sideA, $sideB, $liveEtagsB, $contactStates, $groupStates, $result);
            $processed++;
            $this->state->updateRunProgress($runId, $total, $processed);

            // Renew the lock between items -- the only place it is safe to,
            // since an item is the unit of work this run can be interrupted
            // at. Losing it means our TTL expired and another process has
            // taken the job over; continuing would double-dispatch the rest
            // of the queue against it.
            if ($lock !== null && !$lock->heartbeat()) {
                $reason = 'Lost the job lock mid-run; another process has taken this job over.';
                $this->state->setRunStatus($runId, 'paused', $reason);
                $result->status = 'paused';
                $result->pausedReason = $reason;
                $result->totalItems = $total;
                $result->processedItems = $processed;
                return false;
            }

            if ($deadline !== null && $processed < $total && microtime(true) > $deadline) {
                $remaining = $total - $processed;
                $reason = "Time budget exceeded -- {$remaining} of {$total} items remaining.";
                $this->state->setRunStatus($runId, 'paused', $reason);
                $result->status = 'paused';
                $result->pausedReason = $reason;
                $result->totalItems = $total;
                $result->processedItems = $processed;
                return false;
            }
        }

        // step() only forces a write through the throttle when it sees
        // current >= total, and the in-loop calls report *before*
        // dispatching (so the message names the item being worked on,
        // which matters when one item takes many seconds). That means
        // the last item's completion would otherwise always be
        // throttled away, leaving the bar parked just short of full.
        $progress->step($processed, $total, 'Finishing up');

        $result->totalItems = $total;
        $result->processedItems = $processed;
        return true;
    }

    /**
     * A short human label for the item being worked on right now --
     * the contact's FN where we have its text, falling back to the uid.
     * Purely for the progress display, so a parse failure here must
     * never break the run.
     *
     * @param array<string, mixed> $item
     */
    private static function describeItem(array $item): string
    {
        $verb = match ((string) $item['action']) {
            'create_a_to_b' => 'Creating',
            'update_a_to_b' => 'Updating',
            'remove_on_b' => 'Removing',
            default => 'Processing',
        };

        $name = (string) $item['uid'];
        $snapshot = $item['source_snapshot'] ?? null;
        if (is_string($snapshot) && $snapshot !== '') {
            try {
                $fn = Document::parse($snapshot)->first('FN')?->value;
                if ($fn !== null && trim($fn) !== '') {
                    $name = trim($fn);
                }
            } catch (\Throwable) {
                // Keep the uid; a progress label is never worth an exception.
            }
        }

        return "{$verb} {$item['kind']}: {$name}";
    }

    /** @param array<string, mixed> $item @param array<string, array<string, mixed>> $contactStates @param array<string, array<string, mixed>> $groupStates */
    private function dispatchItem(
        SyncJob $job,
        SideMap $map,
        array $item,
        SyncSide $sideA,
        SyncSide $sideB,
        array $liveEtagsB,
        array $contactStates,
        array $groupStates,
        RunResult $result,
    ): void {
        $id = (int) $item['id'];
        $kind = (string) $item['kind'];
        $uid = (string) $item['uid'];
        $action = (string) $item['action'];

        try {
            switch ($action) {
                case 'create_a_to_b':
                case 'update_a_to_b':
                    $isCreate = $action === 'create_a_to_b';
                    if ($kind === 'contact') {
                        $this->pushContactOne($job, $map, $uid, $item, $sideA, $sideB, $liveEtagsB, $isCreate, $contactStates, $result);
                    } else {
                        $this->pushGroupOne($job, $map, $uid, $item, $sideB, $liveEtagsB, $groupStates, $result);
                    }
                    break;
                case 'remove_on_b':
                    if ($kind === 'contact') {
                        $this->removeOrArchiveContactOne($job, $map, $uid, $sideB, $contactStates, $liveEtagsB, $result);
                    } else {
                        $this->removeGroupOne($job, $map, $uid, $sideB, $groupStates, $liveEtagsB, $result);
                    }
                    break;
                default:
                    throw new \LogicException("run item {$id} has unexpected pending action '{$action}'");
            }
            $this->state->markRunItem($id, 'done');
        } catch (\Throwable $e) {
            $this->fail($result, $job, "{$kind} {$uid}: {$e->getMessage()}", $e);
            $this->state->markRunItem($id, 'error', $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, string> $liveTargetEtags
     * @param array<string, array<string, mixed>> $contactStates
     */
    private function pushContactOne(
        SyncJob $job,
        SideMap $map,
        string $uid,
        array $item,
        SyncSide $source,
        SyncSide $target,
        array $liveTargetEtags,
        bool $isCreate,
        array $contactStates,
        RunResult $result,
    ): void {
        $sourceContact = Model::parse((string) $item['source_snapshot']);
        if (!$sourceContact instanceof Contact) {
            throw new \RuntimeException("uid {$uid} is not a contact vCard");
        }

        $categories = null;
        if ($target->groupStrategy() === 'categories') {
            $decoded = $item['categories_json'] !== null ? json_decode((string) $item['categories_json'], true) : [];
            $categories = is_array($decoded) ? $decoded : [];
        }

        $prepared = $this->preparePhoto($source, $uid, $sourceContact, $job, $result);
        $text = Transform::renderForDestination($prepared, $job->includePhotos, $categories);

        $stateRow = $contactStates[$uid] ?? [];
        $existingHref = $stateRow['b_href'] ?? null;
        $href = $existingHref ?? $target->hrefFor($uid);
        [$newEtag, $storedText] = $target->putVCard($href, $text, $liveTargetEtags[$href] ?? null);

        $this->state->upsertContact($job->id, $uid, $map->toStorage([
            'b_href' => $href,
            'b_etag' => $newEtag,
            // Hashed from what the target actually stored, not from the
            // source's raw text: photo-stripping, category-folding and the
            // hub's own canonicalization can all make the two differ, and
            // a mismatch here means a phantom update on every later run.
            'b_hash' => Model::textHash($storedText),
            'b_rev' => $sourceContact->rev,
            'a_href' => $item['source_href'] ?? ($stateRow['a_href'] ?? null),
            'a_hash' => Model::contactContentHash($sourceContact),
            'a_rev' => $sourceContact->rev,
        ]));
        $isCreate ? $result->created++ : $result->updated++;
    }

    /**
     * @param list<array{href: string, vcard: string}> $fetched
     * @return array<string, string> uid => href
     */
    private function hrefMap(array $fetched): array
    {
        $map = [];
        foreach ($fetched as $pair) {
            try {
                $map[Model::parse($pair['vcard'])->uid] = $pair['href'];
            } catch (\Throwable) {
                // Unparseable -- already reported as a warning by buildAddressBook.
            }
        }
        return $map;
    }

    /**
     * Takes the RunResult rather than a by-reference warnings array, so this
     * warning is logged like every other one. A private array reference was
     * the one path that bypassed warn().
     */
    private function preparePhoto(SyncSide $owner, string $uid, Contact $contact, SyncJob $job, RunResult $result): string
    {
        if (!$job->includePhotos) {
            return $contact->rawText;
        }
        try {
            return Transform::resolvePhotoUri($contact->rawText, fn(string $url): string => $owner->fetchBinary($url));
        } catch (\Throwable $e) {
            $this->warn(
                $result,
                $job,
                "contact {$uid}: failed to fetch referenced photo, syncing without it: {$e->getMessage()}",
            );
            return Transform::stripPhoto($contact->rawText);
        }
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, string> $liveTargetEtags
     * @param array<string, array<string, mixed>> $groupStates
     */
    private function pushGroupOne(
        SyncJob $job,
        SideMap $map,
        string $uid,
        array $item,
        SyncSide $target,
        array $liveTargetEtags,
        array $groupStates,
        RunResult $result,
    ): void {
        if ($target->groupStrategy() === 'categories') {
            // Categories-strategy sides never get a discrete group
            // resource -- membership is folded into each contact's
            // CATEGORIES instead (handled in pushContactOne).
            return;
        }
        if ($target->groupStrategy() === 'collections') {
            $this->warn(
                $result,
                $job,
                "endpoint '{$target->label()}' uses the 'collections' group strategy, which is not yet "
                . 'implemented for live sync (only for capability testing) -- group changes were not '
                . 'propagated there.',
            );
            return;
        }

        $sourceGroup = Model::parse((string) $item['source_snapshot']);
        if (!$sourceGroup instanceof Group) {
            throw new \RuntimeException("uid {$uid} is not a group vCard");
        }

        $stateRow = $groupStates[$uid] ?? [];
        $existingHref = $stateRow['b_href'] ?? null;
        $href = $existingHref ?? $target->hrefFor($uid);
        [$newEtag] = $target->putVCard($href, $sourceGroup->rawText, $liveTargetEtags[$href] ?? null);

        $this->state->upsertGroup($job->id, $uid, $map->toStorage([
            'b_href' => $href,
            'b_etag' => $newEtag,
            // Same bytes pushed verbatim to both sides, so the canonical
            // (name + members) hash is identical on both.
            'b_hash' => Model::groupContentHash($sourceGroup),
            'b_rev' => $sourceGroup->rev,
            'a_href' => $item['source_href'] ?? ($stateRow['a_href'] ?? null),
            'a_hash' => Model::groupContentHash($sourceGroup),
            'a_rev' => $sourceGroup->rev,
        ]) + ['payload_json' => json_encode(['name' => $sourceGroup->name, 'member_uids' => $sourceGroup->memberUids])]);
        $stateRow === [] ? $result->created++ : $result->updated++;
    }

    /** @param array<string, array<string, mixed>> $groupStates @param array<string, string> $liveTargetEtags */
    private function removeGroupOne(
        SyncJob $job,
        SideMap $map,
        string $uid,
        SyncSide $target,
        array $groupStates,
        array $liveTargetEtags,
        RunResult $result,
    ): void {
        $stateRow = $groupStates[$uid] ?? null;
        $href = $stateRow['b_href'] ?? null;
        if ($href !== null) {
            $target->delete($href, $liveTargetEtags[$href] ?? null);
        }
        $this->state->deleteGroup($job->id, $uid);
        $result->deleted++;
    }

    /** @param array<string, array<string, mixed>> $contactStates @param array<string, string> $liveTargetEtags */
    private function removeOrArchiveContactOne(
        SyncJob $job,
        SideMap $map,
        string $uid,
        SyncSide $target,
        array $contactStates,
        array $liveTargetEtags,
        RunResult $result,
    ): void {
        $stateRow = $contactStates[$uid] ?? [];

        if ($job->deletionPolicy === 'mirror') {
            $href = $stateRow['b_href'] ?? null;
            if ($href !== null) {
                $target->delete($href, $liveTargetEtags[$href] ?? null);
            }
            $this->state->deleteContact($job->id, $uid);
            $result->deleted++;
            return;
        }

        // No $liveTargetEtags: everything archiving touches, it reads back
        // for itself. The per-run snapshot is correct for the plan's own
        // items, each of which owns one href and is written once, and wrong
        // for the archive group, which is one shared resource every archival
        // in the run rewrites -- see Archiver::addToArchiveGroup.
        $this->archiveContact($job, $map, $uid, $stateRow, $target);
        $result->archived++;
    }

    /** @param array<string, mixed> $stateRow */
    private function archiveContact(
        SyncJob $job,
        SideMap $map,
        string $uid,
        array $stateRow,
        SyncSide $target,
    ): void {
        $href = $stateRow['b_href'] ?? null;
        if ($href === null) {
            return;
        }

        if ($target->groupStrategy() === 'categories') {
            [$text, $liveEtag] = $target->getVCard($href);
            [$newEtag, $written] = Archiver::writeArchiveTaggedContact($target, $job->archiveGroupName, $href, $text, $liveEtag);
            $this->state->upsertContact($job->id, $uid, $map->toStorage([
                'b_href' => $href,
                'b_etag' => $newEtag,
                // The archive tag rewrote the contact's content; record the
                // new hash, or a run would see it as "changed on this side"
                // and push the archived copy back to the side it was just
                // deleted from.
                'b_hash' => Model::textHash($written),
                'archived_b' => 1,
            ]));
            return;
        }

        $archiveGroupUid = Planner::ARCHIVE_GROUP_PREFIX . $map->roleFor('b') . '__';
        // Read the archive group's state fresh from the DB, not from the
        // per-run snapshot: a second archival in the same batch must see
        // the group the first one just created, or it would rebuild the
        // group from scratch and clobber the earlier membership. The ETag
        // needs the same freshness for the same reason, and gets it inside
        // addToArchiveGroup rather than from anything passed down here.
        $freshGroups = $map->toPlannerAll($this->state->allGroups($job->id));
        $groupState = $freshGroups[$archiveGroupUid] ?? null;
        $existingGroupHref = $groupState['b_href'] ?? null;
        [$groupHref, $newGroupEtag] = Archiver::addToArchiveGroup(
            $target,
            $archiveGroupUid,
            $job->archiveGroupName,
            $uid,
            $existingGroupHref,
        );

        $this->state->upsertGroup($job->id, $archiveGroupUid, $map->toStorage([
            'b_href' => $groupHref,
            'b_etag' => $newGroupEtag,
        ]) + ['payload_json' => json_encode(['name' => $job->archiveGroupName])]);
        $this->state->upsertContact($job->id, $uid, $map->toStorage([
            'b_href' => $href,
            'archived_b' => 1,
        ]));
    }
}
