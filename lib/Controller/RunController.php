<?php

declare(strict_types=1);

namespace OCA\ContactHub\Controller;

use OCA\ContactHub\Service\RunService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class RunController extends ApiController
{
    public function __construct(
        IRequest $request,
        IUserSession $userSession,
        LoggerInterface $logger,
        private readonly RunService $service,
    ) {
        parent::__construct($request, $userSession, $logger);
    }

    #[NoAdminRequired]
    public function index(int $jobId, int $limit = 50): DataResponse
    {
        return $this->respond(fn(): array => $this->service->historyFor($jobId, $this->userId(), $limit));
    }

    #[NoAdminRequired]
    public function open(int $jobId): DataResponse
    {
        return $this->respond(fn(): ?array => $this->service->openRunFor($jobId, $this->userId()));
    }

    /** Everything a run saw, in materialisation order; drives the status page. */
    #[NoAdminRequired]
    public function items(int $jobId, int $runId): DataResponse
    {
        return $this->respond(fn(): array => $this->service->itemsFor($jobId, $this->userId(), $runId));
    }

    /**
     * Live progress, polled while a run is in flight.
     *
     * Keyed by the token the browser generated rather than by job, so a page
     * follows the run it started instead of whatever ran most recently.
     */
    #[NoAdminRequired]
    public function progress(string $token): DataResponse
    {
        return $this->respond(fn(): ?array => $this->service->progressFor($token));
    }

    #[NoAdminRequired]
    public function abandon(int $jobId): DataResponse
    {
        return $this->respond(function () use ($jobId): array {
            $this->service->abandon($jobId, $this->userId());

            return ['abandoned' => $jobId];
        });
    }
}
