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
     * Warn, once and aggregated, about groups this run is about to create on
     * B whose name collides with an existing, untracked group already
     * there.
     *
     * Not a conflict: there is no per-group resolution workflow (unlike
     * DuplicateMatcher for contacts), so the push proceeds -- this only
     * makes an otherwise-invisible near-duplicate visible in the run report,
     * the same way the dangling-members warning in Model::buildAddressBook()
     * does. One line for the whole run, not one per group -- see CLAUDE.md
     * on bounded warnings.
     */
    private function reportGroupNameCollisions(RunResult $result, SyncJob $job, SyncSide $sideB): void
    {
        $collisions = $result->plan->groupNameCollisions;
        if ($collisions === []) {
            return;
        }

        $names = array_map(static fn(array $c): string => $c[2], $collisions);
        $count = count($names);

        $this->warn($result, $job, sprintf(
            '%d group%s about to be created in %s share a name with a group already there: %s. '
                . 'They are likely the same group under two different identities; this app has no '
                . 'automatic way to merge them, so both will exist side by side unless merged by hand.',
            $count,
            $count === 1 ? '' : 's',
            $sideB->label(),
            Model::truncatedList($names, 5),
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

    /**
     * Turn planned creates into updates of a copy B already holds under the
     * same UID.
     *
     * A create writes to hrefFor($uid). When B already has that UID
     * somewhere else -- the usual case after importing a .vcf into Nextcloud
     * and only then setting up the pull job, since an import names its files
     * at random -- Nextcloud refuses a second card with that UID, and the
     * contact failed with one error per run, for ever. It was never a
     * duplicate conflict either, because a same-UID copy is excluded from
     * matching. Verified. A remote server that does not refuse ends up with
     * two cards of one UID, which is worse.
     *
     * The same UID is the same contact, so this job's source wins, as it
     * would for any other contact it tracks. The copy at the identical href
     * was already taken over this way implicitly (the push sends its live
     * ETag); this makes the other case behave the same.
     *
     * @param array<string, string> $hrefsB uid => href of everything read from B
     * @return array<string, string> "contact:<uid>" / "group:<uid>" => adopted href
     */
    private static function adoptExistingOnB(Plan $plan, array $hrefsB, SyncSide $sideB): array
    {
        $adopted = [];

        $creates = [];
        foreach ($plan->contactsCreateAToB as $uid) {
            // Its own uid on B, or -- Planner::identityMatches() -- the
            // derived uid of a UID-less card there that is this contact.
            $href = $hrefsB[$uid] ?? (isset($plan->contactsAdoptAToB[$uid]) ? ($hrefsB[$plan->contactsAdoptAToB[$uid]] ?? null) : null);
            if ($href !== null) {
                $adopted["contact:{$uid}"] = $href;
                $plan->contactsUpdateAToB[] = $uid;
            } else {
                $creates[] = $uid;
            }
        }
        $plan->contactsCreateAToB = $creates;

        // Groups only where B holds them as resources: a categories side
        // never gets a group written, so there is nothing to adopt.
        if ($sideB->groupStrategy() === 'passthrough') {
            $creates = [];
            foreach ($plan->groupsCreateAToB as $uid) {
                if (isset($hrefsB[$uid])) {
                    $adopted["group:{$uid}"] = $hrefsB[$uid];
                    $plan->groupsUpdateAToB[] = $uid;
                } else {
                    $creates[] = $uid;
                }
            }
            $plan->groupsCreateAToB = $creates;
        }

        return $adopted;
    }

    /**
     * Adopted contacts whose copy on B already says what the hub's would,
     * apart from the modification date. Those need no write at all: they are
     * taken out of the update bucket and returned (source uid => B uid) so the
     * caller can record them as synced.
     *
     * The comparison is between what a run would *write* -- the source card
     * rendered for the destination -- and B's card, ignoring REV, so a photo
     * setting or a category that would change the card counts as a
     * difference. Deliberately narrow: only destinations that keep groups as
     * cards (a categories side folds membership into the card, which is
     * derived elsewhere), and never a card whose photo is a URL, since
     * rendering that means a network fetch. Anything it cannot decide is an
     * ordinary update, which is always safe.
     *
     * @param array<string, string> $adopted keys "contact:<uid>" -- from adoptExistingOnB()
     * @return array<string, string>
     */
    private static function identicalOnB(Plan $plan, array $adopted, AddressBook $bookA, AddressBook $bookB, SyncJob $job, SyncSide $sideB): array
    {
        if ($sideB->groupStrategy() === 'categories') {
            return [];
        }

        $identical = [];
        foreach ($adopted as $key => $href) {
            [$kind, $uid] = explode(':', $key, 2);
            $contactA = $kind === 'contact' ? ($bookA->contacts[$uid] ?? null) : null;
            $contactB = $kind === 'contact' ? ($bookB->contacts[$plan->contactsAdoptAToB[$uid] ?? $uid] ?? null) : null;
            // A card that had no UID of its own is never identical: writing
            // the hub's UID into it is the point.
            if ($contactA === null || $contactB === null || $contactB->uidDerived) {
                continue;
            }
            if (self::hasPhotoReference($contactA->rawText)) {
                continue;
            }

            $wouldWrite = Transform::renderForDestination($contactA->rawText, $job->includePhotos, null);
            $theirs = Model::contentHashIgnoringRev($contactB->rawText);
            if ($theirs !== null && Model::contentHashIgnoringRev($wouldWrite) === $theirs) {
                $identical[$uid] = $contactB->uid;
            }
        }

        $plan->contactsUpdateAToB = array_values(array_diff($plan->contactsUpdateAToB, array_keys($identical)));

        return $identical;
    }

    /** A PHOTO that points somewhere instead of carrying the image. */
    private static function hasPhotoReference(string $vcardText): bool
    {
        try {
            foreach (Document::parse($vcardText)->all('PHOTO') as $photo) {
                if (strcasecmp((string) $photo->param('VALUE'), 'uri') === 0 || preg_match('~^https?:~i', trim($photo->value)) === 1) {
                    return true;
                }
            }
        } catch (\Throwable) {
            return true;
        }

        return false;
    }

    private function reportIdentical(RunResult $result, SyncJob $job, int $count, SyncSide $sideB): void
    {
        if ($count === 0) {
            return;
        }
        $this->warn($result, $job, sprintf(
            '%d contact%s in %s %s already identical to the hub\'s apart from the modification date and %s linked without being written.',
            $count,
            $count === 1 ? '' : 's',
            $sideB->label(),
            $count === 1 ? 'was' : 'were',
            $count === 1 ? 'was' : 'were',
        ));
    }

    /**
     * Two lines at most for the lot -- see CLAUDE.md on bounded warnings.
     *
     * @param array<string, string> $adopted
     * @param int $matched how many of those were found by name and a shared
     *        phone or email rather than by UID
     * @param int $identical how many need no write at all; see identicalOnB()
     */
    private function reportAdoptions(RunResult $result, SyncJob $job, array $adopted, SyncSide $sideB, int $matched = 0, int $identical = 0): void
    {
        // Identical ones are same-UID by construction (a card without a UID is
        // never identical) and are not updated; reportIdentical() says so.
        $sameUid = count($adopted) - $matched - $identical;
        if ($sameUid > 0) {
            $this->warn($result, $job, sprintf(
                '%d item%s already existed in %s under the same UID and %s updated there in place rather than '
                    . 'created a second time.',
                $sameUid,
                $sameUid === 1 ? '' : 's',
                $sideB->label(),
                $sameUid === 1 ? 'is' : 'are',
            ));
        }
        if ($matched > 0) {
            $this->warn($result, $job, sprintf(
                '%d contact%s matched %s in %s that %s no UID of %s own -- the same name and a shared email '
                    . 'address or phone number, and no other candidate -- and %s updated there in place rather '
                    . 'than created a second time.',
                $matched,
                $matched === 1 ? '' : 's',
                $matched === 1 ? 'a card' : 'cards',
                $sideB->label(),
                $matched === 1 ? 'has' : 'have',
                $matched === 1 ? 'its' : 'their',
                $matched === 1 ? 'is' : 'are',
            ));
        }
    }

    /**
     * Why a plan must not run: its source has no contacts at all, yet it
     * would remove contacts this job synced from that source earlier. Null
     * when the plan is fine.
     *
     * An address book that suddenly reads as empty is far more often one
     * that was deleted, unshared, switched for another, or answered with an
     * empty listing by a misbehaving server than one somebody really
     * emptied. Every one of those looks, to the Planner, exactly like the
     * user deleting every contact -- and with the mirror policy that becomes
     * a deletion of everything on the destination, which no snapshot covers
     * on a push job (snapshots are of the Nextcloud book, which is the
     * source there). A deleted Nextcloud book did exactly that, verified.
     * SideFactory now catches that particular cause before fetching; this
     * catches every cause, including ones nobody has thought of.
     *
     * Deliberately narrow: an empty source only. Partial deletions, however
     * large, are left alone -- a threshold would be a guess about what a
     * user meant, and it is the all-at-once case that is never real.
     */
    private static function emptySourceRefusal(Plan $plan, AddressBook $bookA, SyncSide $sideA, SyncSide $sideB): ?string
    {
        $removals = count($plan->contactsRemoveOnB);
        if ($bookA->contacts !== [] || $removals === 0) {
            return null;
        }

        return sprintf(
            '%s has no contacts at all, but %d contact%s synced from it before would be removed from %s. '
                . 'Refusing to do that: an address book that suddenly reads as empty has far more often been '
                . 'deleted, unshared or misconfigured than really emptied. If you did empty it on purpose, '
                . 'delete the contacts in %s yourself, or delete this job and create it again.',
            $sideA->label(),
            $removals,
            $removals === 1 ? '' : 's',
            $sideB->label(),
            $sideB->label(),
        );
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
        try {
            [$sideA, $sideB] = $this->sides->forJob($job);
        } catch (HubUnavailable $e) {
            // Recorded as a failed run, not just thrown: a scheduled run's
            // exception ends in the log, and the run history is where the
            // user looks to find out why their sync stopped.
            $runId = $this->state->startRun($job->id, $dryRun, $triggerSource, $progress->token());
            $this->state->finishRun($runId, 0, 0, 0, 0, 0, [], [], 'failed', $e->getMessage());
            $this->logger?->error('Contacts Hub: sync job "{job_name}" not run: {message}', [
                'message' => $e->getMessage(),
                'job' => $job->id,
                'job_name' => $job->name,
                'user' => $job->userId,
                'app' => 'contacthub',
            ]);
            throw $e;
        }
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
                // What the earlier segments did and reported. A resumed
                // segment starts from a fresh RunResult, so without this the
                // run's totals -- the response, finishRun()'s row, the
                // history -- covered only the segment that happened to be
                // last: a push that paused once reported only what its second
                // half created, and the warnings raised while planning were
                // gone from the stored run.
                $result->created = (int) $openRun['created'];
                $result->updated = (int) $openRun['updated'];
                $result->deleted = (int) $openRun['deleted'];
                $result->archived = (int) $openRun['archived'];
                $result->warnings = self::decodeReport($openRun['warnings_json'] ?? null);
                $result->errors = self::decodeReport($openRun['errors_json'] ?? null);
                // A resumed segment does no planning, so there is no fresh
                // Plan to count duplicates from the way the first segment
                // had. Read the live count instead of leaving this at the
                // RunResult default of 0 -- otherwise every consumer of this
                // result (finishRun()'s persisted run row, the manual-run
                // response, RunPanel) would report "0 conflicts" for a run
                // that paused and resumed despite real unresolved conflicts
                // sitting in the database the whole time.
                $result->conflicts = $this->state->countUnresolvedConflicts($job->id);
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

                // Warnings raised while planning exist nowhere else once this
                // request ends; a resumed segment does no planning.
                $this->state->saveRunReport($runId, $result->warnings, $result->errors);

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

        [$bookA, $bookB, $warnings, , $hrefsB, $liveBHrefs] = $this->fetchBothSides($job, $sideA, $sideB, $progress);

        $progress->phase('planning', 'Comparing both sides');

        $plan = (new Planner())->plan(new PlanInput(
            $bookA,
            $bookB,
            self::plannerStates($this->state->allContacts($job->id), $map, $sideA, $sideB),
            self::plannerStates($this->state->allGroups($job->id), $map, $sideA, $sideB),
            $liveBHrefs,
            $force,
            $sideB->groupStrategy() === 'categories',
        ));

        // Seeded through warn() rather than the constructor, so parse warnings
        // from buildAddressBook reach the log like every other warning. Passing
        // them straight in was the last path that bypassed it.
        $adopted = self::adoptExistingOnB($plan, $hrefsB, $sideB);
        $identical = self::identicalOnB($plan, $adopted, $bookA, $bookB, $job, $sideB);

        $result = new RunResult(true, $plan);
        $this->carry($result, $job, $warnings);
        $refusal = self::emptySourceRefusal($plan, $bookA, $sideA, $sideB);
        if ($refusal !== null) {
            $this->warn($result, $job, "A real run would stop here. {$refusal}");
        }
        $this->reportEndpointDrift($result, $job, $bookB, $sideB);
        $this->reportGroupNameCollisions($result, $job, $sideB);
        $this->reportAdoptions($result, $job, $adopted, $sideB, count($plan->contactsAdoptAToB), count($identical));
        $this->reportIdentical($result, $job, count($identical), $sideB);
        // On a categories side a group is never written as such -- its
        // membership travels on the contacts -- so counting it would promise
        // writes that do not happen.
        $groupsWritten = $sideB->groupStrategy() !== 'categories';
        $result->conflicts = count($plan->contactDuplicates);
        $result->created = count($plan->contactsCreateAToB) + ($groupsWritten ? count($plan->groupsCreateAToB) : 0);
        $result->updated = count($plan->contactsUpdateAToB) + ($groupsWritten ? count($plan->groupsUpdateAToB) : 0);
        $result->deleted = count($plan->contactsRemoveOnB) + ($groupsWritten ? count($plan->groupsRemoveOnB) : 0);
        $this->state->finishRun($runId, $result->created, $result->updated, $result->deleted, 0, $result->conflicts, $result->warnings, []);
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
        [$bookA, $warningsA] = Model::buildAddressBook(array_column($fetchedA, 'vcard'), $sideA->label(), count($etagsA), array_column($fetchedA, 'href'));
        $bookA = self::withProjectedGroups($bookA, $sideA, $job);

        $progress->phase('fetch_b', "Reading {$sideB->label()}");
        $fetchedB = $sideB->fetchAll($progress);
        $etagsB = $sideB->listEtags();
        self::assertWholeBookFetched($sideB, $fetchedB, $etagsB);
        [$bookB, $warningsB] = Model::buildAddressBook(array_column($fetchedB, 'vcard'), $sideB->label(), count($etagsB), array_column($fetchedB, 'href'));
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
            self::plannerStates($this->state->allContacts($job->id), $map, $sideA, $sideB),
            self::plannerStates($this->state->allGroups($job->id), $map, $sideA, $sideB),
            $liveBHrefs,
            $force,
            $sideB->groupStrategy() === 'categories',
        ));

        // Before anything is recorded: a refused plan must leave no
        // conflicts, dropped state or run items behind.
        $refusal = self::emptySourceRefusal($plan, $bookA, $sideA, $sideB);
        if ($refusal !== null) {
            throw new \RuntimeException($refusal);
        }

        $adopted = self::adoptExistingOnB($plan, $hrefsB, $sideB);
        $identical = self::identicalOnB($plan, $adopted, $bookA, $bookB, $job, $sideB);
        // Recorded before anything is pushed, so the push finds the existing
        // copy through state like any other tracked item. If the push then
        // fails, the row says B has it and A's hash is unknown, which the
        // next run reads as "changed" and retries -- it cannot get stuck.
        foreach ($adopted as $key => $href) {
            [$kind, $uid] = explode(':', $key, 2);
            $kind === 'contact'
                ? $this->state->upsertContact($job->id, $uid, $map->toStorage(['b_href' => $href]))
                : $this->state->upsertGroup($job->id, $uid, $map->toStorage(['b_href' => $href]));
        }

        // Identical copies need no push, so there is no run item to record
        // them afterwards: they are synced as of now, with both sides' hashes,
        // and an edit on A moves its hash like any other.
        foreach ($identical as $uid => $bUid) {
            $contactA = $bookA->contacts[$uid];
            $contactB = $bookB->contacts[$bUid];
            $href = $adopted["contact:{$uid}"];
            $this->state->upsertContact($job->id, $uid, $map->toStorage([
                'b_href' => $href,
                'b_etag' => $liveBHrefs[$href] ?? null,
                'b_hash' => Model::contactContentHash($contactB),
                'b_rev' => $contactB->rev,
                'a_href' => $hrefsA[$uid] ?? null,
                'a_hash' => Model::contactContentHash($contactA),
                'a_rev' => $contactA->rev,
            ]));
        }

        $result = new RunResult(false, $plan);
        $this->carry($result, $job, $warnings);
        $this->reportEndpointDrift($result, $job, $bookB, $sideB);
        $this->reportGroupNameCollisions($result, $job, $sideB);
        $this->reportAdoptions($result, $job, $adopted, $sideB, count($plan->contactsAdoptAToB), count($identical));
        $this->reportIdentical($result, $job, count($identical), $sideB);
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
                // What a run would fold into CATEGORIES on a categories
                // destination. Resolution has no address book in hand to
                // derive it from, and without it a resolved contact arrived
                // with no groups at all.
                'categories' => Planner::groupNamesForContact($dup->uid, $bookA, null),
            ]));
        }

        // A full plan has just said which conflicts still stand; any other
        // open one describes a situation that no longer exists. See
        // StateMapper::closeConflictsNotIn() for what leaving them open did.
        $closed = $this->state->closeConflictsNotIn(
            $job->id,
            array_map(static fn(DuplicateMatch $d): string => $d->uid, $plan->contactDuplicates),
            'obsolete',
        );
        if ($closed > 0) {
            $this->warn($result, $job, sprintf(
                '%d conflict%s no longer applied -- the contacts involved were changed or deleted -- and %s closed without changing anything.',
                $closed,
                $closed === 1 ? '' : 's',
                $closed === 1 ? 'was' : 'were',
            ));
            if ($job->conflictPaused && $this->state->countUnresolvedConflicts($job->id) === 0) {
                $this->jobs->setConflictPaused($job->id, $job->userId, false);
            }
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
        $contactStates = self::plannerStates($this->state->allContacts($job->id), $map, $sideA, $sideB);
        $groupStates = self::plannerStates($this->state->allGroups($job->id), $map, $sideA, $sideB);

        $progress->phase('applying', 'Applying changes', $total);

        // Warnings and errors are JSON, so they are rewritten only when an
        // item added one; the counters ride along on the progress UPDATE
        // that happens per item anyway.
        $reported = count($result->warnings) + count($result->errors);

        foreach ($items as $item) {
            $progress->step($processed, $total, self::describeItem($item));
            $this->dispatchItem($job, $map, $item, $sideA, $sideB, $liveEtagsB, $contactStates, $groupStates, $result);
            $processed++;
            $this->state->updateRunProgress($runId, $total, $processed, [
                'created' => $result->created,
                'updated' => $result->updated,
                'deleted' => $result->deleted,
                'archived' => $result->archived,
                'errors' => count($result->errors),
            ]);
            if (count($result->warnings) + count($result->errors) !== $reported) {
                $this->state->saveRunReport($runId, $result->warnings, $result->errors);
                $reported = count($result->warnings) + count($result->errors);
            }

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

    /** @return string[] */
    private static function decodeReport(mixed $json): array
    {
        $decoded = is_string($json) ? json_decode($json, true) : null;

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
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
        // $uid is the fallback: a source card with no UID is tracked under the
        // identity derived from its href, and parses as that.
        $sourceContact = Model::parse((string) $item['source_snapshot'], $uid);
        if (!$sourceContact instanceof Contact) {
            throw new \RuntimeException("uid {$uid} is not a contact vCard");
        }

        $categories = null;
        if ($target->groupStrategy() === 'categories') {
            $decoded = $item['categories_json'] !== null ? json_decode((string) $item['categories_json'], true) : [];
            $categories = is_array($decoded) ? $decoded : [];
        }

        $text = DestinationRenderer::render(
            $source,
            $sourceContact->rawText,
            $job->includePhotos,
            $categories,
            fn(string $why) => $this->warn(
                $result,
                $job,
                "contact {$uid}: failed to fetch referenced photo, syncing without it: {$why}",
            ),
        );

        // A card that arrived without a UID leaves with one -- its derived
        // identity -- or the next run, reading the copy it wrote, would see a
        // different contact from the one it tracks. Only the copy written to
        // the other side changes; the source card is left as it was.
        if ($sourceContact->uidDerived) {
            $text = Transform::withUid($text, $uid);
        }

        $stateRow = $contactStates[$uid] ?? [];
        $existingHref = self::ownedHref($target, $stateRow['b_href'] ?? null);
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
     * State rows in the Planner's a/b vocabulary, with every stored href in
     * the spelling its side's live listing uses.
     *
     * The Planner decides "is B's copy still there?" by looking the stored
     * href up in that listing, as a string. State written by an older
     * version, or before the server changed how it spells a URL, can name
     * the right resource in another spelling -- and a contact that looks
     * absent is re-created on every run, refused with a 412 each time.
     *
     * @param array<string, array<string, mixed>> $rows role-named, keyed by uid
     * @return array<string, array<string, mixed>>
     */
    private static function plannerStates(array $rows, SideMap $map, ?SyncSide $sideA, SyncSide $sideB): array
    {
        $states = $map->toPlannerAll($rows);
        foreach ($states as $uid => $state) {
            foreach (['a_href' => $sideA, 'b_href' => $sideB] as $key => $side) {
                if ($side !== null && is_string($state[$key] ?? null) && $state[$key] !== '') {
                    $states[$uid][$key] = $side->normalizeHref($state[$key]);
                }
            }
        }

        return $states;
    }

    /**
     * A stored href, or null when it does not name a location on $side.
     *
     * State written before a job or endpoint was moved elsewhere names the
     * old location. Read as "no copy here", the item is written afresh where
     * it belongs on this side instead of being sent -- with this side's
     * credentials -- to wherever it used to live. See SyncSide::owns().
     */
    private static function ownedHref(SyncSide $side, mixed $href): ?string
    {
        return is_string($href) && $href !== '' && $side->owns($href) ? $href : null;
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
                $map[Model::parse($pair['vcard'], Model::derivedUid($pair['href']))->uid] = $pair['href'];
            } catch (\Throwable) {
                // Unparseable -- already reported as a warning by buildAddressBook.
            }
        }
        return $map;
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
        $sourceGroup = Model::parse((string) $item['source_snapshot'], $uid);
        if (!$sourceGroup instanceof Group) {
            throw new \RuntimeException("uid {$uid} is not a group vCard");
        }
        $stateRow = $groupStates[$uid] ?? [];

        if ($target->groupStrategy() === 'categories') {
            // Categories-strategy sides never get a discrete group
            // resource -- membership is folded into each contact's
            // CATEGORIES instead (pushContactOne, and the Planner re-pushes
            // members when a group changes). Nothing to write, but the group
            // is recorded as seen: without a row it was planned as new again
            // on every run, so every run had work and took a snapshot.
            $this->state->upsertGroup($job->id, $uid, $map->toStorage([
                'a_href' => $item['source_href'] ?? ($stateRow['a_href'] ?? null),
                'a_hash' => Model::groupContentHash($sourceGroup),
                'a_rev' => $sourceGroup->rev,
            ]) + ['payload_json' => json_encode(['name' => $sourceGroup->name, 'member_uids' => $sourceGroup->memberUids])]);
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

        $existingHref = self::ownedHref($target, $stateRow['b_href'] ?? null);
        $href = $existingHref ?? $target->hrefFor($uid);
        // A group that arrived without a UID leaves with its derived one, for
        // the reason a contact does; see pushContactOne().
        $groupText = $sourceGroup->uidDerived ? Transform::withUid($sourceGroup->rawText, $uid) : $sourceGroup->rawText;
        [$newEtag] = $target->putVCard($href, $groupText, $liveTargetEtags[$href] ?? null);

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
        $href = self::ownedHref($target, $stateRow['b_href'] ?? null);
        if ($href !== null) {
            $target->delete($href, $liveTargetEtags[$href] ?? null);
            $result->deleted++;
        }
        $this->state->deleteGroup($job->id, $uid);
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
            $href = self::ownedHref($target, $stateRow['b_href'] ?? null);
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
        if (self::ownedHref($target, $stateRow['b_href'] ?? null) === null) {
            // Nothing on this side to archive. Dropping the row is what ends
            // it: kept, it was planned as a removal again on every run,
            // since nothing ever marked it archived.
            $this->state->deleteContact($job->id, $uid);

            return;
        }

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
        $freshGroups = self::plannerStates($this->state->allGroups($job->id), $map, null, $target);
        $groupState = $freshGroups[$archiveGroupUid] ?? null;
        $existingGroupHref = self::ownedHref($target, $groupState['b_href'] ?? null);
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
