<?php

declare(strict_types=1);

namespace OCA\ContactHub\Tests\Integration;

use OCA\ContactHub\Service\AddressBookService;
use OCA\ContactHub\Service\JobService;
use OCA\ContactHub\Service\ValidationException;
use OCA\ContactHub\Sync\ConflictApplier;
use OCA\ContactHub\Sync\HubUnavailable;
use OCA\ContactHub\Sync\Runner;
use OCA\ContactHub\Sync\SideFactory;
use OCA\ContactHub\Sync\SyncJob;
use OCA\ContactHub\Tests\Support\FakeClientFactory;
use OCA\ContactHub\Tests\Support\FakeHttpTransport;
use OCA\ContactHub\VCard\Group;
use OCA\ContactHub\VCard\Model;

/**
 * Regressions for the ways a sync could damage data it had no business
 * touching, each found by an audit that reproduced it against the real
 * CardDavBackend before it was fixed:
 *
 *  - a deleted address book read as an empty one, and a push job mirrored
 *    that by deleting everything on the endpoint;
 *  - a book shared read-only was writable through a pull job;
 *  - a job moved to another endpoint kept writing to the old one, with the
 *    new one's credentials;
 *  - a renamed category left the old group on the endpoint for ever;
 *  - a pull job from an endpoint with groups never settled;
 *  - a contact already in Nextcloud under another file name failed on
 *    every run;
 *  - resolving a conflict pushed the raw snapshot, and resolving a stale
 *    one deleted a contact that had only ever existed on the destination.
 */
final class DataSafetyTest extends IntegrationTestCase
{
    public function testADeletedSourceAddressBookStopsThePushInsteadOfWipingTheEndpoint(): void
    {
        $this->seedHub($this->catVcard('c1', 'Alice', 'Family'));
        $this->seedHub($this->vcard('c2', 'Bob'));
        $job = $this->makeJob(SyncJob::TO_ENDPOINT, 'mirror');
        $runner = $this->runner();
        $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');
        $before = $this->transport->resources;
        self::assertCount(3, $before);

        $this->backend->deleteAddressBook($this->addressBookId);

        try {
            $runner->run($job, dryRun: false, force: false, triggerSource: 'cron');
            self::fail('a run against a deleted address book must not happen');
        } catch (HubUnavailable $e) {
            self::assertStringContainsString('no longer exists', $e->getMessage());
        }
        self::assertSame($before, $this->transport->resources, 'nothing on the endpoint may change');

        $runs = $this->runRowsFor($job->id);
        self::assertSame('failed', end($runs)['status'], 'the refusal is in the run history, not only the log');
    }

    public function testAnEmptySourceIsNeverMirroredAsADeletionOfEverything(): void
    {
        // The cause SideFactory cannot see: the book exists, it just reads
        // as empty -- a server answering with an empty listing, say.
        $this->seedHub($this->vcard('c1', 'Alice'));
        $this->seedHub($this->vcard('c2', 'Bob'));
        $job = $this->makeJob(SyncJob::TO_ENDPOINT, 'mirror');
        $runner = $this->runner();
        $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');

        $this->hubDelete('c1');
        $this->hubDelete('c2');

        $preview = $runner->run($job, dryRun: true, force: false, triggerSource: 'manual');
        self::assertNotEmpty(
            array_filter($preview->warnings, static fn(string $w) => str_contains($w, 'A real run would stop here')),
            'a preview says the real run would refuse',
        );

        try {
            $runner->run($job, dryRun: false, force: false, triggerSource: 'cron');
            self::fail('an empty source must not be mirrored');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('has no contacts at all', $e->getMessage());
        }
        self::assertCount(2, $this->transport->resources);
        self::assertCount(2, $this->state->allContacts($job->id), 'and nothing was recorded either');
    }

    public function testAReadOnlyShareIsNeverWrittenByAPullJob(): void
    {
        $ownerBookId = $this->createReadOnlyShare();
        try {
            $books = new AddressBookService($this->backend);
            $shared = $books->requireAccess($ownerBookId, self::USER_ID);
            self::assertTrue($shared['readOnly']);
            self::assertFalse($shared['owned'], 'a shared book is not the sharee\'s own');

            // Refused when the job is saved...
            try {
                $this->jobService()->create(self::USER_ID, [
                    'name' => 'Into a read-only book', 'address_book_id' => $ownerBookId,
                    'endpoint_id' => $this->endpointId, 'direction' => SyncJob::FROM_ENDPOINT,
                ]);
                self::fail('a pull job into a read-only share must not be saved');
            } catch (ValidationException $e) {
                self::assertArrayHasKey('address_book_id', $e->errors);
            }

            // ...and when one that predates the check runs.
            $this->seedEndpoint('e1.vcf', $this->vcard('e1', 'Injected'));
            $id = $this->jobs->create(self::USER_ID, [
                'name' => 'Legacy', 'address_book_id' => $ownerBookId, 'endpoint_id' => $this->endpointId,
                'direction' => SyncJob::FROM_ENDPOINT, 'deletion_policy' => 'mirror',
            ]);
            try {
                $this->runner()->run($this->jobs->find($id, self::USER_ID), dryRun: false, force: false, triggerSource: 'cron');
                self::fail('a read-only share must not be written to');
            } catch (HubUnavailable $e) {
                self::assertStringContainsString('read-only', $e->getMessage());
            }
            self::assertSame([], $this->backend->getCards($ownerBookId));

            // Reading from it -- a push job -- stays allowed.
            $push = $this->jobService()->create(self::USER_ID, [
                'name' => 'From a read-only book', 'address_book_id' => $ownerBookId,
                'endpoint_id' => $this->endpointId, 'direction' => SyncJob::TO_ENDPOINT,
            ]);
            self::assertGreaterThan(0, $push);
        } finally {
            $this->backend->deleteAddressBook($ownerBookId);
        }
    }

    public function testMovingAJobToAnotherEndpointNeverWritesToTheOldOne(): void
    {
        $this->seedHub($this->vcard('c1', 'Alice'));
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);
        $this->runner()->run($job, dryRun: false, force: false, triggerSource: 'manual');

        $other = new FakeHttpTransport('https://other-provider.example/');
        $otherId = $this->endpoints->create(self::USER_ID, [
            'name' => 'Other', 'preset' => 'generic', 'base_url' => $other->base,
            'username' => 'u2', 'password' => 'p2', 'collection_href' => $other->collectionHref,
        ]);
        $this->jobService()->update($job->id, self::USER_ID, ['endpoint_id' => $otherId]);
        self::assertSame([], $this->state->allContacts($job->id), 'state about the old endpoint is gone');

        $runner = new Runner($this->state, $this->jobs, $this->locks, new SideFactory(
            $this->backend,
            new FakeClientFactory([$this->endpointId => $this->transport, $otherId => $other]),
            new AddressBookService($this->backend),
        ));
        $result = $runner->run($this->jobs->find($job->id, self::USER_ID), dryRun: false, force: false, triggerSource: 'manual');

        self::assertSame([], $result->errors);
        self::assertSame([$other->collectionHref . 'c1.vcf'], array_keys($other->resources), 'written to the new endpoint, and only there');
    }

    public function testStateLeftByAnOlderVersionIsNeverSentToAForeignHost(): void
    {
        // State written before moves reset it: an endpoint href on another
        // server. It must be treated as "no copy here", never requested.
        $this->seedHub($this->vcard('c1', 'Alice'));
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);
        $this->state->upsertContact($job->id, 'c1', [
            'endpoint_href' => 'https://old-provider.example/card/c1.vcf',
            'hub_href' => 'c1.vcf',
        ]);

        $result = $this->runner()->run($job, dryRun: false, force: false, triggerSource: 'manual');

        self::assertSame([], $result->errors);
        self::assertSame([$this->transport->collectionHref . 'c1.vcf'], array_keys($this->transport->resources));
    }

    public function testMovingAnEndpointToAnotherServerForgetsItsCollectionAndTheJobsState(): void
    {
        $this->seedHub($this->vcard('c1', 'Alice'));
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);
        $this->runner()->run($job, dryRun: false, force: false, triggerSource: 'manual');
        self::assertNotSame([], $this->state->allContacts($job->id));

        $service = new \OCA\ContactHub\Service\EndpointService(
            $this->endpoints,
            new FakeClientFactory([], new FakeHttpTransport('https://elsewhere.example/')),
            $this->jobs,
            $this->stateReset(),
        );
        $service->update($this->endpointId, self::USER_ID, ['base_url' => 'https://elsewhere.example/']);

        self::assertNull($this->endpoints->find($this->endpointId, self::USER_ID)->collectionHref, 'a capability test must pick the collection again');
        self::assertSame([], $this->state->allContacts($job->id));
    }

    public function testARenamedCategoryReplacesItsGroupOnTheEndpoint(): void
    {
        $this->seedHub($this->catVcard('c1', 'Alice', 'Family'));
        $this->seedHub($this->vcard('keeper', 'Kept Throughout'));
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);
        $runner = $this->runner();
        $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');

        $this->seedHub($this->catVcard('c1', 'Alice', 'Relatives'));
        $result = $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');

        self::assertSame([], $result->errors);
        self::assertSame(['Relatives'], array_keys($this->endpointGroups()), 'the old group is deleted, not left beside the new one');
        self::assertTrue($runner->run($job, dryRun: false, force: false, triggerSource: 'manual')->plan->isEmpty());
    }

    public function testAPullFromAnEndpointWithGroupsSettlesAndCarriesMembershipChanges(): void
    {
        $this->seedEndpoint('e1.vcf', $this->vcard('e1', 'Rita'));
        $this->seedEndpoint('e2.vcf', $this->vcard('e2', 'Rene'));
        $this->seedEndpoint('g1.vcf', $this->groupVcard('apple-group-1', 'Family', ['e1']));
        $job = $this->makeJob(SyncJob::FROM_ENDPOINT);
        $runner = $this->runner();
        $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');

        $second = $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');
        self::assertTrue($second->plan->isEmpty(), 'nothing to do, so no snapshot and no warning on every run');
        self::assertSame([], $second->warnings);

        // Add e2 to the group on the endpoint: only the group vCard changes.
        $this->seedEndpoint('g1.vcf', $this->groupVcard('apple-group-1', 'Family', ['e1', 'e2']));
        $third = $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');

        self::assertSame(['e2'], $third->plan->contactsUpdateAToB, 'only the member whose categories changed');
        self::assertSame(['Family'], Model::parse((string) $this->hubGet('e2'))->categories);
        self::assertTrue($runner->run($job, dryRun: false, force: false, triggerSource: 'manual')->plan->isEmpty());
    }

    public function testAContactAlreadyInNextcloudUnderAnotherFileNameIsTakenOver(): void
    {
        // An imported .vcf names its file at random, so the UID is there
        // under a URI the pull would never choose.
        $this->seedHub($this->vcard('e1', 'Rita, imported long ago'), 'a1b2c3-random.vcf');
        $this->seedEndpoint('e1.vcf', $this->vcard('e1', 'Rita'));
        $job = $this->makeJob(SyncJob::FROM_ENDPOINT);

        $result = $this->runner()->run($job, dryRun: false, force: false, triggerSource: 'manual');

        self::assertSame([], $result->errors);
        self::assertSame(['e1' => 'a1b2c3-random.vcf'], $this->hubUris(), 'updated in place, not created twice');
        self::assertSame('Rita', Model::parse((string) $this->hubGet('e1'))->fn);
        self::assertTrue($this->runner()->run($job, dryRun: false, force: false, triggerSource: 'manual')->plan->isEmpty());
    }

    public function testResolvingAConflictWritesWhatARunWouldHaveWritten(): void
    {
        $this->seedHub("BEGIN:VCARD\r\nVERSION:3.0\r\nUID:old\r\nFN:Alice Martin\r\nN:Martin;Alice;;;\r\nEMAIL:a@x.com\r\nEND:VCARD\r\n");
        $this->seedEndpoint('new.vcf', "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:new\r\nFN:Alice Martin\r\nN:Martin;Alice;;;\r\n"
            . "EMAIL:a@x.com\r\nPHOTO;VALUE=uri:https://endpoint.example/photo/1\r\nEND:VCARD\r\n");
        $this->seedEndpoint('g1.vcf', $this->groupVcard('apple-group-1', 'Family', ['new']));
        $id = $this->jobs->create(self::USER_ID, [
            'name' => 'J', 'address_book_id' => $this->addressBookId, 'endpoint_id' => $this->endpointId,
            'direction' => SyncJob::FROM_ENDPOINT, 'deletion_policy' => 'mirror', 'include_photos' => false,
        ]);
        $job = $this->jobs->find($id, self::USER_ID);
        $this->runner()->run($job, dryRun: false, force: false, triggerSource: 'manual');

        $row = $this->state->unresolvedConflicts($job->id)[0];
        $outcome = $this->conflictApplier()->apply($job, $row, ConflictApplier::ENDPOINT);

        self::assertSame(ConflictApplier::APPLIED, $outcome['outcome']);
        $merged = (string) $this->hubGet('new');
        self::assertStringNotContainsString('PHOTO', $merged, 'the job says photos stay out');
        self::assertSame(['Family'], Model::parse($merged)->categories, 'group membership arrives with it');
    }

    public function testAConflictWhoseNewContactWasDeletedIsClosedWithoutTouchingAnything(): void
    {
        $this->seedHub("BEGIN:VCARD\r\nVERSION:3.0\r\nUID:new\r\nFN:Alice Martin\r\nN:Martin;Alice;;;\r\nEMAIL:a@x.com\r\nEND:VCARD\r\n");
        $this->seedHub($this->vcard('keeper', 'Kept Throughout'));
        $preHref = $this->seedEndpoint('pre.vcf', "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:pre\r\nFN:Alice Martin\r\nN:Martin;Alice;;;\r\n"
            . "EMAIL:a@x.com\r\nNOTE:only here\r\nEND:VCARD\r\n");
        $job = $this->makeJob(SyncJob::TO_ENDPOINT, 'mirror');
        $runner = $this->runner();
        $runner->run($job, dryRun: false, force: false, triggerSource: 'cron');
        $row = $this->state->unresolvedConflicts($job->id)[0];

        // The user deletes the duplicate they had just made in Nextcloud,
        // then clicks "merge" on the conflict still on screen.
        $this->hubDelete('new');
        $outcome = $this->conflictApplier()->apply($job, $row, ConflictApplier::HUB);
        $runner->run($job, dryRun: false, force: false, triggerSource: 'cron');

        self::assertSame(ConflictApplier::OBSOLETE, $outcome['outcome']);
        self::assertStringContainsString('only here', $this->transport->resources[$preHref]['body'], 'never overwritten, never deleted');
    }

    public function testARunClosesConflictsThatNoLongerStandAndLiftsThePause(): void
    {
        $this->seedHub("BEGIN:VCARD\r\nVERSION:3.0\r\nUID:new\r\nFN:Alice Martin\r\nN:Martin;Alice;;;\r\nEMAIL:a@x.com\r\nEND:VCARD\r\n");
        $this->seedHub($this->vcard('keeper', 'Kept Throughout'));
        $this->seedEndpoint('pre.vcf', "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:pre\r\nFN:Alice Martin\r\nN:Martin;Alice;;;\r\nEMAIL:a@x.com\r\nEND:VCARD\r\n");
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);
        $this->runner()->run($job, dryRun: false, force: false, triggerSource: 'cron');
        $this->jobs->setConflictPaused($job->id, self::USER_ID, true);

        $this->hubDelete('new');
        $result = $this->runner()->run($this->jobs->find($job->id, self::USER_ID), dryRun: false, force: false, triggerSource: 'manual');

        self::assertSame(0, $this->state->countUnresolvedConflicts($job->id));
        self::assertFalse($this->jobs->find($job->id, self::USER_ID)->conflictPaused);
        self::assertCount(1, array_filter($result->warnings, static fn(string $w) => str_contains($w, 'no longer applied')));
    }

    public function testArchivingIntoNextcloudKeepsTheContactsGroups(): void
    {
        $href = $this->seedEndpoint('e1.vcf', $this->vcard('e1', 'Rita'));
        $this->seedEndpoint('keeper.vcf', $this->vcard('keeper', 'Kept Throughout'));
        $this->seedEndpoint('g1.vcf', $this->groupVcard('apple-group-1', 'Family', ['e1']));
        $job = $this->makeJob(SyncJob::FROM_ENDPOINT, 'archive');
        $runner = $this->runner();
        $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');

        unset($this->transport->resources[$href]);
        $result = $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');

        self::assertSame(1, $result->archived);
        $categories = Model::parse((string) $this->hubGet('e1'))->categories;
        sort($categories);
        self::assertSame(['Deleted', 'Family'], $categories, 'tagged as archived, and still in its group');
        self::assertTrue($runner->run($job, dryRun: false, force: false, triggerSource: 'manual')->plan->isEmpty());
    }

    public function testAUidThatNeedsEscapingLandsAsOneContactAndSettles(): void
    {
        $this->seedHub($this->vcard('x/y#z?w', 'Awkward'));
        $this->seedHub($this->vcard('a b@c', 'Spaced'));
        // The server lists what it stores percent-decoded: same resources,
        // other strings.
        $this->transport->listDecodedHrefs = true;
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);
        $runner = $this->runner();

        $first = $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');

        self::assertSame([], $first->errors);
        self::assertEqualsCanonicalizing(
            // A slash cannot be in a file name at all -- Apache refuses %2F
            // -- so that one gets a name derived from its UID instead.
            [$this->transport->collectionHref . 'uid-' . sha1('x/y#z?w') . '.vcf', $this->transport->collectionHref . 'a%20b@c.vcf'],
            array_keys($this->transport->resources),
            'each inside the collection, as a single resource',
        );

        $second = $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');
        self::assertSame([], $second->errors);
        self::assertTrue($second->plan->isEmpty(), 'a differently spelled listing still says both are there');
    }

    public function testAHrefStoredInAnotherSpellingIsStillRecognised(): void
    {
        $this->seedHub($this->vcard('c1', 'Alice'));
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);
        $runner = $this->runner();
        $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');

        // As an older version might have stored it.
        $this->state->upsertContact($job->id, 'c1', ['endpoint_href' => 'https://ENDPOINT.example:443/home/card/c1.vcf']);
        $result = $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');

        self::assertSame([], $result->errors);
        self::assertTrue($result->plan->isEmpty());
    }

    public function testRestoringASnapshotUnderAPullJobFollowsOneRule(): void
    {
        $e1 = $this->seedEndpoint('e1.vcf', $this->vcard('e1', 'Rita v1'));
        $e2 = $this->seedEndpoint('e2.vcf', $this->vcard('e2', 'Rene'));
        $job = $this->makeJob(SyncJob::FROM_ENDPOINT, 'mirror');
        $runner = $this->runner();
        $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');
        $backups = $this->backupService();
        $snapshot = $backups->snapshot($this->addressBookId, self::USER_ID, 'manual');

        // A run the user then wants to undo: e1 edited, e2 deleted, e3 added.
        $this->transport->resources[$e1] = ['body' => $this->vcard('e1', 'Rita v2'), 'etag' => '"v2"'];
        unset($this->transport->resources[$e2]);
        $this->seedEndpoint('e3.vcf', $this->vcard('e3', 'New'));
        $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');

        $presenter = new \OCA\ContactHub\Service\BackupPresenter($backups, new AddressBookService($this->backend), $this->jobs, $this->state);
        $presenter->restore($snapshot, self::USER_ID, mirror: true);
        self::assertSame('Rita v1', Model::parse((string) $this->hubGet('e1'))->fn);
        self::assertNull($this->hubGet('e3'));
        self::assertNotNull($this->hubGet('e2'));

        $after = $runner->run($job, dryRun: false, force: false, triggerSource: 'manual');

        self::assertSame([], $after->errors);
        // What the endpoint still has, it wins -- the edit as much as the addition.
        self::assertSame('Rita v2', Model::parse((string) $this->hubGet('e1'))->fn, 'used to stay reverted');
        self::assertNotNull($this->hubGet('e3'));
        // What it no longer has stays restored.
        self::assertNotNull($this->hubGet('e2'));
        self::assertTrue($runner->run($job, dryRun: false, force: false, triggerSource: 'manual')->plan->isEmpty());
    }

    public function testTheSnapshotListSaysWhichJobsRunASnapshotPreceded(): void
    {
        // The restore screen names the job ("Before a run of ...") from this.
        $this->seedHub($this->vcard('c1', 'Alice'));
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);
        $backups = $this->backupService();
        $this->runner(withBackups: true)->run($job, dryRun: false, force: false, triggerSource: 'manual');
        $backups->snapshot($this->addressBookId, self::USER_ID, 'manual');

        $presenter = new \OCA\ContactHub\Service\BackupPresenter($backups, new AddressBookService($this->backend), $this->jobs, $this->state);
        $byReason = array_column($presenter->listFor($this->addressBookId, self::USER_ID), 'job_id', 'reason');

        self::assertSame(['manual' => null, 'pre_sync' => $job->id], array_intersect_key($byReason, ['manual' => 0, 'pre_sync' => 0]));
    }

    // ------------------------------------------------------------- helpers

    private function jobService(): JobService
    {
        return new JobService($this->jobs, $this->state, new AddressBookService($this->backend), $this->endpoints, $this->stateReset());
    }

    /** A book owned by someone else, shared with the test user read-only. */
    private function createReadOnlyShare(): int
    {
        $bookId = $this->backend->createAddressBook('principals/users/chubowner', 'owned-' . bin2hex(random_bytes(4)), [
            '{DAV:}displayname' => 'Owner Book',
        ]);
        $book = new \OCA\DAV\CardDAV\AddressBook(
            $this->backend,
            $this->backend->getAddressBookById($bookId),
            \OCP\Server::get(\OCP\L10N\IFactory::class)->get('dav'),
        );
        $this->backend->updateShares($book, [['href' => 'principal:' . self::PRINCIPAL, 'readOnly' => true]], []);

        return $bookId;
    }

    private function catVcard(string $uid, string $fn, string $categories): string
    {
        return "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:{$uid}\r\nFN:{$fn}\r\nCATEGORIES:{$categories}\r\nEND:VCARD\r\n";
    }

    /** @param string[] $members */
    private function groupVcard(string $uid, string $name, array $members): string
    {
        $text = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:{$uid}\r\nFN:{$name}\r\nX-ADDRESSBOOKSERVER-KIND:group\r\n";
        foreach ($members as $member) {
            $text .= "X-ADDRESSBOOKSERVER-MEMBER:urn:uuid:{$member}\r\n";
        }

        return $text . "END:VCARD\r\n";
    }

    /** @return array<string, string[]> group name => member uids, as on the endpoint */
    private function endpointGroups(): array
    {
        $groups = [];
        foreach ($this->transport->resources as $res) {
            $parsed = Model::parse($res['body']);
            if ($parsed instanceof Group) {
                $groups[$parsed->name] = $parsed->memberUids;
            }
        }
        ksort($groups);

        return $groups;
    }
}
