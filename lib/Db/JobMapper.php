<?php

declare(strict_types=1);

namespace OCA\ContactHub\Db;

use OCA\ContactHub\Service\AddressBookService;
use OCA\ContactHub\Sync\Endpoint;
use OCA\ContactHub\Sync\SyncJob;
use OCP\IDBConnection;

/**
 * Sync jobs, scoped to their owning user.
 *
 * The address book name is no longer a JOIN. It used to come from this app's
 * own address_books table; now it belongs to Nextcloud, so it is resolved
 * through AddressBookService per row. That is a lookup rather than a join,
 * which is why hydrating a list caches names for the duration of the call.
 */
class JobMapper extends Mapper
{
    public const string TABLE = 'contacthub_jobs';

    /** @var array<int, string> address book id => display name, per request */
    private array $nameCache = [];

    public function __construct(
        IDBConnection $db,
        private readonly EndpointMapper $endpoints,
        private readonly AddressBookService $addressBooks,
    ) {
        parent::__construct($db);
    }

    public function find(int $id, string $userId): ?SyncJob
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Job names for a user, without hydrating.
     *
     * hydrate() resolves each job's endpoint (decrypting its password) and its
     * address book name, none of which a caller checking for a name clash
     * needs.
     *
     * @return list<string>
     */
    public function namesForUser(string $userId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('name')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        $result = $qb->executeQuery();
        $names = array_map(static fn(array $row): string => (string) $row['name'], $result->fetchAll());
        $result->closeCursor();

        return $names;
    }

    /** @return SyncJob[] */
    public function allForUser(string $userId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->orderBy('name');

        return $this->hydrateAll($qb->executeQuery());
    }

    /** @return SyncJob[] jobs whose hub side is $addressBookId */
    public function forAddressBook(int $addressBookId, string $userId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('address_book_id', $qb->createNamedParameter($addressBookId)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->orderBy('name');

        return $this->hydrateAll($qb->executeQuery());
    }

    /**
     * Every enabled job across every user, oldest run first.
     *
     * Deliberately unscoped: the background job runs as no one in particular
     * and must see all users' work. This is the only unscoped read in the
     * app, and nothing reachable from a web request may call it.
     *
     * Whether a job is actually *due* is decided in PHP against
     * interval_seconds rather than in SQL, because a run that is merely
     * paused must be resumed regardless of its interval.
     *
     * @return SyncJob[]
     */
    public function allEnabled(): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('enabled', $qb->createNamedParameter(true, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL)))
            ->orderBy('last_run_at', 'ASC');

        return $this->hydrateAll($qb->executeQuery());
    }

    /**
     * @param \OCP\DB\IResult $result
     * @return SyncJob[]
     */
    private function hydrateAll($result): array
    {
        $rows = $result->fetchAll();
        $result->closeCursor();

        $jobs = [];
        foreach ($rows as $row) {
            $jobs[] = $this->hydrate($row);
        }

        return $jobs;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): SyncJob
    {
        $userId = (string) $row['user_id'];
        $endpoint = $this->endpoints->find((int) $row['endpoint_id'], $userId);
        if ($endpoint === null) {
            throw new \RuntimeException("Sync job #{$row['id']} references a missing endpoint.");
        }

        $addressBookId = (int) $row['address_book_id'];
        $this->nameCache[$addressBookId] ??= $this->addressBooks->displayName($addressBookId);

        return new SyncJob(
            id: (int) $row['id'],
            userId: $userId,
            name: (string) $row['name'],
            addressBookId: $addressBookId,
            addressBookName: $this->nameCache[$addressBookId],
            endpoint: $endpoint,
            direction: (string) $row['direction'],
            deletionPolicy: (string) $row['deletion_policy'],
            archiveGroupName: (string) $row['archive_group_name'],
            includePhotos: (bool) $row['include_photos'],
            intervalSeconds: (int) $row['interval_seconds'],
            enabled: (bool) $row['enabled'],
            lastRunAt: $row['last_run_at'],
        );
    }

    /**
     * @param array{name: string, address_book_id: int, endpoint_id: int, direction: string,
     *     deletion_policy?: string, archive_group_name?: string, include_photos?: bool,
     *     interval_seconds?: int, enabled?: bool} $data
     */
    public function create(string $userId, array $data): int
    {
        $now = $this->now();
        $qb = $this->db->getQueryBuilder();
        $qb->insert(self::TABLE)->values([
            'user_id' => $qb->createNamedParameter($userId),
            'name' => $qb->createNamedParameter($data['name']),
            'address_book_id' => $qb->createNamedParameter($data['address_book_id']),
            'endpoint_id' => $qb->createNamedParameter($data['endpoint_id']),
            'direction' => $qb->createNamedParameter($data['direction']),
            'deletion_policy' => $qb->createNamedParameter($data['deletion_policy'] ?? 'mirror'),
            'archive_group_name' => $qb->createNamedParameter($data['archive_group_name'] ?? 'Deleted'),
            'include_photos' => $qb->createNamedParameter($data['include_photos'] ?? true, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL),
            'interval_seconds' => $qb->createNamedParameter($data['interval_seconds'] ?? 1800),
            'enabled' => $qb->createNamedParameter($data['enabled'] ?? true, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL),
            'created_at' => $qb->createNamedParameter($now),
            'updated_at' => $qb->createNamedParameter($now),
        ]);
        $qb->executeStatement();

        return $qb->getLastInsertId();
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, string $userId, array $data): void
    {
        $boolColumns = ['include_photos', 'enabled'];
        $columns = [
            'name', 'address_book_id', 'endpoint_id', 'direction', 'deletion_policy',
            'archive_group_name', 'include_photos', 'interval_seconds', 'enabled',
        ];

        $qb = $this->db->getQueryBuilder();
        $qb->update(self::TABLE);

        $touched = false;
        foreach ($columns as $column) {
            if (!array_key_exists($column, $data)) {
                continue;
            }
            $qb->set($column, in_array($column, $boolColumns, true)
                ? $qb->createNamedParameter((bool) $data[$column], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL)
                : $qb->createNamedParameter($data[$column]));
            $touched = true;
        }
        if (!$touched) {
            return;
        }

        $qb->set('updated_at', $qb->createNamedParameter($this->now()))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $qb->executeStatement();
    }

    public function delete(int $id, string $userId): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $qb->executeStatement();
    }

    /**
     * Job ids only, without hydrating.
     *
     * hydrate() resolves each job's endpoint and throws if it is gone, so
     * anything that only needs ids must not go through allForUser() -- most
     * pointedly the deleted-user cleanup, which removes endpoints first and
     * would otherwise be unable to enumerate the jobs it still has to clear.
     *
     * @return list<int>
     */
    public function idsForUser(string $userId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        $result = $qb->executeQuery();
        $ids = array_map(static fn(array $row): int => (int) $row['id'], $result->fetchAll());
        $result->closeCursor();

        return $ids;
    }

    public function touchLastRun(int $id): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->update(self::TABLE)
            ->set('last_run_at', $qb->createNamedParameter($this->now()))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
        $qb->executeStatement();
    }

    public function deleteAllForUser(string $userId): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $qb->executeStatement();
    }
}
