<?php

declare(strict_types=1);

namespace OCA\ContactHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Drop contacthub_jobs.conflict_strategy from installations that still have it.
 *
 * Two-way sync -- the only thing that read the column -- was removed, and the
 * column was deleted from the baseline migration on the grounds that nothing
 * had shipped. An instance installed from a build before that kept the table
 * as it was, and `occ db:schema:check` reports the column as unexpected. It
 * was always harmless to the app (NOT NULL, but with a default, so inserts
 * that never mention it work), which is why it went unnoticed until someone
 * ran the check.
 *
 * Only ever drops, and only when the column is there, so a fresh install --
 * whose baseline never had it -- passes through untouched.
 */
class Version000107Date20261009180000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('contacthub_jobs')) {
            return null;
        }

        $table = $schema->getTable('contacthub_jobs');
        if (!$table->hasColumn('conflict_strategy')) {
            return null;
        }

        $table->dropColumn('conflict_strategy');

        return $schema;
    }
}
