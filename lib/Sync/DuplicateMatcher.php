<?php

declare(strict_types=1);

namespace OCA\ContactHub\Sync;

use OCA\ContactHub\VCard\AddressBook;
use OCA\ContactHub\VCard\Contact;

/**
 * Identifies destination contacts that look like the same real-world
 * person as a new source contact despite having a different UID:
 * identical first+last name (both must be non-empty) plus at least one
 * shared email address or phone number. Labels/TYPE parameters are
 * ignored entirely -- only the values are compared.
 */
final class DuplicateMatcher
{
    /**
     * @param array<string, true>|string[] $excludedUids destination uids that are
     *        already tracked in contact_state (legitimately paired) -- never
     *        duplicate candidates
     * @return Contact[]
     */
    public static function findMatches(Contact $candidate, AddressBook $destBook, array $excludedUids): array
    {
        $excluded = array_is_list($excludedUids) ? array_flip($excludedUids) : $excludedUids;

        $first = self::normalizeName($candidate->firstName);
        $last = self::normalizeName($candidate->lastName);
        if ($first === '' || $last === '') {
            return [];
        }

        $emails = array_map(self::normalizeEmail(...), $candidate->emails);
        $phones = array_filter(array_map(self::normalizePhone(...), $candidate->phones), static fn(string $p) => $p !== '');
        if ($emails === [] && $phones === []) {
            return [];
        }

        $matches = [];
        foreach ($destBook->contacts as $uid => $other) {
            if ($uid === $candidate->uid || isset($excluded[$uid])) {
                continue;
            }
            if (self::normalizeName($other->firstName) !== $first || self::normalizeName($other->lastName) !== $last) {
                continue;
            }
            $otherEmails = array_map(self::normalizeEmail(...), $other->emails);
            $otherPhones = array_map(self::normalizePhone(...), $other->phones);
            if (array_intersect($emails, $otherEmails) !== [] || array_intersect($phones, $otherPhones) !== []) {
                $matches[] = $other;
            }
        }
        return $matches;
    }

    private static function normalizeName(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    private static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    private static function normalizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }
}
