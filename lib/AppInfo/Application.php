<?php

declare(strict_types=1);

namespace OCA\ContactHub\AppInfo;

use OCA\ContactHub\Db\BackupMapper;
use OCA\ContactHub\Files\HubFolder;
use OCA\ContactHub\Listener\UserDeletedListener;
use OCA\ContactHub\Sync\BackupService;
use OCA\ContactHub\Sync\ClientFactory;
use OCA\ContactHub\Sync\DefaultClientFactory;
use OCA\DAV\CardDAV\CardDavBackend;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\User\Events\UserDeletedEvent;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * App entry point.
 *
 * There is no Composer autoloader here, deliberately: Nextcloud resolves
 * OCA\ContactHub\Foo\Bar to lib/Foo/Bar.php by convention, and this app still
 * has zero runtime dependencies. Composer stays a development-only tool for
 * PHPUnit, the same arrangement as before the migration.
 *
 * Most services autowire from lib/. The three registrations below are the
 * ones that cannot: a class from another app, a factory-built object, and a
 * service whose constructor takes a scalar.
 */
class Application extends App implements IBootstrap
{
    public const string APP_ID = 'contacthub';

    /** Per-request HTTP timeout for CardDAV traffic, in seconds. */
    private const string CONFIG_HTTP_TIMEOUT = 'http_timeout_seconds';

    /** How long address book snapshots are kept. */
    private const string CONFIG_RETENTION_DAYS = 'backup_retention_days';

    public function __construct()
    {
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void
    {
        // An endpoint row holds an encrypted CardDAV password, and jobs keep
        // running after their owner is gone (allEnabled() is unscoped), so
        // this is cleanup that matters rather than tidiness.
        $context->registerEventListener(UserDeletedEvent::class, UserDeletedListener::class);

        // CardDavBackend belongs to the bundled `dav` app, so the app
        // container cannot autowire it -- its constructor pulls in Principal
        // and Sharing\Backend, which live over there too. Resolving it
        // through the server container is the supported way to reach across
        // apps, and confining it to this one registration keeps the coupling
        // to a single line. Everything downstream depends on the class, so
        // swapping it in tests stays easy.
        $context->registerService(CardDavBackend::class, static function (ContainerInterface $c): CardDavBackend {
            return \OCP\Server::get(CardDavBackend::class);
        });

        // Snapshots and settings exports live in each user's own
        // "Contacts Hub" folder now, so they are visible in the Files app.
        // IAppData is still registered because snapshots taken before that
        // change are still there and still restorable; nothing new is
        // written to it.
        $context->registerService(IAppData::class, static function (ContainerInterface $c): IAppData {
            return $c->get(IAppDataFactory::class)->get(self::APP_ID);
        });

        $context->registerService(ClientFactory::class, static function (ContainerInterface $c): ClientFactory {
            return new DefaultClientFactory(
                $c->get(IClientService::class),
                (float) $c->get(IAppConfig::class)->getValueInt(self::APP_ID, self::CONFIG_HTTP_TIMEOUT, 30),
            );
        });

        $context->registerService(BackupService::class, static function (ContainerInterface $c): BackupService {
            return new BackupService(
                $c->get(CardDavBackend::class),
                $c->get(BackupMapper::class),
                $c->get(IAppData::class),
                $c->get(HubFolder::class),
                $c->get(LoggerInterface::class),
                $c->get(IAppConfig::class)->getValueInt(self::APP_ID, self::CONFIG_RETENTION_DAYS, 30),
            );
        });
    }

    public function boot(IBootContext $context): void
    {
    }
}
