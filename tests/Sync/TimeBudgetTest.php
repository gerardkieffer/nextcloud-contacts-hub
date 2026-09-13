<?php

declare(strict_types=1);

namespace OCA\ContactHub\Tests\Sync;

use OCA\ContactHub\Sync\TimeBudget;
use PHPUnit\Framework\TestCase;

final class TimeBudgetTest extends TestCase
{
    public function testConfiguredValueInRangePassesThrough(): void
    {
        self::assertSame(TimeBudget::DEFAULT_SECONDS, TimeBudget::clamp(TimeBudget::DEFAULT_SECONDS));
        self::assertSame(45, TimeBudget::clamp(45));
    }

    public function testZeroOrNegativeIsClampedUp(): void
    {
        // The deadline is only checked between items, so a <=0 budget
        // wouldn't fail -- it would degrade every run into a
        // one-item-per-request crawl. Clamp instead.
        self::assertSame(TimeBudget::MIN_SECONDS, TimeBudget::clamp(0));
        self::assertSame(TimeBudget::MIN_SECONDS, TimeBudget::clamp(-50));
    }

    public function testExcessiveBudgetIsClampedToTheWallClockSafeCeiling(): void
    {
        // Above MAX the server-side wall-clock limits (proxy/Apache)
        // start winning -- see docs/timeouts.md for the measurements.
        self::assertSame(TimeBudget::MAX_SECONDS, TimeBudget::clamp(3600));
    }

    public function testTheBoundsThemselvesAreAccepted(): void
    {
        self::assertSame(TimeBudget::MIN_SECONDS, TimeBudget::clamp(TimeBudget::MIN_SECONDS));
        self::assertSame(TimeBudget::MAX_SECONDS, TimeBudget::clamp(TimeBudget::MAX_SECONDS));
    }
}
