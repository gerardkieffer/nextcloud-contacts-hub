<?php

declare(strict_types=1);

namespace OCA\ContactHub\Tests\Integration;

use OCA\ContactHub\Sync\SyncJob;
use OCA\ContactHub\VCard\Document;
use OCA\ContactHub\VCard\Model;

/**
 * Address book snapshots, taken automatically before any sync run that will
 * actually write something.
 *
 * This is what HubBackupTest became. Four of its thirteen cases are gone
 * rather than ported, because their subject no longer exists: the old hub
 * stored photo bytes in a content-addressed blob store, so a snapshot held
 * a token rather than the image, and a whole backup_photos pinning table
 * existed to stop garbage collection reaping bytes a restore would still
 * need. Nextcloud keeps photo data inside the card, so a snapshot is
 * self-contained and there is nothing to pin. The replacement for all four
 * is testASnapshotIsSelfContained below.
 */
final class BackupServiceTest extends IntegrationTestCase
{
    private function photoVcard(string $uid, string $fn): string
    {
        $jpeg = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00"
            . str_repeat("\x00", 4096) . "\xFF\xD9";

        return "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:{$uid}\r\nFN:{$fn}\r\n"
            . 'PHOTO;ENCODING=b;TYPE=JPEG:' . base64_encode($jpeg) . "\r\nEND:VCARD\r\n";
    }

    private function groupVcard(string $uid, string $name, array $members): string
    {
        return \OCA\ContactHub\VCard\Transform::buildGroupVCard($uid, $name, $members);
    }

    // ------------------------------------------------------------ snapshots

    public function testSnapshotRecordsCountsAndStoresABlob(): void
    {
        $this->seedHub($this->vcard('c1', 'Ada'));
        $this->seedHub($this->vcard('c2', 'Grace'));
        $this->seedHub($this->groupVcard('g1', 'Team', ['c1']), 'g1.vcf');

        $id = $this->backupService()->snapshot($this->addressBookId, self::USER_ID, 'manual');

        $row = $this->backupMapper->find($id, self::USER_ID);
        self::assertNotNull($row);
        self::assertSame(2, (int) $row['contact_count']);
        self::assertSame(1, (int) $row['group_count']);
        self::assertGreaterThan(0, (int) $row['byte_size']);
        self::assertSame('manual', $row['reason']);
    }

    public function testASnapshotIsSelfContained(): void
    {
        // Replaces the old photo-token and blob-pinning tests. The snapshot
        // holds the card exactly as stored, photo bytes included, so nothing
        // outside it has to stay alive for a restore to work.
        $this->seedHub($this->photoVcard('c1', 'Ada'));

        $id = $this->backupService()->snapshot($this->addressBookId, self::USER_ID, 'manual');
        $payload = $this->backupService()->contents($id, self::USER_ID);

        self::assertCount(1, $payload['resources']);
        $carddata = $payload['resources'][0]['carddata'];

        // Parse rather than substring-match: a base64 photo is RFC-folded.
        $photo = Document::parse($carddata)->first('PHOTO');
        self::assertNotNull($photo, 'the snapshot must carry the photo bytes, not a reference');
        self::assertGreaterThan(1000, strlen(preg_replace('/\s+/', '', $photo->value)));
    }

    public function testListForReturnsTheAddressBooksSnapshots(): void
    {
        $service = $this->backupService();
        $service->snapshot($this->addressBookId, self::USER_ID, 'manual');
        $service->snapshot($this->addressBookId, self::USER_ID, 'manual');

        self::assertCount(2, $service->listFor($this->addressBookId, self::USER_ID));
    }

    public function testAnotherUsersSnapshotIsNotVisible(): void
    {
        $id = $this->backupService()->snapshot($this->addressBookId, self::USER_ID, 'manual');

        self::assertNull($this->backupMapper->find($id, 'someone-else'));
    }

    // -------------------------------------------------- the Runner's policy

    public function testAnAppliedRunSnapshotsButADryRunDoesNot(): void
    {
        $this->seedHub($this->vcard('c1', 'Ada'));
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);
        $runner = $this->runner(withBackups: true);

        $runner->run($job, dryRun: true, force: false, triggerSource: 'manual');
        self::assertCount(0, $this->backupService()->listFor($this->addressBookId, self::USER_ID));

        $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');
        self::assertCount(1, $this->backupService()->listFor($this->addressBookId, self::USER_ID));
    }

    public function testARunWithNothingToDoTakesNoSnapshot(): void
    {
        // Most scheduled runs find nothing to do. Snapshotting each one would
        // bury the genuinely useful restore points under thousands of
        // identical files.
        $this->seedHub($this->vcard('c1', 'Ada'));
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);
        $runner = $this->runner(withBackups: true);

        $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');
        $runner->run($job, dryRun: false, force: false, triggerSource: 'cron');

        self::assertCount(
            1,
            $this->backupService()->listFor($this->addressBookId, self::USER_ID),
            'the second run had no work and must not have snapshotted',
        );
    }

    public function testResumingARunDoesNotTakeASecondSnapshot(): void
    {
        // A resumed run continues work the original snapshot already covers.
        for ($i = 1; $i <= 4; $i++) {
            $this->seedHub($this->vcard("c{$i}", "Contact {$i}"));
        }
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);
        $runner = $this->runner(withBackups: true);

        $paused = $runner->run($job, dryRun: false, force: false, triggerSource: 'manual', timeBudgetSeconds: 0);
        self::assertSame('paused', $paused->status);
        self::assertCount(1, $this->backupService()->listFor($this->addressBookId, self::USER_ID));

        $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');
        self::assertCount(1, $this->backupService()->listFor($this->addressBookId, self::USER_ID));
    }

    // -------------------------------------------------------------- restore

    public function testRestoredCardsAreByteIdenticalToWhatWasSnapshotted(): void
    {
        $this->seedHub($this->photoVcard('c1', 'Ada'));
        $before = $this->hubGet('c1');

        $id = $this->backupService()->snapshot($this->addressBookId, self::USER_ID, 'manual');
        $this->hubDelete('c1');
        $this->backupService()->restore($id, self::USER_ID);

        self::assertSame(
            Model::textHash((string) $before),
            Model::textHash((string) $this->hubGet('c1')),
        );
    }

    public function testMirrorRestoreRemovesWhatTheSnapshotDidNotContain(): void
    {
        $this->seedHub($this->vcard('c1', 'Ada'));
        $id = $this->backupService()->snapshot($this->addressBookId, self::USER_ID, 'manual');

        $this->seedHub($this->vcard('c2', 'Added later'));
        $stats = $this->backupService()->restore($id, self::USER_ID, mirror: true);

        self::assertSame(1, $stats['deleted']);
        self::assertNull($this->hubGet('c2'));
        self::assertNotNull($this->hubGet('c1'));
    }

    public function testAdditiveRestoreLeavesNewerCardsAlone(): void
    {
        $this->seedHub($this->vcard('c1', 'Ada'));
        $id = $this->backupService()->snapshot($this->addressBookId, self::USER_ID, 'manual');

        $this->seedHub($this->vcard('c2', 'Added later'));
        $stats = $this->backupService()->restore($id, self::USER_ID, mirror: false);

        self::assertSame(0, $stats['deleted']);
        self::assertNotNull($this->hubGet('c2'));
    }

    public function testRestoreSnapshotsFirstSoItIsItselfUndoable(): void
    {
        // The most useful property this feature has, and the one people only
        // discover they needed afterwards.
        $this->seedHub($this->vcard('c1', 'Ada'));
        $id = $this->backupService()->snapshot($this->addressBookId, self::USER_ID, 'manual');

        $this->backupService()->restore($id, self::USER_ID);

        $reasons = array_column($this->backupService()->listFor($this->addressBookId, self::USER_ID), 'reason');
        self::assertContains('pre_restore', $reasons);
    }

    public function testGroupsSurviveASnapshotRestoreCycle(): void
    {
        $this->seedHub($this->vcard('c1', 'Ada'));
        $this->seedHub($this->groupVcard('g1', 'Team', ['c1']), 'g1.vcf');

        $id = $this->backupService()->snapshot($this->addressBookId, self::USER_ID, 'manual');
        $this->hubDelete('g1');
        $this->backupService()->restore($id, self::USER_ID);

        $restored = Model::parse((string) $this->hubGet('g1'));
        self::assertInstanceOf(\OCA\ContactHub\VCard\Group::class, $restored);
        self::assertSame(['c1'], $restored->memberUids);
    }

    public function testRestoringSomeoneElsesSnapshotIsRefused(): void
    {
        $this->seedHub($this->vcard('c1', 'Ada'));
        $id = $this->backupService()->snapshot($this->addressBookId, self::USER_ID, 'manual');

        $this->expectException(\RuntimeException::class);
        $this->backupService()->restore($id, 'someone-else');
    }

    // ---------------------------------------------------------------- prune

    public function testExpiredSnapshotsArePrunedWithTheirBlobs(): void
    {
        $this->seedHub($this->vcard('c1', 'Ada'));

        // Negative retention ages the snapshot past its expiry immediately.
        $expiredId = $this->backupService(retentionDays: -1)
            ->snapshot($this->addressBookId, self::USER_ID, 'manual');
        $liveId = $this->backupService(retentionDays: 30)
            ->snapshot($this->addressBookId, self::USER_ID, 'manual');

        $pruned = $this->backupService()->prune();

        self::assertSame(1, $pruned);
        self::assertNull($this->backupMapper->find($expiredId, self::USER_ID));
        self::assertNotNull($this->backupMapper->find($liveId, self::USER_ID));
    }

    public function testPruningSurvivesARowWhoseOwnerNoLongerExists(): void
    {
        // Snapshots live in the user's Files now, so every delete resolves a
        // home directory -- and a deleted account has none. IRootFolder throws
        // NoUserException there, which extends \Exception and is caught by
        // none of the Files exception types.
        //
        // This is not an edge case: UserDeletedEvent fires *after* the account
        // is gone, so it is the ordinary path for every deleted user. Before
        // the fix, one such row aborted prune() outright, so every expired
        // snapshot belonging to every *other* user stopped being cleaned up
        // and its blob was orphaned.
        $this->seedHub($this->vcard('c1', 'Ada'));
        $mine = $this->backupService(retentionDays: -1)
            ->snapshot($this->addressBookId, self::USER_ID, 'manual');

        $ghost = $this->backupMapper->create([
            'user_id' => 'chub-ghost-' . bin2hex(random_bytes(4)),
            'address_book_id' => $this->addressBookId,
            'job_id' => null,
            'run_id' => null,
            'reason' => 'manual',
            'blob_name' => 'never-written.json.gz',
            'contact_count' => 0,
            'group_count' => 0,
            'byte_size' => 0,
            'retention_days' => -1,
        ]);

        $ghostSurvived = true;
        try {
            $this->backupService()->prune();
            $ghostSurvived = array_filter(
                $this->backupMapper->expired(),
                static fn(array $row): bool => (int) $row['id'] === $ghost,
            ) !== [];
        } finally {
            // The ghost row belongs to another user by construction, so
            // wipeOwnTables() cannot reach it. If it is ever left behind,
            // every later run of the suite sees one extra expired snapshot
            // and the neighbouring prune test fails for a reason that has
            // nothing to do with what it tests.
            $this->backupMapper->delete($ghost);
        }

        self::assertNull(
            $this->backupMapper->find($mine, self::USER_ID),
            'a healthy row must still be pruned even when another row belongs to a deleted account',
        );
        self::assertFalse(
            $ghostSurvived,
            'the unreachable row must be cleared too, or it blocks pruning forever',
        );
    }

    public function testPruningIsSafeWhenABlobHasAlreadyVanished(): void
    {
        $this->seedHub($this->vcard('c1', 'Ada'));
        $id = $this->backupService(retentionDays: -1)
            ->snapshot($this->addressBookId, self::USER_ID, 'manual');

        $row = $this->backupMapper->find($id, self::USER_ID);
        self::assertNotNull($row);

        // Deleted the way it now actually happens: snapshots live in the
        // user's own Files, so the user can put one in the trash themselves.
        // That is the whole reason this case is ordinary rather than exotic.
        $this->hubFolder()->delete(
            self::USER_ID,
            \OCA\ContactHub\Files\HubFolder::SNAPSHOTS,
            (string) $row['blob_name'],
        );

        // The row still has to be cleared, or prune() would fail forever on
        // the same snapshot.
        self::assertSame(1, $this->backupService()->prune());
        self::assertNull($this->backupMapper->find($id, self::USER_ID));
    }
}
