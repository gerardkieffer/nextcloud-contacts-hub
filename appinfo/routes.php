<?php

declare(strict_types=1);

/**
 * Route table.
 *
 * 'routes' serves the SPA shell; everything the Vue app talks to lives under
 * 'ocs' so it gets Nextcloud's OCS envelope, CSRF handling and app-password
 * auth without this app implementing any of it.
 *
 * Route names map to controllers by convention: 'page#index' resolves to
 * PageController::index, and is referenced from info.xml as
 * 'contacthub.page.index'.
 *
 * Nothing here is admin-only. Endpoints and jobs are per-user, so every
 * action is marked NoAdminRequired on the controller and scoped by the
 * session's user id.
 */
return [
    'routes' => [
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
    ],

    'ocs' => [
        // Presets are a static catalogue; the UI must never hardcode one.
        ['name' => 'endpoint#presets', 'url' => '/api/v1/presets', 'verb' => 'GET'],

        ['name' => 'endpoint#index', 'url' => '/api/v1/endpoints', 'verb' => 'GET'],
        ['name' => 'endpoint#create', 'url' => '/api/v1/endpoints', 'verb' => 'POST'],
        ['name' => 'endpoint#show', 'url' => '/api/v1/endpoints/{id}', 'verb' => 'GET'],
        ['name' => 'endpoint#update', 'url' => '/api/v1/endpoints/{id}', 'verb' => 'PUT'],
        ['name' => 'endpoint#destroy', 'url' => '/api/v1/endpoints/{id}', 'verb' => 'DELETE'],
        // POST, not GET: the probes create and delete throwaway resources on
        // the remote server, so this is not a safe method.
        ['name' => 'endpoint#test', 'url' => '/api/v1/endpoints/{id}/test', 'verb' => 'POST'],
        ['name' => 'endpoint#applySuggestions', 'url' => '/api/v1/endpoints/{id}/apply', 'verb' => 'POST'],

        // Configuration transfer. Export is POST because it writes a file
        // into the user's Files; inspect is POST because the file being
        // inspected travels in the body.
        ['name' => 'settings#export', 'url' => '/api/v1/settings/export', 'verb' => 'POST'],
        ['name' => 'settings#available', 'url' => '/api/v1/settings/files', 'verb' => 'GET'],
        ['name' => 'settings#inspect', 'url' => '/api/v1/settings/inspect', 'verb' => 'POST'],
        ['name' => 'settings#import', 'url' => '/api/v1/settings/import', 'verb' => 'POST'],

        ['name' => 'job#index', 'url' => '/api/v1/jobs', 'verb' => 'GET'],
        ['name' => 'job#create', 'url' => '/api/v1/jobs', 'verb' => 'POST'],
        ['name' => 'job#show', 'url' => '/api/v1/jobs/{id}', 'verb' => 'GET'],
        ['name' => 'job#update', 'url' => '/api/v1/jobs/{id}', 'verb' => 'PUT'],
        ['name' => 'job#destroy', 'url' => '/api/v1/jobs/{id}', 'verb' => 'DELETE'],
        // Also the resume path: running an open job continues its queue.
        ['name' => 'job#run', 'url' => '/api/v1/jobs/{id}/run', 'verb' => 'POST'],

        ['name' => 'run#index', 'url' => '/api/v1/jobs/{jobId}/runs', 'verb' => 'GET'],
        ['name' => 'run#open', 'url' => '/api/v1/jobs/{jobId}/runs/open', 'verb' => 'GET'],
        ['name' => 'run#items', 'url' => '/api/v1/jobs/{jobId}/runs/{runId}/items', 'verb' => 'GET'],
        ['name' => 'run#abandon', 'url' => '/api/v1/jobs/{jobId}/runs/abandon', 'verb' => 'POST'],
        // Polled every second or so while a run is in flight.
        ['name' => 'run#progress', 'url' => '/api/v1/progress', 'verb' => 'GET'],

        ['name' => 'conflict#index', 'url' => '/api/v1/conflicts', 'verb' => 'GET'],
        ['name' => 'conflict#forJob', 'url' => '/api/v1/jobs/{jobId}/conflicts', 'verb' => 'GET'],
        ['name' => 'conflict#resolve', 'url' => '/api/v1/conflicts/{id}/resolve', 'verb' => 'POST'],
        ['name' => 'conflict#resolveBatch', 'url' => '/api/v1/conflicts/resolve-batch', 'verb' => 'POST'],

        ['name' => 'addressBook#index', 'url' => '/api/v1/address-books', 'verb' => 'GET'],
        ['name' => 'addressBook#backups', 'url' => '/api/v1/address-books/{addressBookId}/backups', 'verb' => 'GET'],
        ['name' => 'addressBook#snapshot', 'url' => '/api/v1/address-books/{addressBookId}/backups', 'verb' => 'POST'],
        ['name' => 'addressBook#restore', 'url' => '/api/v1/backups/{backupId}/restore', 'verb' => 'POST'],
    ],
];
