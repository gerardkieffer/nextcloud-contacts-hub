<?php

declare(strict_types=1);

namespace OCA\ContactHub\Db;

use OCA\ContactHub\Sync\Endpoint;
use OCP\IDBConnection;
use OCP\Security\ICrypto;

/**
 * CardDAV endpoints, scoped to their owning user.
 *
 * Passwords are encrypted with OCP\Security\ICrypto, which keys off the
 * instance secret. That replaces the old libsodium wrapper and its
 * data/secret.key file: Nextcloud already manages a secret, backs it up with
 * config.php, and rotating it is an administrator's decision rather than
 * this app's problem.
 *
 * Every method takes a $userId and every query filters on it. There is
 * deliberately no unscoped find(): the previous app was implicitly
 * single-tenant, and an unscoped lookup left lying around is exactly how a
 * multi-tenant app grows an access-control hole.
 */
class EndpointMapper extends Mapper
{
    public const string TABLE = 'contacthub_endpoints';

    public function __construct(
        IDBConnection $db,
        private readonly ICrypto $crypto,
    ) {
        parent::__construct($db);
    }

    public function find(int $id, string $userId): ?Endpoint
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
     * Endpoint names for a user, without hydrating anything.
     *
     * hydrate() decrypts the stored password on every row, so asking for whole
     * Endpoint objects purely to read names does authenticated decryption once
     * per endpoint for nothing, and puts credentials in memory that the caller
     * never wanted.
     *
     * @return array<string, int> name => id
     */
    public function idsByNameForUser(string $userId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'name')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        $result = $qb->executeQuery();
        $out = [];
        foreach ($result->fetchAll() as $row) {
            $out[(string) $row['name']] = (int) $row['id'];
        }
        $result->closeCursor();

        return $out;
    }

    /** @return Endpoint[] */
    public function allForUser(string $userId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->orderBy('name');

        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        return array_map($this->hydrate(...), $rows);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Endpoint
    {
        return new Endpoint(
            id: (int) $row['id'],
            name: (string) $row['name'],
            preset: (string) $row['preset'],
            baseUrl: (string) $row['base_url'],
            username: (string) $row['username'],
            password: $this->crypto->decrypt((string) $row['password_encrypted']),
            collectionHref: $row['collection_href'],
            collectionName: $row['collection_name'],
            groupStrategy: (string) $row['group_strategy'],
            capabilities: $row['capabilities_json'] !== null
                ? (json_decode((string) $row['capabilities_json'], true) ?? [])
                : [],
        );
    }

    /**
     * @param array{name: string, preset: string, base_url: string, username: string, password: string,
     *     collection_href?: ?string, collection_name?: ?string, group_strategy?: string} $data
     */
    public function create(string $userId, array $data): int
    {
        $now = $this->now();
        $qb = $this->db->getQueryBuilder();
        $qb->insert(self::TABLE)->values([
            'user_id' => $qb->createNamedParameter($userId),
            'name' => $qb->createNamedParameter($data['name']),
            'preset' => $qb->createNamedParameter($data['preset']),
            'base_url' => $qb->createNamedParameter($data['base_url']),
            'username' => $qb->createNamedParameter($data['username']),
            'password_encrypted' => $qb->createNamedParameter($this->crypto->encrypt($data['password'])),
            'collection_href' => $qb->createNamedParameter($data['collection_href'] ?? null),
            'collection_name' => $qb->createNamedParameter($data['collection_name'] ?? null),
            'group_strategy' => $qb->createNamedParameter($data['group_strategy'] ?? 'passthrough'),
            'created_at' => $qb->createNamedParameter($now),
            'updated_at' => $qb->createNamedParameter($now),
        ]);
        $qb->executeStatement();

        return $qb->getLastInsertId();
    }

    /**
     * A null or absent 'password' leaves the stored credential untouched, so
     * the edit form can omit it rather than round-tripping the plaintext
     * through the browser.
     *
     * @param array<string, mixed> $data
     */
    public function update(int $id, string $userId, array $data): void
    {
        $columns = ['name', 'preset', 'base_url', 'username', 'collection_href', 'collection_name', 'group_strategy'];

        $qb = $this->db->getQueryBuilder();
        $qb->update(self::TABLE);

        $touched = false;
        foreach ($columns as $column) {
            if (array_key_exists($column, $data)) {
                $qb->set($column, $qb->createNamedParameter($data[$column]));
                $touched = true;
            }
        }
        if (($data['password'] ?? '') !== '') {
            $qb->set('password_encrypted', $qb->createNamedParameter($this->crypto->encrypt((string) $data['password'])));
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

    /** @param array<string, mixed> $capabilities */
    public function updateCapabilities(
        int $id,
        string $userId,
        array $capabilities,
        ?string $collectionHref = null,
        ?string $groupStrategy = null,
    ): void {
        $now = $this->now();
        $qb = $this->db->getQueryBuilder();
        $qb->update(self::TABLE)
            ->set('capabilities_json', $qb->createNamedParameter(json_encode($capabilities)))
            ->set('last_tested_at', $qb->createNamedParameter($now))
            ->set('updated_at', $qb->createNamedParameter($now));

        if ($collectionHref !== null) {
            $qb->set('collection_href', $qb->createNamedParameter($collectionHref));
        }
        if ($groupStrategy !== null) {
            $qb->set('group_strategy', $qb->createNamedParameter($groupStrategy));
        }

        $qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
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
     * Whether any job still points at this endpoint.
     *
     * The version this replaces queried endpoint_a_id/endpoint_b_id, columns
     * left over from a two-endpoint era that the schema no longer had. It
     * therefore never matched anything, and the "endpoint is still in use"
     * guard it was meant to power silently never fired.
     */
    public function isReferencedByJob(int $id, string $userId): bool
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from(JobMapper::TABLE)
            ->where($qb->expr()->eq('endpoint_id', $qb->createNamedParameter($id)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $found = $result->fetch();
        $result->closeCursor();

        return $found !== false;
    }

    /** Called when a user is deleted; drops everything they owned. */
    public function deleteAllForUser(string $userId): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $qb->executeStatement();
    }
}
