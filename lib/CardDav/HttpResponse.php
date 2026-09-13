<?php

declare(strict_types=1);

namespace OCA\ContactHub\CardDav;

final class HttpResponse
{
    /** @param array<string, string> $headers lower-cased header name => value (last value wins on repeats) */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
