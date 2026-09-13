<?php

declare(strict_types=1);

namespace OCA\ContactHub\Controller;

use OCA\ContactHub\Service\ConflictService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class ConflictController extends ApiController
{
    public function __construct(
        IRequest $request,
        IUserSession $userSession,
        LoggerInterface $logger,
        private readonly ConflictService $service,
    ) {
        parent::__construct($request, $userSession, $logger);
    }

    /** Every unresolved conflict across the user's jobs. */
    #[NoAdminRequired]
    public function index(): DataResponse
    {
        return $this->respond(fn(): array => $this->service->listFor($this->userId()));
    }

    #[NoAdminRequired]
    public function forJob(int $jobId): DataResponse
    {
        return $this->respond(fn(): array => $this->service->listForJob($jobId, $this->userId()));
    }

    /**
     * Apply a human's pick.
     *
     * $resolution is a role ('hub', 'endpoint'), 'archive' or 'cancel',
     * never an A/B side: which side plays A flips with the job's direction,
     * so a button reading "keep A's version" would mean opposite things on
     * two otherwise identical jobs.
     *
     * 'cancel' only applies to a duplicate-match conflict, and the applier
     * rejects it for the same-UID kind -- there is no "new contact" to
     * decline when both sides already have it.
     */
    #[NoAdminRequired]
    public function resolve(int $id, string $resolution): DataResponse
    {
        return $this->respond(function () use ($id, $resolution): array {
            $this->service->resolve($id, $this->userId(), $resolution);

            return ['resolved' => $id];
        });
    }

    /**
     * Apply one choice to every unresolved duplicate-match conflict at
     * once. $choice is 'merge', 'keep_both', 'archive' or 'cancel' --
     * abstract, not a role, since which role each maps to is decided
     * per-conflict (see ConflictService::resolutionForChoice()).
     */
    #[NoAdminRequired]
    public function resolveBatch(string $choice): DataResponse
    {
        return $this->respond(fn(): array => $this->service->resolveBatch($this->userId(), $choice));
    }
}
