<?php

declare(strict_types=1);

namespace OCA\ContactHub\Sync;

final class RunResult
{
    /**
     * @param string[] $warnings
     * @param string[] $errors
     */
    public function __construct(
        public readonly bool $dryRun,
        public readonly Plan $plan,
        public array $warnings = [],
        public array $errors = [],
        public int $created = 0,
        public int $updated = 0,
        public int $deleted = 0,
        public int $archived = 0,
        public int $conflicts = 0,
        public string $status = 'completed',
        public ?string $pausedReason = null,
        public int $totalItems = 0,
        public int $processedItems = 0,
    ) {
    }
}
