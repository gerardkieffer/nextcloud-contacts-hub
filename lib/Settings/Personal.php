<?php

declare(strict_types=1);

namespace OCA\ContactHub\Settings;

use OCA\ContactHub\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\Util;

/**
 * Personal settings form: where conflict notifications go.
 *
 * The form itself is a small Vue bundle talking to the same OCS API as the
 * main app, so the page carries no state of its own -- the template is a
 * mount point and nothing else.
 */
class Personal implements ISettings
{
    public function getForm(): TemplateResponse
    {
        Util::addScript(Application::APP_ID, 'contacthub-settings');

        return new TemplateResponse(Application::APP_ID, 'settings-personal');
    }

    public function getSection(): string
    {
        return Application::APP_ID;
    }

    public function getPriority(): int
    {
        return 10;
    }
}
