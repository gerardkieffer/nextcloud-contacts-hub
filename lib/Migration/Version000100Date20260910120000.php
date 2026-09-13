<?php

declare(strict_types=1);

namespace OCA\ContactHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Baseline schema.
 *
 * Ported from the standalone app's 0001_baseline.sql, with four deliberate
 * differences:
 *
 *  * No address book, contact, group, photo or user tables. Nextcloud owns
 *    all of those now; this app only stores the sync configuration and the
 *    bookkeeping that describes what it did.
 *
 *  * Every top-level table carries user_id. The old app was implicitly
 *    single-tenant and had nullable, unenforced owner_id columns; here the
 *    column is the access-control boundary and every mapper filters on it.
 *
 *  * sync_jobs.cron_token is gone. URL-triggered cron was a workaround for
 *    shared hosting with no shell; Nextcloud's background job runner
 *    replaces it, so an unauthenticated public trigger URL would be pure
 *    attack surface.
 *
 *  * sync_jobs gains locked_until/lock_token. The old design used MySQL
 *    GET_LOCK, whose auto-release-on-disconnect *proved* that a run row
 *    stuck at 'running' belonged to a dead process. There is no portable
 *    equivalent -- Postgres advisory locks differ and SQLite has none --
 *    so exclusion becomes a compare-and-set on locked_until, refreshed by
 *    a heartbeat while a run works. See Sync\JobLock.
 *
 * State columns stay named for the roles (a hub_ and an endpoint_ set)
 * rather than the Planner's generic a/b sides. Which role plays side A
 * flips with a
 * job's direction, so storing raw a/b would silently invert the meaning of
 * every existing row the moment someone edited a job. Sync\SideMap owns
 * that translation. "hub" now means "the Nextcloud address book", which is
 * still exactly what the word meant before.
 */
class Version000100Date20260910120000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $this->createEndpoints($schema);
        $this->createJobs($schema);
        $this->createContactState($schema);
        $this->createGroupState($schema);
        $this->createRuns($schema);
        $this->createRunItems($schema);
        $this->createConflicts($schema);
        $this->createBackups($schema);

        return $schema;
    }

    private function createEndpoints(ISchemaWrapper $schema): void
    {
        if ($schema->hasTable('contacthub_endpoints')) {
            return;
        }
        $t = $schema->createTable('contacthub_endpoints');
        $t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
        $t->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
        $t->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 190]);
        $t->addColumn('preset', Types::STRING, ['notnull' => true, 'length' => 40]);
        $t->addColumn('base_url', Types::STRING, ['notnull' => true, 'length' => 500]);
        $t->addColumn('username', Types::STRING, ['notnull' => true, 'length' => 255]);
        // Ciphertext from OCP\Security\ICrypto; length is unbounded in practice.
        $t->addColumn('password_encrypted', Types::TEXT, ['notnull' => true]);
        $t->addColumn('collection_href', Types::STRING, ['notnull' => false, 'length' => 500]);
        $t->addColumn('collection_name', Types::STRING, ['notnull' => false, 'length' => 255]);
        $t->addColumn('group_strategy', Types::STRING, ['notnull' => true, 'length' => 20, 'default' => 'passthrough']);
        $t->addColumn('capabilities_json', Types::TEXT, ['notnull' => false]);
        $t->addColumn('last_tested_at', Types::DATETIME, ['notnull' => false]);
        $t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
        $t->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
        $t->setPrimaryKey(['id']);
        $t->addIndex(['user_id'], 'chub_ep_user');
    }

    private function createJobs(ISchemaWrapper $schema): void
    {
        if ($schema->hasTable('contacthub_jobs')) {
            return;
        }
        $t = $schema->createTable('contacthub_jobs');
        $t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
        $t->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
        $t->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 190]);
        // Nextcloud's own address book id, from OCA\DAV's CardDavBackend.
        $t->addColumn('address_book_id', Types::BIGINT, ['notnull' => true]);
        $t->addColumn('endpoint_id', Types::BIGINT, ['notnull' => true]);
        $t->addColumn('direction', Types::STRING, ['notnull' => true, 'length' => 20]);
        $t->addColumn('deletion_policy', Types::STRING, ['notnull' => true, 'length' => 20, 'default' => 'mirror']);
        $t->addColumn('archive_group_name', Types::STRING, ['notnull' => true, 'length' => 190, 'default' => 'Deleted']);
        $t->addColumn('include_photos', Types::BOOLEAN, ['notnull' => true, 'default' => true]);
        $t->addColumn('interval_seconds', Types::INTEGER, ['notnull' => true, 'default' => 1800]);
        $t->addColumn('enabled', Types::BOOLEAN, ['notnull' => true, 'default' => true]);
        $t->addColumn('last_run_at', Types::DATETIME, ['notnull' => false]);
        // Advisory lock, replacing MySQL GET_LOCK. See the class docblock.
        $t->addColumn('locked_until', Types::DATETIME, ['notnull' => false]);
        $t->addColumn('lock_token', Types::STRING, ['notnull' => false, 'length' => 64]);
        $t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
        $t->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
        $t->setPrimaryKey(['id']);
        $t->addIndex(['user_id'], 'chub_job_user');
        $t->addIndex(['endpoint_id'], 'chub_job_endpoint');
        // Drives the background job's "what is due?" scan.
        $t->addIndex(['enabled', 'last_run_at'], 'chub_job_due');
    }

    private function createContactState(ISchemaWrapper $schema): void
    {
        if ($schema->hasTable('contacthub_cstate')) {
            return;
        }
        $t = $schema->createTable('contacthub_cstate');
        $t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
        $t->addColumn('job_id', Types::BIGINT, ['notnull' => true]);
        $t->addColumn('uid', Types::STRING, ['notnull' => true, 'length' => 190]);
        $this->addRoleColumns($t);
        $t->addColumn('archived_hub', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
        $t->addColumn('archived_endpoint', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
        $t->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
        $t->setPrimaryKey(['id']);
        $t->addUniqueIndex(['job_id', 'uid'], 'chub_cstate_job_uid');
    }

    private function createGroupState(ISchemaWrapper $schema): void
    {
        if ($schema->hasTable('contacthub_gstate')) {
            return;
        }
        $t = $schema->createTable('contacthub_gstate');
        $t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
        $t->addColumn('job_id', Types::BIGINT, ['notnull' => true]);
        $t->addColumn('uid', Types::STRING, ['notnull' => true, 'length' => 190]);
        $t->addColumn('payload_json', Types::TEXT, ['notnull' => true]);
        $this->addRoleColumns($t);
        $t->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
        $t->setPrimaryKey(['id']);
        $t->addUniqueIndex(['job_id', 'uid'], 'chub_gstate_job_uid');
    }

    /**
     * The per-side bookkeeping both state tables share. Named for roles, not
     * for the Planner's a/b sides -- see the class docblock.
     */
    private function addRoleColumns(\Doctrine\DBAL\Schema\Table $t): void
    {
        foreach (['hub', 'endpoint'] as $role) {
            $t->addColumn("{$role}_href", Types::STRING, ['notnull' => false, 'length' => 500]);
            $t->addColumn("{$role}_etag", Types::STRING, ['notnull' => false, 'length' => 190]);
            $t->addColumn("{$role}_hash", Types::STRING, ['notnull' => false, 'length' => 64]);
            $t->addColumn("{$role}_rev", Types::STRING, ['notnull' => false, 'length' => 64]);
        }
    }

    private function createRuns(ISchemaWrapper $schema): void
    {
        if ($schema->hasTable('contacthub_runs')) {
            return;
        }
        $t = $schema->createTable('contacthub_runs');
        $t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
        $t->addColumn('job_id', Types::BIGINT, ['notnull' => true]);
        $t->addColumn('started_at', Types::DATETIME, ['notnull' => true]);
        $t->addColumn('finished_at', Types::DATETIME, ['notnull' => false]);
        $t->addColumn('dry_run', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
        $t->addColumn('trigger_source', Types::STRING, ['notnull' => true, 'length' => 20]);
        $t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 20, 'default' => 'running']);
        $t->addColumn('paused_reason', Types::TEXT, ['notnull' => false]);
        $t->addColumn('total_items', Types::INTEGER, ['notnull' => true, 'default' => 0]);
        $t->addColumn('processed_items', Types::INTEGER, ['notnull' => true, 'default' => 0]);
        $t->addColumn('created', Types::INTEGER, ['notnull' => true, 'default' => 0]);
        $t->addColumn('updated', Types::INTEGER, ['notnull' => true, 'default' => 0]);
        $t->addColumn('deleted', Types::INTEGER, ['notnull' => true, 'default' => 0]);
        $t->addColumn('archived', Types::INTEGER, ['notnull' => true, 'default' => 0]);
        $t->addColumn('conflicts', Types::INTEGER, ['notnull' => true, 'default' => 0]);
        $t->addColumn('errors', Types::INTEGER, ['notnull' => true, 'default' => 0]);
        $t->addColumn('warnings_json', Types::TEXT, ['notnull' => false]);
        $t->addColumn('errors_json', Types::TEXT, ['notnull' => false]);
        // Live progress across the fetch/plan/apply phases. total_items only
        // covers the item loop, which is zero during the fetch that dominates
        // a slow run (~85s against Mailo).
        $t->addColumn('progress_token', Types::STRING, ['notnull' => false, 'length' => 64]);
        $t->addColumn('progress_phase', Types::STRING, ['notnull' => false, 'length' => 40]);
        $t->addColumn('progress_current', Types::INTEGER, ['notnull' => true, 'default' => 0]);
        $t->addColumn('progress_total', Types::INTEGER, ['notnull' => true, 'default' => 0]);
        $t->addColumn('progress_message', Types::STRING, ['notnull' => false, 'length' => 255]);
        $t->setPrimaryKey(['id']);
        $t->addIndex(['job_id', 'status'], 'chub_run_job_status');
        $t->addIndex(['progress_token'], 'chub_run_progress');
    }

    private function createRunItems(ISchemaWrapper $schema): void
    {
        if ($schema->hasTable('contacthub_run_items')) {
            return;
        }
        $t = $schema->createTable('contacthub_run_items');
        $t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
        $t->addColumn('run_id', Types::BIGINT, ['notnull' => true]);
        $t->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 10]);
        $t->addColumn('uid', Types::STRING, ['notnull' => true, 'length' => 190]);
        $t->addColumn('action', Types::STRING, ['notnull' => true, 'length' => 30]);
        $t->addColumn('source_href', Types::STRING, ['notnull' => false, 'length' => 500]);
        // The winning side's raw vCard, captured at materialisation time. A
        // resumed run is a fresh process with no memory of the original
        // fetch, so every push must be drivable from this row alone.
        $t->addColumn('source_snapshot', Types::TEXT, ['notnull' => false]);
        $t->addColumn('categories_json', Types::TEXT, ['notnull' => false]);
        $t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 20, 'default' => 'pending']);
        $t->addColumn('error_message', Types::TEXT, ['notnull' => false]);
        $t->addColumn('processed_at', Types::DATETIME, ['notnull' => false]);
        $t->setPrimaryKey(['id']);
        $t->addIndex(['run_id', 'status'], 'chub_item_run_status');
    }

    private function createConflicts(ISchemaWrapper $schema): void
    {
        if ($schema->hasTable('contacthub_conflicts')) {
            return;
        }
        $t = $schema->createTable('contacthub_conflicts');
        $t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
        $t->addColumn('job_id', Types::BIGINT, ['notnull' => true]);
        $t->addColumn('uid', Types::STRING, ['notnull' => true, 'length' => 190]);
        $t->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 10]);
        $t->addColumn('hub_snapshot', Types::TEXT, ['notnull' => false]);
        $t->addColumn('endpoint_snapshot', Types::TEXT, ['notnull' => false]);
        $t->addColumn('duplicate_json', Types::TEXT, ['notnull' => false]);
        $t->addColumn('detected_at', Types::DATETIME, ['notnull' => true]);
        $t->addColumn('resolved_at', Types::DATETIME, ['notnull' => false]);
        $t->addColumn('resolution', Types::STRING, ['notnull' => false, 'length' => 30]);
        $t->setPrimaryKey(['id']);
        $t->addIndex(['job_id', 'resolved_at'], 'chub_conf_job_open');
    }

    private function createBackups(ISchemaWrapper $schema): void
    {
        if ($schema->hasTable('contacthub_backups')) {
            return;
        }
        $t = $schema->createTable('contacthub_backups');
        $t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
        $t->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
        $t->addColumn('address_book_id', Types::BIGINT, ['notnull' => true]);
        $t->addColumn('job_id', Types::BIGINT, ['notnull' => false]);
        $t->addColumn('run_id', Types::BIGINT, ['notnull' => false]);
        $t->addColumn('reason', Types::STRING, ['notnull' => true, 'length' => 30]);
        // Name of the gzipped JSON blob in this app's IAppData folder. There
        // is no photo pinning table any more: Nextcloud stores photo bytes
        // inline in each card, so a snapshot is self-contained.
        $t->addColumn('blob_name', Types::STRING, ['notnull' => true, 'length' => 255]);
        $t->addColumn('contact_count', Types::INTEGER, ['notnull' => true, 'default' => 0]);
        $t->addColumn('group_count', Types::INTEGER, ['notnull' => true, 'default' => 0]);
        $t->addColumn('byte_size', Types::BIGINT, ['notnull' => true, 'default' => 0]);
        $t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
        $t->addColumn('expires_at', Types::DATETIME, ['notnull' => true]);
        $t->setPrimaryKey(['id']);
        $t->addIndex(['address_book_id', 'created_at'], 'chub_bk_book_created');
        $t->addIndex(['expires_at'], 'chub_bk_expires');
        $t->addIndex(['user_id'], 'chub_bk_user');
    }
}
