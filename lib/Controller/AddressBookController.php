<?php

declare(strict_types=1);

namespace OCA\ContactHub\Controller;

use OCA\ContactHub\Service\AddressBookService;
use OCA\ContactHub\Service\BackupPresenter;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Nextcloud's own address books, as far as this app is concerned.
 *
 * There is deliberately no create/edit/delete here: address books belong to
 * the Contacts app, and duplicating that would give users two places to
 * manage the same thing. This lists what a job can point at, and exposes the
 * snapshots this app takes of them.
 */
class AddressBookController extends ApiController
{
    public function __construct(
        IRequest $request,
        IUserSession $userSession,
        LoggerInterface $logger,
        private readonly AddressBookService $addressBooks,
        private readonly BackupPresenter $backups,
    ) {
        parent::__construct($request, $userSession, $logger);
    }

    #[NoAdminRequired]
    public function index(): DataResponse
    {
        return $this->respond(fn(): array => $this->addressBooks->listForUser($this->userId()));
    }

    #[NoAdminRequired]
    public function backups(int $addressBookId): DataResponse
    {
        return $this->respond(fn(): array => $this->backups->listFor($addressBookId, $this->userId()));
    }

    #[NoAdminRequired]
    public function snapshot(int $addressBookId): DataResponse
    {
        return $this->respond(fn(): array => $this->backups->snapshot($addressBookId, $this->userId()));
    }

    /**
     * $mirror decides whether cards absent from the snapshot are removed.
     * Defaulting it to false makes the safer choice the one you get by
     * accident.
     */
    #[NoAdminRequired]
    public function restore(int $backupId, bool $mirror = false): DataResponse
    {
        return $this->respond(fn(): array => $this->backups->restore($backupId, $this->userId(), $mirror));
    }
}
