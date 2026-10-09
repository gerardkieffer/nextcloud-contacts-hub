<?php

declare(strict_types=1);

namespace OCA\ContactHub\Controller;

use OCA\ContactHub\Service\NotificationPreferences;
use OCA\ContactHub\Service\NotificationService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The current user's conflict-notification preferences, plus whether the
 * instance looks able to deliver mail at all.
 *
 * Both travel together because nothing reads one without the other: the
 * personal settings form says where mail will go and warns if it cannot go
 * anywhere, and the Jobs page warns only when notifications are actually on.
 */
class NotificationController extends ApiController
{
    public function __construct(
        IRequest $request,
        IUserSession $userSession,
        LoggerInterface $logger,
        private readonly NotificationPreferences $preferences,
        private readonly NotificationService $notifications,
    ) {
        parent::__construct($request, $userSession, $logger);
    }

    #[NoAdminRequired]
    public function show(): DataResponse
    {
        return $this->respond(
            fn(): array => $this->preferences->get($this->userId()) + ['mail' => $this->notifications->mailStatus()],
        );
    }

    #[NoAdminRequired]
    public function update(string $mode, string $customEmail = ''): DataResponse
    {
        return $this->respond(
            fn(): array => $this->preferences->set($this->userId(), $mode, $customEmail)
                + ['mail' => $this->notifications->mailStatus()],
        );
    }
}
