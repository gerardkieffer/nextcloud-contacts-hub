<?php

declare(strict_types=1);

namespace OCA\ContactHub\Service;

use OCA\ContactHub\Db\JobMapper;
use OCA\ContactHub\Db\StateMapper;
use OCA\ContactHub\Sync\BackupService;
use OCA\ContactHub\Sync\SyncJob;

/**
 * The API-facing wrapper around BackupService.
 *
 * BackupService is used by the Runner, where an address book id is already
 * known to be legitimate. Requests are not: this checks the user can reach
 * the book before anything is snapshotted or restored, and shapes rows for
 * the SPA.
 */
class BackupPresenter
{
    public function __construct(
        private readonly BackupService $backups,
        private readonly AddressBookService $addressBooks,
        private readonly JobMapper $jobs,
        private readonly StateMapper $state,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function listFor(int $addressBookId, string $userId): array
    {
        $this->requireBook($addressBookId, $userId);

        return array_map($this->present(...), $this->backups->listFor($addressBookId, $userId));
    }

    /** @return array<string, mixed> */
    public function snapshot(int $addressBookId, string $userId): array
    {
        $this->requireBook($addressBookId, $userId);
        $this->backups->snapshot($addressBookId, $userId, 'manual');

        return ['snapshots' => $this->listFor($addressBookId, $userId)];
    }

    /** @return array{created: int, updated: int, deleted: int} */
    public function restore(int $backupId, string $userId, bool $mirror): array
    {
        // BackupService refuses another user's snapshot, but owning the
        // snapshot is not the same as being allowed to write the book it was
        // taken from: a shared book may since have been unshared, or have
        // been shared read-only all along, and CardDavBackend writes
        // wherever it is told -- see AddressBookService.
        $addressBookId = $this->backups->addressBookOf($backupId, $userId);
        if ($addressBookId === null) {
            throw new NotFoundException("Snapshot #{$backupId} does not exist or is not yours.");
        }
        try {
            $this->addressBooks->requireWritable($addressBookId, $userId);
        } catch (AddressBookNotAccessible $e) {
            throw new NotFoundException($e->getMessage());
        } catch (AddressBookReadOnly $e) {
            throw ValidationException::field('id', $e->getMessage());
        }

        $result = $this->backups->restore($backupId, $userId, $mirror);

        // A pull job writes into this book from its endpoint, and its next
        // run must treat what the restore rewrote consistently: see
        // StateMapper::forgetSourceHashOfHubCards(). A push job needs
        // nothing -- to it, a restore is an edit of its source like any other.
        foreach ($this->jobs->forAddressBook($addressBookId, $userId) as $job) {
            if ($job->direction === SyncJob::FROM_ENDPOINT) {
                $this->state->forgetSourceHashOfHubCards($job->id, $result['touched']);
            }
        }
        unset($result['touched']);

        return $result;
    }

    private function requireBook(int $addressBookId, string $userId): void
    {
        try {
            $this->addressBooks->requireAccess($addressBookId, $userId);
        } catch (AddressBookNotAccessible $e) {
            throw new NotFoundException($e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'reason' => (string) $row['reason'],
            // Which job's run a pre_sync snapshot was taken before, so the
            // restore screen can name it; null for the other reasons.
            'job_id' => $row['job_id'] !== null ? (int) $row['job_id'] : null,
            'contact_count' => (int) $row['contact_count'],
            'group_count' => (int) $row['group_count'],
            'byte_size' => (int) $row['byte_size'],
            'created_at' => $row['created_at'],
            'expires_at' => $row['expires_at'],
        ];
    }
}
