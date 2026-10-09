<?php

declare(strict_types=1);

namespace OCA\ContactHub\Tests\Integration;

use OCA\ContactHub\AppInfo\Application;
use OCA\ContactHub\Service\NotificationPreferences;
use OCA\ContactHub\Service\ValidationException;
use OCP\Config\IUserConfig;
use OCP\IUserManager;
use OCP\Mail\IMailer;

/**
 * Where conflict notifications go, against the real IUserConfig and a real
 * account -- the profile address only exists on a real user.
 *
 * The rule worth pinning above all: with nothing stored, notifications are
 * ON and go to the profile address, so a user who never opens the settings
 * still hears about a paused job once their profile has an address.
 */
final class NotificationPreferencesTest extends IntegrationTestCase
{
    private const string PROFILE_EMAIL = 'chubtest-profile@example.com';

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureRealUser();
        $this->forgetPreferences();
        $this->setProfileEmail(self::PROFILE_EMAIL);
    }

    protected function tearDown(): void
    {
        $this->forgetPreferences();
        $this->setProfileEmail('');
        parent::tearDown();
    }

    private function forgetPreferences(): void
    {
        $config = \OCP\Server::get(IUserConfig::class);
        $config->deleteUserConfig(self::USER_ID, Application::APP_ID, 'notify_mode');
        $config->deleteUserConfig(self::USER_ID, Application::APP_ID, 'notify_email');
    }

    private function setProfileEmail(string $email): void
    {
        $user = \OCP\Server::get(IUserManager::class)->get(self::USER_ID);
        $user->setPrimaryEMailAddress('');
        $user->setSystemEMailAddress($email);
    }

    private function preferences(): NotificationPreferences
    {
        return new NotificationPreferences(
            \OCP\Server::get(IUserConfig::class),
            \OCP\Server::get(IUserManager::class),
            \OCP\Server::get(IMailer::class),
        );
    }

    public function testWithNothingStoredNotificationsGoToTheProfileAddress(): void
    {
        $prefs = $this->preferences()->get(self::USER_ID);

        self::assertSame(NotificationPreferences::MODE_PROFILE, $prefs['mode']);
        self::assertTrue($prefs['profile_email_valid']);
        self::assertSame(self::PROFILE_EMAIL, $prefs['recipient']);
    }

    public function testProfileModeWithoutAProfileAddressHasNoRecipient(): void
    {
        $this->setProfileEmail('');

        $prefs = $this->preferences()->get(self::USER_ID);

        self::assertSame(NotificationPreferences::MODE_PROFILE, $prefs['mode']);
        self::assertFalse($prefs['profile_email_valid']);
        self::assertNull($prefs['recipient']);
    }

    public function testAddingAProfileAddressLaterSwitchesNotificationsOn(): void
    {
        // The "on by default as soon as there is an address" promise: no
        // preference is written while the profile is empty, so nothing has
        // to be revisited when an address appears.
        $this->setProfileEmail('');
        self::assertNull($this->preferences()->recipient(self::USER_ID));

        $this->setProfileEmail(self::PROFILE_EMAIL);

        self::assertSame(self::PROFILE_EMAIL, $this->preferences()->recipient(self::USER_ID));
    }

    public function testCustomModeSendsToTheCustomAddress(): void
    {
        $prefs = $this->preferences()->set(self::USER_ID, 'custom', '  someone.else@example.org ');

        self::assertSame('custom', $prefs['mode']);
        self::assertSame('someone.else@example.org', $prefs['custom_email'], 'stored trimmed');
        self::assertSame('someone.else@example.org', $prefs['recipient']);
    }

    public function testOffMeansNoRecipientEvenWithAProfileAddress(): void
    {
        $prefs = $this->preferences()->set(self::USER_ID, 'off', '');

        self::assertSame('off', $prefs['mode']);
        self::assertNull($prefs['recipient']);
    }

    public function testCustomModeWithoutAnAddressIsRefusedAndNothingIsStored(): void
    {
        try {
            $this->preferences()->set(self::USER_ID, 'custom', '');
            self::fail('expected a validation error');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('custom_email', $e->errors);
        }

        self::assertSame(NotificationPreferences::MODE_PROFILE, $this->preferences()->get(self::USER_ID)['mode']);
    }

    public function testAnInvalidCustomAddressIsRefusedEvenWhenNotSelected(): void
    {
        // Stored-but-invalid would silently become the recipient the moment
        // someone picked "custom" again, so it is refused up front.
        $this->expectException(ValidationException::class);

        $this->preferences()->set(self::USER_ID, 'off', 'not an address');
    }

    public function testAnUnknownModeIsRefused(): void
    {
        $this->expectException(ValidationException::class);

        $this->preferences()->set(self::USER_ID, 'sms', '');
    }

    public function testTheCustomAddressSurvivesSwitchingAwayAndBack(): void
    {
        $prefs = $this->preferences();
        $prefs->set(self::USER_ID, 'custom', 'holiday@example.org');
        $prefs->set(self::USER_ID, 'off', 'holiday@example.org');

        $after = $prefs->get(self::USER_ID);
        self::assertNull($after['recipient']);
        self::assertSame('holiday@example.org', $after['custom_email']);
    }

    public function testAnUnrecognisedStoredModeFallsBackToTheDefaultNotToOff(): void
    {
        \OCP\Server::get(IUserConfig::class)
            ->setValueString(self::USER_ID, Application::APP_ID, 'notify_mode', 'from-a-future-version');

        self::assertSame(self::PROFILE_EMAIL, $this->preferences()->recipient(self::USER_ID));
    }
}
