<?php

declare(strict_types=1);

namespace OCA\ContactHub\Sync;

use OCA\ContactHub\VCard\AddressBook;
use OCA\ContactHub\VCard\Contact;

/**
 * Identifies destination contacts that look like the same real-world
 * person as a new source contact despite having a different UID. Two
 * independent rules, chosen to match established practice elsewhere in
 * this space (SyncEvolution/Synthesis) rather than anything looser: no
 * single weak signal (name alone, or phone alone) is ever sufficient on
 * its own, because that reliably produces false-positive matches (a
 * shared household phone number, two different "John Smith"s).
 *
 * 1. An exact shared email address is sufficient by itself -- email
 *    collision between two independently-authored contacts is strong
 *    evidence on its own -- **provided the address belongs to one person**.
 *    An address several contacts carry (a household's, a company's
 *    info@) says nothing about which of them a new contact is, and alone
 *    it flagged every new contact at that company or in that family as a
 *    duplicate of the first one synced. That is costly, not cosmetic: a
 *    conflict pauses the job's scheduled runs until someone resolves it.
 *    "Several contacts" is judged within each side separately -- see
 *    sharedEmails().
 * 2. Otherwise, a name match (either structured first+last, both
 *    non-empty, or the plain FN when structured N is empty or a
 *    first/last split isn't available) plus a shared phone number or a
 *    shared email address -- including one of those shared addresses,
 *    since the name then says which of the people behind it is meant.
 *
 * Labels/TYPE parameters are ignored entirely -- only the values are
 * compared.
 */
final class DuplicateMatcher
{
    /**
     * @param array<string, true>|string[] $excludedUids destination uids that are
     *        already tracked in contact_state (legitimately paired) -- never
     *        duplicate candidates
     * @param array<string, true> $sharedEmails normalized addresses that do not
     *        identify one person, from sharedEmails(); never enough alone
     * @return Contact[]
     */
    public static function findMatches(
        Contact $candidate,
        AddressBook $destBook,
        array $excludedUids,
        array $sharedEmails = [],
    ): array {
        $excluded = array_is_list($excludedUids) ? array_flip($excludedUids) : $excludedUids;

        $emails = array_map(Normalize::email(...), $candidate->emails);
        $phones = array_filter(array_map(Normalize::phone(...), $candidate->phones), static fn(string $p) => $p !== '');
        if ($emails === [] && $phones === []) {
            return [];
        }

        $first = Normalize::name($candidate->firstName);
        $last = Normalize::name($candidate->lastName);
        $fn = Normalize::name($candidate->fn);
        $hasStructuredName = $first !== '' && $last !== '';
        $hasFn = $fn !== '';

        $matches = [];
        foreach ($destBook->contacts as $uid => $other) {
            if ($uid === $candidate->uid || isset($excluded[$uid])) {
                continue;
            }

            $otherEmails = array_map(Normalize::email(...), $other->emails);
            $commonEmails = $emails !== [] ? array_intersect($emails, $otherEmails) : [];
            $personalCommon = array_filter($commonEmails, static fn(string $e): bool => !isset($sharedEmails[$e]));
            if ($personalCommon !== []) {
                $matches[] = $other;
                continue;
            }

            if (!$hasStructuredName && !$hasFn) {
                continue;
            }
            // FN is a fallback, not an additional signal: when a structured
            // name is available it is the more specific comparison and
            // decides the match on its own. Falling through to FN whenever
            // it merely also has a value would let a matching-but-generic
            // FN (a shared "Reception"/"Support" label) override a
            // structured name that actually disagrees.
            $nameMatches = $hasStructuredName
                ? (Normalize::name($other->firstName) === $first && Normalize::name($other->lastName) === $last)
                : (Normalize::name($other->fn) === $fn);
            if (!$nameMatches) {
                continue;
            }

            $otherPhones = array_map(Normalize::phone(...), $other->phones);
            if ($commonEmails !== [] || ($phones !== [] && array_intersect($phones, $otherPhones) !== [])) {
                $matches[] = $other;
            }
        }
        return $matches;
    }

    /**
     * Destination cards that are, beyond reasonable doubt, the same person as
     * $candidate *and* have no UID of their own.
     *
     * This is the one place a fuzzy match is trusted without asking. A card
     * with no UID cannot have been synced by anything that keys on UID, so
     * "same name and a shared phone or email" is the only identity evidence
     * there will ever be, and asking a human to confirm hundreds of them is
     * what made such servers unusable. A card that does have a UID keeps
     * going through findMatches() and a conflict, as before.
     *
     * The rule is the second of findMatches()'s two -- a name match plus a
     * shared phone or email -- and never the first, so an address on its own
     * is not enough. Whether a match is *unique* is the caller's business:
     * see Planner::identityMatches().
     *
     * @param array<string, true>|string[] $excludedUids destination uids already tracked
     * @return Contact[]
     */
    public static function findIdentityMatches(Contact $candidate, AddressBook $destBook, array $excludedUids): array
    {
        $excluded = array_is_list($excludedUids) ? array_flip($excludedUids) : $excludedUids;

        $emails = array_map(Normalize::email(...), $candidate->emails);
        $phones = array_filter(array_map(Normalize::phone(...), $candidate->phones), static fn(string $p) => $p !== '');
        if ($emails === [] && $phones === []) {
            return [];
        }

        $matches = [];
        foreach ($destBook->contacts as $uid => $other) {
            if (!$other->uidDerived || isset($excluded[$uid])) {
                continue;
            }
            if (!self::sameName($candidate, $other)) {
                continue;
            }
            $sharedEmail = $emails !== [] && array_intersect($emails, array_map(Normalize::email(...), $other->emails)) !== [];
            $sharedPhone = $phones !== [] && array_intersect($phones, array_map(Normalize::phone(...), $other->phones)) !== [];
            if ($sharedEmail || $sharedPhone) {
                $matches[] = $other;
            }
        }

        return $matches;
    }

    /**
     * Structured first+last when the candidate has both, else plain FN. FN is
     * a fallback and not an extra signal; see findMatches().
     */
    private static function sameName(Contact $candidate, Contact $other): bool
    {
        $first = Normalize::name($candidate->firstName);
        $last = Normalize::name($candidate->lastName);
        if ($first !== '' && $last !== '') {
            return Normalize::name($other->firstName) === $first && Normalize::name($other->lastName) === $last;
        }
        $fn = Normalize::name($candidate->fn);

        return $fn !== '' && Normalize::name($other->fn) === $fn;
    }

    /**
     * Email addresses that do not identify one person: carried by two or
     * more contacts within either book.
     *
     * Counted per book, not across both, because the same person is
     * normally in both -- that is what a synced contact is -- and counting
     * them twice would make every address look shared. Within one book,
     * two contacts with one address are two people sharing it.
     *
     * @return array<string, true> normalized address => true
     */
    public static function sharedEmails(AddressBook ...$books): array
    {
        $shared = [];
        foreach ($books as $book) {
            $seen = [];
            foreach ($book->contacts as $contact) {
                foreach (array_unique(array_map(Normalize::email(...), $contact->emails)) as $email) {
                    if ($email === '') {
                        continue;
                    }
                    if (isset($seen[$email])) {
                        $shared[$email] = true;
                    }
                    $seen[$email] = true;
                }
            }
        }

        return $shared;
    }
}
