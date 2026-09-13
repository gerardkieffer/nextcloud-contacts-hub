<?php

declare(strict_types=1);

namespace OCA\ContactHub\Sync;

use OCA\ContactHub\Db\BackupMapper;
use OCA\ContactHub\Files\HubFolder;
use OCA\ContactHub\VCard\Group;
use OCA\ContactHub\VCard\Model;
use OCA\DAV\CardDAV\CardDavBackend;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use Psr\Log\LoggerInterface;

/**
 * Snapshots of a Nextcloud address book, taken automatically before any sync
 * run that will actually write something.
 *
 * This is what HubBackup became, and it is dramatically simpler. The old
 * version had to join contacts against a content-addressed photo blob store,
 * pin every referenced blob into a backup_photos table so garbage collection
 * could not reap bytes a restore would still need, and re-inline those bytes
 * on restore. Nextcloud keeps photo data inside the card, so a snapshot is
 * just the cards: self-contained, with nothing to pin and nothing to collect.
 *
 * Snapshots are gzipped JSON in the user's own "Contacts Hub/Snapshots"
 * folder, so they can be found, downloaded and kept from the Files app.
 * They used to live in IAppData, which no user can see: fine for a cache,
 * wrong for a backup, whose whole value is being able to get at it when
 * something has gone wrong.
 *
 * Snapshots written before that change stay where they are and are still
 * restorable, because read() falls back to the old folder when a file is not
 * in the new one. No migration, nothing to lose, and the stragglers age out
 * on their own through the existing retention window.
 */
class BackupService
{
    private const int FORMAT_VERSION = 2;

    /** The pre-Files location. Read-only now; nothing new is written here. */
    private const string LEGACY_FOLDER = 'backups';

    public function __construct(
        private readonly CardDavBackend $backend,
        private readonly BackupMapper $backups,
        private readonly IAppData $appData,
        private readonly HubFolder $files,
        private readonly ?LoggerInterface $logger = null,
        private readonly int $retentionDays = 30,
    ) {
    }

    /**
     * Called by Runner between planning and the first write, and only when
     * the plan has work.
     *
     * Planning writes bookkeeping only -- run rows, conflict records, state
     * for uids already gone from both sides -- so nothing a restore would
     * want back has changed yet. The empty-plan skip matters practically:
     * most scheduled runs find nothing to do, and a half-hourly cron over a
     * thirty-day window would otherwise bury the genuinely useful restore
     * points under ~1400 identical files.
     */
    public function beforeSync(SyncJob $job, ?int $runId = null): void
    {
        $this->snapshot($job->addressBookId, $job->userId, 'pre_sync', $job->id, $runId);
    }

    public function snapshot(
        int $addressBookId,
        string $userId,
        string $reason,
        ?int $jobId = null,
        ?int $runId = null,
    ): int {
        $cards = $this->backend->getCards($addressBookId);

        $resources = [];
        $contacts = 0;
        $groups = 0;

        foreach ($cards as $card) {
            $carddata = (string) $card['carddata'];
            $resources[] = ['uri' => (string) $card['uri'], 'carddata' => $carddata];

            if ($this->isGroup($carddata)) {
                $groups++;
            } else {
                $contacts++;
            }
        }

        $payload = json_encode([
            'format_version' => self::FORMAT_VERSION,
            'address_book_id' => $addressBookId,
            'created_at' => gmdate('c'),
            'resources' => $resources,
        ], JSON_THROW_ON_ERROR);

        $gzipped = gzencode($payload, 6);
        if ($gzipped === false) {
            throw new \RuntimeException('Could not compress the address book snapshot.');
        }

        // Named for a person reading a folder listing, not for a database:
        // the address book, then the timestamp, then enough randomness that
        // two snapshots in the same second cannot collide.
        $name = sprintf(
            '%s %s %s.json.gz',
            HubFolder::safeName($this->bookName($addressBookId), (string) $addressBookId),
            gmdate('Y-m-d His'),
            bin2hex(random_bytes(3)),
        );
        try {
            $this->files->write($userId, HubFolder::SNAPSHOTS, $name, $gzipped);
        } catch (\Throwable $e) {
            // Deliberately fatal to the run rather than skipped. This is called
            // between planning and the first write precisely so there is a way
            // back; syncing anyway after failing to take that snapshot would
            // remove the protection without telling anyone. The raw exception
            // is usually a quota message that says nothing about contacts, so
            // the log line supplies the context.
            $this->logger?->error(
                'Contacts Hub: could not write a snapshot to {folder} for {user}, so the sync was stopped '
                . 'before making any changes: {message}',
                [
                    'folder' => HubFolder::ROOT . '/' . HubFolder::SNAPSHOTS,
                    'user' => $userId,
                    'message' => $e->getMessage(),
                    'app' => 'contacthub',
                    'exception' => $e,
                ],
            );
            throw $e;
        }

        return $this->backups->create([
            'user_id' => $userId,
            'address_book_id' => $addressBookId,
            'job_id' => $jobId,
            'run_id' => $runId,
            'reason' => $reason,
            'blob_name' => $name,
            'contact_count' => $contacts,
            'group_count' => $groups,
            'byte_size' => strlen($gzipped),
            'retention_days' => $this->retentionDays,
        ]);
    }

    /**
     * Restore a snapshot over the live address book.
     *
     * Takes its own snapshot first, so a restore is itself undoable -- the
     * single most useful property this feature has, and the one people only
     * discover they needed afterwards.
     *
     * @return array{created: int, updated: int, deleted: int}
     */
    public function restore(int $backupId, string $userId, bool $mirror = true): array
    {
        $meta = $this->backups->find($backupId, $userId);
        if ($meta === null) {
            throw new \RuntimeException("Snapshot #{$backupId} does not exist or is not yours.");
        }

        $addressBookId = (int) $meta['address_book_id'];
        $this->snapshot($addressBookId, $userId, 'pre_restore');

        $payload = $this->read((string) $meta['blob_name'], $userId);
        $wanted = [];
        foreach ($payload['resources'] ?? [] as $resource) {
            $wanted[(string) $resource['uri']] = (string) $resource['carddata'];
        }

        $live = [];
        foreach ($this->backend->getCards($addressBookId) as $card) {
            $live[(string) $card['uri']] = (string) $card['carddata'];
        }

        $stats = ['created' => 0, 'updated' => 0, 'deleted' => 0];

        foreach ($wanted as $uri => $carddata) {
            if (!array_key_exists($uri, $live)) {
                $this->backend->createCard($addressBookId, $uri, $carddata);
                $stats['created']++;
                continue;
            }
            // Compare through textHash: a snapshot taken before a readBlob()
            // rewrite would otherwise look different from identical content.
            if (Model::textHash($live[$uri]) !== Model::textHash($carddata)) {
                $this->backend->updateCard($addressBookId, $uri, $carddata);
                $stats['updated']++;
            }
        }

        if ($mirror) {
            foreach (array_keys($live) as $uri) {
                if (!array_key_exists($uri, $wanted)) {
                    $this->backend->deleteCard($addressBookId, $uri);
                    $stats['deleted']++;
                }
            }
        }

        return $stats;
    }

    /** @return list<array<string, mixed>> */
    public function listFor(int $addressBookId, string $userId): array
    {
        return $this->backups->forAddressBook($addressBookId, $userId);
    }

    /** @return array<string, mixed> the decoded snapshot payload */
    public function contents(int $backupId, string $userId): array
    {
        $meta = $this->backups->find($backupId, $userId);
        if ($meta === null) {
            throw new \RuntimeException("Snapshot #{$backupId} does not exist or is not yours.");
        }

        return $this->read((string) $meta['blob_name'], $userId);
    }

    /**
     * Drop expired snapshots and their blobs.
     *
     * Every row is isolated. This runs unattended over every user's rows at
     * once, so one unreachable snapshot must not stop the rest being cleaned
     * up: a single row that always threw would leave the whole instance's
     * expired snapshots accumulating forever, and the row that caused it is
     * exactly the row nobody is looking at. The row is cleared regardless,
     * because a row whose blob cannot be removed is precisely the one that
     * would otherwise be retried on every tick for ever.
     */
    public function prune(): int
    {
        $expired = $this->backups->expired();
        foreach ($expired as $row) {
            try {
                $this->deleteBlob((string) $row['blob_name'], (string) $row['user_id']);
            } catch (\Throwable $e) {
                $this->logger?->warning(
                    'Contacts Hub: could not delete the file for expired snapshot {id}; clearing the record anyway.',
                    ['id' => (int) $row['id'], 'app' => 'contacthub', 'exception' => $e],
                );
            }
            $this->backups->delete((int) $row['id']);
        }

        return count($expired);
    }

    /**
     * Drop everything a user owned, blobs included.
     *
     * Deleting only the rows would orphan the files forever: prune() finds
     * expired snapshots through those same rows, so once they are gone
     * nothing knows the blobs exist.
     */
    public function deleteAllForUser(string $userId): void
    {
        foreach ($this->backups->allForUser($userId) as $row) {
            $this->deleteBlob((string) $row['blob_name'], $userId);
        }

        $this->backups->deleteAllForUser($userId);
    }

    /**
     * @return array<string, mixed>
     *
     * Looks in the user's Files first, then the pre-Files IAppData folder.
     * Both orders would work; this one means the fallback costs nothing once
     * the old snapshots have expired.
     */
    private function read(string $blobName, string $userId): array
    {
        $raw = $this->files->read($userId, HubFolder::SNAPSHOTS, $blobName)
            ?? $this->readLegacy($blobName);

        if ($raw === null) {
            throw new \RuntimeException(
                "Snapshot file \"{$blobName}\" is missing. It may have been deleted from "
                . HubFolder::ROOT . '/' . HubFolder::SNAPSHOTS . '; check the trash.',
            );
        }

        $json = gzdecode($raw);
        if ($json === false) {
            throw new \RuntimeException("Snapshot file \"{$blobName}\" is corrupt and cannot be read.");
        }

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    private function readLegacy(string $blobName): ?string
    {
        try {
            return $this->legacyFolder()?->getFile($blobName)->getContent();
        } catch (\Throwable) {
            return null;
        }
    }

    private function deleteBlob(string $blobName, string $userId): void
    {
        $this->files->delete($userId, HubFolder::SNAPSHOTS, $blobName);

        try {
            $this->legacyFolder()?->getFile($blobName)->delete();
        } catch (\Throwable) {
            // Not there, which is the normal case for anything recent.
        }
    }

    /**
     * The pre-Files folder, or null when this install never had one.
     *
     * Never creates it. It used to, which meant every prune on an install
     * that post-dates the move conjured an empty appdata "backups" folder in
     * order to look for something that could not be in it.
     */
    private function legacyFolder(): ?ISimpleFolder
    {
        try {
            return $this->appData->getFolder(self::LEGACY_FOLDER);
        } catch (NotFoundException) {
            return null;
        }
    }

    private function bookName(int $addressBookId): string
    {
        $book = $this->backend->getAddressBookById($addressBookId);

        return (string) ($book['{DAV:}displayname'] ?? $book['uri'] ?? (string) $addressBookId);
    }

    /**
     * Parsed, never substring-matched: a KIND:group line can be split across
     * an RFC 6350 fold boundary, and this codebase has shipped that bug
     * before. A card that will not parse is counted as a contact rather than
     * breaking the snapshot.
     */
    private function isGroup(string $carddata): bool
    {
        try {
            return Model::parse($carddata) instanceof Group;
        } catch (\Throwable) {
            return false;
        }
    }
}
