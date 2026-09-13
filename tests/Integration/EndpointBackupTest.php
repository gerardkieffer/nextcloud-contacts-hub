<?php

declare(strict_types=1);

namespace OCA\ContactHub\Tests\Integration;

use OCA\ContactHub\Sync\EndpointBackup;
use OCA\ContactHub\Tests\Support\FakeClientFactory;
use OCA\ContactHub\VCard\Model;

/**
 * Endpoint backup and restore, driven through the real CardDAV client
 * against a fake transport. Unlike the hub snapshots this never touches
 * Nextcloud storage: it captures one endpoint's resources verbatim, so it
 * was already independent of the old hub layer and needed no rewriting
 * beyond the harness swap.
 */
final class EndpointBackupTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        for ($i = 1; $i <= 3; $i++) {
            $this->seedEndpoint("c{$i}.vcf", $this->revVcard("c{$i}", "Contact {$i}", "2026-01-0{$i}T00:00:00Z"));
        }
    }

    private function revVcard(string $uid, string $fn, string $rev): string
    {
        return "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:{$uid}\r\nFN:{$fn}\r\nREV:{$rev}\r\nEND:VCARD\r\n";
    }

    private function service(): EndpointBackup
    {
        return new EndpointBackup(new FakeClientFactory([$this->endpointId => $this->transport]));
    }

    public function testBackupCapturesEveryResourceVerbatim(): void
    {
        $backup = $this->service()->backup($this->endpoints->find($this->endpointId, self::USER_ID));

        self::assertSame(1, $backup['format_version']);
        self::assertCount(3, $backup['resources']);
        $uids = array_column($backup['resources'], 'uid');
        sort($uids);
        self::assertSame(['c1', 'c2', 'c3'], $uids);
    }

    public function testRestoreIsANoOpWhenLiveMatchesBackupExactly(): void
    {
        $endpoint = $this->endpoints->find($this->endpointId, self::USER_ID);
        $backup = $this->service()->backup($endpoint);

        $result = $this->service()->restore($endpoint, $backup);

        self::assertSame(['created' => 0, 'updated' => 0, 'deleted' => 0, 'errors' => []], $result);
    }

    public function testMirrorRestoreRecreatesUpdatesAndDeletesToMatchBackupExactly(): void
    {
        $endpoint = $this->endpoints->find($this->endpointId, self::USER_ID);
        $backup = $this->service()->backup($endpoint);

        // Drift the live data: delete c1, change c2's content, add a new c4 not in the backup.
        $hrefC1 = $this->transport->collectionHref . 'c1.vcf';
        $hrefC2 = $this->transport->collectionHref . 'c2.vcf';
        unset($this->transport->resources[$hrefC1]);
        $this->transport->resources[$hrefC2]['body'] = $this->vcard('c2', 'Bob Changed', '2026-05-05T00:00:00Z');
        $this->transport->resources[$this->transport->collectionHref . 'c4.vcf'] = [
            'body' => $this->vcard('c4', 'Not In Backup', '2026-01-04T00:00:00Z'),
            'etag' => '"seed-4"',
        ];

        $result = $this->service()->restore($endpoint, $backup, mirror: true);

        self::assertSame(1, $result['created'], 'c1 must be recreated');
        self::assertSame(1, $result['updated'], 'c2 must be restored to its backed-up content');
        self::assertSame(1, $result['deleted'], 'c4 (not in the backup) must be removed for an identical restore');
        self::assertSame([], $result['errors']);

        $liveUids = [];
        foreach ($this->transport->resources as $resource) {
            $liveUids[] = Model::parse($resource['body'])->uid;
        }
        sort($liveUids);
        self::assertSame(['c1', 'c2', 'c3'], $liveUids, 'the live collection must match the backup exactly after a mirror restore');

        // REV survives untouched -- restore pushes backup bytes verbatim, never re-renders them.
        $restoredC2 = Model::parse($this->transport->resources[$hrefC2]['body']);
        self::assertSame('Contact 2', $restoredC2->fn);
        self::assertSame('2026-01-02T00:00:00Z', $restoredC2->rev);
    }

    public function testAdditiveRestoreNeverDeletes(): void
    {
        $endpoint = $this->endpoints->find($this->endpointId, self::USER_ID);
        $backup = $this->service()->backup($endpoint);

        $extraHref = $this->transport->collectionHref . 'extra.vcf';
        $this->transport->resources[$extraHref] = [
            'body' => $this->vcard('extra', 'Not In Backup', '2026-01-09T00:00:00Z'),
            'etag' => '"extra"',
        ];

        $result = $this->service()->restore($endpoint, $backup, mirror: false);

        self::assertSame(0, $result['deleted']);
        self::assertArrayHasKey($extraHref, $this->transport->resources, 'additive restore must leave resources not in the backup untouched');
    }
}
