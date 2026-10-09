<?php

declare(strict_types=1);

namespace OCA\ContactHub\Sync;

use OCA\ContactHub\Service\AddressBookNotAccessible;
use OCA\ContactHub\Service\AddressBookReadOnly;
use OCA\ContactHub\Service\AddressBookService;
use OCA\DAV\CardDAV\CardDavBackend;

/**
 * Builds the two SyncSide objects for a job, in the Planner's A/B order.
 *
 * Which role lands in slot A is the job direction's business (see SideMap);
 * this is where that decision becomes concrete objects.
 *
 * It is also the last point before anything touches the address book, so it
 * re-checks that the job's owner can still use it. A job stores a book id,
 * and an id checked only when the job was saved outlives the book being
 * deleted or unshared; see AddressBookService for what CardDavBackend does
 * with an id it should no longer be given. Every caller -- a run, a preview,
 * a conflict resolution -- goes through here, so none of them can skip it.
 */
class SideFactory
{
    public function __construct(
        private readonly CardDavBackend $backend,
        private readonly ClientFactory $clientFactory,
        private readonly AddressBookService $addressBooks,
    ) {
    }

    /**
     * @return array{0: SyncSide, 1: SyncSide} side A, side B
     * @throws HubUnavailable
     */
    public function forJob(SyncJob $job): array
    {
        $hubIsA = $job->sideMap()->hubIsA();
        // The hub is written to exactly when it is the destination, side B.
        $this->assertHubUsable($job, writes: !$hubIsA);

        $hub = new NextcloudSide($this->backend, $job->addressBookId, $job->addressBookName);
        $remote = new RemoteSide($this->clientFactory->create($job->endpoint), $job->endpoint);

        return $hubIsA ? [$hub, $remote] : [$remote, $hub];
    }

    private function assertHubUsable(SyncJob $job, bool $writes): void
    {
        try {
            $writes
                ? $this->addressBooks->requireWritable($job->addressBookId, $job->userId)
                : $this->addressBooks->requireAccess($job->addressBookId, $job->userId);
        } catch (AddressBookNotAccessible) {
            throw new HubUnavailable(
                "The Nextcloud address book this job uses (\"{$job->addressBookName}\") no longer exists, or is "
                . 'no longer shared with you. Nothing was synced: a missing address book reads exactly like an '
                . 'empty one, and syncing from an empty one would delete everything on the other side. Edit the '
                . 'job to choose another address book.',
            );
        } catch (AddressBookReadOnly $e) {
            throw new HubUnavailable(
                $e->getMessage() . ' This job pulls contacts into it, so it cannot run. Edit the job to choose '
                . 'an address book you can write to, or ask its owner for write access.',
            );
        }
    }
}
