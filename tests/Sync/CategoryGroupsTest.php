<?php

declare(strict_types=1);

namespace OCA\ContactHub\Tests\Sync;

use OCA\ContactHub\Sync\CategoryGroups;
use OCA\ContactHub\VCard\AddressBook;
use OCA\ContactHub\VCard\Group;
use OCA\ContactHub\VCard\Model;
use OCA\ContactHub\VCard\Transform;
use PHPUnit\Framework\TestCase;

/**
 * The categories -> passthrough projection. This is what lets group
 * membership flow *out* of Nextcloud (which models groups as CATEGORIES) to
 * an endpoint like iCloud (which wants discrete KIND:group vCards).
 */
final class CategoryGroupsTest extends TestCase
{
    /** @param string[] $categories */
    private function contact(string $uid, string $fn, array $categories = []): string
    {
        $lines = [
            'BEGIN:VCARD',
            'VERSION:3.0',
            "UID:{$uid}",
            "FN:{$fn}",
            "N:{$fn};;;;",
        ];
        if ($categories !== []) {
            $lines[] = 'CATEGORIES:' . implode(',', $categories);
        }
        $lines[] = 'END:VCARD';

        return implode("\r\n", $lines) . "\r\n";
    }

    /** @param string[] $rawTexts */
    private function book(array $rawTexts): AddressBook
    {
        [$book] = Model::buildAddressBook($rawTexts);

        return $book;
    }

    public function testCategoriesBecomeGroupsWithTheRightMembers(): void
    {
        $book = $this->book([
            $this->contact('c1', 'Ada', ['Family', 'Work']),
            $this->contact('c2', 'Grace', ['Work']),
            $this->contact('c3', 'Alan'),
        ]);

        $projected = CategoryGroups::project($book);

        self::assertCount(2, $projected->groups);

        $byName = [];
        foreach ($projected->groups as $group) {
            $byName[$group->name] = $group->memberUids;
        }

        self::assertSame(['c1'], $byName['Family']);
        self::assertSame(['c1', 'c2'], $byName['Work']);
    }

    public function testContactsAreLeftUntouched(): void
    {
        $book = $this->book([$this->contact('c1', 'Ada', ['Family'])]);

        $projected = CategoryGroups::project($book);

        self::assertSame(array_keys($book->contacts), array_keys($projected->contacts));
    }

    public function testABookWithNoCategoriesIsUnchanged(): void
    {
        $book = $this->book([$this->contact('c1', 'Ada')]);

        self::assertSame([], CategoryGroups::project($book)->groups);
    }

    public function testUidsAreStableAcrossRuns(): void
    {
        // The whole design rests on this: the derived UID is what gets
        // written to the passthrough side and recorded in group_state, so an
        // unstable one would recreate every group on every run.
        self::assertSame(CategoryGroups::uidFor('Family'), CategoryGroups::uidFor('Family'));
    }

    public function testUidsAreDistinctPerName(): void
    {
        self::assertNotSame(CategoryGroups::uidFor('Family'), CategoryGroups::uidFor('Work'));
    }

    public function testUidDerivationIsCaseAndWhitespaceInsensitive(): void
    {
        // "Family" and "family " are one group, not two.
        self::assertSame(CategoryGroups::uidFor('Family'), CategoryGroups::uidFor('  family '));
    }

    public function testDerivedUidIsAValidUuidV5(): void
    {
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            CategoryGroups::uidFor('Family'),
        );
    }

    public function testRenamingACategoryChangesItsUid(): void
    {
        // Documented behaviour, not a bug: the passthrough side sees a
        // delete plus a create rather than a rename. Membership survives;
        // the far side's group identity does not.
        self::assertNotSame(CategoryGroups::uidFor('Family'), CategoryGroups::uidFor('Relatives'));
    }

    public function testMemberOrderIsSortedSoTheGeneratedVCardIsByteStable(): void
    {
        $one = CategoryGroups::project($this->book([
            $this->contact('zzz', 'Zoe', ['Team']),
            $this->contact('aaa', 'Ada', ['Team']),
        ]));
        $two = CategoryGroups::project($this->book([
            $this->contact('aaa', 'Ada', ['Team']),
            $this->contact('zzz', 'Zoe', ['Team']),
        ]));

        $uid = CategoryGroups::uidFor('Team');

        // Byte-identical, not merely equivalent: an unstable member order
        // would rewrite the group on the far side every run, which is the
        // phantom-update bug this codebase exists to avoid.
        self::assertSame($one->groups[$uid]->rawText, $two->groups[$uid]->rawText);
    }

    public function testAnExistingRealGroupWinsOverAProjectedOne(): void
    {
        // A categories-based side can still hold real group vCards -- iOS
        // pushes them to Nextcloud -- and a real group is more authoritative.
        $uid = CategoryGroups::uidFor('Family');
        $book = $this->book([$this->contact('c1', 'Ada', ['Family'])]);
        $book->groups[$uid] = new Group($uid, 'Family', ['c9'], Transform::buildGroupVCard($uid, 'Family', ['c9']));

        $projected = CategoryGroups::project($book);

        self::assertSame(['c9'], $projected->groups[$uid]->memberUids);
    }

    public function testTheGeneratedGroupVCardParsesBackToTheSameMembers(): void
    {
        // Guards the fold-boundary hazard: with enough members the
        // X-ADDRESSBOOKSERVER-MEMBER lines get RFC-folded, and anything that
        // substring-matched the raw text would break. Parse, always.
        $contacts = [];
        $expected = [];
        for ($i = 0; $i < 40; $i++) {
            $uid = sprintf('urn-uuid-%02d-aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $i);
            $contacts[] = $this->contact($uid, "Person {$i}", ['Big']);
            $expected[] = $uid;
        }
        sort($expected);

        $projected = CategoryGroups::project($this->book($contacts));
        $group = $projected->groups[CategoryGroups::uidFor('Big')];

        $reparsed = Model::parse($group->rawText);
        self::assertInstanceOf(Group::class, $reparsed);
        self::assertSame($expected, $reparsed->memberUids);
    }

    public function testIgnoredCategoriesDoNotBecomeGroups(): void
    {
        // The archive category is a marker this app writes when a contact is
        // deleted elsewhere, not a group anybody asked to sync. Projecting it
        // would manufacture a "Deleted" group and push it to the other side.
        $book = $this->book([
            $this->contact('c1', 'Ada', ['Family']),
            $this->contact('c2', 'Grace', ['Deleted']),
        ]);

        $projected = CategoryGroups::project($book, ['Deleted']);

        self::assertCount(1, $projected->groups);
        self::assertSame('Family', reset($projected->groups)->name);
    }

    public function testIgnoredCategoriesAreMatchedCaseInsensitively(): void
    {
        $book = $this->book([$this->contact('c1', 'Ada', ['deleted'])]);

        self::assertSame([], CategoryGroups::project($book, ['Deleted'])->groups);
    }

    public function testIgnoringOneCategoryLeavesTheContactsOtherGroups(): void
    {
        $book = $this->book([$this->contact('c1', 'Ada', ['Family', 'Deleted'])]);

        $projected = CategoryGroups::project($book, ['Deleted']);

        self::assertCount(1, $projected->groups);
        self::assertSame(['c1'], reset($projected->groups)->memberUids);
    }

    public function testProjectionIsIdempotent(): void
    {
        $book = $this->book([$this->contact('c1', 'Ada', ['Family'])]);

        $once = CategoryGroups::project($book);
        $twice = CategoryGroups::project($once);

        self::assertSame(
            array_map(static fn(Group $g): string => $g->rawText, $once->groups),
            array_map(static fn(Group $g): string => $g->rawText, $twice->groups),
        );
    }

    public function testCasingVariantsOfOneCategoryBecomeOneGroupWithAllMembers(): void
    {
        // uidFor() is case-insensitive, so these collide on one UID. Keying
        // the aggregation by the raw name instead silently dropped whichever
        // casing lost the race.
        $book = $this->book([
            $this->contact('c1', 'Ada', ['Family']),
            $this->contact('c2', 'Grace', ['family']),
            $this->contact('c3', 'Alan', ['  FAMILY ']),
        ]);

        $projected = CategoryGroups::project($book);

        self::assertCount(1, $projected->groups);
        self::assertSame(['c1', 'c2', 'c3'], reset($projected->groups)->memberUids);
    }

    public function testTheGroupNameIsStableWhateverOrderContactsArriveIn(): void
    {
        // Taking the first-seen casing would make the pushed group's name
        // depend on the order the server returned contacts, rewriting it on
        // the far side for no reason.
        $one = CategoryGroups::project($this->book([
            $this->contact('c1', 'Ada', ['Family']),
            $this->contact('c2', 'Grace', ['family']),
        ]));
        $two = CategoryGroups::project($this->book([
            $this->contact('c2', 'Grace', ['family']),
            $this->contact('c1', 'Ada', ['Family']),
        ]));

        $uid = CategoryGroups::uidFor('Family');
        self::assertSame($one->groups[$uid]->rawText, $two->groups[$uid]->rawText);
    }

    public function testABackslashInACategoryNameSurvivesParsing(): void
    {
        // CATEGORIES is unescaped once by splitUnescaped; unescaping again
        // ate the backslash and the character after it.
        $raw = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:c1\r\nFN:Ada\r\n"
            . "CATEGORIES:Foo\\\\Bar\r\nEND:VCARD\r\n";

        $parsed = Model::parse($raw);

        self::assertSame(['Foo\\Bar'], $parsed->categories);
    }
}
