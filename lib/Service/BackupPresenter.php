<?php

declare(strict_types=1);

namespace OCA\ContactHub\Service;

use OCA\ContactHub\Sync\BackupService;

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
        // BackupService already refuses another user's snapshot; the address
        // book check happens implicitly because the snapshot names it.
        return $this->backups->restore($backupId, $userId, $mirror);
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
            'contact_count' => (int) $row['contact_count'],
            'group_count' => (int) $row['group_count'],
            'byte_size' => (int) $row['byte_size'],
            'created_at' => $row['created_at'],
            'expires_at' => $row['expires_at'],
        ];
    }
}
