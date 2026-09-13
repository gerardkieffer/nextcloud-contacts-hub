<?php

declare(strict_types=1);

namespace OCA\ContactHub\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Snapshot metadata. The gzipped payload itself lives in IAppData; this is
 * only the index over it (see Sync\BackupService).
 */
class BackupMapper extends Mapper
{
    public const string TABLE = 'contacthub_backups';

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $retentionDays = (int) ($data['retention_days'] ?? 30);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $qb = $this->db->getQueryBuilder();
        $qb->insert(self::TABLE)->values([
            'user_id' => $qb->createNamedParameter($data['user_id']),
            'address_book_id' => $qb->createNamedParameter($data['address_book_id']),
            'job_id' => $qb->createNamedParameter($data['job_id'] ?? null),
            'run_id' => $qb->createNamedParameter($data['run_id'] ?? null),
            'reason' => $qb->createNamedParameter($data['reason']),
            'blob_name' => $qb->createNamedParameter($data['blob_name']),
            'contact_count' => $qb->createNamedParameter($data['contact_count'] ?? 0, IQueryBuilder::PARAM_INT),
            'group_count' => $qb->createNamedParameter($data['group_count'] ?? 0, IQueryBuilder::PARAM_INT),
            'byte_size' => $qb->createNamedParameter($data['byte_size'] ?? 0, IQueryBuilder::PARAM_INT),
            'created_at' => $qb->createNamedParameter($now->format('Y-m-d H:i:s')),
            // sprintf rather than "+{$days} days": a negative retention has
            // to render as "-1 days", not the unparseable "+-1 days". Tests
            // use that to age a snapshot past its expiry without waiting.
            'expires_at' => $qb->createNamedParameter(
                $now->modify(sprintf('%+d days', $retentionDays))->format('Y-m-d H:i:s'),
            ),
        ]);
        $qb->executeStatement();

        return $qb->getLastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function find(int $id, string $userId): ?array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from(self::TABLE)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        return $row === false ? null : $row;
    }

    /** @return list<array<string, mixed>> newest first */
    public function forAddressBook(int $addressBookId, string $userId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from(self::TABLE)
            ->where($qb->expr()->eq('address_book_id', $qb->createNamedParameter($addressBookId)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->orderBy('created_at', 'DESC');

        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        return $rows;
    }

    /**
     * Expired snapshots across all users.
     *
     * Unscoped on purpose: pruning runs from the background job, which acts
     * as no one in particular. Nothing reachable from a web request may call
     * this.
     *
     * @return list<array<string, mixed>>
     */
    public function expired(): array
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from(self::TABLE)
            ->where($qb->expr()->lt('expires_at', $qb->createNamedParameter($now)));

        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        return $rows;
    }

    public function delete(int $id): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
        $qb->executeStatement();
    }

    /** @return list<array<string, mixed>> */
    public function allForUser(string $userId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from(self::TABLE)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        return $rows;
    }

    public function deleteAllForUser(string $userId): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $qb->executeStatement();
    }
}
