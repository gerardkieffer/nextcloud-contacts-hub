<?php

declare(strict_types=1);

namespace OCA\ContactHub\Tests\Integration;

use OCA\ContactHub\Sync\SyncJob;
use OCA\ContactHub\VCard\Group;
use OCA\ContactHub\VCard\Model;

/**
 * Integration tests driving the real Planner/Runner/StateRepository/hub
 * repository code end-to-end: the hub side hits a genuine MySQL-backed
 * store, the endpoint side an in-memory fake HTTP transport. No real
 * network, but every layer above the socket is real.
 */
final class RunnerTest extends IntegrationTestCase
{
    public function testPushDryRunDetectsCreateButWritesNothing(): void
    {
        $this->seedHub($this->vcard('c1', 'Alice'));
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);

        $result = $this->runner()->run($job, dryRun: true, force: false, triggerSource: 'manual');

        self::assertSame(1, $result->created);
        self::assertSame([], $this->transport->resources);
    }

    public function testPushCreatesOnEndpoint(): void
    {
        $this->seedHub($this->vcard('c1', 'Alice'));
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);

        $result = $this->runner()->run($job, dryRun: false, force: false, triggerSource: 'manual');

        self::assertSame(1, $result->created);
        self::assertSame([], $result->errors);
        self::assertCount(1, $this->transport->resources);
    }

    public function testPushSecondRunIsANoOp(): void
    {
        $this->seedHub($this->vcard('c1', 'Alice'));
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);
        $runner = $this->runner();
        $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');

        $again = $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');

        self::assertTrue($again->plan->isEmpty());
    }

    public function testPushPropagatesContentUpdate(): void
    {
        $this->seedHub($this->vcard('c1', 'Alice'));
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);
        $runner = $this->runner();
        $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');

        $this->seedHub($this->vcard('c1', 'Alice Updated'));
        $result = $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');

        self::assertSame(1, $result->updated);
        self::assertStringContainsString('Alice Updated', $this->endpointByUid()['c1']);
    }

    public function testPushRecreatesAfterOutOfBandDeletionOnEndpoint(): void
    {
        $this->seedHub($this->vcard('c1', 'Alice'));
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);
        $runner = $this->runner();
        $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');

        $this->transport->resources = [];
        $result = $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');

        self::assertSame(1, $result->updated);
        self::assertCount(1, $this->transport->resources);
    }

    public function testPushMirrorsHubDeletionToEndpoint(): void
    {
        $this->seedHub($this->vcard('c1', 'Alice'));
        // Not the contact under test: it keeps the book non-empty, since a
        // run whose source reads as entirely empty is refused outright (see
        // testAnEmptySourceIsNeverMirroredAsADeletionOfEverything).
        $this->seedHub($this->vcard('keeper', 'Kept Throughout'));
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);
        $runner = $this->runner();
        $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');

        $this->hubDelete('c1');
        $result = $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');

        self::assertSame(1, $result->deleted);
        self::assertSame(['keeper'], array_keys($this->endpointByUid()));
    }

    public function testArchivePolicyTagsInsteadOfDeleting(): void
    {
        $this->endpoints->update($this->endpointId, self::USER_ID, ['group_strategy' => 'categories']);
        $this->seedHub($this->vcard('c2', 'Bob'));
        // Not the contact under test: it keeps the book non-empty, since a
        // run whose source reads as entirely empty is refused outright (see
        // testAnEmptySourceIsNeverMirroredAsADeletionOfEverything).
        $this->seedHub($this->vcard('keeper', 'Kept Throughout'));
        $job = $this->makeJob(SyncJob::TO_ENDPOINT, deletionPolicy: 'archive');
        $runner = $this->runner();
        $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');

        $this->hubDelete('c2');
        $result = $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');

        self::assertSame(1, $result->archived);
        self::assertCount(2, $this->transport->resources);
        self::assertStringContainsString('CATEGORIES:Deleted', $this->endpointByUid()['c2']);

        $again = $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');
        self::assertTrue($again->plan->isEmpty(), 'an archived contact must not be reprocessed every run');
    }

    /**
     * A pull job puts the endpoint in the Planner's A slot; this is the
     * direction that would break if SideMap stopped translating roles.
     */
    public function testPullCreatesInHub(): void
    {
        $this->seedEndpoint('e1.vcf', $this->vcard('e1', 'Remote Rita'));
        $job = $this->makeJob(SyncJob::FROM_ENDPOINT);

        $result = $this->runner()->run($job, dryRun: false, force: false, triggerSource: 'manual');

        self::assertSame(1, $result->created);
        self::assertSame([], $result->errors);
        $stored = $this->hubGet('e1');
        self::assertNotNull($stored);
        self::assertSame('Remote Rita', Model::parse($stored)->fn);
    }

    public function testPullSurvivesAFullReportTimeoutViaChunkedMultiget(): void
    {
        // The Mailo case: the whole-collection REPORT times out (their
        // server can't materialize a large book in one response), but
        // PROPFIND and addressbook-multiget answer fine. The sync must
        // complete through the fallback rather than failing the run.
        $this->seedEndpoint('e1.vcf', $this->vcard('e1', 'Remote Rita'));
        $this->seedEndpoint('e2.vcf', $this->vcard('e2', 'Remote Rene'));
        $this->transport->timeoutFullReport = true;
        $job = $this->makeJob(SyncJob::FROM_ENDPOINT);

        $result = $this->runner()->run($job, dryRun: false, force: false, triggerSource: 'manual');

        self::assertSame('completed', $result->status);
        self::assertSame(2, $result->created);
        self::assertSame([], $result->errors);
        self::assertGreaterThan(0, $this->transport->multigetCallCount);
        self::assertNotNull($this->hubGet('e1'));
    }

    public function testPullSecondRunIsANoOp(): void
    {
        $this->seedEndpoint('e1.vcf', $this->vcard('e1', 'Remote Rita'));
        $job = $this->makeJob(SyncJob::FROM_ENDPOINT);
        $runner = $this->runner();
        $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');

        $again = $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');

        self::assertTrue($again->plan->isEmpty(), 'the hub canonicalizes on write; that must not look like a change');
    }

    public function testPullMirrorsEndpointDeletionIntoHub(): void
    {
        $href = $this->seedEndpoint('e1.vcf', $this->vcard('e1', 'Remote Rita'));
        // Keeps the endpoint non-empty; see testAnEmptySourceIsNeverMirroredAsADeletionOfEverything.
        $this->seedEndpoint('keeper.vcf', $this->vcard('keeper', 'Kept Throughout'));
        $job = $this->makeJob(SyncJob::FROM_ENDPOINT);
        $runner = $this->runner();
        $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');

        unset($this->transport->resources[$href]);
        $result = $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');

        self::assertSame(1, $result->deleted);
        self::assertNull($this->hubGet('e1'));
    }

    public function testPausedRunResumesWithoutRefetchingOrReplanning(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            $this->seedHub($this->vcard("c{$i}", "Contact {$i}"));
        }
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);
        $runner = $this->runner();

        // Deadline already in the past -- processes exactly one item, then pauses.
        $first = $runner->run($job, dryRun: false, force: false, triggerSource: 'manual', timeBudgetSeconds: -1000);

        self::assertSame('paused', $first->status);
        self::assertSame(1, $first->processedItems);
        self::assertSame(4, $first->totalItems);
        self::assertCount(1, $this->transport->resources);
        self::assertSame(1, $this->runCountFor($job->id), 'a paused run must not create a second runs row');
        self::assertSame(1, $this->transport->reportCallCount);

        $second = $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');

        self::assertSame('completed', $second->status);
        self::assertSame(4, $second->processedItems);
        self::assertCount(4, $this->transport->resources);
        self::assertSame(1, $this->runCountFor($job->id), 'resuming must not start a fresh run row');
        self::assertSame(1, $this->transport->reportCallCount, 'resuming must not re-fetch/re-plan from a live REPORT');
    }

    public function testConcurrentRunIsRefusedWhileAnotherProcessHoldsTheJobLock(): void
    {
        $this->seedHub($this->vcard('c1', 'Alice'));
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);
        $runner = $this->runner();

        // Stand in for a concurrent web/cron process mid-run. Under the old
        // GET_LOCK design this needed a second database session; a TTL lock
        // is just a row, so holding it is enough.
        $held = $this->locks->acquire($job->id);
        self::assertNotNull($held, 'precondition: the other process must win the lock');

        $this->expectException(\OCA\ContactHub\Sync\RunAlreadyActive::class);
        try {
            $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');
        } finally {
            // The refused attempt must not have started a run row.
            self::assertSame(0, $this->runCountFor($job->id));
            $held->release();
        }
    }

    public function testInterruptedRunningRunIsRepairedAndResumed(): void
    {
        // A hard-killed process (OOM, kill -9) leaves its run row at
        // 'running' with no chance to mark itself paused. Since MySQL
        // releases the dead process's job lock automatically, the next
        // trigger can prove the row is stale and resume it instead of
        // leaving it "possibly stalled" forever.
        $this->seedHub($this->vcard('c1', 'Alice'));
        $this->seedHub($this->vcard('c2', 'Bob'));
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);
        $runner = $this->runner();

        $paused = $runner->run($job, dryRun: false, force: false, triggerSource: 'manual', timeBudgetSeconds: -1000);
        self::assertSame('paused', $paused->status);

        // Simulate the crash: the row says 'running', but no live
        // process holds the job lock.
        $this->forceRunStatus($job->id, 'running');

        $second = $runner->run($job, dryRun: false, force: false, triggerSource: 'cron');

        self::assertSame('completed', $second->status);
        self::assertSame(2, $second->processedItems);
        self::assertSame(1, $this->runCountFor($job->id), 'repair must resume the same run, not start a new one');
        self::assertCount(2, $this->transport->resources);
    }

    public function testTheTimeBudgetCoversTheFetchPhaseNotJustTheItemLoop(): void
    {
        // Regression guard for a real gap: the deadline used to start
        // *after* fetching and planning, so on a slow endpoint (Mailo's
        // chunked-multiget fallback measured ~91s for 766 contacts) a
        // "20 second" budget produced a 110+ second request -- exactly
        // the overrun the budget exists to prevent.
        //
        // Here the fetch alone outlasts the budget, so the run must
        // pause after a single item instead of draining the queue. If
        // the deadline is ever moved back after planning, the budget
        // still has its full second left at the first checkpoint and
        // this run completes -- failing the assertion below.
        for ($i = 1; $i <= 4; $i++) {
            $this->seedHub($this->vcard("c{$i}", "Contact {$i}"));
        }
        $this->transport->fetchDelaySeconds = 1.2;
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);

        $result = $this->runner()->run($job, dryRun: false, force: false, triggerSource: 'manual', timeBudgetSeconds: 1);

        self::assertSame('paused', $result->status, 'a fetch that outlasts the budget must still bound the request');
        self::assertSame(1, $result->processedItems);
        self::assertSame(4, $result->totalItems);
    }

    public function testAnInFlightPreviewIsNeverResumedAsIfItWereARealRun(): void
    {
        // A preview now opens its run row *before* fetching, so it has
        // somewhere to report progress -- which on a slow endpoint
        // leaves a dry 'running' row sitting there for a minute or
        // more. findOpenRun() must not offer that to a real run as a
        // queue to resume: there are no run_items behind it, so the
        // real run would "resume" zero work and mark itself done
        // without ever syncing anything.
        $this->seedHub($this->vcard('c1', 'Alice'));
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);
        $runner = $this->runner();

        $runner->run($job, dryRun: true, force: false, triggerSource: 'manual');
        // Force the preview's row to look still-in-flight.
        $this->forceRunStatus($job->id, 'running', dryRun: true, clearFinishedAt: true);

        self::assertNull($this->state->findOpenRun($job->id), 'a dry run must never look like an open real run');

        $result = $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');

        self::assertSame('completed', $result->status);
        self::assertSame(1, $result->created, 'the real run must do its own work, not inherit the preview row');
        self::assertCount(1, $this->transport->resources);
    }

    public function testProgressIsReportedForFetchPlanningAndEachItem(): void
    {
        $this->seedHub($this->vcard('c1', 'Alice'));
        $this->seedHub($this->vcard('c2', 'Bob'));
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);

        $seen = [];
        $progress = new \OCA\ContactHub\Sync\Progress(
            static function (string $phase, int $current, int $total, string $message) use (&$seen): void {
                $seen[] = ['phase' => $phase, 'current' => $current, 'total' => $total, 'message' => $message];
            },
            'tok' . str_repeat('a', 12),
            0.0, // no throttling, so the assertions see every step
        );

        $this->runner()->run($job, dryRun: false, force: false, triggerSource: 'manual', progress: $progress);

        $phases = array_values(array_unique(array_column($seen, 'phase')));
        self::assertContains('fetch_a', $phases);
        self::assertContains('fetch_b', $phases);
        self::assertContains('planning', $phases);
        self::assertContains('applying', $phases);

        // The per-item messages must name the contact, not just its uid.
        $messages = implode("\n", array_column($seen, 'message'));
        self::assertStringContainsString('Alice', $messages);
        self::assertStringContainsString('Bob', $messages);
    }

    public function testSystemicFailureMarksRunFailedInsteadOfStuckRunning(): void
    {
        // Two items so the expired-deadline check actually has something
        // still pending to pause on (with only one item, the single-pass
        // loop finishes before the check would matter) -- leaving a
        // genuinely *open* run row for the resume below to fail against.
        $this->seedHub($this->vcard('c1', 'Alice'));
        $this->seedHub($this->vcard('c2', 'Bob'));
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);
        $runner = $this->runner();

        $paused = $runner->run($job, dryRun: false, force: false, triggerSource: 'manual', timeBudgetSeconds: -1000);
        self::assertSame('paused', $paused->status, 'precondition: the run must genuinely be left open before the resume below');

        // Simulate a network blip on the resume's very first request (its
        // live-etag refetch) -- a systemic failure, not a per-item one, so
        // it must propagate rather than being silently absorbed, and the
        // run must not be left "running" forever.
        $this->transport->failNext = true;

        $thrown = null;
        try {
            $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        self::assertNotNull($thrown, 'the systemic failure must propagate to the caller');

        $row = $this->runRowsFor($job->id)[0] ?? [];
        self::assertSame('failed', $row['status'] ?? null);
        self::assertNotNull($row['finished_at'] ?? null);
    }

    public function testUnselectedCollectionThrowsAClearError(): void
    {
        $badEndpointId = $this->endpoints->create(self::USER_ID, [
            'name' => 'Untested', 'preset' => 'generic', 'base_url' => 'https://c.example/',
            'username' => 'u', 'password' => 'p',
        ]);
        $jobId = $this->jobs->create(self::USER_ID, [
            'name' => 'Bad Job',
            'address_book_id' => $this->addressBookId,
            'endpoint_id' => $badEndpointId,
            'direction' => SyncJob::TO_ENDPOINT,
        ]);
        $job = $this->jobs->find($jobId, self::USER_ID);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/capability test/');
        $this->runner()->run($job, dryRun: true, force: false, triggerSource: 'manual');
    }

    public function testDuplicateMatchIsFlaggedAsConflictInsteadOfCreated(): void
    {
        $this->seedHub($this->personVcard('c-new', 'Alice', 'Martin', 'alice@example.com', 'HOME'));
        $endpointHref = $this->seedEndpoint('old-e.vcf', $this->personVcard('old-e', 'Alice', 'Martin', 'ALICE@example.com', 'WORK'));
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);

        $result = $this->runner()->run($job, dryRun: false, force: false, triggerSource: 'manual');

        self::assertSame(0, $result->created);
        self::assertSame(1, $result->conflicts);
        self::assertSame([], $result->errors);
        self::assertSame('completed', $result->status);
        self::assertCount(1, $this->transport->resources, 'nothing may be written for a flagged duplicate');

        $rows = $this->state->unresolvedConflicts($job->id);
        self::assertCount(1, $rows);
        self::assertSame('c-new', $rows[0]['uid']);
        $info = json_decode((string) $rows[0]['duplicate_json'], true);
        self::assertSame('hub', $info['source_role']);
        self::assertSame('old-e', $info['dest_uid']);
        self::assertSame($endpointHref, $info['dest_href']);
    }

    public function testDuplicateConflictIsNotReRecordedByLaterRuns(): void
    {
        $this->seedHub($this->personVcard('c-new', 'Alice', 'Martin', 'alice@example.com'));
        $this->seedEndpoint('old-e.vcf', $this->personVcard('old-e', 'Alice', 'Martin', 'alice@example.com'));
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);
        $runner = $this->runner();

        $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');
        $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');

        self::assertCount(1, $this->state->unresolvedConflicts($job->id));
        self::assertCount(1, $this->transport->resources);
    }

    public function testConflictCountSurvivesAPauseAndResume(): void
    {
        // Regression: a resumed segment used to start from a fresh
        // RunResult(conflicts: 0) with no re-derivation, so a run that
        // recorded a conflict during planning (segment 1) but paused before
        // finishing reported 0 conflicts once it resumed and completed
        // (segment 2) -- silently hiding the conflict from the persisted
        // run row and the manual-run UI, even though it was never resolved.
        $this->seedHub($this->personVcard('c-new', 'Alice', 'Martin', 'alice@example.com', 'HOME'));
        $this->seedEndpoint('old-e.vcf', $this->personVcard('old-e', 'Alice', 'Martin', 'ALICE@example.com', 'WORK'));
        for ($i = 1; $i <= 3; $i++) {
            $this->seedHub($this->vcard("c{$i}", "Contact {$i}"));
        }
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);
        $runner = $this->runner();

        // Deadline already in the past -- processes at most one item, then
        // pauses. The conflict is recorded during planning, before that.
        $first = $runner->run($job, dryRun: false, force: false, triggerSource: 'manual', timeBudgetSeconds: -1000);
        self::assertSame('paused', $first->status);
        self::assertSame(1, $first->conflicts);

        $second = $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');

        self::assertSame('completed', $second->status);
        self::assertSame(1, $second->conflicts, 'the conflict must still be reported after resuming, not reset to 0');
    }

    public function testARunThatPausedReportsWhatEverySegmentDidNotJustTheLast(): void
    {
        // Regression, seen on a large push that paused once: the screen, the
        // response and the stored run row all reported only the second half
        // of it, because a resumed segment starts from a fresh RunResult and
        // only the last segment's tally was ever written down. The planning
        // warnings went the same way -- raised in segment 1, absent from the
        // stored run once segment 2 finished it.
        for ($i = 1; $i <= 5; $i++) {
            $this->seedHub($this->vcard("c{$i}", "Contact {$i}"));
        }
        $this->seedHub(
            "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Team\r\nUID:grp\r\n"
            . "X-ADDRESSBOOKSERVER-KIND:group\r\n"
            . "X-ADDRESSBOOKSERVER-MEMBER:urn:uuid:c1\r\n"
            . "X-ADDRESSBOOKSERVER-MEMBER:urn:uuid:long-gone\r\n"
            . "END:VCARD\r\n",
        );
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);
        $runner = $this->runner();

        // A deadline already in the past pauses after every single item, so
        // each of the six items is its own segment.
        $segments = 0;
        do {
            $result = $runner->run($job, dryRun: false, force: false, triggerSource: 'manual', timeBudgetSeconds: -1000);
            $segments++;
        } while ($result->status === 'paused' && $segments < 20);

        self::assertSame('completed', $result->status);
        self::assertGreaterThan(1, $segments, 'precondition: the run must really have been split');
        self::assertSame(6, $result->created, 'five contacts and a group, across six segments, all count');
        self::assertSame(0, $result->updated);
        self::assertSame(1, $this->runCountFor($job->id));

        $row = $this->runRowsFor($job->id)[0];
        self::assertSame($result->created, (int) $row['created'], 'the stored row must agree with the response');
        self::assertSame($result->updated, (int) $row['updated']);
        self::assertStringContainsString(
            'long-gone',
            (string) $row['warnings_json'],
            'a warning raised while planning must still be on the run once a later segment finishes it',
        );
    }

    /** A card the way a server such as Mailo keeps it: no UID property at all. */
    private function uidlessVcard(string $first, string $last, string $email, string $note = ''): string
    {
        $noteLine = $note !== '' ? "NOTE:{$note}\r\n" : '';

        return "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:{$first} {$last}\r\nN:{$last};{$first};;;\r\n"
            . "{$noteLine}EMAIL:{$email}\r\nEND:VCARD\r\n";
    }

    public function testPushingToAServerWithoutUidsUpdatesTheMatchingCardsInsteadOfDuplicatingThem(): void
    {
        // Cards without a UID used to be discarded as unreadable, so every
        // contact the hub held looked new and would have been pushed beside
        // the card that already described the same person.
        $this->seedHub("BEGIN:VCARD\r\nVERSION:3.0\r\nUID:c-alice\r\nFN:Alice Martin\r\nN:Martin;Alice;;;\r\nEMAIL:alice@example.com\r\nEND:VCARD\r\n");
        $this->seedHub("BEGIN:VCARD\r\nVERSION:3.0\r\nUID:c-bob\r\nFN:Bob Weber\r\nN:Weber;Bob;;;\r\nEMAIL:bob@example.com\r\nEND:VCARD\r\n");
        $aliceHref = $this->seedEndpoint('alice.vcf', $this->uidlessVcard('Alice', 'Martin', 'alice@example.com', 'only on the endpoint'));
        $carolText = $this->uidlessVcard('Carol', 'Keller', 'carol@example.com');
        $carolHref = $this->seedEndpoint('carol.vcf', $carolText);
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);
        $runner = $this->runner();

        $result = $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');

        self::assertSame([], $result->errors);
        self::assertSame(0, $result->conflicts, 'a unique name-and-email match is not something to ask about');
        self::assertCount(3, $this->transport->resources, 'Alice updated in place, Bob created, Carol untouched -- not four');
        self::assertStringContainsString('UID:c-alice', $this->transport->resources[$aliceHref]['body'], 'the existing card now carries the hub contact');
        self::assertSame($carolText, $this->transport->resources[$carolHref]['body'], 'a card nothing matched is left exactly as it was');
        self::assertSame(1, $result->updated);
        self::assertSame(1, $result->created);

        $again = $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');
        self::assertTrue($again->plan->isEmpty(), 'and it settles: the next run has nothing to do');
        self::assertCount(3, $this->transport->resources);
    }

    public function testAServerWhoseFullAnswerOmitsUidsStillMatchesContactsByTheirRealUid(): void
    {
        // The Mailo shape: the cards have UIDs, but the whole-collection
        // answer drops them. The hub's contact and the endpoint's card are the
        // same contact by UID, and two cards for the same name sit beside it.
        // Seen without the UID this was a conflict (two candidates by name);
        // seen with it, it is a plain update of the one card that matches.
        $this->seedHub("BEGIN:VCARD\r\nVERSION:3.0\r\nUID:shared-uid\r\nFN:Alice Martin\r\nN:Martin;Alice;;;\r\nEMAIL:alice@example.com\r\nEND:VCARD\r\n");
        $mine = $this->seedEndpoint('mine.vcf', "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:shared-uid\r\nFN:Alice Martin\r\nN:Martin;Alice;;;\r\nNOTE:old\r\nEMAIL:alice@example.com\r\nEND:VCARD\r\n");
        $other = $this->seedEndpoint('other.vcf', "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:another-uid\r\nFN:Alice Martin\r\nN:Martin;Alice;;;\r\nEMAIL:alice@example.com\r\nEND:VCARD\r\n");
        $otherBefore = $this->transport->resources[$other]['body'];
        $this->transport->reportOmitsUid = true;
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);
        $runner = $this->runner();

        $result = $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');

        self::assertSame([], $result->errors);
        self::assertSame(0, $result->conflicts);
        self::assertCount(2, $this->transport->resources);
        self::assertStringNotContainsString('NOTE:old', $this->transport->resources[$mine]['body'], 'the card with the same UID was updated in place');
        self::assertSame($otherBefore, $this->transport->resources[$other]['body'], 'its namesake was not touched');

        $again = $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');
        self::assertTrue($again->plan->isEmpty());
    }

    public function testACopyThatDiffersOnlyInTheModificationDateIsLinkedWithoutBeingWritten(): void
    {
        // A copy already on the server that differs from the hub's in the
        // last-modified date and nothing else: adopting it as an update would
        // write a card only to change that date, on a server with no snapshot.
        $this->seedHub("BEGIN:VCARD\r\nVERSION:3.0\r\nUID:c-same\r\nFN:Alice Martin\r\nN:Martin;Alice;;;\r\nEMAIL:alice@example.com\r\nREV:2026-01-01T00:00:00Z\r\nEND:VCARD\r\n");
        $this->seedHub("BEGIN:VCARD\r\nVERSION:3.0\r\nUID:c-diff\r\nFN:Bob Weber\r\nN:Weber;Bob;;;\r\nEMAIL:bob@example.com\r\nREV:2026-01-01T00:00:00Z\r\nEND:VCARD\r\n");
        $same = $this->seedEndpoint('same.vcf', "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:c-same\r\nFN:Alice Martin\r\nN:Martin;Alice;;;\r\nEMAIL:alice@example.com\r\nREV:2025-06-06T06:06:06Z\r\nEND:VCARD\r\n");
        $diff = $this->seedEndpoint('diff.vcf', "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:c-diff\r\nFN:Bob Weber\r\nN:Weber;Bob;;;\r\nEMAIL:bob@example.com\r\nNOTE:only here\r\nREV:2025-06-06T06:06:06Z\r\nEND:VCARD\r\n");
        $sameEtag = $this->transport->resources[$same]['etag'];
        $diffEtag = $this->transport->resources[$diff]['etag'];
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);
        $runner = $this->runner();

        $preview = $runner->run($job, dryRun: true, force: false, triggerSource: 'manual');
        self::assertSame(1, $preview->updated, 'the preview counts only what a run would write');
        self::assertSame($sameEtag, $this->transport->resources[$same]['etag']);

        $result = $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');

        self::assertSame([], $result->errors);
        self::assertSame(1, $result->updated, 'only the card whose content differs is written');
        self::assertSame($sameEtag, $this->transport->resources[$same]['etag'], 'the identical card was not touched');
        self::assertNotSame($diffEtag, $this->transport->resources[$diff]['etag'], 'the differing one was');
        self::assertArrayHasKey('c-same', $this->state->allContacts($job->id), 'but it is recorded as synced');

        $again = $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');
        self::assertTrue($again->plan->isEmpty(), 'and the pair settles');

        // The link is a real one: an edit on the hub now reaches the card.
        $this->seedHub("BEGIN:VCARD\r\nVERSION:3.0\r\nUID:c-same\r\nFN:Alice Martin\r\nN:Martin;Alice;;;\r\nEMAIL:alice@example.com\r\nNOTE:edited\r\nREV:2026-02-02T00:00:00Z\r\nEND:VCARD\r\n");
        $edited = $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');
        self::assertSame(1, $edited->updated);
        self::assertStringContainsString('NOTE:edited', $this->transport->resources[$same]['body']);
    }

    public function testAnAmbiguousMatchOnAServerWithoutUidsIsAConflictAndWritesNothing(): void
    {
        $this->seedHub("BEGIN:VCARD\r\nVERSION:3.0\r\nUID:c-alice\r\nFN:Alice Martin\r\nN:Martin;Alice;;;\r\nEMAIL:alice@example.com\r\nEND:VCARD\r\n");
        $first = $this->seedEndpoint('a1.vcf', $this->uidlessVcard('Alice', 'Martin', 'alice@example.com'));
        $second = $this->seedEndpoint('a2.vcf', $this->uidlessVcard('Alice', 'Martin', 'alice@example.com'));
        $before = [$this->transport->resources[$first]['body'], $this->transport->resources[$second]['body']];
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);

        $result = $this->runner()->run($job, dryRun: false, force: false, triggerSource: 'manual');

        self::assertSame(1, $result->conflicts, 'two candidates: a person decides, the app does not guess');
        self::assertSame($before, [$this->transport->resources[$first]['body'], $this->transport->resources[$second]['body']]);
        self::assertCount(2, $this->transport->resources);
    }

    public function testPullingFromAServerWithoutUidsGivesTheHubCopyAUidAndSettles(): void
    {
        $text = $this->uidlessVcard('Dora', 'Schmit', 'dora@example.com');
        $href = $this->seedEndpoint('dora.vcf', $text);
        // Keeps the endpoint non-empty on the second pass; see
        // testAnEmptySourceIsNeverMirroredAsADeletionOfEverything.
        $this->seedEndpoint('keeper.vcf', $this->uidlessVcard('Kept', 'Throughout', 'kept@example.com'));
        $job = $this->makeJob(SyncJob::FROM_ENDPOINT);
        $runner = $this->runner();

        $result = $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');

        self::assertSame([], $result->errors);
        self::assertSame(2, $result->created);
        $uid = Model::derivedUid($href);
        $stored = $this->hubGet($uid);
        self::assertNotNull($stored, 'the hub holds the contact under the identity derived from its href');
        self::assertStringContainsString("UID:{$uid}", $stored);
        self::assertSame($text, $this->transport->resources[$href]['body'], 'the source card is never touched');

        $again = $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');
        self::assertTrue($again->plan->isEmpty(), 'the same card is recognised on the next run, not created a second time');
        self::assertCount(2, $this->hubByUid());
    }

    public function testTwoArchivalsInOneRunShareTheArchiveGroup(): void
    {
        // Regression: archiveContact used to read the archive group's state
        // from a once-per-run snapshot, so the second archival in the same
        // batch didn't see the group the first one just created and rebuilt
        // it from scratch with only its own uid as a member.
        $this->seedHub($this->vcard('g1', 'Alice'));
        $this->seedHub($this->vcard('g2', 'Bob'));
        // Not the contact under test: it keeps the book non-empty, since a
        // run whose source reads as entirely empty is refused outright (see
        // testAnEmptySourceIsNeverMirroredAsADeletionOfEverything).
        $this->seedHub($this->vcard('keeper', 'Kept Throughout'));
        $job = $this->makeJob(SyncJob::TO_ENDPOINT, deletionPolicy: 'archive');
        $runner = $this->runner();
        $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');

        $this->hubDelete('g1');
        $this->hubDelete('g2');
        $result = $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');

        self::assertSame([], $result->errors);
        self::assertSame(2, $result->archived);

        $group = null;
        foreach ($this->transport->resources as $res) {
            $parsed = Model::parse($res['body']);
            if ($parsed instanceof Group) {
                $group = $parsed;
            }
        }
        self::assertNotNull($group, 'the archive group vCard should exist on the endpoint');
        self::assertEqualsCanonicalizing(['g1', 'g2'], $group->memberUids, 'both contacts archived in one run must end up in the group');
    }

    public function testTwoArchivalsIntoAnAlreadyExistingArchiveGroup(): void
    {
        // Regression, reported from a live Infomaniak account: the sibling
        // test above passes only because the archive group is *created*
        // during the same run. When it already exists, its href is in the
        // per-run ETag snapshot taken at fetch time, and addToArchiveGroup
        // preferred that snapshot ETag over the one it had just read back
        // from the server. The first archival changes the group, so the
        // second sent a stale If-Match and sabre answered 412
        // PreconditionFailed -- with the *right* body, since the GET was
        // fresh. One contact archived, the run reported an error, and the
        // contact was left half-processed.
        $this->seedHub($this->vcard('a1', 'Alice'));
        $this->seedHub($this->vcard('a2', 'Bob'));
        $this->seedHub($this->vcard('a3', 'Carol'));
        // Not the contact under test: it keeps the book non-empty, since a
        // run whose source reads as entirely empty is refused outright (see
        // testAnEmptySourceIsNeverMirroredAsADeletionOfEverything).
        $this->seedHub($this->vcard('keeper', 'Kept Throughout'));
        $job = $this->makeJob(SyncJob::TO_ENDPOINT, deletionPolicy: 'archive');
        $runner = $this->runner();
        $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');

        // Run one: the archive group comes into existence.
        $this->hubDelete('a1');
        $first = $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');
        self::assertSame([], $first->errors);

        // Run two: it already exists, and two contacts go into it at once.
        $this->hubDelete('a2');
        $this->hubDelete('a3');
        $second = $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');

        self::assertSame([], $second->errors, 'a second archival must not send a stale If-Match');
        self::assertSame(2, $second->archived);

        $group = null;
        foreach ($this->transport->resources as $res) {
            $parsed = Model::parse($res['body']);
            if ($parsed instanceof Group) {
                $group = $parsed;
            }
        }
        self::assertNotNull($group, 'the archive group vCard should exist on the endpoint');
        self::assertEqualsCanonicalizing(
            ['a1', 'a2', 'a3'],
            $group->memberUids,
            'every archived contact must survive in the group, across runs and within one',
        );
    }

    public function testWipingThePushDestinationRebuildsContactsAndGroups(): void
    {
        // A push-only endpoint is disposable by design: delete everything on
        // it and the next sync should put it back. Contacts always did come
        // back, because planContacts() checks the live href listing. Groups
        // did not -- planGroups() had no equivalent check, so each group sat
        // with an unchanged hub-side hash and nothing to trigger on, and the
        // membership was permanently lost while the plan reported no work.
        $this->seedHub($this->vcard('w1', 'Alice'));
        $this->seedHub($this->vcard('w2', 'Bob'));
        $this->seedHub(
            "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Team\r\nUID:wg\r\n"
            . "X-ADDRESSBOOKSERVER-KIND:group\r\n"
            . "X-ADDRESSBOOKSERVER-MEMBER:urn:uuid:w1\r\n"
            . "X-ADDRESSBOOKSERVER-MEMBER:urn:uuid:w2\r\n"
            . "END:VCARD\r\n",
        );
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);
        $runner = $this->runner();
        $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');
        self::assertArrayHasKey('wg', $this->endpointByUid());

        // Everything deleted on the endpoint, out of band.
        $this->transport->resources = [];
        $result = $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');

        self::assertSame([], $result->errors);
        $rebuilt = $this->endpointByUid();
        self::assertArrayHasKey('w1', $rebuilt);
        self::assertArrayHasKey('w2', $rebuilt);
        self::assertArrayHasKey('wg', $rebuilt, 'the group has to come back too, not just its members');
        self::assertEqualsCanonicalizing(['w1', 'w2'], Model::parse($rebuilt['wg'])->memberUids);
    }

    public function testAGroupIsPushedWithoutMembersThatDoNotExist(): void
    {
        // A group vCard is a list of UIDs and nothing enforces that the
        // contacts exist. Real address books accumulate references to
        // contacts deleted years ago, and this used to copy them onward
        // verbatim -- so pushing a group gave the destination every dangling
        // reference the source had. Reported as an endpoint whose groups
        // listed hundreds of members not in that address book.
        $this->seedHub($this->vcard('real', 'Real Person'));
        $this->seedHub(
            "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Team\r\nUID:grp\r\n"
            . "X-ADDRESSBOOKSERVER-KIND:group\r\n"
            . "X-ADDRESSBOOKSERVER-MEMBER:urn:uuid:real\r\n"
            . "X-ADDRESSBOOKSERVER-MEMBER:urn:uuid:long-gone\r\n"
            . "END:VCARD\r\n",
        );
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);

        $result = $this->runner()->run($job, dryRun: false, force: false, triggerSource: 'manual');

        self::assertSame([], $result->errors);
        $pushed = Model::parse($this->endpointByUid()['grp']);
        self::assertInstanceOf(Group::class, $pushed);
        self::assertSame(['real'], $pushed->memberUids, 'the dangling reference must not be copied onward');
    }

    public function testAGroupWithADanglingMemberSettlesAfterOnePush(): void
    {
        // The bug the first version of the filter had: it dropped dangling
        // members on the way out while the Planner went on diffing the
        // unfiltered book, so the group hashed differently from the copy
        // just written from it and was re-pushed on every single run --
        // forever, for every group carrying one stale reference.
        $this->seedHub($this->vcard('real', 'Real Person'));
        $this->seedHub(
            "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Team\r\nUID:grp\r\n"
            . "X-ADDRESSBOOKSERVER-KIND:group\r\n"
            . "X-ADDRESSBOOKSERVER-MEMBER:urn:uuid:real\r\n"
            . "X-ADDRESSBOOKSERVER-MEMBER:urn:uuid:long-gone\r\n"
            . "END:VCARD\r\n",
        );
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);
        $runner = $this->runner();

        $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');
        $again = $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');

        self::assertSame([], $again->plan->groupsUpdateAToB);
        self::assertTrue($again->plan->isEmpty(), 'a group with a dangling member has to settle like any other');
    }

    public function testAGroupPushedBeforeFilteringIsCleanedUpOnce(): void
    {
        // How the references already out there get cleaned: a group pushed
        // before the filter existed has a stored hash computed from its
        // dangling members, so it differs from the filtered version exactly
        // once, is pushed once, and then settles. No wipe, no forced run.
        $this->seedHub($this->vcard('real', 'Real Person'));
        $group = "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Team\r\nUID:grp\r\n"
            . "X-ADDRESSBOOKSERVER-KIND:group\r\n"
            . "X-ADDRESSBOOKSERVER-MEMBER:urn:uuid:real\r\n"
            . "X-ADDRESSBOOKSERVER-MEMBER:urn:uuid:long-gone\r\n"
            . "END:VCARD\r\n";
        $this->seedHub($group);
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);
        $runner = $this->runner();
        $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');

        // Put the endpoint and the bookkeeping back into the pre-filter
        // shape: the dangling member present in both.
        $this->transport->resources[$this->transport->collectionHref . 'grp.vcf'] = [
            'body' => $group,
            'etag' => '"pre-filter"',
        ];
        $unfiltered = Model::parse($group);
        $this->state->upsertGroup($job->id, 'grp', [
            'hub_hash' => Model::groupContentHash($unfiltered),
            'endpoint_hash' => Model::groupContentHash($unfiltered),
        ]);

        $first = $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');
        self::assertSame(['grp'], $first->plan->groupsUpdateAToB, 'the stale card is noticed');
        self::assertSame(['real'], Model::parse($this->endpointByUid()['grp'])->memberUids);

        $second = $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');
        self::assertTrue($second->plan->isEmpty(), 'and only once');
    }

    public function testAGroupWhoseMembersAllResolveIsPushedUntouched(): void
    {
        // The ordinary case has to stay byte-identical, or every group would
        // reserialize on push and look changed on the next run.
        $this->seedHub($this->vcard('m1', 'Alice'));
        $this->seedHub($this->vcard('m2', 'Bob'));
        $group = "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Team\r\nUID:grp\r\n"
            . "X-ADDRESSBOOKSERVER-KIND:group\r\n"
            . "X-ADDRESSBOOKSERVER-MEMBER:urn:uuid:m1\r\n"
            . "X-ADDRESSBOOKSERVER-MEMBER:urn:uuid:m2\r\n"
            . "END:VCARD\r\n";
        $this->seedHub($group);
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);
        $runner = $this->runner();

        $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');
        $again = $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');

        self::assertTrue($again->plan->isEmpty(), 'an untouched group must not look changed on the next run');
        self::assertEqualsCanonicalizing(['m1', 'm2'], Model::parse($this->endpointByUid()['grp'])->memberUids);
    }

    public function testAPartlyReadAddressBookStopsTheRun(): void
    {
        // Every fetch path can come back short without saying so:
        // collectAddressData() skips multistatus entries carrying no
        // address-data, so a truncated response reads as a small address
        // book. Nothing downstream can tell the difference, and on a pull
        // job the missing contacts look deleted -- which would mirror a
        // deletion nobody made into the side that still has them.
        $this->seedEndpoint('e1.vcf', $this->vcard('e1', 'Rita'));
        $this->seedEndpoint('e2.vcf', $this->vcard('e2', 'Rene'));
        $job = $this->makeJob(SyncJob::FROM_ENDPOINT);
        $runner = $this->runner();
        $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');
        self::assertNotNull($this->hubGet('e2'));

        // The collection still lists both; the body fetch returns one.
        $this->transport->dropFromAddressData = [$this->transport->collectionHref . 'e2.vcf'];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('returned 1 of 2 entries');
        $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');
    }

    public function testAShortFetchDoesNotDeleteTheContactsItDidNotSee(): void
    {
        $this->seedEndpoint('e1.vcf', $this->vcard('e1', 'Rita'));
        $this->seedEndpoint('e2.vcf', $this->vcard('e2', 'Rene'));
        $job = $this->makeJob(SyncJob::FROM_ENDPOINT);
        $runner = $this->runner();
        $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');

        $this->transport->dropFromAddressData = [$this->transport->collectionHref . 'e2.vcf'];
        try {
            $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');
        } catch (\RuntimeException) {
            // expected
        }

        self::assertNotNull($this->hubGet('e2'), 'a contact the fetch missed must survive the run');
    }

    public function testATrackedContactThatNeverLandedOnTheEndpointIsPushed(): void
    {
        // Tracked, present on A, no href on B: the contact was never
        // successfully written. The out-of-band-deletion check used to
        // require b_href to be non-null, so this was skipped entirely, and
        // the content comparison sees A unchanged since the row was written.
        // The contact sat tracked and absent from the endpoint forever, and
        // every run reported no work -- which is what being in sync looks
        // like.
        $this->seedHub($this->vcard('orphan', 'Never Pushed'));
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);
        $runner = $this->runner();
        $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');

        // Rewrite the bookkeeping into the broken shape, and remove the copy.
        unset($this->transport->resources[$this->transport->collectionHref . 'orphan.vcf']);
        $this->state->upsertContact($job->id, 'orphan', ['endpoint_href' => null, 'endpoint_etag' => null]);

        $result = $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');

        self::assertSame([], $result->errors);
        self::assertSame(['orphan'], $result->plan->contactsUpdateAToB);
        self::assertArrayHasKey('orphan', $this->endpointByUid(), 'it has to actually arrive');
    }

    public function testForceDoesNotRePushAnUnchangedAddressBook(): void
    {
        // Reported from a live instance: forcing an iCloud -> Nextcloud run
        // reported 597 contacts updated when nothing had been touched, 597
        // being every contact in the book. force used to short-circuit the
        // content comparison entirely -- `$in->force || ...` -- so every
        // tracked contact on side A counted as changed. Not a miscount: those
        // were real writes to the destination.
        foreach (['f1', 'f2', 'f3'] as $uid) {
            $this->seedHub($this->vcard($uid, 'Person ' . $uid));
        }
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);
        $runner = $this->runner();
        $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');

        $forced = $runner->run($job, dryRun: true, force: true, triggerSource: 'manual');

        self::assertSame(0, $forced->updated, 'nothing changed, so a forced run has nothing to update');
        self::assertTrue($forced->plan->isEmpty());
    }

    public function testForceFindsAContactEditedDirectlyOnTheEndpoint(): void
    {
        // And this is what force is actually for. An ordinary one-way run
        // notices only that the endpoint's resource still *exists*; it never
        // compares its content, so an edit made directly on the endpoint is
        // invisible. Force compares, which is what the checkbox promises.
        $this->seedHub($this->vcard('f1', 'Alice'));
        $this->seedHub($this->vcard('f2', 'Bob'));
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);
        $runner = $this->runner();
        $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');

        $href = $this->transport->collectionHref . 'f1.vcf';
        $this->transport->resources[$href] = [
            'body' => $this->vcard('f1', 'Edited On The Server'),
            'etag' => '"tampered"',
        ];

        $ordinary = $runner->run($job, dryRun: true, force: false, triggerSource: 'manual');
        self::assertSame(0, $ordinary->updated, 'an ordinary one-way run cannot see endpoint content drift');

        $forced = $runner->run($job, dryRun: false, force: true, triggerSource: 'manual');
        self::assertSame(1, $forced->updated, 'exactly the drifted contact, not the whole book');
        self::assertSame(['f1'], $forced->plan->contactsUpdateAToB);
        self::assertStringContainsString('Alice', $this->endpointByUid()['f1'], 'the source of truth wins back');
    }

    public function testForceSaysWhyItKeepsFindingTheSameContacts(): void
    {
        // A server that rewrites what it stores -- re-encoding a photo on
        // upload is the usual one -- fails the comparison again on every
        // forced run, forever, with nothing to fix. Saying so beats silently
        // redoing the work, and the photo count is what makes it actionable.
        $photo = base64_encode(str_repeat('x', 40));
        $this->seedHub(
            "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:p1\r\nFN:Ada\r\n"
            . "PHOTO;ENCODING=b;TYPE=JPEG:{$photo}\r\nEND:VCARD\r\n",
        );
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);
        $runner = $this->runner();
        $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');

        // Stand in for a server that shrank the photo on the way in.
        $href = $this->transport->collectionHref . 'p1.vcf';
        $smaller = base64_encode(str_repeat('x', 12));
        $this->transport->resources[$href] = [
            'body' => "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:p1\r\nFN:Ada\r\n"
                . "PHOTO;ENCODING=b;TYPE=JPEG:{$smaller}\r\nEND:VCARD\r\n",
            'etag' => '"reencoded"',
        ];

        $forced = $runner->run($job, dryRun: true, force: true, triggerSource: 'manual');

        self::assertSame(['p1'], $forced->plan->contactsDriftedOnB);
        $drift = array_values(array_filter(
            $forced->warnings,
            static fn(string $w): bool => str_starts_with($w, 'Forced run:'),
        ));
        self::assertCount(1, $drift, 'one line for the whole set, not one per contact');
        self::assertStringContainsString('all of them have photos', $drift[0]);
    }

    public function testCategoriesDestinationSecondRunIsANoOp(): void
    {
        // Regression: the category-folding push reserializes the vCard with
        // CRLF, but the next run re-fetches it via REPORT, which normalizes
        // to LF -- hashing the written bytes without normalizing made every
        // pushed contact look "changed on the endpoint" forever, echoing
        // writes back and forth on each run.
        $this->endpoints->update($this->endpointId, self::USER_ID, ['group_strategy' => 'categories']);
        $this->seedHub($this->vcard('c1', 'Alice'));
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);
        $runner = $this->runner();
        $first = $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');
        self::assertSame([], $first->errors);

        $again = $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');

        self::assertTrue($again->plan->isEmpty(), 'a category-folded push must not look changed on the next run');
    }

    public function testArchivePolicyDoesNotResurrectDeletedContact(): void
    {
        // Regression: archiving on a categories-strategy side rewrites the
        // contact (folds in the archive category) but the stored hash was
        // not updated, so the next run saw "the endpoint changed" and
        // pushed the archived copy back to the side it was just deleted
        // from.
        $this->endpoints->update($this->endpointId, self::USER_ID, ['group_strategy' => 'categories']);
        $this->seedHub($this->vcard('c9', 'Carol'));
        // Not the contact under test: it keeps the book non-empty, since a
        // run whose source reads as entirely empty is refused outright (see
        // testAnEmptySourceIsNeverMirroredAsADeletionOfEverything).
        $this->seedHub($this->vcard('keeper', 'Kept Throughout'));
        $job = $this->makeJob(SyncJob::TO_ENDPOINT, deletionPolicy: 'archive');
        $runner = $this->runner();
        $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');

        $this->hubDelete('c9');
        $second = $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');
        self::assertSame(1, $second->archived);
        self::assertSame([], $second->errors);

        $third = $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');
        self::assertTrue($third->plan->isEmpty(), 'archiving must not make the contact look changed on the next run');
        self::assertNull($this->hubGet('c9'), 'the archived copy must not be pushed back into the hub');
    }

    /**
     * A photo survives a full push/pull cycle unchanged, and the second
     * run sees nothing to do -- the end-to-end version of the byte-exact
     * round trip HubVCardTest pins down at the codec level.
     */
    public function testContactWithPhotoRoundTripsAndSettles(): void
    {
        $photo = "\xFF\xD8\xFF\xE0" . str_repeat('p', 4000);
        $vcard = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:ph\r\nFN:Photo Person\r\nN:Person;Photo;;;\r\n"
            . 'PHOTO;ENCODING=b;TYPE=JPEG:' . base64_encode($photo) . "\r\nEND:VCARD\r\n";
        $this->seedHub($vcard);
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);
        $runner = $this->runner();

        $first = $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');
        self::assertSame([], $first->errors);
        self::assertSame(1, $first->created);

        $onEndpoint = $this->endpointByUid()['ph'];
        $photoProp = \OCA\ContactHub\VCard\Document::parse($onEndpoint)->first('PHOTO');
        self::assertNotNull($photoProp);
        self::assertSame($photo, base64_decode(preg_replace('/\s+/', '', $photoProp->value) ?? '', true));

        $again = $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');
        self::assertTrue($again->plan->isEmpty(), 'a photo contact must not look changed on the next run');
    }

    private function personVcard(string $uid, string $first, string $last, string $email, string $emailType = 'HOME'): string
    {
        return "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:{$uid}\r\nFN:{$first} {$last}\r\n"
            . "N:{$last};{$first};;;\r\nEMAIL;TYPE={$emailType}:{$email}\r\nEND:VCARD\r\n";
    }
}
