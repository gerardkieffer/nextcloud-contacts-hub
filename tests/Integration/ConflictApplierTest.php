<?php

declare(strict_types=1);

namespace OCA\ContactHub\Tests\Integration;

use OCA\ContactHub\Files\HubFolder;
use OCA\ContactHub\Sync\ConflictApplier;
use OCA\ContactHub\Sync\SyncJob;
use OCA\ContactHub\VCard\Document;
use OCA\ContactHub\VCard\Model;

/**
 * Integration tests for manual duplicate-match conflict resolution --
 * the only kind of conflict this app raises -- especially the 'archive'
 * choice (back up the existing copy about to be overwritten as a .vcf in
 * the user's Files, then apply the new one), driven end-to-end through
 * the real Runner/Client stack -- same layer as RunnerTest.
 *
 * Anything exercising 'archive' needs ensureRealUser(): the backup goes
 * into the user's own home, and there is no home without an account.
 */
final class ConflictApplierTest extends IntegrationTestCase
{
    private function personVcard(string $uid, string $first, string $last, string $email, string $emailType = 'HOME', string $note = ''): string
    {
        $noteLine = $note !== '' ? "NOTE:{$note}\r\n" : '';
        return "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:{$uid}\r\nFN:{$first} {$last}\r\n"
            . "N:{$last};{$first};;;\r\n{$noteLine}EMAIL;TYPE={$emailType}:{$email}\r\nEND:VCARD\r\n";
    }

    /** @return array{0: array<string, mixed>, 1: string} conflict row, the endpoint's pre-existing near-copy href */
    private function seedDuplicateConflict(SyncJob $job): array
    {
        $this->seedHub($this->personVcard('c1', 'Alice', 'Martin', 'alice@x.com', 'HOME', 'from the hub'));
        $endpointHref = $this->seedEndpoint('old9.vcf', $this->personVcard('old9', 'Alice', 'Martin', 'ALICE@x.com', 'WORK', 'old endpoint copy'));

        $this->runner()->run($job, dryRun: false, force: false, triggerSource: 'manual');

        $rows = $this->state->unresolvedConflicts($job->id);
        self::assertCount(1, $rows, 'expected exactly one recorded duplicate conflict');
        self::assertNotNull($rows[0]['duplicate_json']);
        return [$rows[0], $endpointHref];
    }

    public function testDuplicateOverwriteResolutionReplacesExistingCopyInPlace(): void
    {
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);
        [$row, $endpointHref] = $this->seedDuplicateConflict($job);

        $this->conflictApplier()->apply($job, $row, ConflictApplier::HUB);

        self::assertCount(1, $this->transport->resources, 'existing resource overwritten, nothing new created');
        $body = $this->transport->resources[$endpointHref]['body'];
        self::assertSame('c1', Model::parse($body)->uid, "the source's identity takes over the matched resource");
        self::assertSame('from the hub', Document::parse($body)->first('NOTE')?->value);

        $again = $this->runner()->run($job, dryRun: false, force: false, triggerSource: 'manual');
        self::assertTrue($again->plan->isEmpty(), 'the resolved pair must not be re-flagged or re-pushed');
        self::assertCount(0, $this->state->unresolvedConflicts($job->id));
    }

    public function testDuplicateKeepBothResolutionCreatesSeparatelyAndLeavesExistingUntouched(): void
    {
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);
        [$row, $endpointHref] = $this->seedDuplicateConflict($job);
        $originalBody = $this->transport->resources[$endpointHref]['body'];

        $this->conflictApplier()->apply($job, $row, ConflictApplier::ENDPOINT);

        self::assertCount(2, $this->transport->resources);
        self::assertSame($originalBody, $this->transport->resources[$endpointHref]['body'], 'the existing copy must be untouched');
        $newHref = $this->transport->collectionHref . 'c1.vcf';
        self::assertSame('c1', Model::parse($this->transport->resources[$newHref]['body'])->uid);

        $again = $this->runner()->run($job, dryRun: false, force: false, triggerSource: 'manual');
        self::assertTrue($again->plan->isEmpty(), 'a resolved keep-both pair must never be re-flagged as a duplicate');
    }

    public function testDuplicateArchiveResolutionBacksUpExistingCopyToAFileThenOverwrites(): void
    {
        // A duplicate's archive never touches the CardDAV side at all: the
        // backup is a .vcf file in the user's own Files, not a second
        // address-book entry a future sync would have to account for. That
        // needs a real Nextcloud user (Files has no home for the synthetic
        // principal everything else here gets by with).
        $this->ensureRealUser();
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);
        [$row, $endpointHref] = $this->seedDuplicateConflict($job);

        $this->conflictApplier()->apply($job, $row, ConflictApplier::ARCHIVE);

        // Only the live resource, overwritten in place -- no synthetic
        // archive contact or group.
        self::assertCount(1, $this->transport->resources);
        self::assertSame('c1', Model::parse($this->transport->resources[$endpointHref]['body'])->uid);

        $files = $this->hubFolder()->listFiles(self::USER_ID, HubFolder::ARCHIVED_CONTACTS);
        self::assertCount(1, $files, 'expected exactly one archived .vcf file');
        $archived = Model::parse($this->hubFolder()->read(self::USER_ID, HubFolder::ARCHIVED_CONTACTS, $files[0]['name']));
        self::assertSame('old9', $archived->uid, 'the file must hold the existing copy that was about to be overwritten');
        self::assertSame('old endpoint copy', Document::parse($archived->rawText)->first('NOTE')?->value);

        $again = $this->runner()->run($job, dryRun: false, force: false, triggerSource: 'manual');
        self::assertTrue($again->plan->isEmpty(), 'the resolved pair must not be reprocessed');
    }

    public function testDuplicateArchiveResolutionDisambiguatesACollidingFilename(): void
    {
        $this->ensureRealUser();
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);
        [$row] = $this->seedDuplicateConflict($job);

        // Pre-seed a file that would collide with the name the archive step
        // is about to write ("Alice Martin.vcf", from the existing copy's FN).
        $this->hubFolder()->write(self::USER_ID, HubFolder::ARCHIVED_CONTACTS, 'Alice Martin.vcf', 'pre-existing');

        $this->conflictApplier()->apply($job, $row, ConflictApplier::ARCHIVE);

        $files = $this->hubFolder()->listFiles(self::USER_ID, HubFolder::ARCHIVED_CONTACTS);
        self::assertCount(2, $files, 'the pre-existing file must survive, disambiguated rather than overwritten');
        $names = array_column($files, 'name');
        self::assertContains('Alice Martin.vcf', $names);
        self::assertTrue(
            (bool) array_filter($names, static fn(string $n) => $n !== 'Alice Martin.vcf' && str_starts_with($n, 'Alice Martin ')),
            'expected a second, timestamp-disambiguated file',
        );
    }

    public function testThreeSameNamedContactsArchivedInOneSecondKeepEveryBackup(): void
    {
        // The case a one-second timestamp could not disambiguate. The first
        // archive takes the plain "<name>.vcf"; from the second on, every
        // one of them collides with it and falls back to the same
        // timestamped name within the same second -- the fallback was never
        // itself re-checked, and HubFolder::write() overwrites silently. So
        // it takes three same-named archives to lose one, and resolving a
        // batch is exactly how three arrive back to back, duplicate matches
        // being name matches by definition.
        $this->ensureRealUser();
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);

        foreach (['first', 'second', 'third'] as $i => $which) {
            $n = $i + 1;
            $this->seedHub($this->personVcard("new{$n}", 'Sam', 'Reed', "sam{$n}@x.com"));
            $this->seedEndpoint("old{$n}.vcf", $this->personVcard("old{$n}", 'Sam', 'Reed', "SAM{$n}@x.com", 'WORK', "{$which} old copy"));
        }

        $this->runner()->run($job, dryRun: false, force: false, triggerSource: 'manual');
        $rows = $this->state->unresolvedConflicts($job->id);
        self::assertCount(3, $rows, 'expected all three same-named pairs to be flagged');

        foreach ($rows as $row) {
            $this->conflictApplier()->apply($job, $row, ConflictApplier::ARCHIVE);
        }

        $files = $this->hubFolder()->listFiles(self::USER_ID, HubFolder::ARCHIVED_CONTACTS);
        self::assertCount(3, $files, 'every archived backup must survive');

        $notes = [];
        foreach ($files as $file) {
            $text = $this->hubFolder()->read(self::USER_ID, HubFolder::ARCHIVED_CONTACTS, $file['name']);
            $notes[] = Document::parse((string) $text)->first('NOTE')?->value;
        }
        sort($notes);
        self::assertSame(['first old copy', 'second old copy', 'third old copy'], $notes, 'no backup may be overwritten by another');
    }

    public function testResolvingConflictsLooksUpOneEtagEachInsteadOfListingTheCollection(): void
    {
        // Each resolution needs the live ETag of the one resource it is
        // about to overwrite, and used to get it by asking for every ETag
        // in the collection and indexing into the answer. That is one
        // whole-collection PROPFIND per conflict -- fine for one, and the
        // reason batch-resolving a few hundred duplicates against a slow
        // endpoint (Mailo answers a 766-resource PROPFIND in about a
        // second) spent minutes fetching data it discarded.
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);

        foreach ([1, 2, 3] as $n) {
            $this->seedHub($this->personVcard("new{$n}", 'Pat', "Nolan{$n}", "pat{$n}@x.com"));
            $this->seedEndpoint("old{$n}.vcf", $this->personVcard("old{$n}", 'Pat', "Nolan{$n}", "PAT{$n}@x.com", 'WORK'));
        }
        $this->runner()->run($job, dryRun: false, force: false, triggerSource: 'manual');
        $rows = $this->state->unresolvedConflicts($job->id);
        self::assertCount(3, $rows);

        $listingsBefore = $this->transport->etagListCallCount;
        foreach ($rows as $row) {
            $this->conflictApplier()->apply($job, $row, ConflictApplier::HUB);
        }

        self::assertSame(0, $this->transport->etagListCallCount - $listingsBefore, 'no conflict may pay for a whole-collection listing');
        self::assertSame(3, $this->transport->singleEtagCallCount, 'one targeted ETag lookup per conflict');

        // And the cheaper lookup must still return the etag the write is
        // guarded by: a wrong or missing one turns the update into a
        // create and 412s against the resource already sitting there.
        foreach ([1, 2, 3] as $n) {
            $body = $this->transport->resources[$this->transport->collectionHref . "old{$n}.vcf"]['body'];
            self::assertSame("new{$n}", Model::parse($body)->uid, 'the matched resource must have been overwritten in place');
        }
    }

    public function testDuplicateCancelResolutionNeverPushesAndIsNotReprocessed(): void
    {
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);
        [$row, $endpointHref] = $this->seedDuplicateConflict($job);
        $originalBody = $this->transport->resources[$endpointHref]['body'];

        $this->conflictApplier()->apply($job, $row, ConflictApplier::CANCEL);

        self::assertCount(1, $this->transport->resources, 'nothing new created, nothing overwritten');
        self::assertSame($originalBody, $this->transport->resources[$endpointHref]['body'], 'the existing copy must be untouched');

        $resolved = $this->state->findConflict((int) $row['id']);
        self::assertSame(ConflictApplier::CANCEL, $resolved['resolution']);
        self::assertNotNull($resolved['resolved_at']);

        $again = $this->runner()->run($job, dryRun: false, force: false, triggerSource: 'manual');
        self::assertTrue($again->plan->isEmpty(), 'a cancelled duplicate must never be re-offered or pushed on a later run');
        self::assertCount(1, $this->transport->resources, 'still just the untouched existing copy');
    }

    public function testCancelledDuplicateSurvivesALaterEditOfTheSourceContact(): void
    {
        // The regression this exists for: cancelling recorded the contact
        // one-sided but nothing said "declined", so the next run after any
        // edit saw a tracked row whose hash had moved, planned an ordinary
        // update, and created the contact on the endpoint the user had
        // just declined. Editing a contact you cancelled is ordinary --
        // cancelling says "don't sync this", not "never touch this again".
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);
        [$row, $endpointHref] = $this->seedDuplicateConflict($job);
        $originalBody = $this->transport->resources[$endpointHref]['body'];

        $this->conflictApplier()->apply($job, $row, ConflictApplier::CANCEL);

        $this->seedHub($this->personVcard('c1', 'Alice', 'Martin', 'alice@x.com', 'HOME', 'edited well after cancelling'));

        $again = $this->runner()->run($job, dryRun: false, force: false, triggerSource: 'manual');

        self::assertTrue($again->plan->isEmpty(), 'an edit to a cancelled contact must not schedule anything');
        self::assertCount(1, $this->transport->resources, 'nothing may be created on the declined side');
        self::assertSame($originalBody, $this->transport->resources[$endpointHref]['body']);
        self::assertCount(0, $this->state->unresolvedConflicts($job->id), 'and it must not be re-flagged either');
    }

    public function testCancelledStateIsDroppedOnceTheContactItselfIsGone(): void
    {
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);
        [$row] = $this->seedDuplicateConflict($job);
        $this->conflictApplier()->apply($job, $row, ConflictApplier::CANCEL);

        $this->hubDelete('c1');
        $this->runner()->run($job, dryRun: false, force: false, triggerSource: 'manual');

        self::assertArrayNotHasKey('c1', $this->state->allContacts($job->id), 'a declined row must not outlive its uid');
        self::assertCount(1, $this->transport->resources, 'and deleting it must not touch the other side');
    }

    public function testConflictRowWithoutDuplicateJsonIsRejected(): void
    {
        // A row with no duplicate_json is a same-UID edit conflict, a kind
        // this app no longer raises now that two-way sync is gone -- nothing
        // in the app writes one any more, StateMapper included. But an
        // instance upgraded from before this change may still have one of
        // these sitting unresolved from back when two-way jobs could create
        // them, so apply() must still refuse it cleanly rather than
        // misreading it as a duplicate. Seeded with a raw insert, matching
        // how such a row would actually have survived: as leftover data,
        // not through any API this app still exposes.
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);
        $qb = $this->db->getQueryBuilder();
        $qb->insert('contacthub_conflicts')->values([
            'job_id' => $qb->createNamedParameter($job->id),
            'uid' => $qb->createNamedParameter('g1'),
            'kind' => $qb->createNamedParameter('group'),
            'hub_snapshot' => $qb->createNamedParameter('BEGIN:VCARD...'),
            'endpoint_snapshot' => $qb->createNamedParameter('BEGIN:VCARD...'),
            'detected_at' => $qb->createNamedParameter(gmdate('Y-m-d H:i:s')),
        ]);
        $qb->executeStatement();
        $row = $this->state->unresolvedConflicts($job->id)[0];

        $this->expectException(\RuntimeException::class);
        $this->conflictApplier()->apply($job, $row, ConflictApplier::ARCHIVE);
    }

    public function testUnknownResolutionIsRejected(): void
    {
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);
        [$row] = $this->seedDuplicateConflict($job);

        $this->expectException(\InvalidArgumentException::class);
        // 'a' was the old A/B vocabulary; resolutions are role-named now.
        $this->conflictApplier()->apply($job, $row, 'a');
    }
}
