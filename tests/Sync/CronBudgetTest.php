<?php

declare(strict_types=1);

namespace OCA\ContactHub\Tests\Sync;

use OCA\ContactHub\Sync\CronBudget;
use OCA\ContactHub\Sync\TimeBudget;
use PHPUnit\Framework\TestCase;

final class CronBudgetTest extends TestCase
{
    public function testWebcronGetsTheSameCeilingAsAWebTriggeredRun(): void
    {
        // Webcron is an ordinary web request: the reverse proxy that bounds
        // "Apply now" bounds it too, and a tick killed there leaves
        // reserved_at set in oc_jobs, silencing the job for twelve hours.
        self::assertSame(TimeBudget::MAX_SECONDS, CronBudget::tick(false));
    }

    public function testSystemCronGetsTheLongTick(): void
    {
        // No browser, no proxy, and Nextcloud's own runner hands out jobs
        // for fourteen minutes.
        self::assertSame(CronBudget::CLI_TICK_SECONDS, CronBudget::tick(true));
        self::assertGreaterThan(CronBudget::tick(false), CronBudget::tick(true));
    }

    public function testAJobNeverGetsMoreThanThePerJobCeiling(): void
    {
        self::assertSame(CronBudget::PER_JOB_SECONDS, CronBudget::forJob(600.0));
    }

    public function testAJobIsCappedByWhatIsLeftOfTheTick(): void
    {
        // Without this the tick overshoots by a whole job: the deadline is
        // checked before the job starts, and then the job is handed a full
        // per-job budget regardless.
        self::assertSame(30, CronBudget::forJob(30.4));
    }

    public function testAnAlmostSpentTickStartsNoFurtherJob(): void
    {
        // Starting one would fetch and plan the whole address book, then
        // pause before the first write -- a run's most expensive phase
        // bought for no work.
        self::assertNull(CronBudget::forJob((float) TimeBudget::MIN_SECONDS - 0.5));
        self::assertNull(CronBudget::forJob(0.0));
        self::assertNull(CronBudget::forJob(-12.0));
    }

    public function testTheMinimumItselfIsEnough(): void
    {
        self::assertSame(TimeBudget::MIN_SECONDS, CronBudget::forJob((float) TimeBudget::MIN_SECONDS));
    }
}
