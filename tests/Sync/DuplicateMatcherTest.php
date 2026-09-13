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

    public function testSharedEmailWithDifferentNameIsNotAMatch(): void
    {
        $candidate = $this->person('n1', 'Jean', 'Muller', ['shared@x.com']);
        $book = new AddressBook(contacts: ['d1' => $this->person('d1', 'Jeanne', 'Muller', ['shared@x.com'])]);

        self::assertSame([], DuplicateMatcher::findMatches($candidate, $book, []));
    }

    public function testEmptyFirstOrLastNameNeverMatches(): void
    {
        $candidate = $this->person('n1', 'Cher', '', ['cher@x.com']);
        $book = new AddressBook(contacts: ['d1' => $this->person('d1', 'Cher', '', ['cher@x.com'])]);

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
}
