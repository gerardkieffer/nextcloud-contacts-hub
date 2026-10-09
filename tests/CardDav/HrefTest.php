<?php

declare(strict_types=1);

namespace OCA\ContactHub\Tests\CardDav;

use OCA\ContactHub\CardDav\Href;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HrefTest extends TestCase
{
    public function testEquivalentSpellingsOfOneResourceBecomeOneString(): void
    {
        $canonical = Href::canonical('https://dav.example/card/a%20b@c.vcf');

        foreach ([
            'https://dav.example/card/a b@c.vcf',
            'https://dav.example/card/a%20b%40c.vcf',
            'HTTPS://DAV.example:443/card/a%20b@c.vcf',
        ] as $spelling) {
            self::assertSame($canonical, Href::canonical($spelling), $spelling);
        }
    }

    public function testCharactersSabreLeavesBareStayBare(): void
    {
        // So hrefs this app already stored for ordinary UIDs keep their spelling.
        $href = 'https://dav.example/card/1f2e-3d4c_x.y~z(1):user@host.vcf';

        self::assertSame($href, Href::canonical($href));
    }

    public function testANonDefaultPortIsKept(): void
    {
        self::assertSame('http://localhost:8080/card/a.vcf', Href::canonical('http://localhost:8080/card/a.vcf'));
    }

    public function testAnEncodedSlashStaysInsideItsSegment(): void
    {
        self::assertSame('https://dav.example/card/a%2Fb.vcf', Href::canonical('https://dav.example/card/a%2fb.vcf'));
    }

    /** @return array<string, array{string, string}> */
    public static function awkwardUids(): array
    {
        return [
            'space' => ['a b', 'a%20b'],
            'slash' => ['a/b', 'a%2Fb'],
            'parent' => ['../other', '..%2Fother'],
            'fragment' => ['a#b', 'a%23b'],
            'query' => ['a?b', 'a%3Fb'],
            'percent' => ['100%', '100%25'],
        ];
    }

    #[DataProvider('awkwardUids')]
    public function testAUidIsOnePathSegmentWhateverItContains(string $uid, string $encoded): void
    {
        self::assertSame($encoded, Href::encodeSegment($uid));
    }
}
