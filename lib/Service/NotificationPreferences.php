<?php

declare(strict_types=1);

namespace OCA\ContactHub\Service;

use OCA\ContactHub\AppInfo\Application;
use OCP\Config\IUserConfig;
use OCP\IUserManager;
use OCP\Mail\IMailer;

/**
 * Where, if anywhere, a user's conflict notifications go.
 *
 * Three modes, stored per user:
 *
 *   profile  the email address on the user's Nextcloud profile (default)
 *   custom   an address the user typed into this app's personal settings
 *   off      no email at all
 *
 * "On by default" is the profile mode with nothing stored: a user who never
 * opened the settings gets mail as soon as their profile has a valid
 * address, and nothing before that. Absence of a row means the default
 * rather than "off", so adding an address to a profile later switches
 * notifications on without anyone revisiting this app.
 *
 * The custom address is kept when the user switches away from it, so
 * flipping to "off" for a holiday and back does not make them retype it.
 * It is validated whenever it is non-empty, not only while selected: a
 * stored-but-invalid address would silently become the recipient the moment
 * someone picks "custom" again.
 */
class NotificationPreferences
{
    public const string MODE_PROFILE = 'profile';
    public const string MODE_CUSTOM = 'custom';
    public const string MODE_OFF = 'off';
    public const array MODES = [self::MODE_PROFILE, self::MODE_CUSTOM, self::MODE_OFF];

    private const string KEY_MODE = 'notify_mode';
    private const string KEY_EMAIL = 'notify_email';

    public function __construct(
        private readonly IUserConfig $config,
        private readonly IUserManager $userManager,
        private readonly IMailer $mailer,
    ) {
    }

    /**
     * Everything the settings form shows, including the effective recipient
     * so the page can say in plain words where mail will actually go.
     *
     * @return array{mode: string, custom_email: string, profile_email: string, profile_email_valid: bool, recipient: ?string}
     */
    public function get(string $userId): array
    {
        $mode = $this->mode($userId);
        $custom = $this->config->getValueString($userId, Application::APP_ID, self::KEY_EMAIL, '');
        $profile = $this->profileEmail($userId);
        $profileValid = $profile !== '' && $this->mailer->validateMailAddress($profile);

        return [
            'mode' => $mode,
            'custom_email' => $custom,
            'profile_email' => $profile,
            'profile_email_valid' => $profileValid,
            'recipient' => match ($mode) {
                self::MODE_PROFILE => $profileValid ? $profile : null,
                self::MODE_CUSTOM => $custom !== '' && $this->mailer->validateMailAddress($custom) ? $custom : null,
                default => null,
            },
        ];
    }

    /**
     * @return array{mode: string, custom_email: string, profile_email: string, profile_email_valid: bool, recipient: ?string}
     * @throws ValidationException
     */
    public function set(string $userId, string $mode, string $customEmail): array
    {
        $customEmail = trim($customEmail);
        $errors = [];

        if (!in_array($mode, self::MODES, true)) {
            $errors['mode'] = 'Choose where notifications should go.';
        }
        if ($customEmail !== '' && !$this->mailer->validateMailAddress($customEmail)) {
            $errors['custom_email'] = 'This is not a valid email address.';
        } elseif ($mode === self::MODE_CUSTOM && $customEmail === '') {
            $errors['custom_email'] = 'Enter the address notifications should go to.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $this->config->setValueString($userId, Application::APP_ID, self::KEY_MODE, $mode);
        if ($customEmail === '') {
            $this->config->deleteUserConfig($userId, Application::APP_ID, self::KEY_EMAIL);
        } else {
            $this->config->setValueString($userId, Application::APP_ID, self::KEY_EMAIL, $customEmail);
        }

        return $this->get($userId);
    }

    /** The address to notify, or null when the user opted out or has none that is usable. */
    public function recipient(string $userId): ?string
    {
        return $this->get($userId)['recipient'];
    }

    private function mode(string $userId): string
    {
        $mode = $this->config->getValueString($userId, Application::APP_ID, self::KEY_MODE, self::MODE_PROFILE);

        // A value this version does not know (hand-edited, or written by a
        // later version and then downgraded) falls back to the default
        // rather than to "off": silently dropping notifications is the
        // worse failure.
        return in_array($mode, self::MODES, true) ? $mode : self::MODE_PROFILE;
    }

    private function profileEmail(string $userId): string
    {
        // getEMailAddress() is the documented "where to send mail" getter:
        // the user's primary address if they set one, the system address
        // (LDAP, SAML, admin-set) otherwise.
        return trim((string) $this->userManager->get($userId)?->getEMailAddress());
    }
}
