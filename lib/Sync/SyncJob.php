<?php

declare(strict_types=1);

namespace OCA\ContactHub\Sync;

/**
 * A sync job always connects the hub -- one Nextcloud address book -- to
 * one remote endpoint. There is deliberately no endpoint-to-endpoint job
 * shape: the address book is the single place contacts actually live, and
 * every endpoint is something it syncs with.
 *
 * That is also how the two headline features are expressed, without any
 * new machinery:
 *
 *   one-to-many        N jobs, one address book, N endpoints
 *   bridge two services  two jobs sharing one address book
 */
final class SyncJob
{
    public const string TO_ENDPOINT = 'to_endpoint';
    public const string FROM_ENDPOINT = 'from_endpoint';

    public const array DIRECTIONS = [self::TO_ENDPOINT, self::FROM_ENDPOINT];
    public const array DELETION_POLICIES = ['mirror', 'archive'];

    public function __construct(
        public readonly int $id,
        public readonly string $userId,
        public readonly string $name,
        public readonly int $addressBookId,
        public readonly string $addressBookName,
        public readonly Endpoint $endpoint,
        public readonly string $direction,
        public readonly string $deletionPolicy,
        public readonly string $archiveGroupName,
        public readonly bool $includePhotos,
        public readonly int $intervalSeconds,
        public readonly bool $enabled,
        public readonly ?string $lastRunAt,
    ) {
    }

    /**
     * Whether the configured interval has elapsed since the last run. A job
     * that has never run is always due.
     *
     * lastRunAt is a UTC 'Y-m-d H:i:s' string, so the timezone has to be
     * stated explicitly -- strtotime() would otherwise read it as server
     * local time and skew every decision by the server's UTC offset.
     */
    public function isDue(?int $now = null): bool
    {
        if ($this->lastRunAt === null || $this->lastRunAt === '') {
            return true;
        }

        $last = strtotime($this->lastRunAt . ' UTC');
        if ($last === false) {
            return true;
        }

        return (($now ?? time()) - $last) >= $this->intervalSeconds;
    }

    public function sideMap(): SideMap
    {
        return SideMap::forDirection($this->direction);
    }

    /**
     * The role vocabulary stays "hub" everywhere internally -- column names,
     * SideMap, conflict resolutions -- but the hub is a Nextcloud address
     * book now, and "hub" means nothing to someone reading a dropdown.
     */
    public static function directionLabel(string $direction): string
    {
        return match ($direction) {
            self::TO_ENDPOINT => 'Nextcloud to endpoint',
            self::FROM_ENDPOINT => 'Endpoint to Nextcloud',
            default => $direction,
        };
    }
}
