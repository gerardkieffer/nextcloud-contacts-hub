<?php

declare(strict_types=1);

namespace OCA\ContactHub\Service;

use OCA\ContactHub\AppInfo\Application;
use OCA\ContactHub\Db\StateMapper;
use OCA\ContactHub\Sync\Progress;
use OCA\ContactHub\Sync\RunResult;
use OCA\ContactHub\Sync\Runner;
use OCA\ContactHub\Sync\SyncJob;
use OCA\ContactHub\Sync\TimeBudget;
use OCP\IAppConfig;

/**
 * Starting, resuming and reporting on sync runs.
 *
 * The time budget is the reason this class exists rather than the controller
 * calling Runner directly. A web-triggered run has to pause itself before
 * the server's own wall-clock limits kill it mid-write, and the budget is
 * clamped by TimeBudget so a configuration edit cannot push it into
 * territory where those limits win. Reading it from one place keeps a raw
 * config read from creeping into a call site.
 */
class RunService
{
    private const string CONFIG_WEB_BUDGET = 'web_time_budget_seconds';

    public function __construct(
        private readonly Runner $runner,
        private readonly StateMapper $state,
        private readonly JobService $jobs,
        private readonly IAppConfig $config,
    ) {
    }

    /**
     * Run a job from a web request.
     *
     * Always bounded by the web time budget: a paused run resumes on the
     * next trigger, whether that is the browser re-submitting, a cron tick,
     * or a manual Resume.
     */
    public function runFromWeb(int $jobId, string $userId, bool $dryRun, bool $force, string $progressToken = ''): RunResult
    {
        $job = $this->jobs->require($jobId, $userId);

        return $this->runner->run(
            $job,
            dryRun: $dryRun,
            force: $force,
            triggerSource: 'manual',
            timeBudgetSeconds: $this->webBudget(),
            progress: $this->progressReporter($progressToken),
        );
    }

    /**
     * A Progress that writes to the run row the browser is watching.
     *
     * Progress is deliberately a plain callback holder that knows nothing
     * about the database, so deciding that "announcing a step" means an
     * UPDATE is this layer's job. The run id is resolved from the token
     * lazily because the token reaches the runs row only when startRun()
     * fires, which is after the reporter is constructed but before the first
     * write. Resolution is cached once it succeeds; until then each attempt
     * is cheap and rate-limited by Progress's own throttle.
     */
    private function progressReporter(string $progressToken): Progress
    {
        if ($progressToken === '') {
            return Progress::none();
        }

        $runId = null;

        return new Progress(
            function (string $phase, int $current, int $total, string $message) use ($progressToken, &$runId): void {
                $runId ??= $this->state->findRunByProgressToken($progressToken)['id'] ?? null;
                if ($runId !== null) {
                    $this->state->writeProgress((int) $runId, $phase, $current, $total, $message);
                }
            },
            $progressToken,
        );
    }

    /**
     * Run a job from the background job runner.
     *
     * No budget by default: Nextcloud's cron already bounds how long it will
     * let a job take, and the resumable queue handles being cut short.
     */
    public function runFromCron(SyncJob $job, ?int $timeBudgetSeconds = null): RunResult
    {
        return $this->runner->run(
            $job,
            dryRun: false,
            force: false,
            triggerSource: 'cron',
            timeBudgetSeconds: $timeBudgetSeconds,
        );
    }

    public function webBudget(): int
    {
        return TimeBudget::clamp(
            $this->config->getValueInt(Application::APP_ID, self::CONFIG_WEB_BUDGET, TimeBudget::DEFAULT_SECONDS),
        );
    }

    /** @return list<array<string, mixed>> */
    public function historyFor(int $jobId, string $userId, int $limit = 50): array
    {
        $this->jobs->require($jobId, $userId);

        return $this->state->runsForJob($jobId, $limit);
    }

    /** @return array<string, mixed>|null */
    public function openRunFor(int $jobId, string $userId): ?array
    {
        $this->jobs->require($jobId, $userId);

        return $this->state->findOpenRun($jobId);
    }

    /** @return list<array<string, mixed>> */
    public function itemsFor(int $jobId, string $userId, int $runId): array
    {
        $this->jobs->require($jobId, $userId);

        $run = $this->state->getRun($runId);
        if ($run === null || (int) $run['job_id'] !== $jobId) {
            throw new NotFoundException("Run {$runId} does not belong to job {$jobId}.");
        }

        return $this->state->runItemsForRun($runId);
    }

    /**
     * Live progress for a browser watching its own run.
     *
     * Keyed by the token the browser generated, not by job, so a page shows
     * the run it started rather than whatever ran most recently.
     *
     * @return array<string, mixed>|null
     */
    public function progressFor(string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        $run = $this->state->findRunByProgressToken($token);
        if ($run === null) {
            return null;
        }

        return [
            'run_id' => (int) $run['id'],
            'status' => (string) $run['status'],
            'phase' => $run['progress_phase'],
            'current' => (int) $run['progress_current'],
            'total' => (int) $run['progress_total'],
            'message' => $run['progress_message'],
            'processed_items' => (int) $run['processed_items'],
            'total_items' => (int) $run['total_items'],
        ];
    }

    /**
     * Stop an open run at the user's request.
     *
     * Whatever was already applied stays applied -- the state tables are the
     * source of truth for what is synced. This only frees the job to start
     * fresh instead of resuming a queue the user no longer wants continued.
     */
    public function abandon(int $jobId, string $userId): void
    {
        $this->jobs->require($jobId, $userId);

        $open = $this->state->findOpenRun($jobId);
        if ($open === null) {
            throw new NotFoundException("Job {$jobId} has no open run to abandon.");
        }

        $this->state->abandonRun((int) $open['id'], 'Stopped by the user.');
    }
}
