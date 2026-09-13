<?php

declare(strict_types=1);

namespace OCA\ContactHub\Tests\Support;

use OCA\ContactHub\CardDav\HttpResponse;
use OCA\ContactHub\CardDav\HttpTransport;

/**
 * In-memory CardDAV server double: one account, one address-book
 * collection, backed by a plain array instead of real HTTP/network
 * calls. Lets Client/Runner/Tester be exercised end-to-end (discovery,
 * REPORT, PUT/GET/DELETE with If-Match, MKCOL) without a real server.
 */
final class FakeHttpTransport implements HttpTransport
{
    /** @var array<string, array{body: string, etag: string}> */
    public array $resources = [];
    public string $base;
    public string $principalHref;
    public string $homeHref;
    public string $collectionHref;
    public string $displayName = 'Contacts';
    public bool $writable = true;
    /** How many times the full REPORT (address-book-query) fetch has been issued -- lets resumability tests assert a resumed run skips re-fetching/re-planning entirely. */
    public int $reportCallCount = 0;

    /** How many addressbook-multiget REPORTs were issued -- chunked-fallback tests assert on this. */
    public int $multigetCallCount = 0;

    /** Whole-collection PROPFIND listings, and single-resource Depth: 0 ETag lookups. Resolving conflicts one at a time must use the second, not the first. */
    public int $etagListCallCount = 0;
    public int $singleEtagCallCount = 0;

    /** When true, the whole-collection addressbook-query REPORT throws DavTimeout (multiget still answers), mimicking a server that can't materialize the full book in one response. */
    public bool $timeoutFullReport = false;

    /** Artificial delay on the whole-collection fetch only, for tests that need the fetch phase to consume a run's time budget. Kept off by default -- real sleeping in tests is a cost, not a feature. */
    public float $fetchDelaySeconds = 0.0;
    /** Set to simulate a one-off systemic failure (e.g. a network blip) on the very next request, regardless of method/URL -- resets itself once triggered. */
    public bool $failNext = false;

    /** When true, every request answers 401 -- a server that reached the account and refused the credentials, as opposed to one that could not be reached at all. */
    public bool $rejectCredentials = false;

    /**
     * Hrefs the collection keeps listing but whose bodies come back with no
     * address-data -- a truncated or partly-refused REPORT.
     *
     * This is the shape the real hazard takes: the response is a perfectly
     * valid 207, and Client::collectAddressData() skips entries carrying no
     * address-data, so a short answer is indistinguishable from a smaller
     * address book unless something counts.
     *
     * @var list<string>
     */
    public array $dropFromAddressData = [];

    public function __construct(string $base)
    {
        $this->base = rtrim($base, '/') . '/';
        $this->principalHref = $this->base . 'principal/';
        $this->homeHref = $this->base . 'home/';
        $this->collectionHref = $this->homeHref . 'card/';
    }

    public function request(string $method, string $url, ?string $body = null, array $headers = []): HttpResponse
    {
        if ($this->failNext) {
            $this->failNext = false;
            throw new \RuntimeException('simulated network failure');
        }
        if ($this->rejectCredentials) {
            return new HttpResponse(401, [], 'Unauthorized');
        }
        if ($method === 'PROPFIND' && $url === $this->base) {
            return $this->principalResponse();
        }
        if ($method === 'PROPFIND' && $url === $this->principalHref) {
            return $this->homeSetResponse();
        }
        if ($method === 'PROPFIND' && $url === $this->homeHref) {
            return $this->collectionsResponse();
        }
        if ($method === 'PROPFIND' && $url === $this->collectionHref) {
            return $this->etagListResponse();
        }
        if ($method === 'PROPFIND') {
            // A single resource. Real servers answer Depth: 0 here; the
            // point of the separate branch is that tests can tell a
            // one-resource ETag lookup apart from a whole-collection
            // listing, which is the difference batch resolution turns on.
            return $this->singleEtagResponse($url);
        }
        if ($method === 'REPORT' && $url === $this->collectionHref) {
            if ($body !== null && str_contains($body, 'addressbook-multiget')) {
                return $this->multigetResponse($body);
            }
            if ($this->fetchDelaySeconds > 0.0) {
                usleep((int) ($this->fetchDelaySeconds * 1_000_000));
            }
            if ($this->timeoutFullReport) {
                $this->reportCallCount++;
                throw new \OCA\ContactHub\CardDav\DavTimeout(
                    "HTTP request failed for REPORT {$url}: simulated timeout with 0 bytes received"
                );
            }
            return $this->reportResponse();
        }
        if ($method === 'GET') {
            return isset($this->resources[$url])
                ? new HttpResponse(200, ['etag' => $this->resources[$url]['etag']], $this->resources[$url]['body'])
                : new HttpResponse(404, [], 'not found');
        }
        if ($method === 'PUT') {
            return $this->handlePut($url, $body ?? '', $headers['If-Match'] ?? null, $headers['If-None-Match'] ?? null);
        }
        if ($method === 'DELETE') {
            return $this->handleDelete($url, $headers['If-Match'] ?? null);
        }
        if ($method === 'MKCOL') {
            return new HttpResponse(201, [], '');
        }
        return new HttpResponse(400, [], 'unsupported in fake: ' . $method . ' ' . $url);
    }

    /** @return array{0: HttpResponse, 1: string} */
    public function requestFollowingRedirects(
        string $method,
        string $url,
        ?string $body = null,
        array $headers = [],
        int $maxRedirects = 5,
    ): array {
        return [$this->request($method, $url, $body, $headers), $url];
    }

    private function principalResponse(): HttpResponse
    {
        $xml = '<?xml version="1.0"?><D:multistatus xmlns:D="DAV:"><D:response><D:href>/</D:href>'
            . '<D:propstat><D:prop><D:current-user-principal><D:href>' . $this->principalHref
            . '</D:href></D:current-user-principal></D:prop><D:status>HTTP/1.1 200 OK</D:status>'
            . '</D:propstat></D:response></D:multistatus>';
        return new HttpResponse(200, [], $xml);
    }

    private function homeSetResponse(): HttpResponse
    {
        $xml = '<?xml version="1.0"?><D:multistatus xmlns:D="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav">'
            . '<D:response><D:href>' . $this->principalHref . '</D:href><D:propstat><D:prop>'
            . '<card:addressbook-home-set><D:href>' . $this->homeHref . '</D:href></card:addressbook-home-set>'
            . '</D:prop><D:status>HTTP/1.1 200 OK</D:status></D:propstat></D:response></D:multistatus>';
        return new HttpResponse(200, [], $xml);
    }

    private function collectionsResponse(): HttpResponse
    {
        $privilege = $this->writable
            ? '<D:current-user-privilege-set><D:privilege><D:write/></D:privilege></D:current-user-privilege-set>'
            : '<D:current-user-privilege-set><D:privilege><D:read/></D:privilege></D:current-user-privilege-set>';
        $xml = '<?xml version="1.0"?><D:multistatus xmlns:D="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav">'
            . '<D:response><D:href>' . $this->collectionHref . '</D:href><D:propstat><D:prop>'
            . '<D:resourcetype><D:collection/><card:addressbook/></D:resourcetype>'
            . '<D:displayname>' . $this->displayName . '</D:displayname>'
            . $privilege
            . '</D:prop><D:status>HTTP/1.1 200 OK</D:status></D:propstat></D:response></D:multistatus>';
        return new HttpResponse(200, [], $xml);
    }

    /** Answers only for the hrefs the request body names, like a real server. */
    private function multigetResponse(string $body): HttpResponse
    {
        $this->multigetCallCount++;
        preg_match_all('~<D:href>(.*?)</D:href>~', $body, $m);

        $responses = '';
        foreach ($m[1] as $escapedHref) {
            $href = html_entity_decode($escapedHref, ENT_XML1);
            if (!isset($this->resources[$href])) {
                continue;
            }
            if (in_array($href, $this->dropFromAddressData, true)) {
                continue;
            }
            $res = $this->resources[$href];
            $escaped = htmlspecialchars($res['body'], ENT_XML1);
            $responses .= '<D:response><D:href>' . $href . '</D:href><D:propstat><D:prop>'
                . '<D:getetag>' . $res['etag'] . '</D:getetag>'
                . '<card:address-data>' . $escaped . '</card:address-data>'
                . '</D:prop><D:status>HTTP/1.1 200 OK</D:status></D:propstat></D:response>';
        }
        $xml = '<?xml version="1.0"?><D:multistatus xmlns:D="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav">'
            . $responses . '</D:multistatus>';
        return new HttpResponse(207, [], $xml);
    }

    private function singleEtagResponse(string $url): HttpResponse
    {
        $this->singleEtagCallCount++;
        if (!isset($this->resources[$url])) {
            return new HttpResponse(404, [], 'not found');
        }

        return new HttpResponse(207, [], '<?xml version="1.0"?><D:multistatus xmlns:D="DAV:">'
            . '<D:response><D:href>' . $url . '</D:href><D:propstat><D:prop><D:getetag>'
            . $this->resources[$url]['etag'] . '</D:getetag></D:prop>'
            . '<D:status>HTTP/1.1 200 OK</D:status></D:propstat></D:response></D:multistatus>');
    }

    private function etagListResponse(): HttpResponse
    {
        $this->etagListCallCount++;
        $responses = '';
        foreach ($this->resources as $href => $res) {
            $responses .= '<D:response><D:href>' . $href . '</D:href><D:propstat><D:prop><D:getetag>'
                . $res['etag'] . '</D:getetag></D:prop><D:status>HTTP/1.1 200 OK</D:status></D:propstat></D:response>';
        }
        return new HttpResponse(200, [], '<?xml version="1.0"?><D:multistatus xmlns:D="DAV:">' . $responses . '</D:multistatus>');
    }

    private function reportResponse(): HttpResponse
    {
        $this->reportCallCount++;
        $responses = '';
        foreach ($this->resources as $href => $res) {
            if (in_array($href, $this->dropFromAddressData, true)) {
                continue;
            }
            $escaped = htmlspecialchars($res['body'], ENT_XML1);
            $responses .= '<D:response><D:href>' . $href . '</D:href><D:propstat><D:prop><card:address-data>'
                . $escaped . '</card:address-data></D:prop><D:status>HTTP/1.1 200 OK</D:status></D:propstat></D:response>';
        }
        $xml = '<?xml version="1.0"?><D:multistatus xmlns:D="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav">'
            . $responses . '</D:multistatus>';
        return new HttpResponse(200, [], $xml);
    }

    private function handlePut(string $url, string $body, ?string $ifMatch, ?string $ifNoneMatch = null): HttpResponse
    {
        $existing = $this->resources[$url] ?? null;
        if ($ifMatch !== null && ($existing === null || $existing['etag'] !== $ifMatch)) {
            return new HttpResponse(412, [], 'Precondition Failed');
        }
        if ($ifNoneMatch === '*' && $existing !== null) {
            return new HttpResponse(412, [], 'Precondition Failed');
        }
        $etag = '"' . substr(sha1($body), 0, 12) . '"';
        $this->resources[$url] = ['body' => $body, 'etag' => $etag];
        return new HttpResponse($existing === null ? 201 : 204, ['etag' => $etag], '');
    }

    private function handleDelete(string $url, ?string $ifMatch): HttpResponse
    {
        $existing = $this->resources[$url] ?? null;
        if ($existing === null) {
            return new HttpResponse(404, [], '');
        }
        if ($ifMatch !== null && $existing['etag'] !== $ifMatch) {
            return new HttpResponse(412, [], 'Precondition Failed');
        }
        unset($this->resources[$url]);
        return new HttpResponse(204, [], '');
    }
}
