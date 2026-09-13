<?php

declare(strict_types=1);

namespace OCA\ContactHub\Tests\Sync;

use OCA\ContactHub\Sync\Planner;
use OCA\ContactHub\Sync\PlanInput;
use OCA\ContactHub\VCard\AddressBook;
use OCA\ContactHub\VCard\Contact;
use OCA\ContactHub\VCard\Group;
use OCA\ContactHub\VCard\Model;
use PHPUnit\Framework\TestCase;

final class PlannerTest extends TestCase
{
    private Planner $planner;

    protected function setUp(): void
    {
        $this->planner = new Planner();
    }

    private function contact(string $uid, string $fn, ?string $rev = null): Contact
    {
        return new Contact($uid, $fn, "BEGIN:VCARD\r\nUID:{$uid}\r\nFN:{$fn}\r\nEND:VCARD\r\n", false, $rev);
    }

    /** @param string[] $emails @param string[] $phones */
    private function person(string $uid, string $first, string $last, array $emails = [], array $phones = []): Contact
    {
        return new Contact(
            $uid,
            "{$first} {$last}",
            "BEGIN:VCARD\r\nUID:{$uid}\r\nFN:{$first} {$last}\r\nEND:VCARD\r\n",
            false,
            null,
            $first,
            $last,
            $emails,
            $phones,
        );
    }

    public function testOneWayCreate(): void
    {
        $bookA = new AddressBook(contacts: ['u1' => $this->contact('u1', 'Alice')]);
        $plan = $this->planner->plan(new PlanInput($bookA, new AddressBook(), [], [], []));

        self::assertSame(['u1'], $plan->contactsCreateAToB);
    }

    public function testOneWayUpdateOnContentChange(): void
    {
        $bookA = new AddressBook(contacts: ['u1' => $this->contact('u1', 'Alice Updated')]);
        $oldHash = Model::contactContentHash($this->contact('u1', 'Alice'));
        $states = ['u1' => ['a_href' => 'a/u1', 'a_hash' => $oldHash, 'b_href' => 'b/u1', 'b_hash' => null]];

        $plan = $this->planner->plan(new PlanInput($bookA, new AddressBook(), $states, [], []));

        self::assertSame(['u1'], $plan->contactsUpdateAToB);
    }

    public function testOneWayNoOpWhenUnchangedAndStillLiveOnB(): void
    {
        $contact = $this->contact('u1', 'Alice Updated');
        $bookA = new AddressBook(contacts: ['u1' => $contact]);
        $states = ['u1' => ['a_href' => 'a/u1', 'a_hash' => Model::contactContentHash($contact), 'b_href' => 'b/u1', 'b_hash' => null]];

        $plan = $this->planner->plan(new PlanInput($bookA, new AddressBook(), $states, [], ['b/u1' => 'etag-1']));

        self::assertTrue($plan->isEmpty());
    }

    public function testOneWayRecreatesOnBAfterOutOfBandDeletion(): void
    {
        $contact = $this->contact('u1', 'Alice Updated');
        $bookA = new AddressBook(contacts: ['u1' => $contact]);
        $states = ['u1' => ['a_href' => 'a/u1', 'a_hash' => Model::contactContentHash($contact), 'b_href' => 'b/u1', 'b_hash' => null]];

        // b/u1 missing from the live listing -> treated as gone out-of-band.
        $plan = $this->planner->plan(new PlanInput($bookA, new AddressBook(), $states, [], []));

        self::assertSame(['u1'], $plan->contactsUpdateAToB);
    }

    public function testOneWayRemovalMirrorsToB(): void
    {
        $states = ['u1' => ['a_href' => 'a/u1', 'a_hash' => 'h', 'b_href' => 'b/u1', 'b_hash' => null]];
        $bookAEmpty = new AddressBook();

        $plan = $this->planner->plan(new PlanInput($bookAEmpty, new AddressBook(), $states, [], []));

        self::assertSame(['u1'], $plan->contactsRemoveOnB);
    }

    public function testArchivedRemovalIsNotReprocessedOnNextRun(): void
    {
        $states = ['u1' => [
            'a_href' => 'a/u1', 'a_hash' => 'h', 'b_href' => 'b/u1', 'b_hash' => 'h', 'archived_b' => 1,
        ]];
        $bookAEmpty = new AddressBook(); // still gone from A

        $plan = $this->planner->plan(new PlanInput($bookAEmpty, new AddressBook(), $states, [], []));

        self::assertTrue($plan->isEmpty(), 'an already-archived removal must not be re-flagged for removal every run');
    }

    public function testOneWayNewContactMatchingUntrackedDestinationContactIsFlaggedAsDuplicate(): void
    {
        $bookA = new AddressBook(contacts: ['new-1' => $this->person('new-1', 'Alice', 'Martin', ['alice@example.com'])]);
        $bookB = new AddressBook(contacts: ['old-9' => $this->person('old-9', 'alice', 'MARTIN', ['ALICE@example.com', 'other@x.com'])]);

        $plan = $this->planner->plan(new PlanInput($bookA, $bookB, [], [], []));

        self::assertSame([], $plan->contactsCreateAToB);
        self::assertCount(1, $plan->contactDuplicates);
        self::assertSame('new-1', $plan->contactDuplicates[0]->uid);
        self::assertSame('old-9', $plan->contactDuplicates[0]->destUid);
        self::assertSame('a', $plan->contactDuplicates[0]->sourceSide);
    }

    public function testDuplicateMatchByPhoneIgnoresFormatting(): void
    {
        $bookA = new AddressBook(contacts: ['new-1' => $this->person('new-1', 'Bob', 'Weber', [], ['+352 621 123 456'])]);
        $bookB = new AddressBook(contacts: ['old-2' => $this->person('old-2', 'Bob', 'Weber', ['bob@elsewhere.com'], ['00352621123456'])]);

        $plan = $this->planner->plan(new PlanInput($bookA, $bookB, [], [], []));

        // Phone digits differ (+352... vs 00352...) -- prefix normalization is
        // out of scope, so this must NOT match... unless digits align. They
        // don't here (352... vs 00352...), so it's a plain create.
        self::assertSame(['new-1'], $plan->contactsCreateAToB);

        $bookB2 = new AddressBook(contacts: ['old-2' => $this->person('old-2', 'Bob', 'Weber', [], ['(352) 621-123-456'])]);
        $plan2 = $this->planner->plan(new PlanInput($bookA, $bookB2, [], [], []));
        self::assertCount(1, $plan2->contactDuplicates);
        self::assertSame([], $plan2->contactsCreateAToB);
    }

    public function testSameNameWithoutSharedEmailOrPhoneIsNotADuplicate(): void
    {
        $bookA = new AddressBook(contacts: ['new-1' => $this->person('new-1', 'Jean', 'Muller', ['jean@a.com'], ['111'])]);
        $bookB = new AddressBook(contacts: ['old-2' => $this->person('old-2', 'Jean', 'Muller', ['jean@b.com'], ['222'])]);

        $plan = $this->planner->plan(new PlanInput($bookA, $bookB, [], [], []));

        self::assertSame(['new-1'], $plan->contactsCreateAToB);
        self::assertSame([], $plan->contactDuplicates);
    }

    public function testTrackedDestinationContactIsNeverADuplicateCandidate(): void
    {
        $tracked = $this->person('old-2', 'Alice', 'Martin', ['alice@example.com']);
        $bookA = new AddressBook(contacts: ['new-1' => $this->person('new-1', 'Alice', 'Martin', ['alice@example.com'])]);
        $bookB = new AddressBook(contacts: ['old-2' => $tracked]);
        $states = ['old-2' => ['a_href' => null, 'a_hash' => null, 'b_href' => 'b/old-2', 'b_hash' => Model::contactContentHash($tracked)]];

        $plan = $this->planner->plan(new PlanInput($bookA, $bookB, $states, [], ['b/old-2' => 'e1']));

        self::assertSame([], $plan->contactDuplicates);
        self::assertSame(['new-1'], $plan->contactsCreateAToB);
    }

    public function testContactWithoutFirstOrLastNameNeverMatches(): void
    {
        $bookA = new AddressBook(contacts: ['new-1' => $this->person('new-1', 'Madonna', '', ['m@x.com'])]);
        $bookB = new AddressBook(contacts: ['old-2' => $this->person('old-2', 'Madonna', '', ['m@x.com'])]);

        $plan = $this->planner->plan(new PlanInput($bookA, $bookB, [], [], []));

        self::assertSame(['new-1'], $plan->contactsCreateAToB);
        self::assertSame([], $plan->contactDuplicates);
    }

    public function testGroupNamesForContactMergesBothSidesAndDedups(): void
    {
        $groupA = new Group('g1', 'Family', ['u1'], '');
        $groupB = new Group('g2', 'Friends', ['u1'], '');
        $bookA = new AddressBook(contacts: ['u1' => $this->contact('u1', 'Alice')], groups: ['g1' => $groupA]);
        $bookB = new AddressBook(contacts: ['u1' => $this->contact('u1', 'Alice')], groups: ['g2' => $groupB]);

        self::assertSame(['Family', 'Friends'], Planner::groupNamesForContact('u1', $bookA, $bookB));
    }

    public function testArchiveGroupUidsAreExcludedFromGeneralGroupDiff(): void
    {
        $archiveUid = Planner::ARCHIVE_GROUP_PREFIX . 'b__';
        $bookA = new AddressBook();
        $bookB = new AddressBook();
        $groupStates = [$archiveUid => ['a_href' => null, 'b_href' => 'b/archive.vcf']];

        $plan = $this->planner->plan(new PlanInput($bookA, $bookB, [], $groupStates, []));

        self::assertTrue($plan->isEmpty());
    }
}
