<?php

declare(strict_types=1);

namespace OCA\ContactHub\Controller;

use OCA\ContactHub\Service\JobService;
use OCA\ContactHub\Service\RunService;
use OCA\ContactHub\Sync\RunResult;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class JobController extends ApiController
{
    public function __construct(
        IRequest $request,
        IUserSession $userSession,
        LoggerInterface $logger,
        private readonly JobService $jobs,
        private readonly RunService $runs,
    ) {
        parent::__construct($request, $userSession, $logger);
    }

    #[NoAdminRequired]
    public function index(): DataResponse
    {
        return $this->respond(fn(): array => $this->jobs->listFor($this->userId()));
    }

    #[NoAdminRequired]
    public function show(int $id): DataResponse
    {
        return $this->respond(fn(): array => $this->jobs->get($id, $this->userId()));
    }

    #[NoAdminRequired]
    public function create(
        string $name = '',
        int $addressBookId = 0,
        int $endpointId = 0,
        string $direction = '',
        string $deletionPolicy = 'mirror',
        string $archiveGroupName = 'Deleted',
        bool $includePhotos = true,
        int $intervalSeconds = 1800,
        bool $enabled = true,
    ): DataResponse {
        $input = [
            'name' => $name,
            'address_book_id' => $addressBookId,
            'endpoint_id' => $endpointId,
            'direction' => $direction,
            'deletion_policy' => $deletionPolicy,
            'archive_group_name' => $archiveGroupName,
            'include_photos' => $includePhotos,
            'interval_seconds' => $intervalSeconds,
            'enabled' => $enabled,
        ];

        return $this->respond(function () use ($input): array {
            $id = $this->jobs->create($this->userId(), $input);

            return $this->jobs->get($id, $this->userId());
        });
    }

    #[NoAdminRequired]
    public function update(
        int $id,
        ?string $name = null,
        ?int $addressBookId = null,
        ?int $endpointId = null,
        ?string $direction = null,
        ?string $deletionPolicy = null,
        ?string $archiveGroupName = null,
        ?bool $includePhotos = null,
        ?int $intervalSeconds = null,
        ?bool $enabled = null,
    ): DataResponse {
        $input = array_filter([
            'name' => $name,
            'address_book_id' => $addressBookId,
            'endpoint_id' => $endpointId,
            'direction' => $direction,
            'deletion_policy' => $deletionPolicy,
            'archive_group_name' => $archiveGroupName,
            'include_photos' => $includePhotos,
            'interval_seconds' => $intervalSeconds,
            'enabled' => $enabled,
        ], static fn(mixed $v): bool => $v !== null);

        return $this->respond(function () use ($id, $input): array {
            $this->jobs->update($id, $this->userId(), $input);

            return $this->jobs->get($id, $this->userId());
        });
    }

    #[NoAdminRequired]
    public function destroy(int $id): DataResponse
    {
        return $this->respond(function () use ($id): array {
            $this->jobs->delete($id, $this->userId());

            return ['deleted' => $id];
        });
    }

    /**
     * Preview or apply a run.
     *
     * A real run is bounded by the web time budget and may come back
     * 'paused' with work still queued; that is a normal outcome, not an
     * error, and the caller is expected to submit again to continue. The
     * same call resumes an already-open run, so the SPA needs no separate
     * resume endpoint.
     */
    #[NoAdminRequired]
    public function run(int $id, bool $dryRun = true, bool $force = false, string $progressToken = ''): DataResponse
    {
        // Through respond() like every other action. It used to catch its
        // own RunAlreadyActive and NotFoundException and nothing else, so a
        // run that failed the way runs actually fail -- a 401 from the
        // endpoint, an unreachable host -- threw straight past it and the
        // browser got an OCS envelope with no message to show.
        return $this->respond(fn(): array => $this->presentResult(
            $this->runs->runFromWeb($id, $this->userId(), $dryRun, $force, $progressToken),
        ));
    }

    /** @return array<string, mixed> */
    private function presentResult(RunResult $result): array
    {
        return [
            'status' => $result->status,
            'dry_run' => $result->dryRun,
            'paused_reason' => $result->pausedReason,
            'created' => $result->created,
            'updated' => $result->updated,
            'deleted' => $result->deleted,
            'archived' => $result->archived,
            'conflicts' => $result->conflicts,
            'total_items' => $result->totalItems,
            'processed_items' => $result->processedItems,
            'warnings' => $result->warnings,
            'errors' => $result->errors,
        ];
    }
}
