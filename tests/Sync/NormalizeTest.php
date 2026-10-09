<?php

declare(strict_types=1);

namespace OCA\ContactHub\Tests\Sync;

use OCA\ContactHub\Sync\Normalize;
use PHPUnit\Framework\TestCase;

final class NormalizeTest extends TestCase
{
    public function testNameLowercasesAndTrims(): void
    {
        self::assertSame('alice martin', Normalize::name('  Alice MARTIN '));
    }

    public function testEmailLowercasesAndTrims(): void
    {
        self::assertSame('alice@example.com', Normalize::email(' Alice@Example.COM '));
    }

    public function testPhoneStripsEverythingButDigits(): void
    {
        self::assertSame('352621123456', Normalize::phone('+352 621-123 456'));
        self::assertSame('352621123456', Normalize::phone('(352)621123456'));
    }

    public function testPhoneWithNoDigitsIsEmptyString(): void
    {
        self::assertSame('', Normalize::phone('n/a'));
    }
}
