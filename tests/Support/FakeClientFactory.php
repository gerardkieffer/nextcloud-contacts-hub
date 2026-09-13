<?php

declare(strict_types=1);

namespace OCA\ContactHub\Tests\Support;

use OCA\ContactHub\CardDav\Client;
use OCA\ContactHub\CardDav\HttpTransport;
use OCA\ContactHub\Sync\ClientFactory;
use OCA\ContactHub\Sync\Endpoint;

final class FakeClientFactory implements ClientFactory
{
    /**
     * @param array<int, HttpTransport> $transportsByEndpointId
     * @param HttpTransport|null $default Used for any endpoint id not in the map.
     *     Needed by tests that verify an endpoint *before* it is stored, where
     *     the id is 0 on the way in and the real id afterwards, so neither is
     *     known in advance. Without it those tests get a fresh, healthy
     *     transport and silently assert nothing.
     */
    public function __construct(
        private readonly array $transportsByEndpointId,
        private readonly ?HttpTransport $default = null,
    ) {
    }

    public function create(Endpoint $endpoint): Client
    {
        if ($this->default !== null) {
            return new Client($this->transportsByEndpointId[$endpoint->id] ?? $this->default);
        }

        // An unregistered endpoint gets a fresh empty transport rather
        // than blowing up. DefaultClientFactory never fails either -- it
        // only wraps credentials -- so throwing here would pre-empt the
        // errors that are supposed to surface later, such as RemoteSide
        // rejecting an endpoint whose collection was never selected.
        return new Client(
            $this->transportsByEndpointId[$endpoint->id] ?? new FakeHttpTransport($endpoint->baseUrl)
        );
    }
}
