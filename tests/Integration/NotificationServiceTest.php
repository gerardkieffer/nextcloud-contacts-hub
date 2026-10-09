<?php

declare(strict_types=1);

namespace OCA\ContactHub\Tests\Integration;

use OCA\ContactHub\AppInfo\Application;
use OCA\ContactHub\Service\NotificationPreferences;
use OCA\ContactHub\Service\NotificationService;
use OCA\ContactHub\Sync\SyncJob;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use Psr\Log\LoggerInterface;

/**
 * Who the conflict-pause email goes to, and the mail-health bookkeeping.
 *
 * The mailer is the real one for building messages and validating
 * addresses, with only send() intercepted -- so the recipient asserted on is
 * the one actually set on a real message, and nothing ever leaves the dev
 * instance.
 */
final class NotificationServiceTest extends IntegrationTestCase
{
    private const string PROFILE_EMAIL = 'chubtest-profile@example.com';

    /** @var list<IMessage> */
    private array $sent = [];
    private string $originalEmailTest = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureRealUser();
        $this->forgetPreferences();
        $user = \OCP\Server::get(IUserManager::class)->get(self::USER_ID);
        $user->setPrimaryEMailAddress('');
        $user->setSystemEMailAddress(self::PROFILE_EMAIL);
        $this->originalEmailTest = \OCP\Server::get(IAppConfig::class)->getValueString('core', 'emailTestSuccessful', '');
    }

    protected function tearDown(): void
    {
        $appConfig = \OCP\Server::get(IAppConfig::class);
        // Never leave this dev instance's real mail-configuration warning
        // state polluted by a test that stopped mid-way.
        $appConfig->deleteKey(Application::APP_ID, NotificationService::CONFIG_LAST_FAILURE);
        if ($this->originalEmailTest === '') {
            $appConfig->deleteKey('core', 'emailTestSuccessful');
        } else {
            $appConfig->setValueString('core', 'emailTestSuccessful', $this->originalEmailTest);
        }
        $this->forgetPreferences();
        \OCP\Server::get(IUserManager::class)->get(self::USER_ID)?->setSystemEMailAddress('');
        parent::tearDown();
    }

    private function forgetPreferences(): void
    {
        $config = \OCP\Server::get(IUserConfig::class);
        $config->deleteUserConfig(self::USER_ID, Application::APP_ID, 'notify_mode');
        $config->deleteUserConfig(self::USER_ID, Application::APP_ID, 'notify_email');
    }

    private function preferences(): NotificationPreferences
    {
        return new NotificationPreferences(
            \OCP\Server::get(IUserConfig::class),
            \OCP\Server::get(IUserManager::class),
            \OCP\Server::get(IMailer::class),
        );
    }

    private function notificationService(): NotificationService
    {
        $real = \OCP\Server::get(IMailer::class);
        $mailer = $this->createMock(IMailer::class);
        $mailer->method('createMessage')->willReturnCallback(fn() => $real->createMessage());
        $mailer->method('createEMailTemplate')->willReturnCallback(fn(string $id, array $data = []) => $real->createEMailTemplate($id, $data));
        $mailer->method('validateMailAddress')->willReturnCallback(fn(string $a) => $real->validateMailAddress($a));
        $mailer->method('send')->willReturnCallback(function (IMessage $m): array {
            $this->sent[] = $m;
            return [];
        });

        return new NotificationService(
            $mailer,
            \OCP\Server::get(IUserManager::class),
            \OCP\Server::get(IURLGenerator::class),
            \OCP\Server::get(IAppConfig::class),
            \OCP\Server::get(IConfig::class),
            $this->preferences(),
            \OCP\Server::get(LoggerInterface::class),
        );
    }

    /** @return string[] */
    private function recipientsOf(IMessage $message): array
    {
        // getTo() is on the concrete message only; IMessage has no getter.
        return array_keys($message->getTo());
    }

    private function invokePrivate(object $object, string $method): void
    {
        $reflected = new \ReflectionMethod($object, $method);
        $reflected->setAccessible(true);
        $reflected->invoke($object);
    }

    public function testByDefaultTheProfileAddressIsNotified(): void
    {
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);

        $this->notificationService()->notifyJobPaused($job, 2);

        self::assertCount(1, $this->sent);
        self::assertSame([self::PROFILE_EMAIL], $this->recipientsOf($this->sent[0]));
        self::assertStringContainsString($job->name, $this->sent[0]->getSubject());
    }

    public function testACustomAddressReplacesTheProfileOne(): void
    {
        $this->preferences()->set(self::USER_ID, 'custom', 'elsewhere@example.org');
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);

        $this->notificationService()->notifyJobPaused($job, 1);

        self::assertCount(1, $this->sent);
        self::assertSame(['elsewhere@example.org'], $this->recipientsOf($this->sent[0]));
    }

    public function testSwitchedOffSendsNothing(): void
    {
        $this->preferences()->set(self::USER_ID, 'off', '');
        $job = $this->makeJob(SyncJob::TO_ENDPOINT);

        $this->notificationService()->notifyJobPaused($job, 1);

        self::assertSame([], $this->sent);
    }

    public function testRecordSuccessClearsAPreviouslyRecordedFailure(): void
    {
        // Regression: `last_mail_failure_at` used to be set on a failed send
        // and never cleared, so a single transient failure permanently
        // pinned the mail-configuration warning on.
        $appConfig = \OCP\Server::get(IAppConfig::class);
        $service = $this->notificationService();

        $this->invokePrivate($service, 'recordFailure');
        self::assertNotSame('', $appConfig->getValueString(Application::APP_ID, NotificationService::CONFIG_LAST_FAILURE, ''));

        $this->invokePrivate($service, 'recordSuccess');
        self::assertSame('', $appConfig->getValueString(Application::APP_ID, NotificationService::CONFIG_LAST_FAILURE, ''));
    }

    public function testMailStatusFollowsTheAdminTestAndRecentFailures(): void
    {
        $appConfig = \OCP\Server::get(IAppConfig::class);
        $service = $this->notificationService();

        $appConfig->setValueString('core', 'emailTestSuccessful', '0');
        self::assertFalse($service->mailStatus()['likely_configured'], 'a failed or unverified admin test');

        $appConfig->setValueString('core', 'emailTestSuccessful', '1');
        self::assertSame(
            \OCP\Server::get(IConfig::class)->getSystemValueString('mail_smtpmode', 'smtp') !== 'null',
            $service->mailStatus()['likely_configured'],
            'a passed admin test counts as configured unless delivery is disabled outright',
        );

        $this->invokePrivate($service, 'recordFailure');
        self::assertFalse($service->mailStatus()['likely_configured'], 'a real send failed since');
        self::assertTrue($service->mailStatus()['recent_send_failure']);
    }
}
