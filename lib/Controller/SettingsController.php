<?php

declare(strict_types=1);

namespace OCA\ContactHub\Controller;

use OCA\ContactHub\Service\SettingsTransfer;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Export and import of endpoint and job configuration.
 *
 * Nothing here streams a download: the export is written into the user's own
 * "Contacts Hub/Settings" folder and the Files app does the downloading,
 * sharing and syncing. One fewer route to get the content-disposition and
 * CSRF handling right on, and the file is still there tomorrow.
 */
class SettingsController extends ApiController
{
    public function __construct(
        IRequest $request,
        IUserSession $userSession,
        LoggerInterface $logger,
        private readonly SettingsTransfer $transfer,
    ) {
        parent::__construct($request, $userSession, $logger);
    }

    #[NoAdminRequired]
    public function export(bool $includePasswords = false): DataResponse
    {
        return $this->respond(
            fn(): array => $this->transfer->export($this->userId(), $includePasswords),
        );
    }

    /** Settings files already sitting in the folder, offered as import sources. */
    #[NoAdminRequired]
    public function available(): DataResponse
    {
        return $this->respond(fn(): array => ['files' => $this->transfer->available($this->userId())]);
    }

    /**
     * What an import would do, without doing it.
     *
     * Separate from import() so the user sees which endpoints need a password
     * typed before anything is created, rather than discovering it from a
     * list of failures afterwards.
     */
    #[NoAdminRequired]
    public function inspect(?string $content = null, ?string $path = null): DataResponse
    {
        return $this->respond(
            fn(): array => $this->transfer->inspect($this->userId(), $content, $path),
        );
    }

    /** @param array<string, string> $passwords endpoint name => password */
    #[NoAdminRequired]
    public function import(?string $content = null, ?string $path = null, array $passwords = []): DataResponse
    {
        return $this->respond(
            fn(): array => $this->transfer->import($this->userId(), $content, $path, $passwords),
        );
    }
}
