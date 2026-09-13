<?php

declare(strict_types=1);

namespace OCA\ContactHub\CardDav;

/**
 * Transport-independent parts of the CardDAV HTTP contract: manual redirect
 * following and URL resolution.
 *
 * Redirects are never followed by the underlying HTTP stack. Discovery has
 * to loop over them itself, because it needs the URL a response was finally
 * reached at -- iCloud redirects the first request to a per-account host,
 * and every subsequent request must go there rather than back to the
 * advertised entry point. Handing that to the HTTP library would swallow
 * the information.
 */
abstract class AbstractTransport implements HttpTransport
{
    /** @param array<string, string> $headers */
    abstract public function request(string $method, string $url, ?string $body = null, array $headers = []): HttpResponse;

    /**
     * Follow 301/302/307/308 up to $maxRedirects hops, returning both the
     * final response and the URL it was actually reached at.
     *
     * @param array<string, string> $headers
     * @return array{0: HttpResponse, 1: string}
     */
    public function requestFollowingRedirects(
        string $method,
        string $url,
        ?string $body = null,
        array $headers = [],
        int $maxRedirects = 5,
    ): array {
        $current = $url;
        for ($i = 0; $i < $maxRedirects; $i++) {
            $resp = $this->request($method, $current, $body, $headers);
            if (in_array($resp->status, [301, 302, 307, 308], true)) {
                $location = $resp->header('location');
                if ($location === null || $location === '') {
                    throw new DavException("Redirect from {$current} had no Location header");
                }
                $current = self::resolveUrl($current, $location);
                continue;
            }
            return [$resp, $current];
        }

        throw new DavException('Too many redirects during discovery');
    }

    public static function resolveUrl(string $base, string $reference): string
    {
        if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $reference) === 1) {
            return $reference;
        }

        $baseParts = parse_url($base);
        if ($baseParts === false) {
            throw new DavException("Could not parse base URL: {$base}");
        }
        $scheme = $baseParts['scheme'] ?? 'https';
        $host = $baseParts['host'] ?? '';
        $port = isset($baseParts['port']) ? ':' . $baseParts['port'] : '';
        $authority = "{$scheme}://{$host}{$port}";

        if (str_starts_with($reference, '/')) {
            return $authority . $reference;
        }

        $basePath = $baseParts['path'] ?? '/';
        $dir = str_ends_with($basePath, '/') ? $basePath : (dirname($basePath) . '/');

        return $authority . self::normalizePath($dir . $reference);
    }

    private static function normalizePath(string $path): string
    {
        $segments = explode('/', $path);
        $resolved = [];
        foreach ($segments as $segment) {
            if ($segment === '.' || $segment === '') {
                continue;
            }
            if ($segment === '..') {
                array_pop($resolved);
                continue;
            }
            $resolved[] = $segment;
        }
        $prefix = str_starts_with($path, '/') ? '/' : '';
        $suffix = str_ends_with($path, '/') ? '/' : '';

        return $prefix . implode('/', $resolved) . $suffix;
    }
}
