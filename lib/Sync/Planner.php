<?php

declare(strict_types=1);

namespace OCA\ContactHub\Sync;

use OCA\ContactHub\VCard\AddressBook;
use OCA\ContactHub\VCard\Group;
use OCA\ContactHub\VCard\Model;

/**
 * Computes what a sync run would do, without touching either endpoint.
 *
 * Every job is one-way, A -> B. Side B is still fetched and parsed in
 * full -- not just listed -- because duplicate detection needs its
 * N/EMAIL/TEL, an accepted cost over a cheap href/etag-only destination
 * listing. B's own uids never enter the diff; presence on B is checked
 * separately via the live href/etag listing ($liveBHrefs), which is what
 * lets an out-of-band deletion on B be noticed without B's uids being
 * part of this loop.
 */
final class Planner
{
    /**
     * Synthetic group uids created by the Runner's archive-on-deletion
     * path (see Runner::archiveContact) are managed exclusively by that
     * write path -- they're excluded here so the general group diff
     * never tries to propagate a side's private "Deleted" bookkeeping
     * group to the other side.
     */
    public const string ARCHIVE_GROUP_PREFIX = '__archive_';

    public function plan(PlanInput $in): Plan
    {
        $plan = new Plan();
        $this->planContacts($in, $plan);
        $this->planGroups($in, $plan);
        if ($in->bGroupsAsCategories) {
            $this->planCategoryMembership($in, $plan);
        }
        return $plan;
    }

    /**
     * Which new contacts on A are, unambiguously, cards B already holds
     * without a UID. Source uid => B uid.
     *
     * Unambiguous means one to one: a contact matching two such cards, or two
     * contacts matching one, are left alone here and fall through to the
     * ordinary duplicate conflict. Guessing which of two namesakes sharing a
     * household phone is meant, on a card that is then overwritten, is the one
     * thing this must never do.
     *
     * @return array<string, string>
     */
    private static function identityMatches(PlanInput $in): array
    {
        $candidates = [];
        $claims = [];
        foreach ($in->bookA->contacts as $uid => $contactA) {
            // Tracked, or already on B under its own uid: not a new contact.
            if (isset($in->contactStates[$uid]) || isset($in->bookB->contacts[$uid])) {
                continue;
            }
            $found = DuplicateMatcher::findIdentityMatches($contactA, $in->bookB, $in->contactStates);
            $candidates[$uid] = array_map(static fn($c): string => $c->uid, $found);
            foreach ($candidates[$uid] as $bUid) {
                $claims[$bUid] = ($claims[$bUid] ?? 0) + 1;
            }
        }

        $unique = [];
        foreach ($candidates as $uid => $bUids) {
            if (count($bUids) === 1 && $claims[$bUids[0]] === 1) {
                $unique[$uid] = $bUids[0];
            }
        }

        return $unique;
    }

    private function planContacts(PlanInput $in, Plan $plan): void
    {
        $uids = array_unique(array_merge(array_keys($in->bookA->contacts), array_keys($in->contactStates)));
        $sharedEmails = DuplicateMatcher::sharedEmails($in->bookA, $in->bookB);
        $identity = self::identityMatches($in);

        foreach ($uids as $uid) {
            $contactA = $in->bookA->contacts[$uid] ?? null;
            $state = $in->contactStates[$uid] ?? null;

            if ($state === null) {
                if ($contactA !== null && isset($identity[$uid])) {
                    // An existing, UID-less card on B that is this contact.
                    // Planned as a create so that everything downstream --
                    // seeding state, the in-place update -- is the one path a
                    // same-UID copy already takes.
                    $plan->contactsCreateAToB[] = $uid;
                    $plan->contactsAdoptAToB[$uid] = $identity[$uid];
                } elseif ($contactA !== null && isset($in->bookB->contacts[$uid])) {
                    // B already holds this very UID: the same contact, whatever
                    // else on B shares its name or address. Namesakes are what
                    // a duplicate conflict is for; this one is not a candidate
                    // for it, and flagging it kept every contact a server
                    // already shared by UID waiting on a person. Runner turns
                    // the create into an in-place update (adoptExistingOnB).
                    $plan->contactsCreateAToB[] = $uid;
                } elseif ($contactA !== null) {
                    $match = DuplicateMatcher::findMatches($contactA, $in->bookB, $in->contactStates, $sharedEmails)[0] ?? null;
                    if ($match !== null) {
                        $plan->contactDuplicates[] = new DuplicateMatch($uid, $match->uid, 'a');
                    } else {
                        $plan->contactsCreateAToB[] = $uid;
                    }
                }
                continue;
            }

            // A pair the user explicitly declined ("Cancel" on a
            // duplicate-match conflict). Nothing is ever planned for it:
            // not a push, and not a deletion on a side it was never on.
            //
            // The change detection below is exactly what this has to come
            // before. A cancelled contact stays live and gets edited like
            // any other, and an edit moves its source hash -- which read
            // as an ordinary update, and became a *create* on the declined
            // side, since that side has no href to update. Cancel undid
            // itself on the first edit.
            //
            // Dropped once the contact it refers to is gone, so a declined
            // row cannot outlive the uid it was about.
            if ((bool) ($state['cancelled'] ?? false)) {
                $declined = $state['a_href'] !== null
                    ? $contactA
                    : ($in->bookB->contacts[$uid] ?? null);
                if ($declined === null) {
                    $plan->contactsDropState[] = $uid;
                }
                continue;
            }

            $aHadIt = $state['a_href'] !== null;
            // "aGone" (removed from A) results in an archive action *on
            // B* when the deletion policy is archive -- so it's guarded
            // by archived_b, or an already-archived contact (permanently
            // absent from the side it was removed from) would be
            // reprocessed as "removed" on every run.
            $aGone = $aHadIt && $contactA === null && !(bool) ($state['archived_b'] ?? false);

            if ($aGone) {
                $plan->contactsRemoveOnB[] = $uid;
                continue;
            }

            $aChanged = $contactA !== null && Model::contactContentHash($contactA) !== ($state['a_hash'] ?? null);

            // Force: re-derive from content instead of assuming.
            //
            // This flag used to short-circuit the comparison above --
            // `$in->force || ...` -- so a forced run called every tracked
            // contact on side A changed, re-pushed all of them, and reported
            // the whole address book as updated. A user forcing an
            // iCloud -> Nextcloud run was told 597 contacts were updated
            // when nothing had been touched, which is not a cosmetic
            // miscount: those were 597 real writes.
            //
            // What the checkbox promises ("ignore stored state and
            // re-compare everything") is the useful behaviour and is
            // achievable, because B is always fetched in full anyway. The
            // comparison above already covers A. What force adds is the
            // check an ordinary run skips: whether B's copy still matches
            // what this app last wrote there. Normal runs notice only that
            // B's resource *exists* (liveBHrefs), never that its content
            // drifted, so an edit made directly on the endpoint is
            // invisible until someone forces a run. That is the gap force
            // is for.
            //
            // A contact whose endpoint copy drifted is re-pushed from A,
            // which is what this app means: A is the source of truth.
            if ($in->force && $contactA !== null) {
                $liveB = $in->bookB->contacts[$uid] ?? null;
                if ($liveB !== null && Model::contactContentHash($liveB) !== ($state['b_hash'] ?? null)) {
                    $aChanged = true;
                    $plan->contactsDriftedOnB[] = $uid;
                }
            }

            if ($aChanged) {
                $plan->contactsUpdateAToB[] = $uid;
                continue;
            }

            if ($contactA !== null) {
                // Content unchanged on A, but B has no live copy -- recreate
                // it from A's still-current content. Requires
                // contactA !== null: an already-archived contact (gone
                // from A, archived_b suppressing aGone above) has nothing
                // on A left to push, so it must not be "recreated" here.
                //
                // Two ways for B to have no copy, and only the first used to
                // be checked:
                //
                //   b_href set, but not in the live listing -- someone
                //   deleted it directly on the server;
                //
                //   b_href never set at all -- the contact is tracked and
                //   was never successfully written to B. A run that died
                //   between recording A's side and pushing leaves exactly
                //   this, and so does any path that upserts a state row
                //   before the push lands.
                //
                // The second was silently permanent: the guard required
                // b_href to be non-null, so the check was skipped, and the
                // content comparison above sees A unchanged since the row
                // was written. The contact then sits tracked, present on A,
                // absent from B, and no run ever plans anything for it --
                // including the run that would have noticed, because a plan
                // reporting no work is what "in sync" looks like.
                $bHref = $state['b_href'] ?? null;
                if ($bHref === null || !isset($in->liveBHrefs[$bHref])) {
                    $plan->contactsUpdateAToB[] = $uid;
                }
            }
        }
    }

    private function planGroups(PlanInput $in, Plan $plan): void
    {
        $uids = array_unique(array_merge(array_keys($in->bookA->groups), array_keys($in->groupStates)));

        foreach ($uids as $uid) {
            if (str_starts_with($uid, self::ARCHIVE_GROUP_PREFIX)) {
                continue;
            }

            $groupA = $in->bookA->groups[$uid] ?? null;
            $state = $in->groupStates[$uid] ?? null;

            if ($state === null) {
                if ($groupA !== null) {
                    // Only where B holds groups as resources. On a categories
                    // side "a group of the same name" is just the category
                    // this group is about to become -- the same group, not a
                    // rival identity -- and reporting it fired on every run
                    // of every pull job whose endpoint had groups.
                    $collision = $in->bGroupsAsCategories
                        ? null
                        : self::findGroupNameCollision($groupA, $in->bookB, $in->groupStates);
                    if ($collision !== null) {
                        $plan->groupNameCollisions[] = [$groupA->uid, $collision->uid, $groupA->name];
                    }
                    $plan->groupsCreateAToB[] = $uid;
                }
                continue;
            }

            // A group derived from Nextcloud categories has no resource of
            // its own on A, so a_href was never recorded for it, and "gone
            // from A" never fired: removing or renaming a category left the
            // old group on the endpoint for ever, still listing its last
            // members. A renamed category therefore showed up as two groups
            // on iCloud, not as the delete-plus-create the README promised.
            // Verified. The derived UID is recognisable from the stored name,
            // which keeps this from claiming rows of any other origin.
            $aHadIt = $state['a_href'] !== null || self::isDerivedCategoryGroup($uid, $state);
            $aGone = $aHadIt && $groupA === null;

            if ($aGone) {
                $plan->groupsRemoveOnB[] = $uid;
                continue;
            }

            $aChanged = $groupA !== null && Model::groupContentHash($groupA) !== ($state['a_hash'] ?? null);

            if ($in->force && $groupA !== null) {
                $liveB = $in->bookB->groups[$uid] ?? null;
                if ($liveB !== null && Model::groupContentHash($liveB) !== ($state['b_hash'] ?? null)) {
                    $aChanged = true;
                }
            }

            if ($aChanged) {
                $plan->groupsUpdateAToB[] = $uid;
                continue;
            }

            // The same out-of-band-deletion check contacts get, which groups
            // simply never had. Content unchanged on A, but B has no live
            // copy -- either its href is not in the listing any more, or one
            // was never recorded.
            //
            // Without it, emptying the destination out of band brought every
            // contact back on the next run and no groups at all: the
            // contacts branch above recreated them, while each group sat
            // with an unchanged hub-side hash and nothing to trigger on.
            // Membership was then permanently lost on that side, with a plan
            // reporting no work to do. Found while checking whether a user
            // could simply wipe a push-only endpoint and let the next sync
            // rebuild it -- they could not.
            // Not on a categories side, where no group ever has a resource:
            // reading that as "missing" re-planned every group on every run.
            if ($groupA !== null && !$in->bGroupsAsCategories) {
                $bHref = $state['b_href'] ?? null;
                if ($bHref === null || !isset($in->liveBHrefs[$bHref])) {
                    $plan->groupsUpdateAToB[] = $uid;
                }
            }
        }
    }

    /** @param array<string, mixed> $state a group_state row */
    private static function isDerivedCategoryGroup(string $uid, array $state): bool
    {
        $payload = json_decode((string) ($state['payload_json'] ?? ''), true);
        $name = is_array($payload) ? ($payload['name'] ?? null) : null;

        return is_string($name) && $name !== '' && CategoryGroups::uidFor($name) === $uid;
    }

    /**
     * Carry group changes on A to a B that keeps groups as CATEGORIES.
     *
     * There, membership lives on each member contact, so a group change has
     * nothing of its own to write -- it is a change to its members. On a
     * pull from iCloud or Infomaniak, adding someone to a group edits only
     * the group vCard; the contact is untouched, so nothing re-pushed it,
     * and the new category never reached Nextcloud until the contact itself
     * happened to be edited.
     *
     * Every contact a changed group touches -- its members now, plus its
     * members when last synced -- is re-pushed if, and only if, B's copy
     * does not already carry exactly the categories it should. That last
     * condition is what keeps this quiet: a group seen for the first time
     * (every group, on the first run after this was introduced) costs
     * nothing for members that already have the right categories.
     */
    private function planCategoryMembership(PlanInput $in, Plan $plan): void
    {
        $affected = [];
        foreach ([...$plan->groupsCreateAToB, ...$plan->groupsUpdateAToB, ...$plan->groupsRemoveOnB] as $groupUid) {
            foreach ($in->bookA->groups[$groupUid]->memberUids ?? [] as $member) {
                $affected[$member] = true;
            }
            $payload = json_decode((string) ($in->groupStates[$groupUid]['payload_json'] ?? ''), true);
            foreach (is_array($payload) && is_array($payload['member_uids'] ?? null) ? $payload['member_uids'] : [] as $member) {
                $affected[(string) $member] = true;
            }
        }

        $planned = array_flip([
            ...$plan->contactsCreateAToB,
            ...$plan->contactsUpdateAToB,
            ...$plan->contactsRemoveOnB,
            ...array_map(static fn(DuplicateMatch $d): string => $d->uid, $plan->contactDuplicates),
        ]);

        foreach (array_keys($affected) as $uid) {
            $uid = (string) $uid;
            $state = $in->contactStates[$uid] ?? null;
            $onB = $in->bookB->contacts[$uid] ?? null;
            if (
                isset($planned[$uid])
                || !isset($in->bookA->contacts[$uid])
                || $state === null
                || (bool) ($state['cancelled'] ?? false)
                || $onB === null
            ) {
                continue;
            }

            $want = self::groupNamesForContact($uid, $in->bookA, null);
            $have = $onB->categories;
            sort($have);
            if ($want !== $have) {
                $plan->contactsUpdateAToB[] = $uid;
            }
        }
    }

    /**
     * A group about to be created on B whose normalized name matches an
     * existing, untracked group already on B -- almost certainly the same
     * real-world group under two different UIDs, one this app just derived
     * or received and one that already lived on the endpoint.
     *
     * Deliberately does not stop the create or raise a conflict (unlike
     * DuplicateMatcher for contacts): there is no per-group conflict
     * resolution workflow. Callers surface this as an aggregated warning
     * instead -- see Runner's group-name-collision reporting.
     *
     * @param array<string, mixed> $excludedUids uids already tracked in
     *        group_state -- never collision candidates
     */
    private static function findGroupNameCollision(Group $groupA, AddressBook $bookB, array $excludedUids): ?Group
    {
        $name = Normalize::name($groupA->name);
        foreach ($bookB->groups as $uid => $other) {
            if ($uid === $groupA->uid || isset($excludedUids[$uid])) {
                continue;
            }
            if (Normalize::name($other->name) === $name) {
                return $other;
            }
        }
        return null;
    }

    /**
     * Canonical group names a contact belongs to, from whichever side(s)
     * actually expose discrete group vCards -- used when rendering
     * CATEGORIES for a destination whose own group_strategy is
     * 'categories'.
     */
    /** @return string[] */
    public static function groupNamesForContact(string $uid, ?AddressBook $bookA, ?AddressBook $bookB): array
    {
        $names = [];
        foreach ([$bookA, $bookB] as $book) {
            if ($book === null) {
                continue;
            }
            foreach ($book->groups as $group) {
                if (in_array($uid, $group->memberUids, true)) {
                    $names[] = $group->name;
                }
            }
        }
        $names = array_unique($names);
        sort($names);
        return $names;
    }
}
