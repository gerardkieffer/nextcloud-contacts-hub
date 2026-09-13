<?php

declare(strict_types=1);

namespace OCA\ContactHub\Tests\VCard;

use OCA\ContactHub\VCard\Contact;
use OCA\ContactHub\VCard\Group;
use OCA\ContactHub\VCard\LineCodec;
use OCA\ContactHub\VCard\Model;
use OCA\ContactHub\VCard\VCardParseException;
use PHPUnit\Framework\TestCase;

final class ModelTest extends TestCase
{
    private function contactVCard(): string
    {
        return "BEGIN:VCARD\r\n"
            . "VERSION:3.0\r\n"
            . "N:Doe;John;;;\r\n"
            . "FN:John Doe\r\n"
            . "EMAIL;TYPE=INTERNET:john@example.com\r\n"
            . "PHOTO;VALUE=uri;TYPE=JPEG:https://gateway.icloud.com/contacts/acct/ck/card/abc123\r\n"
            . "REV:2026-01-01T00:00:00Z\r\n"
            . "UID:11111111-1111-1111-1111-111111111111\r\n"
            . "END:VCARD\r\n";
    }

    public function testParsesContactFields(): void
    {
        $item = Model::parse($this->contactVCard());

        self::assertInstanceOf(Contact::class, $item);
        self::assertSame('11111111-1111-1111-1111-111111111111', $item->uid);
        self::assertSame('John Doe', $item->fn);
        self::assertTrue($item->hasPhoto);
        self::assertSame('2026-01-01T00:00:00Z', $item->rev);
    }

    public function testParsesNameEmailsAndPhones(): void
    {
        $item = Model::parse(
            "BEGIN:VCARD\r\n"
            . "VERSION:3.0\r\n"
            . "N:Van der Berg\\, Jr.;Anna-Marie;Middle;Dr.;PhD\r\n"
            . "FN:Anna-Marie Van der Berg\r\n"
            . "EMAIL;TYPE=HOME:anna@home.example\r\n"
            . "EMAIL;TYPE=WORK:anna@work.example\r\n"
            . "TEL;TYPE=CELL:+352 621 111 222\r\n"
            . "UID:u-1\r\n"
            . "END:VCARD\r\n"
        );

        self::assertInstanceOf(Contact::class, $item);
        self::assertSame('Anna-Marie', $item->firstName);
        self::assertSame('Van der Berg, Jr.', $item->lastName);
        self::assertSame(['anna@home.example', 'anna@work.example'], $item->emails);
        self::assertSame(['+352 621 111 222'], $item->phones);
    }

    public function testMissingNameEmailAndPhoneParseToEmptyValues(): void
    {
        $item = Model::parse("BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Solo\r\nUID:u-2\r\nEND:VCARD\r\n");

        self::assertInstanceOf(Contact::class, $item);
        self::assertSame('', $item->firstName);
        self::assertSame('', $item->lastName);
        self::assertSame([], $item->emails);
        self::assertSame([], $item->phones);
    }

    public function testMissingUidThrows(): void
    {
        $this->expectException(VCardParseException::class);
        Model::parse("BEGIN:VCARD\r\nVERSION:3.0\r\nFN:No Uid\r\nEND:VCARD\r\n");
    }

    public function testParsesGroupAndStripsMemberPrefix(): void
    {
        $group = "BEGIN:VCARD\r\n"
            . "VERSION:3.0\r\n"
            . "FN:Family\r\n"
            . "X-ADDRESSBOOKSERVER-KIND:group\r\n"
            . "X-ADDRESSBOOKSERVER-MEMBER:urn:uuid:11111111-1111-1111-1111-111111111111\r\n"
            . "X-ADDRESSBOOKSERVER-MEMBER:urn:uuid:22222222-2222-2222-2222-222222222222\r\n"
            . "UID:group-uid-1\r\n"
            . "END:VCARD\r\n";

        $item = Model::parse($group);

        self::assertInstanceOf(Group::class, $item);
        self::assertSame('Family', $item->name);
        self::assertSame(
            ['11111111-1111-1111-1111-111111111111', '22222222-2222-2222-2222-222222222222'],
            $item->memberUids,
        );
    }

    public function testBuildAddressBookWarnsOnMissingGroupMember(): void
    {
        $group = "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Family\r\nX-ADDRESSBOOKSERVER-KIND:group\r\n"
            . "X-ADDRESSBOOKSERVER-MEMBER:urn:uuid:missing-uid\r\nUID:g1\r\nEND:VCARD\r\n";

        [$book, $warnings] = Model::buildAddressBook([$group]);

        self::assertCount(1, $book->groups);
        self::assertCount(0, $book->contacts);
        self::assertNotEmpty($warnings);
        self::assertStringContainsString('missing-uid', $warnings[0]);
    }

    public function testDanglingMembersAreOneWarningPerGroupNotPerMember(): void
    {
        // Reported from a live instance: roughly five hundred log lines per
        // sync, one for every contact in every group, several times an hour.
        // The observation is worth keeping -- it is about an address book's
        // own consistency -- but it is per group, and a group that outlived
        // some of its members is an ordinary thing to find in a real one.
        $members = '';
        foreach (range(1, 40) as $n) {
            $members .= "X-ADDRESSBOOKSERVER-MEMBER:urn:uuid:gone-{$n}\r\n";
        }
        $group = "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Future skills\r\n"
            . "X-ADDRESSBOOKSERVER-KIND:group\r\n{$members}UID:g1\r\nEND:VCARD\r\n";

        [, $warnings] = Model::buildAddressBook([$group]);

        self::assertCount(1, $warnings);
        self::assertStringContainsString('40 members', $warnings[0]);
        self::assertStringContainsString('gone-1', $warnings[0]);
        self::assertStringContainsString('and 37 more', $warnings[0]);
    }

    public function testTheWarningSaysWhichAddressBookToLookIn(): void
    {
        // Both sides of a job can hold groups. Without the label the message
        // named a UID and left no way to tell whether to go looking in
        // Nextcloud or at the endpoint, which is what made the live report
        // impossible to diagnose from the log alone.
        $group = "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Family\r\n"
            . "X-ADDRESSBOOKSERVER-KIND:group\r\n"
            . "X-ADDRESSBOOKSERVER-MEMBER:urn:uuid:gone\r\nUID:g1\r\nEND:VCARD\r\n";

        [, $warnings] = Model::buildAddressBook([$group], 'Infomaniak');

        self::assertStringContainsString('in Infomaniak', $warnings[0]);
        self::assertStringContainsString('a member that is not', $warnings[0], 'singular reads as singular');
    }

    public function testTheWarningSaysHowMuchOfTheBookWasRead(): void
    {
        // "Not in that address book" and "not in the part of it we managed
        // to read" are different findings, and the message could not tell
        // them apart. That ambiguity cost a round trip with a user, so the
        // numbers are stated and the warning answers it on its own.
        $group = "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Family\r\n"
            . "X-ADDRESSBOOKSERVER-KIND:group\r\n"
            . "X-ADDRESSBOOKSERVER-MEMBER:urn:uuid:gone\r\nUID:g1\r\nEND:VCARD\r\n";

        [, $complete] = Model::buildAddressBook([$group], 'Infomaniak', 1);
        self::assertStringContainsString('(1 of 1 entries read)', $complete[0]);

        [, $short] = Model::buildAddressBook([$group], 'Infomaniak', 742);
        self::assertStringContainsString('(1 of 742 entries read)', $short[0]);

        [, $unknown] = Model::buildAddressBook([$group], 'Infomaniak');
        self::assertStringNotContainsString('entries read', $unknown[0], 'say nothing rather than guess');
    }

    public function testAGroupWhoseMembersAllExistWarnsAboutNothing(): void
    {
        $group = "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Family\r\n"
            . "X-ADDRESSBOOKSERVER-KIND:group\r\n"
            . "X-ADDRESSBOOKSERVER-MEMBER:urn:uuid:11111111-1111-1111-1111-111111111111\r\n"
            . "UID:g1\r\nEND:VCARD\r\n";

        [, $warnings] = Model::buildAddressBook([$this->contactVCard(), $group], 'Nextcloud');

        self::assertSame([], $warnings);
    }

    public function testBuildAddressBookCollectsWarningButSkipsUnparseableEntries(): void
    {
        [$book, $warnings] = Model::buildAddressBook([
            $this->contactVCard(),
            "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:No Uid\r\nEND:VCARD\r\n",
        ]);

        self::assertCount(1, $book->contacts);
        self::assertNotEmpty($warnings);
    }

    public function testContentHashIsStableForIdenticalRawText(): void
    {
        $a = Model::parse($this->contactVCard());
        $b = Model::parse($this->contactVCard());

        self::assertSame(Model::contactContentHash($a), Model::contactContentHash($b));
    }

    public function testGroupContentHashIgnoresMemberOrder(): void
    {
        $g1 = new Group('g', 'Name', ['a', 'b'], '');
        $g2 = new Group('g', 'Name', ['b', 'a'], '');

        self::assertSame(Model::groupContentHash($g1), Model::groupContentHash($g2));
    }

    public function testGroupContentHashChangesWithMembership(): void
    {
        $g1 = new Group('g', 'Name', ['a'], '');
        $g2 = new Group('g', 'Name', ['a', 'b'], '');

        self::assertNotSame(Model::groupContentHash($g1), Model::groupContentHash($g2));
    }

    /** @param string[] $categoryLines */
    private function contactWithCategories(array $categoryLines): Contact
    {
        $lines = ['BEGIN:VCARD', 'VERSION:3.0', 'UID:c1', 'FN:Ada'];
        foreach ($categoryLines as $line) {
            $lines[] = 'CATEGORIES:' . $line;
        }
        $lines[] = 'END:VCARD';

        $parsed = Model::parse(implode("\r\n", $lines) . "\r\n");
        self::assertInstanceOf(Contact::class, $parsed);

        return $parsed;
    }

    public function testCategoriesAreSplitOnCommas(): void
    {
        self::assertSame(['Family', 'Work'], $this->contactWithCategories(['Family,Work'])->categories);
    }

    public function testAContactWithNoCategoriesHasNone(): void
    {
        self::assertSame([], $this->contactWithCategories([])->categories);
    }

    public function testAnEscapedCommaDoesNotSplitACategory(): void
    {
        // "Smith\, Ada" is one category name, not two. A plain explode()
        // here would silently invent a group called " Ada".
        self::assertSame(['Smith, Ada'], $this->contactWithCategories(['Smith\\, Ada'])->categories);
    }

    public function testMultipleCategoriesLinesAreMerged(): void
    {
        self::assertSame(
            ['Family', 'Work', 'Choir'],
            $this->contactWithCategories(['Family,Work', 'Choir'])->categories,
        );
    }

    public function testDuplicateCategoriesCollapse(): void
    {
        // Two lines naming the same group still mean one membership.
        self::assertSame(['Family'], $this->contactWithCategories(['Family', 'Family'])->categories);
    }

    public function testEmptyCategoryEntriesAreDropped(): void
    {
        self::assertSame(['Family'], $this->contactWithCategories(['Family,,  ,'])->categories);
    }

    public function testCategoriesSurviveFoldingOfALongLine(): void
    {
        // A long CATEGORIES line gets RFC-folded in transit; the parser must
        // unfold before splitting or a name is cut in half at the boundary.
        $names = [];
        for ($i = 0; $i < 30; $i++) {
            $names[] = "Category Number {$i}";
        }
        $folded = LineCodec::fold('CATEGORIES:' . implode(',', $names));

        $raw = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:c1\r\nFN:Ada\r\n{$folded}\r\nEND:VCARD\r\n";
        $parsed = Model::parse($raw);

        self::assertInstanceOf(Contact::class, $parsed);
        self::assertSame($names, $parsed->categories);
    }
}
