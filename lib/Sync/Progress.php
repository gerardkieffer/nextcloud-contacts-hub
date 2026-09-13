<?php

declare(strict_types=1);

namespace OCA\ContactHub\Sync;

/**
 * Reports what a run is doing right now, so the browser can show a
 * progress bar instead of an apparently-hung page.
 *
 * Deliberately a plain callback holder rather than something that
 * knows about the database: the Runner and the CardDAV client just
 * announce steps, and only the web layer decides that "announcing"
 * means an UPDATE on the runs row. That keeps CLI runs (which pass no
 * Progress at all) free of write traffic they have no use for, and
 * keeps the sync engine testable without a progress sink.
 *
 * Writes are throttled: a 766-contact run would otherwise issue one
 * UPDATE per contact purely for cosmetics. Phase changes and the final
 * step of a phase always get through, so the bar never sticks at
 * "45 of 46" because the last tick was throttled away.
 */
final class Progress
{
    private string $phase = '';
    private float $lastWriteAt = 0.0;

    /** @var callable(string, int, int, string): void */
    private $sink;

    /**
     * @param callable(string, int, int, string): void $sink phase, current, total, message
     * @param string $token identifies the browser watching this run, so a
     *        polling page shows its own run and not whatever ran last
     */
    public function __construct(
        callable $sink,
        private readonly string $token = '',
        private readonly float $minIntervalSeconds = 0.4,
    ) {
        $this->sink = $sink;
    }

    /** A no-op reporter, so callers never need to null-check. */
    public static function none(): self
    {
        return new self(static function (): void {
        });
    }

    public function token(): string
    {
        return $this->token;
    }

    /** Enter a new phase; always written through. */
    public function phase(string $phase, string $message = '', int $total = 0): void
    {
        $this->phase = $phase;
        $this->write($phase, 0, $total, $message, true);
    }

    /** Report a step within the current phase. */
    public function step(int $current, int $total, string $message = ''): void
    {
        $this->write($this->phase, $current, $total, $message, $total > 0 && $current >= $total);
    }

    private function write(string $phase, int $current, int $total, string $message, bool $force): void
    {
        $now = microtime(true);
        if (!$force && ($now - $this->lastWriteAt) < $this->minIntervalSeconds) {
            return;
        }
        $this->lastWriteAt = $now;
        ($this->sink)($phase, $current, $total, $message);
    }
}
