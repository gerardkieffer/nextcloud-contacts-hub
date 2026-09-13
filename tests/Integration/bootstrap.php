<?php

declare(strict_types=1);

/**
 * Bootstrap for the integration suite: the tests that need a real database
 * and a real CardDavBackend.
 *
 * These boot Nextcloud itself rather than extending the server's own
 * \Test\TestCase, because the official Docker image ships no tests/
 * directory -- the server test framework simply is not there. Requiring
 * lib/base.php is what occ does, and it gives us the one thing the tests
 * actually need: a working service container.
 *
 * Run them with ./dev/phpunit-integration, which executes inside the app
 * container as www-data. Running as anyone else leaves root-owned files in
 * the data directory.
 */

if (\PHP_SAPI !== 'cli') {
    throw new \RuntimeException('The integration suite is CLI-only.');
}

$ncRoot = getenv('NEXTCLOUD_ROOT') ?: '/var/www/html';
$base = $ncRoot . '/lib/base.php';

if (!is_file($base)) {
    throw new \RuntimeException(
        "Nextcloud not found at {$ncRoot}. These tests must run inside the dev container "
        . '(use ./dev/phpunit-integration), not on the host.',
    );
}

// Mirrors occ: base.php branches on this to skip the web-request setup.
if (!defined('OC_CONSOLE')) {
    define('OC_CONSOLE', 1);
}

require_once $base;

// CardDavBackend lives in the dav app and this app's classes live in
// custom_apps; loading both registers their namespaces with Nextcloud's
// autoloader.
\OC_App::loadApp('dav');
\OC_App::loadApp('contacthub');

// Test classes are not part of the app's lib/, so Nextcloud's convention
// based loader will not find them.
spl_autoload_register(static function (string $class): void {
    $prefix = 'OCA\\ContactHub\\Tests\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = dirname(__DIR__) . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require $path;
    }
});
