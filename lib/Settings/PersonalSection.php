<?php

declare(strict_types=1);

namespace OCA\ContactHub\Settings;

use OCA\ContactHub\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

/**
 * The "Contacts Hub" entry in Personal settings.
 *
 * Its own section rather than a form dropped into Nextcloud's shared
 * "Notifications" one: that section belongs to the activity and
 * notifications apps, which may be disabled, and a form registered into a
 * section nobody provides is simply never shown.
 */
class PersonalSection implements IIconSection
{
    public function __construct(
        private readonly IURLGenerator $url,
    ) {
    }

    public function getID(): string
    {
        return Application::APP_ID;
    }

    public function getName(): string
    {
        return 'Contacts Hub';
    }

    public function getPriority(): int
    {
        return 80;
    }

    public function getIcon(): string
    {
        return $this->url->imagePath(Application::APP_ID, 'app-dark.svg');
    }
}
