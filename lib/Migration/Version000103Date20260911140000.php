<?php

declare(strict_types=1);

namespace OCA\ContactHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * contact_state.cancelled: "the user declined to sync this pair."
 *
 * Added because there was no way to say that. Resolving a duplicate-match
 * conflict with "Cancel" recorded the new contact one-sided -- its own href
 * and hash on the side it came from, nothing on the side it was declined
 * for -- borrowing the trick Runner uses for an archived backup copy when a
 * deletion propagates. That is enough to keep detection from
 * re-offering the pair, and it is what the original Cancel implementation
 * relied on.
 *
 * It is not enough to keep the contact from being pushed. An archived copy
 * is inert: a synthetic uid nobody edits again, so "tracked, one-sided,
 * unchanged forever" holds. A cancelled contact is an ordinary live contact
 * the user goes on editing for unrelated reasons -- and on the next run
 * after any such edit, Planner::planContacts() saw a tracked row whose
 * source hash no longer matched and scheduled an ordinary update, which
 * Runner::pushContactOne() turns into a *create* under a fresh href because
 * the declined side has none. "Never sync this contact" silently undid
 * itself the first time the contact was edited.
 *
 * Role-agnostic on purpose, unlike archived_hub/archived_endpoint next to
 * it: declining is a property of the pair, not of one side, and it means
 * the same thing whichever direction the job runs in -- so there is no a/b
 * inversion hazard of the kind the role-named columns exist to avoid.
 */
class Version000103Date20260911140000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('contacthub_cstate')) {
            return null;
        }

        $table = $schema->getTable('contacthub_cstate');
        if ($table->hasColumn('cancelled')) {
            return null;
        }

        // Defaulting to false is what makes this safe to apply to existing
        // rows: everything already tracked was tracked by an ordinary sync,
        // and must keep syncing exactly as before.
        $table->addColumn('cancelled', Types::BOOLEAN, ['notnull' => true, 'default' => false]);

        return $schema;
    }
}
