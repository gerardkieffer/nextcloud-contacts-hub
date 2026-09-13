<?php

declare(strict_types=1);

namespace OCA\ContactHub\Tests\Integration;

use OCA\ContactHub\Service\AddressBookService;
use OCA\ContactHub\Service\ConflictService;
use OCA\ContactHub\Service\JobService;
use OCA\ContactHub\Sync\ConflictApplier;
use OCA\ContactHub\Sync\SyncJob;

/**
 * What ConflictService tells the UI to send back.
 *
 * This exists because of a bug the UI shipped with: the conflict screen
 * hardcoded resolution='hub' for "same person, merge" and 'endpoint' for
 * "different people, keep both". For a *duplicate*, ConflictApplier decides
 * which is which from duplicate_json.source_role -- the side the new contact
 * arrived on -- so those literals are correct in one direction and exactly
 * inverted in the other. In the inverted case the button labelled "keep
 * both" overwrote the Nextcloud contact the user believed they were keeping.
 *
 * The mapping now lives in ConflictService, and these tests pin it in both
 * directions. The pre-existing ConflictApplierTest only ever exercised
 * to_endpoint, which is why the bug survived it.
 */
final class ConflictPresentationTest extends IntegrationTestCase
{
    private function service(): ConflictService
    {
        $addressBooks = new AddressBookService($this->backend);
        $jobService = new JobService($this->jobs, $this->state, $addressBooks, $this->endpoints);

        return new ConflictService($this->state, $this->conflictApplier(), $jobService);
    }

    private function personVcard(string $uid, string $first, string $last, string $email, string $emailType = 'HOME'): string
    {
        return "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:{$uid}\r\nFN:{$first} {$last}\r\n"
            . "N:{$last};{$first};;;\r\nEMAIL;TYPE={$emailType}:{$email}\r\nEND:VCARD\r\n";
    }

    /**
     * Seed a near-identical pair under different UIDs so the duplicate
     * matcher flags them, then return the presented conflict.
     *
     * @return array<string, mixed>
     */
    private function presentedDuplicate(string $direction): array
    {
        $job = $this->makeJob($direction);

        $this->seedHub($this->personVcard('hub-new', 'Alice', 'Martin', 'alice@x.com'));
        $this->seedEndpoint('ep-old.vcf', $this->personVcard('ep-old', 'Alice', 'Martin', 'ALICE@x.com', 'WORK'));

        $this->runner()->run($job, dryRun: false, force: false, triggerSource: 'manual');

        $presented = $this->service()->listForJob($job->id, self::USER_ID);
        self::assertCount(1, $presented, 'expected exactly one duplicate conflict');

        return $presented[0];
    }

    public function testWhenTheNewContactCameFromNextcloudMergeMeansHub(): void
    {
        // to_endpoint puts the hub in slot A, so the new contact is the
        // hub's and source_role is 'hub'.
        $conflict = $this->presentedDuplicate(SyncJob::TO_ENDPOINT);

        self::assertSame('hub', $conflict['duplicate']['source_role']);
        self::assertSame(ConflictApplier::HUB, $conflict['merge_resolution']);
        self::assertSame(ConflictApplier::ENDPOINT, $conflict['keep_both_resolution']);
        self::assertSame('hub', $conflict['new_contact_role']);
    }

    public function testWhenTheNewContactCameFromTheEndpointMergeMeansEndpoint(): void
    {
        // from_endpoint puts the endpoint in slot A, so source_role is
        // 'endpoint' and BOTH resolutions invert. This is the direction the
        // hardcoded UI got backwards.
        $conflict = $this->presentedDuplicate(SyncJob::FROM_ENDPOINT);

        self::assertSame('endpoint', $conflict['duplicate']['source_role']);
        self::assertSame(ConflictApplier::ENDPOINT, $conflict['merge_resolution']);
        self::assertSame(ConflictApplier::HUB, $conflict['keep_both_resolution']);
        self::assertSame('endpoint', $conflict['new_contact_role']);
    }

    public function testMergeAndKeepBothAreNeverTheSameRole(): void
    {
        foreach ([SyncJob::TO_ENDPOINT, SyncJob::FROM_ENDPOINT] as $direction) {
            $this->tearDown();
            $this->setUp();

            $conflict = $this->presentedDuplicate($direction);
            self::assertNotSame(
                $conflict['merge_resolution'],
                $conflict['keep_both_resolution'],
                "merge and keep-both collapsed onto one role for {$direction}",
            );
        }
    }

    public function testKeepBothReallyKeepsBothWhenTheDuplicateCameFromTheEndpoint(): void
    {
        // The end-to-end proof, in the direction that used to destroy data:
        // taking the presented "keep both" value must leave the existing
        // Nextcloud contact intact.
        $job = $this->makeJob(SyncJob::FROM_ENDPOINT);

        $this->seedHub($this->personVcard('hub-old', 'Alice', 'Martin', 'alice@x.com'));
        $this->seedEndpoint('ep-new.vcf', $this->personVcard('ep-new', 'Alice', 'Martin', 'ALICE@x.com', 'WORK'));
        $this->runner()->run($job, dryRun: false, force: false, triggerSource: 'manual');

        $conflict = $this->service()->listForJob($job->id, self::USER_ID)[0];
        $this->service()->resolve((int) $conflict['id'], self::USER_ID, $conflict['keep_both_resolution']);

        $hub = $this->hubByUid();
        self::assertArrayHasKey('hub-old', $hub, 'the existing Nextcloud contact must survive "keep both"');
        self::assertArrayHasKey('ep-new', $hub, 'the new contact must also be present');
    }

    public function testTheLiteralTheUiUsedToSendDestroysTheExistingContact(): void
    {
        // Not a test of desired behaviour -- a record of the severity, and a
        // guard that these two resolutions never quietly become the same
        // action.
        //
        // With the duplicate arriving from the endpoint, 'endpoint' is the
        // *merge* role. The old UI hardcoded that literal onto the button
        // labelled "Different people — keep both", so clicking it overwrote
        // the Nextcloud contact the user had just been told would be kept.
        $job = $this->makeJob(SyncJob::FROM_ENDPOINT);

        $this->seedHub($this->personVcard('hub-old', 'Alice', 'Martin', 'alice@x.com'));
        $this->seedEndpoint('ep-new.vcf', $this->personVcard('ep-new', 'Alice', 'Martin', 'ALICE@x.com', 'WORK'));
        $this->runner()->run($job, dryRun: false, force: false, triggerSource: 'manual');

        $conflict = $this->service()->listForJob($job->id, self::USER_ID)[0];
        self::assertSame(ConflictApplier::ENDPOINT, $conflict['merge_resolution']);

        $this->service()->resolve((int) $conflict['id'], self::USER_ID, ConflictApplier::ENDPOINT);

        $hub = $this->hubByUid();
        self::assertArrayNotHasKey('hub-old', $hub, 'merging replaces the existing contact, as documented');
        self::assertArrayHasKey('ep-new', $hub);
    }

    /** A duplicate conflict whose duplicate_json names no usable source role. */
    private function seedUnmappableDuplicate(SyncJob $job): void
    {
        $card = $this->personVcard('broken', 'Dana', 'Fox', 'dana@x.com');
        $this->state->recordDuplicateConflict($job->id, 'broken', $card, $card, (string) json_encode(['dest_uid' => 'whatever']));
    }

    public function testAConflictWithNoUsableSourceRoleIsOfferedNoChoices(): void
    {
        // This used to fall through to a guess (destRole = hub) and render
        // the full set of buttons on top of it -- an action whose meaning
        // nobody could vouch for, in the one computation that has already
        // shipped a destructive bug when it was gotten wrong.
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);
        $this->seedUnmappableDuplicate($job);

        $conflict = $this->service()->listForJob($job->id, self::USER_ID)[0];

        self::assertFalse($conflict['is_resolvable']);
        self::assertArrayNotHasKey('merge_resolution', $conflict, 'no choice may be offered on a guessed role');
        self::assertArrayNotHasKey('keep_both_resolution', $conflict);
    }

    public function testResolveBatchReportsAConflictItCannotMapInsteadOfSkippingIt(): void
    {
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);
        $this->seedUnmappableDuplicate($job);

        $result = $this->service()->resolveBatch(self::USER_ID, 'merge');

        self::assertSame(0, $result['resolved']);
        self::assertCount(1, $result['errors'], 'a row the batch could not act on must be reported, not dropped');
        self::assertStringContainsString('broken', $result['errors'][0]);
        self::assertCount(1, $this->state->unresolvedConflicts($job->id), 'and it must stay unresolved');
    }

    public function testEveryBatchChoiceExplainsAnUnmappableConflictTheSameWay(): void
    {
        // 'archive' and 'cancel' do not need the roles, so they used to
        // return their literal without checking them -- and applyDuplicate()
        // then read the same roles and threw "Corrupt duplicate_json on
        // conflict row.", which the batch reported verbatim. One bad row,
        // two messages, depending on which button was pressed.
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);
        $this->seedUnmappableDuplicate($job);

        foreach (['merge', 'keep_both', 'archive', 'cancel'] as $choice) {
            $result = $this->service()->resolveBatch(self::USER_ID, $choice);

            self::assertSame(0, $result['resolved'], "{$choice}: nothing may be resolved");
            self::assertCount(1, $result['errors'], "{$choice}: the row must be reported");
            self::assertStringContainsString(
                'does not record which side the new contact came from',
                $result['errors'][0],
                "{$choice}: must explain the defect, not surface an internal exception message",
            );
        }
    }

    public function testResolveBatchMergeMeansHubWhenTheNewContactCameFromNextcloud(): void
    {
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);
        $this->seedHub($this->personVcard('hub-new', 'Alice', 'Martin', 'alice@x.com'));
        $this->seedEndpoint('ep-old.vcf', $this->personVcard('ep-old', 'Alice', 'Martin', 'ALICE@x.com', 'WORK'));
        $this->runner()->run($job, dryRun: false, force: false, triggerSource: 'manual');

        $result = $this->service()->resolveBatch(self::USER_ID, 'merge');

        self::assertSame(1, $result['resolved']);
        self::assertSame([], $result['errors']);
        self::assertArrayHasKey('hub-new', $this->hubByUid());
    }

    public function testResolveBatchMergeMeansEndpointWhenTheNewContactCameFromTheEndpoint(): void
    {
        // Same bug class as the single-conflict tests above, but for the
        // batch path: with the duplicate arriving from the endpoint,
        // 'merge' must resolve to the endpoint's role, not a literal
        // carried over from the to_endpoint case above.
        $job = $this->makeJob(SyncJob::FROM_ENDPOINT);
        $this->seedHub($this->personVcard('hub-old', 'Alice', 'Martin', 'alice@x.com'));
        $this->seedEndpoint('ep-new.vcf', $this->personVcard('ep-new', 'Alice', 'Martin', 'ALICE@x.com', 'WORK'));
        $this->runner()->run($job, dryRun: false, force: false, triggerSource: 'manual');

        $result = $this->service()->resolveBatch(self::USER_ID, 'merge');

        self::assertSame(1, $result['resolved']);
        $hub = $this->hubByUid();
        self::assertArrayNotHasKey('hub-old', $hub, 'merging replaces the existing hub contact when the duplicate arrived from the endpoint');
        self::assertArrayHasKey('ep-new', $hub);
    }

    public function testResolveBatchResolvesEveryPendingDuplicateInOneCall(): void
    {
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);
        $this->seedHub($this->personVcard('hub-a', 'Alice', 'Martin', 'alice@x.com'));
        $this->seedEndpoint('ep-a.vcf', $this->personVcard('ep-a', 'Alice', 'Martin', 'ALICE@x.com', 'WORK'));
        $this->seedHub($this->personVcard('hub-b', 'Bob', 'Jones', 'bob@x.com'));
        $this->seedEndpoint('ep-b.vcf', $this->personVcard('ep-b', 'Bob', 'Jones', 'BOB@x.com', 'WORK'));
        $this->runner()->run($job, dryRun: false, force: false, triggerSource: 'manual');
        self::assertCount(2, $this->state->unresolvedConflicts($job->id));

        $result = $this->service()->resolveBatch(self::USER_ID, 'cancel');

        self::assertSame(2, $result['resolved']);
        self::assertCount(0, $this->state->unresolvedConflicts($job->id));
    }
}
