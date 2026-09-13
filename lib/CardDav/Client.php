<?php

declare(strict_types=1);

namespace OCA\ContactHub\CardDav;

/**
 * Generic RFC 6352 CardDAV client: discovery + read + write. Unlike the
 * old Python codebase (which split a read-only iCloud client from a
 * read/write destination client to make the origin un-mutable "by
 * construction"), any endpoint can now be a source or a destination, so
 * there is one client. A job's direction decides which: see the sync
 * engine for how it decides whether a given endpoint is actually
 * written to.
 */
final class Client
{
    private const string PRINCIPAL_BODY = <<<'XML'
        <?xml version="1.0" encoding="utf-8" ?>
        <D:propfind xmlns:D="DAV:">
          <D:prop><D:current-user-principal/></D:prop>
        </D:propfind>
        XML;

    private const string HOME_SET_BODY = <<<'XML'
        <?xml version="1.0" encoding="utf-8" ?>
        <D:propfind xmlns:D="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav">
          <D:prop><card:addressbook-home-set/></D:prop>
        </D:propfind>
        XML;

    private const string LIST_COLLECTIONS_BODY = <<<'XML'
        <?xml version="1.0" encoding="utf-8" ?>
        <D:propfind xmlns:D="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav">
          <D:prop>
            <D:resourcetype/>
            <D:displayname/>
            <D:current-user-privilege-set/>
            <D:supported-report-set/>
            <card:supported-address-data/>
          </D:prop>
        </D:propfind>
        XML;

    private const string LIST_RESOURCES_BODY = <<<'XML'
        <?xml version="1.0" encoding="utf-8" ?>
        <D:propfind xmlns:D="DAV:">
          <D:prop><D:getetag/></D:prop>
        </D:propfind>
        XML;

    private const string ADDRESSBOOK_QUERY_BODY = <<<'XML'
        <?xml version="1.0" encoding="utf-8" ?>
        <C:addressbook-query xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:carddav">
          <D:prop><C:address-data/></D:prop>
        </C:addressbook-query>
        XML;

    public function __construct(private readonly HttpTransport $http)
    {
    }

    public function discoverHomeSet(string $startUrl): string
    {
        [$resp, $base] = $this->http->requestFollowingRedirects(
            'PROPFIND',
            $startUrl,
            self::PRINCIPAL_BODY,
            ['Content-Type' => 'text/xml; charset=utf-8', 'Depth' => '0'],
        );
        DavXml::assertOk($resp, 'principal discovery');
        $principalHref = DavXml::extractText($resp->body, 'current-user-principal', 'href');
        if ($principalHref === null) {
            throw new DavException('Could not find current-user-principal');
        }
        $principalUrl = AbstractTransport::resolveUrl($base, $principalHref);

        [$resp2, $base2] = $this->http->requestFollowingRedirects(
            'PROPFIND',
            $principalUrl,
            self::HOME_SET_BODY,
            ['Content-Type' => 'text/xml; charset=utf-8', 'Depth' => '0'],
        );
        DavXml::assertOk($resp2, 'addressbook-home-set discovery');
        $homeHref = DavXml::extractText($resp2->body, 'addressbook-home-set', 'href');
        if ($homeHref === null) {
            throw new DavException('Could not find addressbook-home-set');
        }
        $homeUrl = AbstractTransport::resolveUrl($base2, $homeHref);
        return str_ends_with($homeUrl, '/') ? $homeUrl : $homeUrl . '/';
    }

    /** @return list<array{href: string, displayname: ?string, writable: bool, supports_sync_collection: bool}> */
    public function listCollections(string $homeUrl): array
    {
        $resp = $this->http->request(
            'PROPFIND',
            $homeUrl,
            self::LIST_COLLECTIONS_BODY,
            ['Content-Type' => 'text/xml; charset=utf-8', 'Depth' => '1'],
        );
        DavXml::assertOk($resp, 'list address-book collections');

        $out = [];
        foreach (DavXml::parseMultistatus($resp->body) as $r) {
            if ($r->href === null || !$r->hasChild('resourcetype', 'addressbook')) {
                continue;
            }
            $writable = $r->hasChild('current-user-privilege-set', 'write')
                || $r->hasChild('current-user-privilege-set', 'all')
                || $r->hasChild('current-user-privilege-set', 'write-content');
            $out[] = [
                'href' => AbstractTransport::resolveUrl($homeUrl, $r->href),
                'displayname' => $r->text('displayname'),
                'writable' => $writable,
                'supports_sync_collection' => $r->hasChild('supported-report-set', 'sync-collection'),
            ];
        }
        return $out;
    }

    public function pickCollection(string $homeUrl, ?string $collectionName = null): string
    {
        $collections = $this->listCollections($homeUrl);

        if ($collectionName !== null) {
            foreach ($collections as $c) {
                if ($c['displayname'] === $collectionName) {
                    if (!$c['writable']) {
                        throw new DavException(
                            "Collection '{$collectionName}' was found but is not writable by this account -- "
                            . 'refusing to guess a different one.'
                        );
                    }
                    return $c['href'];
                }
            }
            $available = implode(', ', array_map(
                static fn(array $c): string => "'" . ($c['displayname'] ?? '(unnamed)') . "'",
                $collections,
            ));
            throw new DavException(
                "Collection named '{$collectionName}' not found. Available collections: "
                . ($available !== '' ? $available : '(none)')
            );
        }

        $writable = array_values(array_filter($collections, static fn(array $c): bool => $c['writable']));
        if ($writable === []) {
            throw new DavException('No writable address-book collection found on this endpoint.');
        }
        if (count($writable) > 1) {
            $names = implode(', ', array_map(
                static fn(array $c): string => "'" . ($c['displayname'] ?? '(unnamed)') . "'",
                $writable,
            ));
            throw new DavException(
                "Multiple writable address-book collections found ({$names}) and no collection name was "
                . 'configured -- set one explicitly rather than letting this pick one arbitrarily.'
            );
        }
        return $writable[0]['href'];
    }

    /** @return array<string, string> href => etag */
    public function listCollectionEtags(string $collectionHref): array
    {
        $resp = $this->http->request(
            'PROPFIND',
            $collectionHref,
            self::LIST_RESOURCES_BODY,
            ['Content-Type' => 'text/xml; charset=utf-8', 'Depth' => '1'],
        );
        DavXml::assertOk($resp, "list resources in {$collectionHref}");

        $out = [];
        $collectionPath = rtrim($collectionHref, '/');
        foreach (DavXml::parseMultistatus($resp->body) as $r) {
            if ($r->href === null) {
                continue;
            }
            $href = AbstractTransport::resolveUrl($collectionHref, $r->href);
            if (rtrim($href, '/') === $collectionPath) {
                continue;
            }
            $etag = $r->text('getetag');
            if ($etag !== null && $etag !== '') {
                $out[$href] = $etag;
            }
        }
        return $out;
    }

    /**
     * One resource's live ETag, or null when it is not there.
     *
     * A Depth: 0 PROPFIND on the resource itself, rather than asking for
     * the whole collection and picking one line out of the answer. The
     * collection listing is cheap per *run* -- a run needs all of them
     * anyway -- but expensive per *item*: resolving a batch of conflicts
     * against an address book of a few thousand cards otherwise pays for
     * one full listing per conflict.
     *
     * Only 404/410 mean "not there". Every other error still throws,
     * because a caller reading null as absence turns its next write into
     * a create (If-None-Match: *), and a server that merely refused the
     * PROPFIND must not be allowed to look like an empty collection.
     */
    public function etagFor(string $href): ?string
    {
        $resp = $this->http->request(
            'PROPFIND',
            $href,
            self::LIST_RESOURCES_BODY,
            ['Content-Type' => 'text/xml; charset=utf-8', 'Depth' => '0'],
        );
        if ($resp->status === 404 || $resp->status === 410) {
            return null;
        }
        DavXml::assertOk($resp, "read the ETag of {$href}");

        foreach (DavXml::parseMultistatus($resp->body) as $r) {
            $etag = $r->text('getetag');
            if ($etag !== null && $etag !== '') {
                return $etag;
            }
        }

        // A 207 that carries no getetag: the resource is gone (a 404
        // propstat) or the server does not expose one. Either way there is
        // no validator to guard a write with.
        return null;
    }

    /**
     * How many hrefs go into one addressbook-multiget when falling back
     * from the single whole-collection REPORT. Sized from a live
     * measurement against Mailo (2026-07-28): 50 cards came back as
     * ~550 KB in ~3s, on the same account whose whole-collection REPORT
     * (766 contacts) produced zero bytes in 30s.
     */
    private const int MULTIGET_CHUNK = 50;

    /**
     * Fetch every vCard (contacts + group vCards) in a collection,
     * paired with its own href -- needed so the sync engine can track
     * each item's location on this side, not just its content.
     *
     * Normally one addressbook-query REPORT for the whole collection
     * (a single round trip; iCloud and Infomaniak answer it fine). If
     * that request *times out* -- some servers (Mailo) cannot
     * materialize hundreds of cards in one response -- fall back to
     * listing hrefs via PROPFIND (cheap everywhere: 766 resources in
     * ~1s on the server that motivated this) and pulling the bodies in
     * bounded addressbook-multiget chunks. Only DavTimeout triggers
     * the fallback: a 4xx/5xx means the server refused, and refusing
     * in 16 smaller pieces is still refusing.
     *
     * @param null|callable(int, int): void $onProgress fetched-so-far, total (chunked path only)
     * @return list<array{href: string, vcard: string}>
     */
    public function fetchAllVCards(string $collectionHref, ?callable $onProgress = null): array
    {
        try {
            $resp = $this->http->request(
                'REPORT',
                $collectionHref,
                self::ADDRESSBOOK_QUERY_BODY,
                ['Content-Type' => 'text/xml; charset=utf-8', 'Depth' => '1'],
            );
        } catch (DavTimeout) {
            return $this->fetchAllVCardsChunked($collectionHref, $onProgress);
        }
        DavXml::assertOk($resp, 'addressbook-query REPORT');

        return $this->collectAddressData($collectionHref, $resp->body);
    }

    /**
     * @param null|callable(int, int): void $onProgress
     * @return list<array{href: string, vcard: string}>
     */
    private function fetchAllVCardsChunked(string $collectionHref, ?callable $onProgress = null): array
    {
        $hrefs = array_keys($this->listCollectionEtags($collectionHref));
        $total = count($hrefs);

        $out = [];
        foreach (array_chunk($hrefs, self::MULTIGET_CHUNK) as $chunk) {
            foreach ($this->multigetVCards($collectionHref, $chunk) as $pair) {
                $out[] = $pair;
            }
            if ($onProgress !== null) {
                $onProgress(count($out), $total);
            }
        }
        return $out;
    }

    /**
     * addressbook-multiget REPORT (RFC 6352 section 8.7) for an
     * explicit list of hrefs.
     *
     * @param list<string> $hrefs
     * @return list<array{href: string, vcard: string}>
     */
    public function multigetVCards(string $collectionHref, array $hrefs): array
    {
        $body = '<?xml version="1.0" encoding="utf-8" ?>'
            . '<C:addressbook-multiget xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:carddav">'
            . '<D:prop><D:getetag/><C:address-data/></D:prop>';
        foreach ($hrefs as $href) {
            $body .= '<D:href>' . self::xmlEscape($href) . '</D:href>';
        }
        $body .= '</C:addressbook-multiget>';

        $resp = $this->http->request(
            'REPORT',
            $collectionHref,
            $body,
            ['Content-Type' => 'text/xml; charset=utf-8', 'Depth' => '1'],
        );
        DavXml::assertOk($resp, 'addressbook-multiget REPORT');

        return $this->collectAddressData($collectionHref, $resp->body);
    }

    /** @return list<array{href: string, vcard: string}> */
    private function collectAddressData(string $collectionHref, string $multistatusXml): array
    {
        $out = [];
        foreach (DavXml::parseMultistatus($multistatusXml) as $r) {
            $vcard = $r->text('address-data');
            if ($r->href === null || $vcard === null || $vcard === '') {
                continue;
            }
            $out[] = ['href' => AbstractTransport::resolveUrl($collectionHref, $r->href), 'vcard' => $vcard];
        }
        return $out;
    }

    /** @return array{0: string, 1: ?string} vCard text + ETag */
    public function getVCard(string $href): array
    {
        $resp = $this->http->request('GET', $href);
        DavXml::assertOk($resp, "GET {$href}");
        return [$resp->body, $resp->header('etag')];
    }

    public function fetchBinary(string $url): string
    {
        $resp = $this->http->request('GET', $url);
        DavXml::assertOk($resp, "GET {$url}");
        return $resp->body;
    }

    /**
     * Returns the new ETag, if the server sent one. Every PUT is
     * conditional: with $etag it's an update guarded by If-Match
     * ($etag is always the just-fetched live value, never a cached
     * one), without it it's a create guarded by If-None-Match: * --
     * a resource that unexpectedly appeared at the target href 412s
     * instead of being silently overwritten (RFC 6352 §6.3.2). Every
     * caller passing null genuinely means "this should not exist yet".
     */
    public function putVCard(string $href, string $vcardText, ?string $etag = null): ?string
    {
        $headers = ['Content-Type' => 'text/vcard; charset=utf-8'];
        if ($etag !== null) {
            $headers['If-Match'] = $etag;
        } else {
            $headers['If-None-Match'] = '*';
        }
        $resp = $this->http->request('PUT', $href, $vcardText, $headers);
        DavXml::assertOk($resp, "PUT {$href}");
        return $resp->header('etag');
    }

    public function delete(string $href, ?string $etag = null): void
    {
        $headers = [];
        if ($etag !== null) {
            $headers['If-Match'] = $etag;
        }
        $resp = $this->http->request('DELETE', $href, null, $headers);
        if (!in_array($resp->status, [200, 204, 404], true)) {
            DavXml::assertOk($resp, "DELETE {$href}");
        }
    }

    public function mkcol(string $homeUrl, string $name): string
    {
        $href = AbstractTransport::resolveUrl($homeUrl, self::slugify($name) . '/');
        $body = '<?xml version="1.0" encoding="utf-8" ?>'
            . '<D:mkcol xmlns:D="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav">'
            . '<D:set><D:prop>'
            . '<D:resourcetype><D:collection/><card:addressbook/></D:resourcetype>'
            . '<D:displayname>' . self::xmlEscape($name) . '</D:displayname>'
            . '</D:prop></D:set></D:mkcol>';
        $resp = $this->http->request('MKCOL', $href, $body, ['Content-Type' => 'text/xml; charset=utf-8']);
        DavXml::assertOk($resp, "create collection '{$name}'");
        return $href;
    }

    private static function slugify(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9._-]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        return $slug !== '' ? $slug : 'collection';
    }

    private static function xmlEscape(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }
}
