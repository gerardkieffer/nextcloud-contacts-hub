<?php

declare(strict_types=1);

namespace OCA\ContactHub\Sync;

use OCA\ContactHub\VCard\Contact;
use OCA\ContactHub\VCard\Model;
use OCA\ContactHub\VCard\Transform;

/**
 * "Tag a contact as archived" primitives for a side's configured group
 * strategy, used when a contact is deleted with deletion_policy=archive
 * (Runner::archiveContact, same uid/href -- the contact keeps living
 * where it was). This class does not decide state bookkeeping -- that
 * stays the caller's own responsibility.
 */
final class Archiver
{
    /**
     * Tag $vcardText as archived per $side's group strategy and write it
     * to $href. For 'categories' sides this adds the archive category to
     * the vCard's own; for any other strategy the vCard is written
     * unchanged (archival there is expressed via group membership -- see
     * addToArchiveGroup). Returns the new ETag and the exact text
     * stored, so callers can record a hash of what is now actually
     * there.
     *
     * *Adds*, not replaces. It used to replace CATEGORIES with the archive
     * category alone, so a contact archived into Nextcloud silently left
     * every group it was in -- the opposite of what "keep it, tagged as
     * archived" says. On a group-vCard side archiving never touched the
     * contact's groups either, so this also makes the two strategies agree.
     *
     * @return array{0: ?string, 1: string} new ETag, stored vCard text
     */
    public static function writeArchiveTaggedContact(
        SyncSide $side,
        string $archiveGroupName,
        string $href,
        string $vcardText,
        ?string $existingEtag,
    ): array {
        $text = $side->groupStrategy() === 'categories'
            ? self::withCategoryAdded($vcardText, $archiveGroupName)
            : $vcardText;

        return $side->putVCard($href, $text, $existingEtag);
    }

    /** $vcardText with $category among its CATEGORIES, compared case-insensitively. */
    private static function withCategoryAdded(string $vcardText, string $category): string
    {
        $parsed = Model::parse($vcardText);
        $existing = $parsed instanceof Contact ? $parsed->categories : [];
        foreach ($existing as $name) {
            if (Normalize::name($name) === Normalize::name($category)) {
                return $vcardText;
            }
        }

        return Transform::setCategories($vcardText, [...$existing, $category]);
    }

    /**
     * Add $uid as a member of the side's archive group vCard, creating
     * it on first use. No-op to call for 'categories' sides (they never
     * get a discrete archive group -- use writeArchiveTaggedContact
     * instead).
     *
     * This is a read-modify-write, and the ETag it guards the write with
     * is deliberately the one that came back from *its own* GET a line
     * earlier. Nothing else may be substituted for it, which is not
     * fussiness: this used to accept the caller's ETag and prefer it, and
     * the caller's best source is the per-run ETag snapshot taken at fetch
     * time. Archiving two contacts in one run then sent the second PUT an
     * ETag the first PUT had already invalidated, and any server actually
     * enforcing If-Match (sabre, so Infomaniak and iCloud both) answered
     * 412 -- with a body built from a perfectly fresh GET, which is what
     * made it look like a server problem rather than ours. Reported from a
     * live account after one contact archived and the next failed.
     *
     * Guarding with the freshly read ETag is not weaker. The window If-Match
     * has to close is between this GET and this PUT; an older ETag closes
     * the same window and adds false failures on top.
     *
     * @return array{0: string, 1: ?string} groupHref, new group ETag
     */
    public static function addToArchiveGroup(
        SyncSide $side,
        string $archiveGroupUid,
        string $archiveGroupName,
        string $uid,
        ?string $existingGroupHref,
    ): array {
        $groupHref = $existingGroupHref ?? $side->hrefFor($archiveGroupUid);

        if ($existingGroupHref === null) {
            $groupText = Transform::buildGroupVCard($archiveGroupUid, $archiveGroupName, [$uid]);
            $sendEtag = null;
        } else {
            [$currentText, $sendEtag] = $side->getVCard($groupHref);
            $groupText = Transform::withMemberAdded($currentText, $uid);
        }

        [$newEtag] = $side->putVCard($groupHref, $groupText, $sendEtag);
        return [$groupHref, $newEtag];
    }
}
