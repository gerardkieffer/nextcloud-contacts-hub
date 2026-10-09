<?php

declare(strict_types=1);

namespace OCA\ContactHub\Tests\Sync;

use OCA\ContactHub\Sync\CategoryGroups;
use OCA\ContactHub\Sync\Planner;
use OCA\ContactHub\Sync\PlanInput;
use OCA\ContactHub\VCard\AddressBook;
use OCA\ContactHub\VCard\Contact;
use OCA\ContactHub\VCard\Group;
use OCA\ContactHub\VCard\Model;
use PHPUnit\Framework\TestCase;

/**
 * Group reconciliation the Planner used to miss: derived groups never being
 * removed, and a categories destination never settling or never hearing
 * about a membership change. Integration coverage is in DataSafetyTest.
 */
final class PlannerGroupsTest extends TestCase
{
    /** @param string[] $categories */
    private function contact(string $uid, array $categories = []): Contact
    {
        return new Contact($uid, $uid, "BEGIN:VCARD\r\nUID:{$uid}\r\nEND:VCARD\r\n", false, null, '', '', [], [], $categories);
    }

    /** @return array<string, mixed> */
    private function trackedContact(string $uid): array
    {
        $text = "BEGIN:VCARD\r\nUID:{$uid}\r\nEND:VCARD\r\n";

        return ['a_href' => "{$uid}.vcf", 'b_href' => "b/{$uid}.vcf", 'a_hash' => Model::textHash($text), 'b_hash' => null];
    }

    /** @param string[] $members @return array<string, mixed> */
    private function groupState(string $name, array $members, ?string $aHref, ?string $bHref): array
    {
        return [
            'a_href' => $aHref,
            'b_href' => $bHref,
            'a_hash' => null,
            'b_hash' => null,
            'payload_json' => json_encode(['name' => $name, 'member_uids' => $members]),
        ];
    }

    public function testADerivedGroupWhoseCategoryVanishedIsRemovedFromB(): void
    {
        $uid = CategoryGroups::uidFor('Family');
        $plan = (new Planner())->plan(new PlanInput(
            new AddressBook(contacts: ['c1' => $this->contact('c1')]),
            new AddressBook(),
            [],
            [$uid => $this->groupState('Family', ['c1'], null, "b/{$uid}.vcf")],
            ["b/{$uid}.vcf" => '"e"'],
        ));

        self::assertSame([$uid], $plan->groupsRemoveOnB);
    }

    public function testAGroupThatOnlyExistedOnBBeforeADirectionFlipIsLeftAlone(): void
    {
        // A pull job recorded the endpoint's own group; the job now pushes
        // instead, and the hub has no such group. It was never A's to delete.
        $plan = (new Planner())->plan(new PlanInput(
            new AddressBook(),
            new AddressBook(),
            [],
            ['apple-group-1' => $this->groupState('Family', ['c1'], null, 'b/g.vcf')],
            ['b/g.vcf' => '"e"'],
        ));

        self::assertSame([], $plan->groupsRemoveOnB);
    }

    public function testATrackedGroupOnACategoriesDestinationIsNotRePlannedEveryRun(): void
    {
        $group = new Group('g1', 'Family', ['c1'], '');
        $state = $this->groupState('Family', ['c1'], 'a/g1.vcf', null);
        $state['a_hash'] = Model::groupContentHash($group);

        $plan = (new Planner())->plan(new PlanInput(
            new AddressBook(contacts: ['c1' => $this->contact('c1')], groups: ['g1' => $group]),
            new AddressBook(contacts: ['c1' => $this->contact('c1', ['Family'])]),
            ['c1' => $this->trackedContact('c1')],
            ['g1' => $state],
            ['b/c1.vcf' => '"e"'],
            bGroupsAsCategories: true,
        ));

        self::assertTrue($plan->isEmpty());
    }

    public function testAMembershipChangeRePushesOnlyTheMembersWhoseCategoriesDiffer(): void
    {
        $group = new Group('g1', 'Family', ['c1', 'c2'], '');
        $state = $this->groupState('Family', ['c1'], 'a/g1.vcf', null);
        $state['a_hash'] = 'stale';

        $plan = (new Planner())->plan(new PlanInput(
            new AddressBook(contacts: ['c1' => $this->contact('c1'), 'c2' => $this->contact('c2')], groups: ['g1' => $group]),
            new AddressBook(contacts: ['c1' => $this->contact('c1', ['Family']), 'c2' => $this->contact('c2')]),
            ['c1' => $this->trackedContact('c1'), 'c2' => $this->trackedContact('c2')],
            ['g1' => $state],
            ['b/c1.vcf' => '"e"', 'b/c2.vcf' => '"e"'],
            bGroupsAsCategories: true,
        ));

        self::assertSame(['g1'], $plan->groupsUpdateAToB);
        self::assertSame(['c2'], $plan->contactsUpdateAToB);
    }

    public function testNoNameCollisionIsReportedForACategoriesDestination(): void
    {
        $plan = (new Planner())->plan(new PlanInput(
            new AddressBook(groups: ['g1' => new Group('g1', 'Family', [], '')]),
            new AddressBook(groups: [CategoryGroups::uidFor('Family') => new Group(CategoryGroups::uidFor('Family'), 'Family', [], '')]),
            [],
            [],
            [],
            bGroupsAsCategories: true,
        ));

        self::assertSame([], $plan->groupNameCollisions);
    }
}
