<?php

declare(strict_types=1);

namespace OCA\ContactHub\Tests\Integration;

use OCA\DAV\CardDAV\CardDavBackend;
use OCP\IDBConnection;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;

/**
 * Proves the integration harness itself works before anything is built on
 * it: Nextcloud booted, the service container answers, and this app's tables
 * exist.
 *
 * If this fails, nothing else in the suite is meaningful.
 */
final class HarnessTest extends TestCase
{
    public function testNextcloudBooted(): void
    {
        self::assertTrue(class_exists(\OC::class), 'Nextcloud did not boot.');
    }

    public function testTheServiceContainerResolvesWhatTheAppNeeds(): void
    {
        self::assertInstanceOf(IDBConnection::class, \OCP\Server::get(IDBConnection::class));
        self::assertInstanceOf(ICrypto::class, \OCP\Server::get(ICrypto::class));
    }

    public function testCardDavBackendIsReachableFromThisApp(): void
    {
        // The whole hub rests on this class, and it belongs to another app.
        self::assertInstanceOf(CardDavBackend::class, \OCP\Server::get(CardDavBackend::class));
    }

    public function testThisAppsTablesExist(): void
    {
        $schema = \OCP\Server::get(IDBConnection::class)->createSchema();
        $prefix = \OCP\Server::get(\OCP\IConfig::class)->getSystemValueString('dbtableprefix', 'oc_');

        foreach ([
            'contacthub_endpoints', 'contacthub_jobs', 'contacthub_cstate',
            'contacthub_gstate', 'contacthub_runs', 'contacthub_run_items',
            'contacthub_conflicts', 'contacthub_backups',
        ] as $table) {
            self::assertTrue(
                $schema->hasTable($prefix . $table),
                "Missing table {$table}; the migration did not run.",
            );
        }
    }

    public function testAppClassesAutoload(): void
    {
        self::assertTrue(class_exists(\OCA\ContactHub\Sync\NextcloudSide::class));
        self::assertTrue(class_exists(\OCA\ContactHub\Db\StateMapper::class));
    }
}
