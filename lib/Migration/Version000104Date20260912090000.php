<?php

declare(strict_types=1);

namespace OCA\ContactHub\Migration;

use Closure;
use OCA\ContactHub\BackgroundJob\SyncTimedJob;
use OCP\BackgroundJob\IJob;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Un-stick SyncTimedJob's row in oc_jobs. No schema change at all.
 *
 * Two pieces of state live in Nextcloud's own jobs table, are written once
 * and never written back, and either one can stop scheduled syncs dead
 * without logging a thing. Changing the PHP does not touch them, so an
 * instance that has already run the job keeps the old behaviour forever
 * unless something repairs the row. That is what this step is.
 *
 * **time_sensitive.** SyncTimedJob used to mark itself TIME_INSENSITIVE.
 * Under system cron with `maintenance_window_start` configured, Nextcloud
 * runs insensitive jobs only inside that four hour window -- so a job the
 * user set to run hourly ran once a night. The flag is gone from the class
 * now, but `JobList::setLastRun()` only ever writes the column *towards*
 * insensitive; nothing writes it back. Hence the explicit reset.
 *
 * **reserved_at.** A tick that dies mid-run leaves the job reserved, and
 * `JobList::getNext()` then skips it until the reservation is twelve hours
 * old. On the CLI that barely happens, because cron.php registers a shutdown
 * handler that unlocks the job. Under webcron there is no such handler and
 * the reverse proxy is entitled to cut the request off, so one over-long
 * tick used to buy half a day of silence. The tick budget is bounded to the
 * web ceiling now (see Sync\CronBudget), but an instance arriving at this
 * migration may well be carrying a live reservation from before that.
 *
 * Clearing `reserved_at` alone would be enough; `last_checked` goes back to
 * now as well so the job is due on the next tick rather than at whatever
 * future timestamp a skip had pushed it to. `last_run` is deliberately left
 * alone: it is Nextcloud's record of when the tick last happened, not the
 * app's record of when an address book last synced (that lives in
 * contacthub_jobs.last_run_at), and resetting it would only mean one
 * redundant tick.
 */
class Version000104Date20260912090000 extends SimpleMigrationStep
{
    public function __construct(private readonly IDBConnection $db)
    {
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->update('jobs')
            ->set('time_sensitive', $qb->createNamedParameter(IJob::TIME_SENSITIVE, IQueryBuilder::PARAM_INT))
            ->set('reserved_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
            ->set('last_checked', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('class', $qb->createNamedParameter(SyncTimedJob::class)));

        $updated = $qb->executeStatement();

        $output->info($updated > 0
            ? 'Contacts Hub: reset the background job so it is picked up on the next cron tick.'
            : 'Contacts Hub: no background job row to reset (it is registered on enable).');
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        return null;
    }
}
