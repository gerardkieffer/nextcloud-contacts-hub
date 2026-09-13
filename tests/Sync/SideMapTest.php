<?php

declare(strict_types=1);

namespace OCA\ContactHub\Tests\Sync;

use OCA\ContactHub\Sync\SideMap;
use OCA\ContactHub\Sync\SyncJob;
use PHPUnit\Framework\TestCase;

final class SideMapTest extends TestCase
{
    public function testHubIsSideAForPush(): void
    {
        $map = SideMap::forDirection(SyncJob::TO_ENDPOINT);
        self::assertTrue($map->hubIsA());
        self::assertSame('hub', $map->roleFor('a'));
        self::assertSame('endpoint', $map->roleFor('b'));
    }

    /**
     * The whole point of SideMap: a pull job reverses the slots so the
     * Planner's single one-way A -> B path covers it too.
     */
    public function testEndpointIsSideAForPull(): void
    {
        $map = SideMap::forDirection(SyncJob::FROM_ENDPOINT);

        self::assertFalse($map->hubIsA());
        self::assertSame('endpoint', $map->roleFor('a'));
        self::assertSame('hub', $map->roleFor('b'));
        self::assertSame('a', $map->sideFor('endpoint'));
        self::assertSame('b', $map->sideFor('hub'));
    }

    public function testStoredRowIsProjectedOntoPlannerSlots(): void
    {
        $row = [
            'uid' => 'c1',
            'hub_href' => 'hub://book/1/c1.vcf',
            'hub_etag' => 'H1',
            'hub_hash' => 'hh',
            'hub_rev' => '2026-01-01',
            'endpoint_href' => 'https://x/c1.vcf',
            'endpoint_etag' => 'E1',
            'endpoint_hash' => 'eh',
            'endpoint_rev' => '2026-02-02',
            'archived_hub' => 0,
            'archived_endpoint' => 1,
        ];

        $push = SideMap::forDirection(SyncJob::TO_ENDPOINT)->toPlanner($row);
        self::assertSame('hub://book/1/c1.vcf', $push['a_href']);
        self::assertSame('https://x/c1.vcf', $push['b_href']);
        self::assertSame(1, $push['archived_b']);
        self::assertSame('c1', $push['uid'], 'unrelated keys pass through');

        $pull = SideMap::forDirection(SyncJob::FROM_ENDPOINT)->toPlanner($row);
        self::assertSame('https://x/c1.vcf', $pull['a_href'], 'the endpoint occupies slot A on a pull');
        self::assertSame('hub://book/1/c1.vcf', $pull['b_href']);
        self::assertSame(1, $pull['archived_a']);
        self::assertSame(0, $pull['archived_b']);
    }

    public function testPlannerKeyedUpdateIsRewrittenToRoleColumns(): void
    {
        $update = ['a_href' => 'A', 'b_etag' => 'B', 'archived_b' => 1, 'payload_json' => '{}'];

        $push = SideMap::forDirection(SyncJob::TO_ENDPOINT)->toStorage($update);
        self::assertSame(
            ['hub_href' => 'A', 'endpoint_etag' => 'B', 'archived_endpoint' => 1, 'payload_json' => '{}'],
            $push,
        );

        $pull = SideMap::forDirection(SyncJob::FROM_ENDPOINT)->toStorage($update);
        self::assertSame(
            ['endpoint_href' => 'A', 'hub_etag' => 'B', 'archived_hub' => 1, 'payload_json' => '{}'],
            $pull,
        );
    }

    /**
     * Storing raw a/b would silently reinterpret every existing row when
     * a job's direction is edited; round-tripping through role names is
     * what prevents that.
     */
    public function testRoundTripIsStableUnderADirectionChange(): void
    {
        $stored = SideMap::forDirection(SyncJob::TO_ENDPOINT)
            ->toStorage(['a_href' => 'hub-href', 'b_href' => 'endpoint-href']);

        self::assertSame(['hub_href' => 'hub-href', 'endpoint_href' => 'endpoint-href'], $stored);

        // Same row, now read by a job whose direction was flipped: the hub
        // href is still the hub's, it has just moved to the other slot.
        $reread = SideMap::forDirection(SyncJob::FROM_ENDPOINT)->toPlanner($stored);
        self::assertSame('hub-href', $reread['b_href']);
        self::assertSame('endpoint-href', $reread['a_href']);
    }

    public function testMissingColumnsProjectToNull(): void
    {
        $projected = SideMap::forDirection(SyncJob::TO_ENDPOINT)->toPlanner(['uid' => 'c1']);

        self::assertNull($projected['a_href']);
        self::assertNull($projected['b_hash']);
        self::assertSame(0, $projected['archived_a']);
    }
}
