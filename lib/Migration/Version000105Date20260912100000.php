<?php

declare(strict_types=1);

namespace OCA\ContactHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Disable any job left over from two-way sync (removed in this same
 * release). No schema change.
 *
 * A job's `direction` column is a free string, not a DB-level enum, so
 * removing SyncJob::TWO_WAY from the PHP side does nothing to a row an
 * instance already wrote with direction = 'two_way'. Left alone, the next
 * run would hit SideMap::forDirection(), which only recognises
 * FROM_ENDPOINT specially and treats anything else -- 'two_way' included --
 * as a to_endpoint-shaped push. The job would keep running, silently
 * one-way now instead of two-way, with nothing in the UI or the log to say
 * so: a real, user-configured pull direction would simply stop happening.
 *
 * Disabling it is the only safe move here. There is no bookkeeping that
 * says what the user would want instead (mirror? archive? which
 * direction?), and a background job silently changing behaviour is worse
 * than one that stops and waits to be looked at. The user re-enables it
 * after picking a direction in the edit form, which no longer offers
 * "two-way" at all.
 */
class Version000105Date20260912100000 extends SimpleMigrationStep
{
    public function __construct(private readonly IDBConnection $db)
    {
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->update('contacthub_jobs')
            ->set('enabled', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
            ->where($qb->expr()->eq('direction', $qb->createNamedParameter('two_way')));

        $updated = $qb->executeStatement();

        $output->info($updated > 0
            ? "Contacts Hub: disabled {$updated} job(s) that were configured for two-way sync, which no longer "
                . 'exists. Edit each one to pick a direction, then re-enable it.'
            : 'Contacts Hub: no leftover two-way jobs found.');
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        return null;
    }
}
