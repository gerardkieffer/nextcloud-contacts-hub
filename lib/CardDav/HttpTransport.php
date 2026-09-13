<?php

declare(strict_types=1);

namespace OCA\ContactHub\CardDav;

interface HttpTransport
{
    /** @param array<string, string> $headers */
    public function request(string $method, string $url, ?string $body = null, array $headers = []): HttpResponse;

    /**
     * @param array<string, string> $headers
     * @return array{0: HttpResponse, 1: string}
     */
    public function requestFollowingRedirects(
        string $method,
        string $url,
        ?string $body = null,
        array $headers = [],
        int $maxRedirects = 5,
    ): array;
}
