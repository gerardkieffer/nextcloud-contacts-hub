<?php

declare(strict_types=1);

namespace OCA\ContactHub\Db;

use OCP\IDBConnection;

/**
 * Shared plumbing for this app's mappers.
 *
 * These are hand-written against IQueryBuilder rather than extending
 * QBMapper on purpose. The sync engine's domain model is a set of readonly
 * value objects (Sync\Endpoint, Sync\SyncJob) that the Planner, Runner and
 * JobValidator all consume; QBMapper wants mutable Entity subclasses with
 * magic accessors, and converting would ripple through the entire ported
 * engine and its tests to buy nothing the engine actually uses.
 *
 * Timestamps are stored as UTC 'Y-m-d H:i:s' strings. Every supported
 * database accepts that form for a datetime column and hands it back as a
 * string, which keeps the value objects free of DateTime plumbing and
 * matches what the ported code already expects to read.
 */
abstract class Mapper
{
    public function __construct(
        protected readonly IDBConnection $db,
    ) {
    }

    protected function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
