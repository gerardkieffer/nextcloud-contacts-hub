<?php

declare(strict_types=1);

namespace OCA\ContactHub\Sync;

use OCA\ContactHub\CardDav\Client;

interface ClientFactory
{
    public function create(Endpoint $endpoint): Client;
}
