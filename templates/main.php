<?php

declare(strict_types=1);

/**
 * SPA mount point. The bundle is registered by PageController::index via
 * Util::addScript, so there is nothing to include here. The version is
 * passed as a data attribute rather than fetched over the API: App.vue
 * reads it straight off the mount point to render a footer, so a stale
 * deploy shows the old version number instead of merely missing features.
 */
?>
<div id="contacthub" data-version="<?php p($_['version']); ?>"></div>
