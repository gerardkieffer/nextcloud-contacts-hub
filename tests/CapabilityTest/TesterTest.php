<?php

declare(strict_types=1);

namespace OCA\ContactHub\Tests\CapabilityTest;

use OCA\ContactHub\CapabilityTest\Tester;
use OCA\ContactHub\CardDav\Client;
use OCA\ContactHub\Tests\Support\FakeHttpTransport;
use PHPUnit\Framework\TestCase;

final class TesterTest extends TestCase
{
    public function testDiscoverFindsWritableCollection(): void
    {
        $transport = new FakeHttpTransport('https://a.example/');
        $tester = new Tester(new Client($transport));

        $result = $tester->discover($transport->base);

        self::assertSame($transport->collectionHref, $result['selected']['href']);
        self::assertTrue($result['selected']['writable']);
        self::assertSame([], $result['warnings']);
    }

    public function testDiscoverWarnsWhenNamedCollectionNotFound(): void
    {
        $transport = new FakeHttpTransport('https://a.example/');
        $tester = new Tester(new Client($transport));

        $result = $tester->discover($transport->base, 'Nonexistent Collection');

        self::assertNull($result['selected']);
        self::assertNotEmpty($result['warnings']);
    }

    /**
     * End-to-end regression test for the real bug found via manual
     * browser testing: a long X-ADDRESSBOOKSERVER-MEMBER line gets
     * folded by the vCard writer, and the group round-trip check used
     * to do a raw substring search on the (now folded) wire text,
     * which failed even though the round-trip was perfectly correct.
     * The fix parses the fetched vCard structurally instead.
     */
    public function testWriteProbeGroupRoundtripSurvivesLongMemberUid(): void
    {
        $transport = new FakeHttpTransport('https://a.example/');
        $tester = new Tester(new Client($transport));

        $result = $tester->writeProbe($transport->collectionHref);

        self::assertTrue($result['contact_roundtrip_ok']);
        self::assertTrue($result['categories_roundtrip_ok']);
        self::assertTrue($result['group_roundtrip_ok']);
        self::assertTrue($result['if_match_enforced']);
        self::assertTrue($result['cleanup_ok']);
        self::assertSame([], $result['errors']);
        self::assertSame([], $transport->resources, 'test resources must be cleaned up afterward');
    }

    public function testMkcolProbeReportsSuccessAndCleansUp(): void
    {
        $transport = new FakeHttpTransport('https://a.example/');
        $tester = new Tester(new Client($transport));

        $result = $tester->mkcolProbe($transport->homeHref);

        self::assertTrue($result['ok']);
        self::assertTrue($result['cleanup_ok']);
    }

    public function testSuggestGroupStrategyPrefersPassthroughWhenGroupRoundtripsOk(): void
    {
        self::assertSame(
            'passthrough',
            Tester::suggestGroupStrategy(['group_roundtrip_ok' => true, 'categories_roundtrip_ok' => true], null),
        );
    }

    public function testSuggestGroupStrategyFallsBackToCollectionsWhenMkcolWorks(): void
    {
        self::assertSame(
            'collections',
            Tester::suggestGroupStrategy(['group_roundtrip_ok' => false, 'categories_roundtrip_ok' => false], ['ok' => true]),
        );
    }

    public function testSuggestGroupStrategyFallsBackToCategoriesAsLastResort(): void
    {
        self::assertSame(
            'categories',
            Tester::suggestGroupStrategy(['group_roundtrip_ok' => false, 'categories_roundtrip_ok' => false], ['ok' => false]),
        );
    }
}
