<?php

declare(strict_types=1);

namespace OCA\ContactHub\Sync;

use OCA\ContactHub\VCard\AddressBook;
use OCA\ContactHub\VCard\Group;
use OCA\ContactHub\VCard\Transform;

/**
 * Projects a categories-strategy side's CATEGORIES into real Group objects,
 * so the Planner can diff group membership in both directions.
 *
 * # Why this exists
 *
 * Two ways of expressing "this contact is in that group" are in play:
 *
 *   passthrough  discrete KIND:group vCards with X-ADDRESSBOOKSERVER-MEMBER
 *                lines (iCloud, Infomaniak)
 *   categories   a CATEGORIES property on each contact (Nextcloud's Contacts
 *                app, and endpoints configured that way)
 *
 * The engine has always handled passthrough -> categories: Runner renders
 * CATEGORIES onto each pushed contact and skips pushing group resources.
 * The reverse never existed. A categories side produced zero Group objects,
 * so Planner::planGroups() had nothing to diff and membership was silently
 * dropped on the way out -- not even warned about, unlike the
 * 'collections' case.
 *
 * That gap did not matter while the hub was this app's own store, which was
 * hard-coded passthrough. It matters now: Nextcloud is the hub and it is
 * categories, so without this class groups would flow *into* Nextcloud and
 * never back out to iCloud.
 *
 * # Deterministic UIDs
 *
 * A synthesised group needs a UID that is stable across runs, because it is
 * what gets written to the passthrough side and recorded in group_state. It
 * is derived from the category name via UUIDv5, so the same name always
 * yields the same UID on every run and every machine, with no extra table to
 * remember the mapping.
 *
 * The consequence, which is a real behavioural difference and not a bug:
 * renaming a category changes its derived UID, so the passthrough side sees
 * a delete plus a create rather than a rename. Group membership survives;
 * the group's identity on the far side does not.
 */
final class CategoryGroups
{
    /**
     * Fixed namespace for this app's derived group UIDs. Any constant UUID
     * works; what matters is that it never changes, or every synthesised
     * group would be recreated on the far side.
     */
    private const string NAMESPACE_UUID = '6ba7b812-9dad-11d1-80b4-00c04fd430c8';

    private const string NAME_PREFIX = 'contacthub:category:';

    /**
     * Return a copy of $book whose groups are derived from its contacts'
     * CATEGORIES.
     *
     * Any groups the book already carries are kept and win on a UID clash: a
     * side can hold real group vCards even when its own UI is
     * categories-based (Nextcloud stores whatever iOS pushes), and a real
     * group is more authoritative than a derived one.
     *
     * $ignoreNames are category names that must not become groups. There is
     * one caller and one reason: a categories-strategy side expresses "this
     * contact was deleted elsewhere and archived here" by folding the job's
     * archive category into the contact. That marker is this app's own
     * bookkeeping, not a group anybody asked to sync. Projecting it would
     * manufacture a group named "Deleted" on one side only, which the
     * Planner would dutifully propagate back to the side the contact was
     * just deleted from.
     *
     * The passthrough side has never had this problem because its archive
     * group carries a __archive_ prefixed UID that Planner::planGroups()
     * already skips. A derived UID cannot use that convention, so the
     * exclusion happens here instead.
     *
     * @param string[] $ignoreNames matched case-insensitively
     */
    public static function project(AddressBook $book, array $ignoreNames = []): AddressBook
    {
        $ignored = [];
        foreach ($ignoreNames as $name) {
            $ignored[mb_strtolower(trim($name))] = true;
        }

        // Grouped by the *derived* UID, not by the raw category text.
        // uidFor() is case- and whitespace-insensitive, so keying by the raw
        // name splits "Family" and "family" into two buckets that then
        // collide on one UID -- and whichever lost the race was silently
        // dropped from the group pushed to the far side.
        $members = [];
        foreach ($book->contacts as $contact) {
            foreach ($contact->categories as $name) {
                $name = trim($name);
                if ($name === '' || isset($ignored[mb_strtolower($name)])) {
                    continue;
                }

                $uid = self::uidFor($name);
                $members[$uid]['names'][] = $name;
                $members[$uid]['uids'][] = $contact->uid;
            }
        }

        if ($members === []) {
            return $book;
        }

        $projected = new AddressBook($book->contacts, $book->groups);

        // Sort by UID so the set of groups is built in a fixed order
        // regardless of the order contacts came back from the server.
        ksort($members);

        foreach ($members as $uid => $bucket) {
            // A real group vCard already in the book wins: a categories-based
            // side can still hold one (iOS pushes them to Nextcloud), and a
            // real group is more authoritative than a derived one.
            if (isset($projected->groups[$uid])) {
                continue;
            }

            // Casing variants have to resolve deterministically too. Taking
            // whichever was seen first would make the group's *name* depend
            // on iteration order, so the far side would be rewritten
            // whenever the server returned contacts in a different order.
            $names = array_values(array_unique($bucket['names']));
            sort($names);
            $name = $names[0];

            $uids = array_values(array_unique($bucket['uids']));
            sort($uids);

            $projected->groups[$uid] = new Group(
                $uid,
                $name,
                $uids,
                Transform::buildGroupVCard($uid, $name, $uids),
            );
        }

        return $projected;
    }

    /**
     * Stable UID for a category name.
     *
     * Case- and whitespace-insensitive, so "Family" and "family " are one
     * group rather than two. The display name keeps its original casing;
     * only the identity is normalised.
     */
    public static function uidFor(string $categoryName): string
    {
        return self::uuidV5(self::NAMESPACE_UUID, self::NAME_PREFIX . mb_strtolower(trim($categoryName)));
    }

    /** RFC 4122 section 4.3: SHA-1 of namespace bytes plus name, version 5. */
    private static function uuidV5(string $namespace, string $name): string
    {
        $hex = str_replace('-', '', $namespace);
        $bytes = hex2bin($hex);
        if ($bytes === false) {
            throw new \LogicException("Invalid namespace UUID: {$namespace}");
        }

        $hash = sha1($bytes . $name);

        return sprintf(
            '%08s-%04s-%04x-%04x-%12s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            // Version 5: keep the low 12 bits, force the version nibble.
            (hexdec(substr($hash, 12, 4)) & 0x0fff) | 0x5000,
            // Variant RFC 4122: clear the top two bits, set bit 7.
            (hexdec(substr($hash, 16, 4)) & 0x3fff) | 0x8000,
            substr($hash, 20, 12),
        );
    }
}
