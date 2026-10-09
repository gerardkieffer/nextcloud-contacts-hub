<?php

declare(strict_types=1);

namespace OCA\ContactHub\Tests\CardDav;

use OCA\ContactHub\CardDav\Client;
use OCA\ContactHub\CardDav\DavException;
use OCA\ContactHub\Tests\Support\FakeHttpTransport;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    private function vcard(string $uid, string $fn): string
    {
        return "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:{$uid}\r\nFN:{$fn}\r\nEND:VCARD\r\n";
    }

    public function testFetchAllFallsBackToChunkedMultigetWhenTheFullReportTimesOut(): void
    {
        // 120 resources with a 50-href chunk size -> exactly 3 multiget
        // calls; the whole set must come back with hrefs intact.
        $transport = new FakeHttpTransport('https://x.example/');
        for ($i = 1; $i <= 120; $i++) {
            $href = $transport->collectionHref . "c{$i}.vcf";
            $transport->resources[$href] = ['etag' => "\"e{$i}\"", 'body' => $this->vcard("c{$i}", "Contact {$i}")];
        }
        $transport->timeoutFullReport = true;

        $client = new Client($transport);
        $all = $client->fetchAllVCards($transport->collectionHref);

        self::assertCount(120, $all);
        self::assertSame(3, $transport->multigetCallCount);
        $byHref = array_column($all, 'vcard', 'href');
        self::assertStringContainsString('UID:c120', $byHref[$transport->collectionHref . 'c120.vcf']);
    }

    public function testFetchAllCompletesCardsWhoseUidTheWholeCollectionAnswerLeftOut(): void
    {
        // Mailo's addressbook-query drops UID from every contact; GET and
        // multiget keep it. Taken at face value the whole book was cards
        // with no identity, and contacts the hub already shared by UID
        // were reported as conflicts.
        $transport = new FakeHttpTransport('https://x.example/');
        for ($i = 1; $i <= 120; $i++) {
            $href = $transport->collectionHref . "c{$i}.vcf";
            $transport->resources[$href] = ['etag' => "\"e{$i}\"", 'body' => $this->vcard("c{$i}", "Contact {$i}")];
        }
        $transport->reportOmitsUid = true;

        $all = (new Client($transport))->fetchAllVCards($transport->collectionHref);

        self::assertCount(120, $all);
        self::assertSame(3, $transport->multigetCallCount, '120 incomplete cards, fetched 50 at a time');
        foreach ($all as $card) {
            self::assertMatchesRegularExpression('/^UID:c\d+/m', $card['vcard']);
        }
    }

    public function testFetchAllDoesNotRefetchWhenTheAnswerWasComplete(): void
    {
        $transport = new FakeHttpTransport('https://x.example/');
        for ($i = 1; $i <= 5; $i++) {
            $transport->resources[$transport->collectionHref . "c{$i}.vcf"] = ['etag' => "\"e{$i}\"", 'body' => $this->vcard("c{$i}", "Contact {$i}")];
        }

        (new Client($transport))->fetchAllVCards($transport->collectionHref);

        self::assertSame(0, $transport->multigetCallCount, 'a well-behaved server pays nothing for this');
    }

    public function testACardThatReallyHasNoUidIsLeftAsItIs(): void
    {
        $transport = new FakeHttpTransport('https://x.example/');
        $href = $transport->collectionHref . 'plain.vcf';
        $body = "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:No Identity\r\nEND:VCARD\r\n";
        $transport->resources[$href] = ['etag' => '"e"', 'body' => $body];

        $all = (new Client($transport))->fetchAllVCards($transport->collectionHref);

        self::assertCount(1, $all);
        self::assertSame(1, $transport->multigetCallCount, 'asked once, and the answer was no different');
        self::assertStringNotContainsString('UID', $all[0]['vcard']);
    }

    public function testFetchAllDoesNotFallBackOnAServerError(): void
    {
        // A 4xx/5xx is a refusal, not slowness -- retrying it as 16
        // smaller requests would still be refused. Only DavTimeout may
        // trigger the chunked path.
        $inner = new FakeHttpTransport('https://x.example/');
        $transport = new class ($inner) implements \OCA\ContactHub\CardDav\HttpTransport {
            public function __construct(public readonly FakeHttpTransport $inner)
            {
            }

            public function request(string $method, string $url, ?string $body = null, array $headers = []): \OCA\ContactHub\CardDav\HttpResponse
            {
                if ($method === 'REPORT' && $body !== null && !str_contains($body, 'addressbook-multiget')) {
                    return new \OCA\ContactHub\CardDav\HttpResponse(507, [], 'insufficient storage');
                }
                return $this->inner->request($method, $url, $body, $headers);
            }

            public function requestFollowingRedirects(string $method, string $url, ?string $body = null, array $headers = [], int $maxRedirects = 5): array
            {
                return [$this->request($method, $url, $body, $headers), $url];
            }
        };

        $client = new Client($transport);
        try {
            $client->fetchAllVCards($inner->collectionHref);
            self::fail('a 507 on the full REPORT must propagate');
        } catch (DavException) {
        }
        self::assertSame(0, $inner->multigetCallCount);
    }

    public function testPutWithoutEtagCreatesNewResource(): void
    {
        $transport = new FakeHttpTransport('https://x.example/');
        $client = new Client($transport);
        $href = $transport->collectionHref . 'new.vcf';

        $etag = $client->putVCard($href, $this->vcard('new', 'Alice'));

        self::assertNotNull($etag);
        self::assertArrayHasKey($href, $transport->resources);
    }

    public function testPutWithoutEtagRefusesToOverwriteExistingResource(): void
    {
        // Every PUT is conditional: a create (no etag) carries
        // If-None-Match: *, so a resource that unexpectedly exists at the
        // target href 412s instead of being silently overwritten.
        $transport = new FakeHttpTransport('https://x.example/');
        $client = new Client($transport);
        $href = $transport->collectionHref . 'c1.vcf';
        $transport->resources[$href] = ['body' => $this->vcard('c1', 'Existing'), 'etag' => '"pre"'];

        $this->expectException(DavException::class);
        $client->putVCard($href, $this->vcard('c1', 'Clobber'));
    }

    public function testPutWithMatchingEtagUpdatesExistingResource(): void
    {
        $transport = new FakeHttpTransport('https://x.example/');
        $client = new Client($transport);
        $href = $transport->collectionHref . 'c1.vcf';
        $transport->resources[$href] = ['body' => $this->vcard('c1', 'Old'), 'etag' => '"pre"'];

        $etag = $client->putVCard($href, $this->vcard('c1', 'New'), '"pre"');

        self::assertNotNull($etag);
        self::assertStringContainsString('New', $transport->resources[$href]['body']);
    }
}
