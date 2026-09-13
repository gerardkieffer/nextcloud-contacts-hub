<?php

declare(strict_types=1);

namespace OCA\ContactHub\Tests\Integration;

use OCA\ContactHub\Sync\SyncJob;

/**
 * The portable upsert, and the partial-write guarantee it has to keep.
 *
 * `ON DUPLICATE KEY UPDATE` is not portable, so upsert() is
 * update-then-insert. That shape has a MySQL-specific trap: an UPDATE that
 * matches a row without changing any value reports 0 affected rows, because
 * MySQL counts changed rows rather than matched ones. The insert then runs,
 * collides on the unique index, and the retry path is taken -- which makes
 * that path ordinary rather than exotic, and worth testing directly.
 */
final class StateMapperTest extends IntegrationTestCase
{
    private function job(): SyncJob
    {
        return $this->makeJob(SyncJob::TO_ENDPOINT);
    }

    /** @return array<string, mixed> */
    private function groupRow(int $jobId, string $uid): array
    {
        $row = $this->state->allGroups($jobId)[$uid] ?? null;
        self::assertNotNull($row, "no group state row for {$uid}");

        return $row;
    }

    public function testAPartialUpsertLeavesUnmentionedColumnsAlone(): void
    {
        $job = $this->job();

        $this->state->upsertContact($job->id, 'c1', ['hub_href' => 'h.vcf', 'hub_hash' => 'aaa']);
        $this->state->upsertContact($job->id, 'c1', ['endpoint_href' => 'e.vcf']);

        $row = $this->state->allContacts($job->id)['c1'];
        self::assertSame('h.vcf', $row['hub_href'], 'the omitted hub columns must survive');
        self::assertSame('aaa', $row['hub_hash']);
        self::assertSame('e.vcf', $row['endpoint_href']);
    }

    public function testARepeatedIdenticalUpsertDoesNotWipeTheGroupPayload(): void
    {
        // The regression. Writing the same values twice makes MySQL report 0
        // affected rows, so the insert runs and collides, and the retry used
        // to carry the payload_json default -- overwriting a real payload
        // with '{}'.
        $job = $this->job();
        $payload = json_encode(['name' => 'Family', 'member_uids' => ['c1', 'c2']]);

        $this->state->upsertGroup($job->id, 'g1', [
            'hub_hash' => 'same',
            'payload_json' => $payload,
        ]);

        // Byte-identical repeat: this is what a settled run does every time.
        $this->state->upsertGroup($job->id, 'g1', ['hub_hash' => 'same']);

        self::assertSame($payload, $this->groupRow($job->id, 'g1')['payload_json']);
    }

    public function testAnExplicitPayloadStillOverwritesTheOldOne(): void
    {
        $job = $this->job();

        $this->state->upsertGroup($job->id, 'g1', ['payload_json' => '{"name":"Old"}']);
        $this->state->upsertGroup($job->id, 'g1', ['payload_json' => '{"name":"New"}']);

        self::assertSame('{"name":"New"}', $this->groupRow($job->id, 'g1')['payload_json']);
    }

    public function testAFirstGroupInsertWithoutAPayloadGetsTheDefault(): void
    {
        // payload_json is NOT NULL, so the default has to be supplied on a
        // first insert even though it must never reach an update.
        $job = $this->job();

        $this->state->upsertGroup($job->id, 'g1', ['hub_hash' => 'aaa']);

        self::assertSame('{}', $this->groupRow($job->id, 'g1')['payload_json']);
    }

    public function testRepeatedIdenticalContactUpsertsAreStable(): void
    {
        $job = $this->job();
        $fields = ['hub_href' => 'h.vcf', 'hub_hash' => 'aaa', 'endpoint_hash' => 'bbb'];

        $this->state->upsertContact($job->id, 'c1', $fields);
        $this->state->upsertContact($job->id, 'c1', $fields);
        $this->state->upsertContact($job->id, 'c1', $fields);

        self::assertCount(1, $this->state->allContacts($job->id), 'no duplicate rows');
        $row = $this->state->allContacts($job->id)['c1'];
        self::assertSame('aaa', $row['hub_hash']);
        self::assertSame('bbb', $row['endpoint_hash']);
    }

    public function testArchivedFlagsRoundTripAsBooleans(): void
    {
        // Booleans need an explicit parameter type or Postgres rejects an int
        // for a bool column; this pins the round trip on whatever backs the
        // test run.
        $job = $this->job();

        $this->state->upsertContact($job->id, 'c1', ['archived_endpoint' => true]);
        self::assertTrue((bool) $this->state->allContacts($job->id)['c1']['archived_endpoint']);

        $this->state->upsertContact($job->id, 'c1', ['archived_endpoint' => false]);
        self::assertFalse((bool) $this->state->allContacts($job->id)['c1']['archived_endpoint']);
    }

    public function testDeletingAJobRemovesItsStateRunsAndConflicts(): void
    {
        // There are no foreign keys, so the cascade is explicit code and
        // deserves a test rather than trust.
        $job = $this->job();

        $this->state->upsertContact($job->id, 'c1', ['hub_hash' => 'aaa']);
        $this->state->upsertGroup($job->id, 'g1', ['hub_hash' => 'bbb']);
        $runId = $this->state->startRun($job->id, false, 'manual');
        $this->state->materializeRunItems($runId, [[
            'kind' => 'contact', 'uid' => 'c1', 'action' => 'create',
            'source_href' => null, 'source_snapshot' => null,
        ]]);
        $this->state->recordDuplicateConflict($job->id, 'c1', 'a', 'b', '{}');

        $this->state->deleteEverythingForJob($job->id);

        self::assertSame([], $this->state->allContacts($job->id));
        self::assertSame([], $this->state->allGroups($job->id));
        self::assertSame([], $this->state->unresolvedConflicts($job->id));
        self::assertSame([], $this->state->runsForJob($job->id));
        self::assertSame([], $this->state->runItemsForRun($runId));
    }

    public function testOpenRunsForBatchesWhatFindOpenRunAnswersOneAtATime(): void
    {
        // The jobs listing needs this per row, and asking per job turns
        // opening a screen into one query per sync job. It has to agree with
        // findOpenRun() exactly, or the screen reopens a run the resume path
        // does not recognise.
        $a = $this->state->startRun(101, false, 'manual', 'tok-a');
        $this->state->startRun(102, true, 'manual', 'tok-dry');
        $c = $this->state->startRun(103, false, 'manual', 'tok-c');
        $this->state->finishRun($c, 0, 0, 0, 0, 0, [], []);

        $open = $this->state->openRunsFor([101, 102, 103, 104]);

        self::assertArrayHasKey(101, $open);
        self::assertSame($a, (int) $open[101]['id']);
        self::assertArrayNotHasKey(102, $open, 'a preview is not a run to reopen');
        self::assertArrayNotHasKey(103, $open, 'a finished run is not open');
        self::assertArrayNotHasKey(104, $open, 'a job with no runs at all');

        foreach ([101, 102, 103] as $jobId) {
            $single = $this->state->findOpenRun($jobId);
            self::assertSame(
                $single === null ? null : (int) $single['id'],
                isset($open[$jobId]) ? (int) $open[$jobId]['id'] : null,
                "batched and single lookups must agree for job {$jobId}",
            );
        }
    }

    public function testOpenRunsForIsEmptyWithoutJobs(): void
    {
        self::assertSame([], $this->state->openRunsFor([]));
    }
}
