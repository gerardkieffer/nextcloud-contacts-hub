<?php

declare(strict_types=1);

namespace OCA\ContactHub\Tests\CardDav;

use OCA\ContactHub\CardDav\AbstractTransport;
use PHPUnit\Framework\TestCase;

final class UrlResolutionTest extends TestCase
{
    public function testAbsoluteReferenceWins(): void
    {
        self::assertSame(
            'https://b.example/y',
            AbstractTransport::resolveUrl('https://a.example/x/', 'https://b.example/y'),
        );
    }

    public function testRootRelativeReference(): void
    {
        self::assertSame('https://a.example/z/', AbstractTransport::resolveUrl('https://a.example/x/y/', '/z/'));
    }

    public function testRelativeReferenceJoinsToDirectory(): void
    {
        self::assertSame('https://a.example/x/y/card/', AbstractTransport::resolveUrl('https://a.example/x/y/', 'card/'));
    }

    public function testRelativeReferenceFromFilePathReplacesTheFile(): void
    {
        self::assertSame('https://a.example/x/card/', AbstractTransport::resolveUrl('https://a.example/x/y', 'card/'));
    }

    public function testPortIsPreserved(): void
    {
        self::assertSame('https://a.example:8443/z/', AbstractTransport::resolveUrl('https://a.example:8443/x/', '/z/'));
    }

    public function testDotDotIsNormalized(): void
    {
        self::assertSame('https://a.example/x/z/', AbstractTransport::resolveUrl('https://a.example/x/y/', '../z/'));
    }
}
