<?php

declare(strict_types=1);

namespace OCA\ContactHub\Sync;

use OCA\ContactHub\Db\StateMapper;
use OCA\ContactHub\Files\HubFolder;
use OCA\ContactHub\VCard\Model;

/**
 * Applies a human's manual pick for a duplicate-match conflict recorded
 * by the Runner: a new contact matching an existing untracked one by
 * name+email/phone. The choices are "overwrite the existing copy",
 * "keep both -- not the same person", "archive the existing copy to a
 * file first, then overwrite", or "cancel -- don't sync the new contact
 * at all". Source/destination are derived from the row's duplicate_json
 * column -- see applyDuplicate().
 *
 * Resolutions are named for roles ('hub' / 'endpoint' / 'archive'),
 * not for the Planner's A/B slots. A user picking "keep the hub's
 * version" means that regardless of which direction the job runs in,
 * and the stored conflict snapshots are role-named for the same reason.
 *
 * Archiving writes a .vcf into the user's own Files via
 * archiveContactToFile() rather than a copy written back to the
 * endpoint under a synthetic uid: a backup living in the address book is
 * a resource every later run must be taught to ignore, it shows up in
 * the Contacts app beside the live contact under a uid nobody can read,
 * and a file the user can open, forward or delete needs none of that.
 * Runner still writes archive copies into the address book when a
 * *deletion* propagates -- that is a different feature, and there the
 * point is that the contact stays reachable where contacts live.
 */
class ConflictApplier
{
    public const string HUB = 'hub';
    public const string ENDPOINT = 'endpoint';
    public const string ARCHIVE = 'archive';
    public const string CANCEL = 'cancel';

    public function __construct(
        private readonly StateMapper $state,
        private readonly SideFactory $sides,
        private readonly HubFolder $files,
    ) {
    }

    /**
     * The (source, destination) role pair a duplicate-match conflict names,
     * or null when duplicate_json does not name a valid source role.
     *
     * One definition, deliberately, because this is the computation that
     * has already shipped a destructive bug once: which role means "merge"
     * and which means "keep both" flips with the side the new contact
     * arrived from, so a second copy that drifts inverts a button's meaning
     * in one direction only and silently overwrites the copy the user was
     * told would be kept. Callers may still differ in what they *do* about
     * unusable data -- apply() throws, the presenter and the batch path
     * decline to offer choices -- but they no longer differ on what the
     * data means.
     *
     * @param array<string, mixed> $duplicate decoded duplicate_json
     * @return array{0: string, 1: string}|null source role, destination role
     */
    public static function rolesForDuplicate(array $duplicate): ?array
    {
        $sourceRole = $duplicate['source_role'] ?? null;
        if (!in_array($sourceRole, [self::HUB, self::ENDPOINT], true)) {
            return null;
        }

        return [$sourceRole, $sourceRole === self::HUB ? self::ENDPOINT : self::HUB];
    }

    /** @param array<string, mixed> $conflictRow */
    public function apply(SyncJob $job, array $conflictRow, string $resolution): void
    {
        if (!in_array($resolution, [self::HUB, self::ENDPOINT, self::ARCHIVE, self::CANCEL], true)) {
            throw new \InvalidArgumentException("resolution must be 'hub', 'endpoint', 'archive', or 'cancel'");
        }

        if (($conflictRow['duplicate_json'] ?? null) === null) {
            throw new \RuntimeException('Conflict row carries no duplicate_json; only duplicate-match conflicts can be resolved.');
        }

        $this->applyDuplicate($job, $conflictRow, $resolution);
    }

    /**
     * Resolves a duplicate-match conflict: a new contact ($uid, on the
     * source role) matched an existing untracked contact on the other
     * side. The choices are "overwrite the existing copy" (resolution =
     * source role), "keep both -- not the same person" (resolution =
     * destination role, performs the create that detection intercepted),
     * "archive the existing copy to a file first, then overwrite", or
     * "cancel -- don't sync the new contact at all". All four end by
     * tracking something in contact_state so the next run's detection
     * never re-flags the same pair.
     *
     * @param array<string, mixed> $conflictRow
     */
    private function applyDuplicate(SyncJob $job, array $conflictRow, string $resolution): void
    {
        $info = json_decode((string) $conflictRow['duplicate_json'], true);
        $roles = is_array($info) ? self::rolesForDuplicate($info) : null;
        if ($roles === null) {
            throw new \RuntimeException('Corrupt duplicate_json on conflict row.');
        }
        [$sourceRole, $destRole] = $roles;

        $uid = (string) $conflictRow['uid'];
        $sourceSnapshot = $conflictRow["{$sourceRole}_snapshot"];
        $destSnapshot = $conflictRow["{$destRole}_snapshot"];
        if ($sourceSnapshot === null) {
            throw new \RuntimeException('Cannot resolve: the new contact has no recorded content.');
        }

        if ($resolution === self::CANCEL) {
            $sourceHref = $info['source_href'] ?? null;
            if (!is_string($sourceHref) || $sourceHref === '') {
                throw new \RuntimeException('Cannot cancel: the new contact has no recorded href.');
            }
            $this->cancelDuplicate($job, $uid, $sourceRole, (string) $sourceSnapshot, $sourceHref);

            // Both halves, not just the new contact -- see cancelDuplicate().
            $destUid = $info['dest_uid'] ?? null;
            $destHref = $info['dest_href'] ?? null;
            if ($destSnapshot !== null && is_string($destUid) && $destUid !== '' && is_string($destHref) && $destHref !== '') {
                $this->cancelDuplicate($job, $destUid, $destRole, (string) $destSnapshot, $destHref);
            }

            $this->state->resolveConflict((int) $conflictRow['id'], $resolution);
            return;
        }

        $dest = $this->sideForRole($job, $destRole);

        if ($resolution === self::ARCHIVE) {
            if ($destSnapshot === null) {
                throw new \RuntimeException('Cannot archive: the existing copy has no recorded content.');
            }
            $this->archiveContactToFile($job, (string) $destSnapshot);
        }

        if ($resolution === $destRole) {
            // Keep both: create the new contact under its own fresh href,
            // leaving the existing copy untouched and untracked.
            $href = $dest->hrefFor($uid);
            [$newEtag, $storedText] = $dest->putVCard($href, (string) $sourceSnapshot, null);
        } else {
            $destHref = $info['dest_href'] ?? null;
            if (!is_string($destHref) || $destHref === '') {
                throw new \RuntimeException('Cannot resolve: the matched existing copy has no recorded href.');
            }
            [$newEtag, $storedText] = $dest->putVCard($destHref, (string) $sourceSnapshot, $dest->etagFor($destHref));
            $href = $destHref;
        }

        $parsed = Model::parse((string) $sourceSnapshot);
        $this->state->upsertContact($job->id, $uid, [
            "{$destRole}_href" => $href,
            "{$destRole}_etag" => $newEtag,
            "{$destRole}_hash" => Model::textHash($storedText),
            "{$destRole}_rev" => $parsed->rev,
            "{$sourceRole}_href" => $info['source_href'] ?? null,
            "{$sourceRole}_hash" => Model::contactContentHash($parsed),
            "{$sourceRole}_rev" => $parsed->rev,
        ]);

        $this->state->resolveConflict((int) $conflictRow['id'], $resolution);
    }

    /**
     * Marks one contact of a declined pair "leave this alone": tracked
     * one-sided -- its real href on the role it actually lives on,
     * nothing on the other -- which takes it out of the Planner's
     * "untracked, try to match or create" branch, and flagged `cancelled`,
     * which keeps it out of the *tracked* branch's change detection too.
     *
     * Called for **both** halves of the pair. It used to be called only
     * for the newly-arrived contact, on the reasoning that the existing
     * copy was never touched and should stay a duplicate candidate for
     * anything that might genuinely match it later. That reasoning held
     * only because this app's diff never looks at the far side's own
     * uids directly -- found originally against a since-removed two-way
     * mode, where the next run reached the existing copy's uid, found it
     * untracked, found no duplicate for it (its former partner is tracked
     * now, so the matcher passes over it), and planned it as a create on
     * the other side. The declined pairing reassembled itself from the
     * opposite direction, one run later.
     *
     * The cost of the fix is the property that reasoning was protecting:
     * the existing copy is now excluded as a duplicate candidate, so a
     * third contact that genuinely resembles it syncs across instead of
     * being flagged. That is the same thing that happens for every
     * ordinary synced contact (see DuplicateMatcher), so it is at least
     * not a special case -- but it is a real trade, and the alternative
     * (persisting the pairing so the Planner can skip the peer without
     * tracking it) is a column and a migration, not a cheaper line.
     *
     * Both halves are load-bearing. One-sided tracking alone is enough
     * for an inert row -- the archive copies Runner writes when a
     * deletion propagates are one-sided too, and get away with it
     * because a synthetic uid is never edited again. A cancelled contact
     * is a live contact the user goes on editing, and without the flag
     * the next run after any edit saw a
     * tracked row whose source hash had moved, scheduled an ordinary
     * update, and Runner::pushContactOne() turned that into a create on
     * the very side the user had declined -- silently undoing the
     * decision. See Planner::planContacts() and the migration that adds
     * the column.
     */
    private function cancelDuplicate(SyncJob $job, string $uid, string $role, string $snapshot, string $href): void
    {
        $parsed = Model::parse($snapshot);
        $this->state->upsertContact($job->id, $uid, [
            "{$role}_href" => $href,
            "{$role}_hash" => Model::contactContentHash($parsed),
            "{$role}_rev" => $parsed->rev,
            'cancelled' => true,
        ]);
    }

    /**
     * Writes a contact's vCard as a stand-alone .vcf file into the user's
     * own "Contacts Hub/Archived contacts" folder before its record is
     * overwritten -- by an incoming duplicate, or by the hub's version of
     * a same-UID conflict. The backup never touches the CardDAV side at
     * all: it is a file the user can open, forward or restore by hand
     * from the Files app, not a second address-book entry that every
     * future sync has to account for.
     *
     * Plainly "<display name>.vcf" while that name is free, because the
     * point of these files is that a person can find and read them in the
     * Files app. A second contact of the same name does not overwrite the
     * first.
     *
     * The disambiguated name carries random bytes as well as a timestamp,
     * the same shape BackupService uses for snapshots, because a timestamp
     * alone was not enough: gmdate() has one-second resolution, the
     * fallback name was never itself re-checked, and HubFolder::write()
     * overwrites silently. Two same-named contacts archived in the same
     * second therefore lost one backup with nothing reported -- and batch
     * resolve makes that a realistic case rather than a theoretical one,
     * since duplicate matches are name matches by definition.
     */
    private function archiveContactToFile(SyncJob $job, string $snapshot): void
    {
        $parsed = Model::parse($snapshot);
        $base = HubFolder::safeName($parsed->fn !== '' ? $parsed->fn : $parsed->uid);
        $name = $base . '.vcf';

        if ($this->files->exists($job->userId, HubFolder::ARCHIVED_CONTACTS, $name)) {
            $name = sprintf('%s %s %s.vcf', $base, gmdate('Y-m-d His'), bin2hex(random_bytes(3)));
        }

        $this->files->write($job->userId, HubFolder::ARCHIVED_CONTACTS, $name, $snapshot);
    }

    private function sideForRole(SyncJob $job, string $role): SyncSide
    {
        [$sideA, $sideB] = $this->sides->forJob($job);
        return $job->sideMap()->roleA === $role ? $sideA : $sideB;
    }
}
