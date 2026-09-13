<?php

declare(strict_types=1);

namespace OCA\ContactHub\Tests\Integration;

use OCA\ContactHub\Db\BackupMapper;
use OCA\ContactHub\Files\HubFolder;
use OCA\ContactHub\Db\EndpointMapper;
use OCA\ContactHub\Db\JobMapper;
use OCA\ContactHub\Db\StateMapper;
use OCA\ContactHub\Service\AddressBookService;
use OCA\ContactHub\Sync\BackupService;
use OCA\ContactHub\Sync\ConflictApplier;
use OCA\ContactHub\Sync\JobLock;
use OCA\ContactHub\Sync\Runner;
use OCA\ContactHub\Sync\SideFactory;
use OCA\ContactHub\Sync\SyncJob;
use OCA\ContactHub\Tests\Support\FakeClientFactory;
use OCA\ContactHub\Tests\Support\FakeHttpTransport;
use OCA\ContactHub\VCard\Model;
use OCA\DAV\CardDAV\CardDavBackend;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IRootFolder;
use OCP\IUserManager;
use OCP\IDBConnection;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;

/**
 * Shared harness for tests that drive the real Runner, Planner, mappers and
 * CardDavBackend against a FakeHttpTransport standing in for the remote
 * endpoint. No network, but every layer above the socket is genuine.
 *
 * This is what HubTestCase became. The hub is now a Nextcloud address book
 * rather than this app's own tables, so seedHub() writes a card through
 * CardDavBackend and the assertions read it back the same way -- which is
 * precisely the point, since that path is where readBlob() rewrites content.
 *
 * Isolation is per test: a fresh address book with a unique URI, plus a wipe
 * of this app's own tables. A fresh book rather than emptying a shared one
 * keeps the tests from interfering with anything else in the dev instance.
 *
 * Mostly no Nextcloud user is created. CardDavBackend keys address books on a
 * principal URI string and never checks that the user behind it exists, and
 * the mappers store user_id as an opaque string, so a synthetic principal
 * exercises every code path a real one would without the cost and the
 * side effects of user management.
 *
 * The exception is anything touching the user's Files -- snapshots and
 * settings exports both live in "Contacts Hub" now -- because IRootFolder
 * resolves a home directory and there is no home without an account.
 * ensureRealUser() creates one on demand and leaves it: provisioning and
 * destroying a home per test costs more than everything else here combined.
 *
 * Every job is hub <-> endpoint, so "side A" is the hub for to_endpoint
 * jobs and the endpoint for from_endpoint ones. Tests should express
 * intent with seedHub()/seedEndpoint() rather than thinking in A/B.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected const string USER_ID = 'chubtest';
    protected const string PRINCIPAL = 'principals/users/chubtest';

    protected IDBConnection $db;
    protected CardDavBackend $backend;
    protected StateMapper $state;
    protected EndpointMapper $endpoints;
    protected JobMapper $jobs;
    protected BackupMapper $backupMapper;
    protected JobLock $locks;
    protected FakeHttpTransport $transport;

    protected int $addressBookId;
    protected int $endpointId;

    private string $addressBookUri;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = \OCP\Server::get(IDBConnection::class);
        $this->backend = \OCP\Server::get(CardDavBackend::class);

        $crypto = \OCP\Server::get(ICrypto::class);
        $this->state = new StateMapper($this->db);
        $this->endpoints = new EndpointMapper($this->db, $crypto);
        $this->backupMapper = new BackupMapper($this->db);
        $this->jobs = new JobMapper($this->db, $this->endpoints, new AddressBookService($this->backend));
        $this->locks = new JobLock($this->db);

        $this->wipeOwnTables();

        $this->addressBookUri = 'chub-' . bin2hex(random_bytes(6));
        $this->addressBookId = $this->backend->createAddressBook(
            self::PRINCIPAL,
            $this->addressBookUri,
            ['{DAV:}displayname' => 'Test Book'],
        );

        $this->transport = new FakeHttpTransport('https://endpoint.example/');
        $this->endpointId = $this->endpoints->create(self::USER_ID, [
            'name' => 'Endpoint',
            'preset' => 'generic',
            'base_url' => $this->transport->base,
            'username' => 'user',
            'password' => 'pass',
            'collection_href' => $this->transport->collectionHref,
        ]);
    }

    protected function tearDown(): void
    {
        try {
            $this->backend->deleteAddressBook($this->addressBookId);
        } catch (\Throwable) {
            // A test may have deleted it already; nothing to clean up.
        }
        $this->wipeOwnTables();
        $this->wipeHubFolder();

        parent::tearDown();
    }

    /**
     * Remove only the test user's rows.
     *
     * This used to clear the tables wholesale, which is fine in a suite that
     * owns its database and actively harmful otherwise: the dev container is
     * also where you click around, and running the suite silently deleted an
     * endpoint and job that had been set up by hand. Scoping by user makes
     * the suite safe to run against an instance someone is also using.
     *
     * Plain DELETEs, not TRUNCATE: TRUNCATE is not portable and, on MySQL,
     * implicitly commits, which would break any test wrapping itself in a
     * transaction.
     */
    private function wipeOwnTables(): void
    {
        // Children first: state, runs and conflicts hang off a job, and run
        // items off a run, with no database-level cascades to lean on.
        foreach ($this->testUserJobIds() as $jobId) {
            $this->state->deleteEverythingForJob($jobId);
        }

        foreach (['contacthub_jobs', 'contacthub_endpoints', 'contacthub_backups'] as $table) {
            $qb = $this->db->getQueryBuilder();
            $qb->delete($table)
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter(self::USER_ID)));
            $qb->executeStatement();
        }
    }

    /** @return list<int> */
    private function testUserJobIds(): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')->from('contacthub_jobs')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter(self::USER_ID)));

        $result = $qb->executeQuery();
        $ids = array_map(static fn(array $r): int => (int) $r['id'], $result->fetchAll());
        $result->closeCursor();

        return $ids;
    }

    // ------------------------------------------------------------ factories

    protected function sideFactory(): SideFactory
    {
        return new SideFactory(
            $this->backend,
            new FakeClientFactory([$this->endpointId => $this->transport]),
        );
    }

    protected function runner(bool $withBackups = false): Runner
    {
        return new Runner(
            $this->state,
            $this->jobs,
            $this->locks,
            $this->sideFactory(),
            $withBackups ? $this->backupService() : null,
        );
    }

    protected function conflictApplier(): ConflictApplier
    {
        return new ConflictApplier($this->state, $this->sideFactory(), $this->hubFolder());
    }

    protected function backupService(int $retentionDays = 30): BackupService
    {
        // Snapshots go into the user's own Files now, so this is the one
        // service that needs a *real* Nextcloud user rather than the
        // synthetic principal everything else gets by with: IRootFolder
        // resolves a home directory, and there is no home without an account.
        $this->ensureRealUser();

        return new BackupService(
            $this->backend,
            $this->backupMapper,
            \OCP\Server::get(IAppDataFactory::class)->get('contacthub'),
            $this->hubFolder(),
            null,
            $retentionDays,
        );
    }

    protected function hubFolder(): HubFolder
    {
        return new HubFolder(\OCP\Server::get(IRootFolder::class));
    }

    /**
     * Create the test account if it is not there, and leave it.
     *
     * Deliberately not torn down. Creating and deleting a user provisions and
     * then destroys a home directory, which is far more expensive than every
     * other thing these tests do put together, and account deletion fires
     * listeners across the whole instance including this app's own. One
     * account left behind in a throwaway dev container is the cheaper side of
     * that trade; each test still clears its own rows and files.
     */
    protected function ensureRealUser(): void
    {
        $users = \OCP\Server::get(IUserManager::class);
        if (!$users->userExists(self::USER_ID)) {
            $users->createUser(self::USER_ID, bin2hex(random_bytes(16)));
        }
    }

    /** Remove the "Contacts Hub" folder from the test user's files, if present. */
    protected function wipeHubFolder(): void
    {
        try {
            $home = \OCP\Server::get(IRootFolder::class)->getUserFolder(self::USER_ID);
            if ($home->nodeExists(HubFolder::ROOT)) {
                $home->get(HubFolder::ROOT)->delete();
            }
        } catch (\Throwable) {
            // No account, or no home yet: nothing to clean up.
        }
    }

    protected function makeJob(
        string $direction = SyncJob::TO_ENDPOINT,
        string $deletionPolicy = 'mirror',
    ): SyncJob {
        $id = $this->jobs->create(self::USER_ID, [
            'name' => 'Test Job',
            'address_book_id' => $this->addressBookId,
            'endpoint_id' => $this->endpointId,
            'direction' => $direction,
            'deletion_policy' => $deletionPolicy,
        ]);

        $job = $this->jobs->find($id, self::USER_ID);
        self::assertNotNull($job);

        return $job;
    }

    // ---------------------------------------------------------------- seeds

    /**
     * Put a card in the Nextcloud address book, returning its URI.
     *
     * Upserts, matching the old hub repository's save(): several tests seed
     * the same UID twice to simulate an edit, and CardDavBackend rejects a
     * duplicate UID within an address book rather than replacing it.
     */
    protected function seedHub(string $vcard, ?string $uri = null): string
    {
        $uid = Model::parse($vcard)->uid;

        $existing = $this->hubUris()[$uid] ?? null;
        if ($existing !== null) {
            $this->backend->updateCard($this->addressBookId, $existing, $vcard);

            return $existing;
        }

        $uri ??= $uid . '.vcf';
        $this->backend->createCard($this->addressBookId, $uri, $vcard);

        return $uri;
    }

    /** Put a raw resource on the remote endpoint, returning its href. */
    protected function seedEndpoint(string $filename, string $vcard): string
    {
        $href = $this->transport->collectionHref . $filename;
        $this->transport->resources[$href] = ['body' => $vcard, 'etag' => '"seed-' . $filename . '"'];

        return $href;
    }

    protected function vcard(string $uid, string $fn, ?string $rev = null): string
    {
        $revLine = $rev !== null ? "REV:{$rev}\r\n" : '';

        return "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:{$uid}\r\nFN:{$fn}\r\n{$revLine}END:VCARD\r\n";
    }

    // ------------------------------------------------------- hub inspection

    /** The card body Nextcloud currently holds for $uid, or null. */
    protected function hubGet(string $uid): ?string
    {
        return $this->hubByUid()[$uid] ?? null;
    }

    protected function hubDelete(string $uid): void
    {
        $uri = $this->hubUris()[$uid] ?? null;
        if ($uri !== null) {
            $this->backend->deleteCard($this->addressBookId, $uri);
        }
    }

    /** @return array<string, string> uid => content hash, as the diff sees it */
    protected function hubHashes(): array
    {
        return array_map(Model::textHash(...), $this->hubByUid());
    }

    /** @return array<string, string> uid => DAV uri */
    protected function hubUris(): array
    {
        $out = [];
        foreach ($this->backend->getCards($this->addressBookId) as $card) {
            try {
                $out[Model::parse((string) $card['carddata'])->uid] = (string) $card['uri'];
            } catch (\Throwable) {
                // Unparseable cards are not what any assertion here is about.
            }
        }

        return $out;
    }

    // ------------------------------------------------------- run inspection

    /** @return list<array<string, mixed>> every run row for the job, oldest first */
    protected function runRowsFor(int $jobId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from(StateMapper::RUNS)
            ->where($qb->expr()->eq('job_id', $qb->createNamedParameter($jobId)))
            ->orderBy('id');

        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        return $rows;
    }

    protected function runCountFor(int $jobId): int
    {
        return count($this->runRowsFor($jobId));
    }

    /**
     * Force a job's run rows into a state the Runner would not normally
     * leave behind, to simulate a process killed mid-run.
     */
    protected function forceRunStatus(
        int $jobId,
        string $status,
        ?bool $dryRun = null,
        bool $clearFinishedAt = false,
    ): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update(StateMapper::RUNS)
            ->set('status', $qb->createNamedParameter($status))
            ->where($qb->expr()->eq('job_id', $qb->createNamedParameter($jobId)));

        if ($clearFinishedAt) {
            $qb->set('finished_at', $qb->createNamedParameter(null));
        }
        if ($dryRun !== null) {
            $qb->andWhere($qb->expr()->eq(
                'dry_run',
                $qb->createNamedParameter($dryRun, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL),
            ));
        }

        $qb->executeStatement();
    }

    // ----------------------------------------------------------- assertions

    /** @return array<string, string> uid => card body currently in Nextcloud */
    protected function hubByUid(): array
    {
        return $this->byUid(array_map(
            static fn(array $card): string => (string) $card['carddata'],
            $this->backend->getCards($this->addressBookId),
        ));
    }

    /** @return array<string, string> uid => vCard body currently on the endpoint */
    protected function endpointByUid(): array
    {
        return $this->byUid(array_map(
            static fn(array $resource): string => (string) $resource['body'],
            array_values($this->transport->resources),
        ));
    }

    /**
     * @param list<string> $bodies
     * @return array<string, string>
     */
    private function byUid(array $bodies): array
    {
        $out = [];
        foreach ($bodies as $body) {
            try {
                $out[Model::parse($body)->uid] = $body;
            } catch (\Throwable) {
                // Unparseable resources are not what any assertion here is about.
            }
        }

        return $out;
    }
}
