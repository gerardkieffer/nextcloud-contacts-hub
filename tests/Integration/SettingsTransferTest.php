<?php

declare(strict_types=1);

namespace OCA\ContactHub\Tests\Integration;

use OCA\ContactHub\Files\HubFolder;
use OCA\ContactHub\Service\AddressBookService;
use OCA\ContactHub\Service\EndpointService;
use OCA\ContactHub\Service\JobService;
use OCA\ContactHub\Service\SettingsTransfer;
use OCA\ContactHub\Sync\SyncJob;
use OCA\ContactHub\Tests\Support\FakeClientFactory;
use OCA\ContactHub\Tests\Support\FakeHttpTransport;

/**
 * Exporting and re-importing endpoint and job configuration.
 *
 * The properties worth pinning are the destructive ones. An import that
 * quietly replaced a working endpoint with an older copy of itself, or that
 * switched on a job pointed at data this instance had never seen, would be
 * a data-loss bug dressed as a convenience feature.
 */
final class SettingsTransferTest extends IntegrationTestCase
{
    private FakeHttpTransport $probe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureRealUser();
        // Must answer at the same base URL the harness endpoint carries, or
        // every import fails at the credential check for the wrong reason.
        $this->probe = new FakeHttpTransport($this->transport->base);
    }

    private function transfer(): SettingsTransfer
    {
        $books = new AddressBookService($this->backend);
        $endpointService = new EndpointService($this->endpoints, new FakeClientFactory([], $this->probe));

        return new SettingsTransfer(
            $this->endpoints,
            $this->jobs,
            $endpointService,
            new JobService($this->jobs, $this->state, $books, $this->endpoints),
            $books,
            $this->hubFolder(),
        );
    }

    private function exportPayload(bool $withPasswords = false): string
    {
        $result = $this->transfer()->export(self::USER_ID, $withPasswords);
        $raw = $this->hubFolder()->read(self::USER_ID, HubFolder::SETTINGS, $result['name']);
        self::assertNotNull($raw, 'the export must be readable back from the user files');

        return $raw;
    }

    public function testExportLandsInTheUsersFilesWhereTheyCanReachIt(): void
    {
        $result = $this->transfer()->export(self::USER_ID);

        self::assertStringStartsWith(HubFolder::ROOT . '/' . HubFolder::SETTINGS . '/', $result['path']);
        self::assertSame(1, $result['endpoints'], 'the harness endpoint');
        self::assertNotNull($this->hubFolder()->read(self::USER_ID, HubFolder::SETTINGS, $result['name']));
    }

    public function testPasswordsAreAbsentUnlessAskedFor(): void
    {
        $withoutPasswords = json_decode($this->exportPayload(false), true);
        self::assertFalse($withoutPasswords['includes_passwords']);
        self::assertArrayNotHasKey('password', $withoutPasswords['endpoints'][0]);

        $withPasswords = json_decode($this->exportPayload(true), true);
        self::assertTrue($withPasswords['includes_passwords']);
        self::assertSame('pass', $withPasswords['endpoints'][0]['password']);
    }

    public function testJobsReferenceEndpointsByNameNotByLocalId(): void
    {
        // Ids mean nothing on the far side of an export; names are what the
        // user typed and what the import can relink against.
        $this->makeJob(SyncJob::TO_ENDPOINT);

        $payload = json_decode($this->exportPayload(), true);

        self::assertSame('Endpoint', $payload['jobs'][0]['endpoint_name']);
        self::assertArrayNotHasKey('endpoint_id', $payload['jobs'][0]);
        self::assertSame('Test Book', $payload['jobs'][0]['address_book_name']);
    }

    public function testImportingIntoAnEmptyInstanceRecreatesEverything(): void
    {
        $this->makeJob(SyncJob::TO_ENDPOINT);
        $payload = $this->exportPayload(true);

        // Wipe the configuration, keeping the address book, and import.
        $this->wipeConfiguration();
        $outcome = $this->transfer()->import(self::USER_ID, $payload, null);

        self::assertSame(['Endpoint'], $outcome['created']['endpoints']);
        self::assertSame(['Test Job'], $outcome['created']['jobs']);
        self::assertSame([], $outcome['failed']['endpoints']);
        self::assertSame([], $outcome['failed']['jobs']);
    }

    public function testImportedJobsArriveSwitchedOff(): void
    {
        // A file can describe a setup pointing at data this instance has
        // never seen, and a mirror deletion policy is the expensive one to
        // get wrong.
        $this->makeJob(SyncJob::TO_ENDPOINT);
        $payload = $this->exportPayload(true);
        $this->wipeConfiguration();

        $this->transfer()->import(self::USER_ID, $payload, null);

        $jobs = $this->jobs->allForUser(self::USER_ID);
        self::assertCount(1, $jobs);
        self::assertFalse($jobs[0]->enabled, 'an imported job must not start running on its own');
    }

    public function testAnExistingNameIsSkippedRatherThanOverwritten(): void
    {
        $this->makeJob(SyncJob::TO_ENDPOINT);
        $payload = $this->exportPayload(true);

        // Import over the top of the very configuration it came from.
        $outcome = $this->transfer()->import(self::USER_ID, $payload, null);

        self::assertSame([], $outcome['created']['endpoints']);
        self::assertSame('Endpoint', $outcome['skipped']['endpoints'][0]['name']);
        self::assertSame('Test Job', $outcome['skipped']['jobs'][0]['name']);
        self::assertCount(1, $this->endpoints->allForUser(self::USER_ID), 'no duplicate was created');
    }

    public function testAnEndpointWithNoPasswordFailsInsteadOfBeingCreatedBroken(): void
    {
        $payload = $this->exportPayload(false);
        $this->wipeConfiguration();

        $outcome = $this->transfer()->import(self::USER_ID, $payload, null);

        self::assertSame([], $outcome['created']['endpoints']);
        self::assertSame('Endpoint', $outcome['failed']['endpoints'][0]['name']);
        self::assertSame([], $this->endpoints->allForUser(self::USER_ID));
    }

    public function testASuppliedPasswordCompletesAPasswordlessExport(): void
    {
        $payload = $this->exportPayload(false);
        $this->wipeConfiguration();

        $outcome = $this->transfer()->import(self::USER_ID, $payload, null, ['Endpoint' => 'typed-by-hand']);

        self::assertSame(['Endpoint'], $outcome['created']['endpoints']);
    }

    public function testASuppliedPasswordIsVerifiedLikeAnyOther(): void
    {
        // Import is a normal way to end up with a stale password, which makes
        // the credential check more useful here than anywhere else.
        $payload = $this->exportPayload(false);
        $this->wipeConfiguration();
        $this->probe->rejectCredentials = true;

        $outcome = $this->transfer()->import(self::USER_ID, $payload, null, ['Endpoint' => 'wrong']);

        self::assertSame([], $outcome['created']['endpoints']);
        self::assertStringContainsString('rejected', $outcome['failed']['endpoints'][0]['reason']);
    }

    public function testAJobWhoseAddressBookIsMissingFailsWithAUsableReason(): void
    {
        $this->makeJob(SyncJob::TO_ENDPOINT);
        $payload = json_decode($this->exportPayload(true), true);
        $payload['jobs'][0]['address_book_uri'] = 'gone';
        $payload['jobs'][0]['address_book_name'] = 'Not Here';
        $this->wipeConfiguration();

        $outcome = $this->transfer()->import(self::USER_ID, json_encode($payload), null);

        self::assertSame(['Endpoint'], $outcome['created']['endpoints']);
        self::assertStringContainsString('Not Here', $outcome['failed']['jobs'][0]['reason']);
    }

    public function testAFileFromSomethingElseIsRefused(): void
    {
        $this->expectExceptionMessageMatches('/not a Contacts Hub settings export/');
        $this->transfer()->inspect(self::USER_ID, '{"format":"something-else"}', null);
    }

    public function testInspectSaysWhichEndpointsStillNeedAPassword(): void
    {
        $payload = $this->exportPayload(false);
        $this->wipeConfiguration();

        $preview = $this->transfer()->inspect(self::USER_ID, $payload, null);

        self::assertFalse($preview['includes_passwords']);
        self::assertFalse($preview['endpoints'][0]['has_password']);
        self::assertFalse($preview['endpoints'][0]['exists']);
    }

    public function testImportCanReadAFileFromTheUsersOwnFiles(): void
    {
        $result = $this->transfer()->export(self::USER_ID, true);
        $this->wipeConfiguration();

        $outcome = $this->transfer()->import(self::USER_ID, null, $result['path']);

        self::assertSame(['Endpoint'], $outcome['created']['endpoints']);
    }

    /** Clear endpoints and jobs, leaving the address book and the files alone. */
    private function wipeConfiguration(): void
    {
        foreach ($this->jobs->idsForUser(self::USER_ID) as $jobId) {
            $this->state->deleteEverythingForJob($jobId);
        }
        $this->jobs->deleteAllForUser(self::USER_ID);
        $this->endpoints->deleteAllForUser(self::USER_ID);
    }
}
