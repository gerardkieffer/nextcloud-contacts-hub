<?php

declare(strict_types=1);

namespace OCA\ContactHub\Sync;

/**
 * The effective time budget for web-triggered sync runs, clamped so a
 * config edit cannot push it into territory where the server-side
 * limits win (see docs/timeouts.md for the full layer-by-layer
 * analysis and the measurements behind these numbers).
 *
 * Why a clamp and not a comparison against live ini values: the two
 * limits that could actually kill a web run mid-item are wall-clock
 * ones (Apache/reverse-proxy), which PHP cannot read at runtime. PHP's
 * own max_execution_time is *CPU* seconds on Linux -- time blocked on
 * curl/DB I/O doesn't count against it, and sync runs are almost pure
 * I/O wait -- so comparing the budget against it would be comparing
 * wall-clock apples to CPU oranges. A fixed conservative ceiling is
 * the honest guard: MAX (120s) plus the worst realistic single-item
 * overrun (a few HTTP calls at 30s curl timeout each) still fits under
 * every wall-clock limit measured on the target hosting (>=185s
 * verified) and Apache's stock Timeout of 300s.
 *
 * MIN exists because the deadline is only checked *between* items: a
 * zero or negative configured budget wouldn't fail, it would just
 * degrade every run into a one-item-per-request crawl.
 *
 * This takes a plain int rather than reading configuration itself, so it
 * stays a pure function testable without a Nextcloud runtime. Reading
 * IAppConfig is the calling service's job.
 */
final class TimeBudget
{
    public const int MIN_SECONDS = 5;
    public const int MAX_SECONDS = 120;
    public const int DEFAULT_SECONDS = 20;

    public static function clamp(int $configured): int
    {
        return max(self::MIN_SECONDS, min(self::MAX_SECONDS, $configured));
    }
}
