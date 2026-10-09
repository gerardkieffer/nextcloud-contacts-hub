<?php

declare(strict_types=1);

namespace OCA\ContactHub\CardDav;

/**
 * One spelling for every endpoint URL this app stores or compares.
 *
 * A URL can be written many ways that a server treats as the same resource:
 * `@` or `%40`, `%2f` or `%2F`, a space raw or as `%20`, `:443` or nothing.
 * This app compares hrefs as strings -- state against the live listing, to
 * decide whether a contact is still there -- so two spellings of one
 * resource read as one missing contact and one stranger. The contact was
 * then re-created on every run and refused with a 412 every time.
 *
 * That is also what a contact UID needing escapes did: hrefs were built as
 * "{$uid}.vcf" with nothing escaped, so a space came back from the server
 * encoded and never matched again, and `#`, `?` or `/` produced a URL that
 * was not the resource at all -- `/` a path into a sub-collection, `../` one
 * outside the collection entirely.
 *
 * Canonical form: scheme and host lower-cased, a default port dropped, and
 * every path segment decoded and re-encoded with the set sabre/dav leaves
 * bare, which is what the servers this app is used with send back anyway.
 */
final class Href
{
    /** Escape $segment for use as one path segment. Everything sabre/dav leaves bare stays bare. */
    public static function encodeSegment(string $segment): string
    {
        return preg_replace_callback(
            '/[^A-Za-z0-9_\-.~():@]/',
            static fn(array $m): string => sprintf('%%%02X', ord($m[0])),
            $segment,
        ) ?? $segment;
    }

    public static function canonical(string $href): string
    {
        $parts = parse_url($href);
        if ($parts === false || !isset($parts['host'])) {
            return $href;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $port = isset($parts['port']) && $parts['port'] !== ($scheme === 'http' ? 80 : 443)
            ? ':' . $parts['port']
            : '';
        $path = implode('/', array_map(
            static fn(string $segment): string => self::encodeSegment(rawurldecode($segment)),
            explode('/', (string) ($parts['path'] ?? '/')),
        ));
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';

        return $scheme . '://' . strtolower($parts['host']) . $port . ($path === '' ? '/' : $path) . $query;
    }
}
