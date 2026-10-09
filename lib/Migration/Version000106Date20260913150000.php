<?php

declare(strict_types=1);

namespace OCA\ContactHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * contacthub_jobs.paused_for_conflicts / paused_for_conflicts_at.
 *
 * Deliberately separate from `enabled`, which stays purely user-driven: a
 * scheduled run that raises unresolved duplicate-match conflicts sets this
 * flag to stop further scheduled runs until a human resolves them, and
 * ConflictService clears it automatically once a job's unresolved-conflict
 * count reaches zero. Colliding this with `enabled` would mean a
 * user-disabled job and a conflict-paused job could not be told apart, and
 * a user re-enabling their own job could accidentally resume one paused for
 * an unrelated reason.
 *
 * Defaulting to false is what makes this safe to apply to existing rows:
 * nothing already configured should stop running because of a column it
 * never had an opinion on.
 */
class Version000106Date20260913150000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('contacthub_jobs')) {
            return null;
        }

        $table = $schema->getTable('contacthub_jobs');
        if ($table->hasColumn('paused_for_conflicts')) {
            return null;
        }

        $table->addColumn('paused_for_conflicts', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
        $table->addColumn('paused_for_conflicts_at', Types::DATETIME, ['notnull' => false]);

        return $schema;
    }
}
