<?php

declare(strict_types=1);

namespace OCA\ContactHub\Tests\Sync;

use OCA\ContactHub\Sync\DuplicateMatcher;
use OCA\ContactHub\VCard\AddressBook;
use OCA\ContactHub\VCard\Contact;
use PHPUnit\Framework\TestCase;

final class DuplicateMatcherTest extends TestCase
{
    /** @param string[] $emails @param string[] $phones */
    private function person(string $uid, string $first, string $last, array $emails = [], array $phones = []): Contact
    {
        return new Contact($uid, "{$first} {$last}", '', false, null, $first, $last, $emails, $phones);
    }

    /**
     * A contact with no structured N at all (company contacts, single-field
     * names) -- only FN is populated.
     *
     * @param string[] $emails @param string[] $phones
     */
    private function fnOnlyPerson(string $uid, string $fn, array $emails = [], array $phones = []): Contact
    {
        return new Contact($uid, $fn, '', false, null, '', '', $emails, $phones);
    }

    /** A contact with both a structured name and an independently-set FN. @param string[] $phones */
    private function personWithFn(string $uid, string $first, string $last, string $fn, array $phones = []): Contact
    {
        return new Contact($uid, $fn, '', false, null, $first, $last, [], $phones);
    }

    public function testMatchesOnNameAndSharedEmailIgnoringCase(): void
    {
        $candidate = $this->person('n1', 'Alice', 'Martin', ['Alice@Example.COM ']);
        $book = new AddressBook(contacts: ['d1' => $this->person('d1', ' alice ', 'MARTIN', ['alice@example.com'])]);

        $matches = DuplicateMatcher::findMatches($candidate, $book, []);

        self::assertCount(1, $matches);
        self::assertSame('d1', $matches[0]->uid);
    }

    public function testMatchesOnNameAndSharedPhoneIgnoringFormatting(): void
    {
        $candidate = $this->person('n1', 'Bob', 'Weber', [], ['+352 621-123 456']);
        $book = new AddressBook(contacts: ['d1' => $this->person('d1', 'Bob', 'Weber', [], ['(352)621123456'])]);

        self::assertCount(1, DuplicateMatcher::findMatches($candidate, $book, []));
    }

    public function testNameMatchAloneIsNotEnough(): void
    {
        $candidate = $this->person('n1', 'Jean', 'Muller', ['a@x.com'], ['111']);
        $book = new AddressBook(contacts: ['d1' => $this->person('d1', 'Jean', 'Muller', ['b@x.com'], ['222'])]);

        self::assertSame([], DuplicateMatcher::findMatches($candidate, $book, []));
    }

    public function testSharedEmailAloneIsAMatchEvenWithDifferentNames(): void
    {
        // An exact email collision between two independently-authored
        // contacts is strong evidence on its own -- no name agreement
        // required. This mirrors SyncEvolution's "main value" matching for
        // email specifically.
        $candidate = $this->person('n1', 'Jean', 'Muller', ['shared@x.com']);
        $book = new AddressBook(contacts: ['d1' => $this->person('d1', 'Jeanne', 'Muller', ['shared@x.com'])]);

        $matches = DuplicateMatcher::findMatches($candidate, $book, []);

        self::assertCount(1, $matches);
        self::assertSame('d1', $matches[0]->uid);
    }

    public function testPhoneAloneWithoutAnyNameSignalNeverMatches(): void
    {
        // No structured name, no FN, only a shared phone: the phone-based
        // rule requires a name signal to confirm it, and there isn't one
        // here -- a bare shared phone number is not sufficient (households/
        // offices commonly share one).
        $candidate = $this->fnOnlyPerson('n1', '', [], ['+352621123456']);
        $book = new AddressBook(contacts: ['d1' => $this->fnOnlyPerson('d1', '', [], ['621123456'])]);

        self::assertSame([], DuplicateMatcher::findMatches($candidate, $book, []));
    }

    public function testEmptyStructuredNameStillMatchesOnSharedEmail(): void
    {
        // A contact with no last name (e.g. a single-field name) is no
        // longer excluded outright -- the email-alone rule still applies.
        $candidate = $this->person('n1', 'Cher', '', ['cher@x.com']);
        $book = new AddressBook(contacts: ['d1' => $this->person('d1', 'Cher', '', ['cher@x.com'])]);

        $matches = DuplicateMatcher::findMatches($candidate, $book, []);

        self::assertCount(1, $matches);
        self::assertSame('d1', $matches[0]->uid);
    }

    public function testFnOnlyNameMatchesWithSharedPhone(): void
    {
        // Company/single-field contacts often carry no structured N at all.
        // A match on the plain FN, plus a shared phone, should still be
        // caught -- this is the gap the old first+last-only rule missed.
        $candidate = $this->fnOnlyPerson('n1', 'Acme Corp', [], ['+352 27 12 34']);
        $book = new AddressBook(contacts: ['d1' => $this->fnOnlyPerson('d1', 'acme corp', [], ['(352) 27-12-34'])]);

        $matches = DuplicateMatcher::findMatches($candidate, $book, []);

        self::assertCount(1, $matches);
        self::assertSame('d1', $matches[0]->uid);
    }

    public function testFnMismatchWithSharedPhoneIsNotAMatch(): void
    {
        $candidate = $this->fnOnlyPerson('n1', 'Acme Corp', [], ['271234']);
        $book = new AddressBook(contacts: ['d1' => $this->fnOnlyPerson('d1', 'Other Company', [], ['271234'])]);

        self::assertSame([], DuplicateMatcher::findMatches($candidate, $book, []));
    }

    public function testMatchingFnNeverOverridesADisagreeingStructuredName(): void
    {
        // Both contacts have a real structured name, and it disagrees --
        // that has to decide the match on its own. A shared FN label (e.g.
        // a generic "Reception"/"Support" contact-form name) plus a shared
        // phone must not sneak a match past the structured-name mismatch.
        $candidate = $this->personWithFn('n1', 'Front', 'Desk', 'Reception', ['+352 27 12 34']);
        $book = new AddressBook(contacts: [
            'd1' => $this->personWithFn('d1', 'Jane', 'Doe', 'Reception', ['(352) 27-12-34']),
        ]);

        self::assertSame([], DuplicateMatcher::findMatches($candidate, $book, []));
    }

    public function testExcludedUidsAndSelfAreSkipped(): void
    {
        $candidate = $this->person('n1', 'Alice', 'Martin', ['alice@x.com']);
        $book = new AddressBook(contacts: [
            'n1' => $this->person('n1', 'Alice', 'Martin', ['alice@x.com']),
            'tracked' => $this->person('tracked', 'Alice', 'Martin', ['alice@x.com']),
            'free' => $this->person('free', 'Alice', 'Martin', ['alice@x.com']),
        ]);

        $matches = DuplicateMatcher::findMatches($candidate, $book, ['tracked']);

        self::assertCount(1, $matches);
        self::assertSame('free', $matches[0]->uid);
    }

    public function testReturnsAllMatchesWhenSeveralQualify(): void
    {
        $candidate = $this->person('n1', 'Alice', 'Martin', ['alice@x.com']);
        $book = new AddressBook(contacts: [
            'd1' => $this->person('d1', 'Alice', 'Martin', ['alice@x.com']),
            'd2' => $this->person('d2', 'Alice', 'Martin', [], []),
            'd3' => $this->person('d3', 'Alice', 'Martin', ['other@x.com', 'alice@x.com']),
        ]);

        $matches = DuplicateMatcher::findMatches($candidate, $book, []);

        self::assertSame(['d1', 'd3'], array_map(static fn(Contact $c) => $c->uid, $matches));
    }

    public function testAnAddressSeveralContactsShareIsNotEnoughOnItsOwn(): void
    {
        // A company's info@ on two existing contacts: a third person at that
        // company is not a duplicate of whichever one the matcher met first.
        $candidate = $this->person('n1', 'Carla', 'Dupont', ['info@acme.example']);
        $book = new AddressBook(contacts: [
            'd1' => $this->person('d1', 'Anne', 'Martin', ['info@acme.example']),
            'd2' => $this->person('d2', 'Paul', 'Weber', ['info@acme.example']),
        ]);
        $shared = DuplicateMatcher::sharedEmails(new AddressBook(contacts: ['n1' => $candidate]), $book);

        self::assertSame(['info@acme.example' => true], $shared);
        self::assertSame([], DuplicateMatcher::findMatches($candidate, $book, [], $shared));
    }

    public function testAHouseholdAddressOnTheSourceIsNotEnoughOnItsOwn(): void
    {
        // Both partners are on the source; only one was ever on the
        // destination. The other arriving is not that one.
        $jane = $this->person('n1', 'Jane', 'Doe', ['family@doe.example']);
        $john = $this->person('n2', 'John', 'Doe', ['family@doe.example']);
        $source = new AddressBook(contacts: ['n1' => $jane, 'n2' => $john]);
        $dest = new AddressBook(contacts: ['d1' => $this->person('d1', 'John', 'Doe', ['family@doe.example'])]);

        $shared = DuplicateMatcher::sharedEmails($source, $dest);

        self::assertSame([], DuplicateMatcher::findMatches($jane, $dest, [], $shared));
    }

    public function testASharedAddressStillCountsWhenTheNameAgrees(): void
    {
        $candidate = $this->person('n1', 'Anne', 'Martin', ['info@acme.example']);
        $book = new AddressBook(contacts: [
            'd1' => $this->person('d1', 'Anne', 'Martin', ['info@acme.example']),
            'd2' => $this->person('d2', 'Paul', 'Weber', ['info@acme.example']),
        ]);
        $shared = DuplicateMatcher::sharedEmails($book);

        $matches = DuplicateMatcher::findMatches($candidate, $book, [], $shared);

        self::assertCount(1, $matches);
        self::assertSame('d1', $matches[0]->uid);
    }

    public function testTheSamePersonInBothBooksDoesNotMakeTheirAddressShared(): void
    {
        $alice = $this->person('a', 'Alice', 'Martin', ['alice@x.example']);

        self::assertSame([], DuplicateMatcher::sharedEmails(
            new AddressBook(contacts: ['a' => $alice]),
            new AddressBook(contacts: ['a' => $alice]),
        ));
    }

    /** A destination card the server keeps without a UID. @param string[] $emails @param string[] $phones */
    private function uidless(string $uid, string $first, string $last, array $emails = [], array $phones = []): Contact
    {
        return new Contact($uid, "{$first} {$last}", '', false, null, $first, $last, $emails, $phones, [], true);
    }

    public function testAUidlessCardWithTheSameNameAndASharedEmailIsAnIdentityMatch(): void
    {
        $candidate = $this->person('n1', 'Alice', 'Martin', ['alice@example.com']);
        $book = new AddressBook(contacts: ['d1' => $this->uidless('d1', 'ALICE', ' martin', ['Alice@Example.com'])]);

        self::assertSame(['d1'], array_map(static fn(Contact $c): string => $c->uid, DuplicateMatcher::findIdentityMatches($candidate, $book, [])));
    }

    public function testAUidlessCardWithTheSameNameAndASharedPhoneIsAnIdentityMatch(): void
    {
        $candidate = $this->person('n1', 'Bob', 'Weber', [], ['+352 621-123 456']);
        $book = new AddressBook(contacts: ['d1' => $this->uidless('d1', 'Bob', 'Weber', [], ['(352)621123456'])]);

        self::assertCount(1, DuplicateMatcher::findIdentityMatches($candidate, $book, []));
    }

    public function testAnIdentityMatchNeedsMoreThanASharedEmail(): void
    {
        // Rule one of findMatches() -- an address alone -- is deliberately not
        // enough to overwrite a card unasked.
        $candidate = $this->person('n1', 'Alice', 'Martin', ['shared@example.com']);
        $book = new AddressBook(contacts: ['d1' => $this->uidless('d1', 'Someone', 'Else', ['shared@example.com'])]);

        self::assertSame([], DuplicateMatcher::findIdentityMatches($candidate, $book, []));
        self::assertCount(1, DuplicateMatcher::findMatches($candidate, $book, []), 'it is still an ordinary duplicate');
    }

    public function testAnIdentityMatchNeedsMoreThanTheName(): void
    {
        $candidate = $this->person('n1', 'Jean', 'Muller', ['a@x.com'], ['111']);
        $book = new AddressBook(contacts: ['d1' => $this->uidless('d1', 'Jean', 'Muller', ['b@x.com'], ['222'])]);

        self::assertSame([], DuplicateMatcher::findIdentityMatches($candidate, $book, []));
    }

    public function testACardThatHasItsOwnUidIsNeverAnIdentityMatch(): void
    {
        $candidate = $this->person('n1', 'Alice', 'Martin', ['alice@example.com']);
        $book = new AddressBook(contacts: ['d1' => $this->person('d1', 'Alice', 'Martin', ['alice@example.com'])]);

        self::assertSame([], DuplicateMatcher::findIdentityMatches($candidate, $book, []), 'a card with a UID goes through a conflict, as before');
    }

    public function testATrackedUidlessCardIsNotACandidate(): void
    {
        $candidate = $this->person('n1', 'Alice', 'Martin', ['alice@example.com']);
        $book = new AddressBook(contacts: ['d1' => $this->uidless('d1', 'Alice', 'Martin', ['alice@example.com'])]);

        self::assertSame([], DuplicateMatcher::findIdentityMatches($candidate, $book, ['d1']));
    }
}
