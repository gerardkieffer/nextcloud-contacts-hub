<?php

declare(strict_types=1);

/**
 * Bootstrap for the *unit* suite: the tests that need no database and no
 * Nextcloud runtime.
 *
 * This app ships no Composer autoloader (it has no runtime dependencies and
 * relies on Nextcloud's convention-based loader in production), so the unit
 * suite brings its own twelve-line PSR-4 loader rather than requiring a
 * `composer install`. That keeps the fast feedback loop runnable anywhere PHP
 * is available -- including `./dev/php tools/phpunit.phar` -- with no
 * Nextcloud installed at all.
 *
 * The integration suite has its own bootstrap and runs inside the container,
 * where Nextcloud's own autoloader is already in scope.
 */

spl_autoload_register(static function (string $class): void {
    // Order matters: the Tests\ prefix is longer and must be tried first,
    // or every test class would be looked up under lib/.
    $prefixes = [
        'OCA\\ContactHub\\Tests\\' => __DIR__,
        'OCA\\ContactHub\\' => dirname(__DIR__) . '/lib',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }
        $relative = substr($class, strlen($prefix));
        $path = $baseDir . '/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require $path;
        }
        return;
    }
});
