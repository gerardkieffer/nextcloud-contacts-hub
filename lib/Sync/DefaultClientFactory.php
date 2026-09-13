<?php

declare(strict_types=1);

namespace OCA\ContactHub\Sync;

use OCA\ContactHub\CardDav\Client;
use OCA\ContactHub\CardDav\NextcloudHttpTransport;
use OCP\Http\Client\IClientService;

/**
 * Builds a CardDAV client per endpoint, since credentials and therefore the
 * transport differ per endpoint. Tests substitute FakeClientFactory.
 */
class DefaultClientFactory implements ClientFactory
{
    public function __construct(
        private readonly IClientService $clientService,
        private readonly float $timeoutSeconds = 30.0,
    ) {
    }

    public function create(Endpoint $endpoint): Client
    {
        return new Client(new NextcloudHttpTransport(
            $this->clientService,
            $endpoint->username,
            $endpoint->password(),
            $this->timeoutSeconds,
        ));
    }
}
