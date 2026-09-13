<?php

declare(strict_types=1);

namespace OCA\ContactHub\Sync;

use OCA\DAV\CardDAV\CardDavBackend;

/**
 * Builds the two SyncSide objects for a job, in the Planner's A/B order.
 *
 * Which role lands in slot A is the job direction's business (see SideMap);
 * this is where that decision becomes concrete objects.
 */
class SideFactory
{
    public function __construct(
        private readonly CardDavBackend $backend,
        private readonly ClientFactory $clientFactory,
    ) {
    }

    /** @return array{0: SyncSide, 1: SyncSide} side A, side B */
    public function forJob(SyncJob $job): array
    {
        $hub = new NextcloudSide($this->backend, $job->addressBookId, $job->addressBookName);
        $remote = new RemoteSide($this->clientFactory->create($job->endpoint), $job->endpoint);

        return $job->sideMap()->hubIsA() ? [$hub, $remote] : [$remote, $hub];
    }
}
