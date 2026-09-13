<?php

declare(strict_types=1);

namespace OCA\ContactHub\Controller;

use OCA\ContactHub\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\Util;

/**
 * Serves the single-page app shell. Every screen below it is Vue talking to
 * the OCS controllers; this exists only to mount it.
 */
class PageController extends Controller
{
    public function __construct(
        IRequest $request,
        private IAppManager $appManager,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * Any logged-in user gets their own hub -- endpoints and jobs are
     * per-user, so this is deliberately not admin-gated.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse
    {
        Util::addScript(Application::APP_ID, 'contacthub-main');

        // Rendered in the app's footer so a stale deploy (old files still
        // being served, or a cached JS bundle) is obvious at a glance
        // instead of showing up as individually missing features.
        return new TemplateResponse(Application::APP_ID, 'main', [
            'version' => $this->appManager->getAppVersion(Application::APP_ID),
        ]);
    }
}
