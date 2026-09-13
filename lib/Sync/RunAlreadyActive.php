<?php

declare(strict_types=1);

namespace OCA\ContactHub\Sync;

/**
 * Another process holds the job's run lock right now (an active web
 * request, cron invocation, or HTTP cron trigger is mid-run).
 *
 * Deliberately a distinct class: callers treat it as "try again
 * shortly", not as a failure -- the web UI keeps its auto-resume
 * polling going, and the cron entry points exit quietly. Nothing about
 * the job's state is wrong when this is thrown.
 */
final class RunAlreadyActive extends \RuntimeException
{
    public static function forJob(SyncJob $job): self
    {
        return new self(
            "Another process is already working on '{$job->name}' -- it will finish or pause on its own; try again shortly."
        );
    }
}
