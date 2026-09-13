<?php

declare(strict_types=1);

namespace OCA\ContactHub\CardDav;

use OCP\Http\Client\IClientService;
use OCP\Http\Client\LocalServerException;

/**
 * CardDAV over Nextcloud's own HTTP client.
 *
 * Replaces the standalone app's direct curl wrapper. The reasons are
 * administrative rather than technical: IClientService honours the
 * instance's proxy configuration and certificate bundle, and shipping raw
 * curl calls is something app-store review reliably objects to.
 *
 * IClient::request() takes an arbitrary verb, which is what makes this
 * possible at all -- PROPFIND, REPORT and MKCOL are not among the named
 * helper methods.
 *
 * Two options are load-bearing:
 *
 *  * allow_redirects => false. Discovery follows redirects itself so it can
 *    keep the URL it was finally answered at (see AbstractTransport).
 *  * http_errors => false. A 4xx/5xx is data here, not an exception: the
 *    capability tester and the conditional-PUT paths both branch on status
 *    codes, and 412 in particular is a normal, expected answer.
 */
class NextcloudHttpTransport extends AbstractTransport
{
    public function __construct(
        private readonly IClientService $clientService,
        private readonly string $username,
        private readonly string $password,
        private readonly float $timeoutSeconds = 30.0,
    ) {
    }

    /** @param array<string, string> $headers */
    public function request(string $method, string $url, ?string $body = null, array $headers = []): HttpResponse
    {
        $options = [
            'headers' => $headers,
            'auth' => [$this->username, $this->password],
            'allow_redirects' => false,
            'http_errors' => false,
            'timeout' => $this->timeoutSeconds,
            'connect_timeout' => min(10.0, $this->timeoutSeconds),
            'verify' => true,
        ];
        if ($body !== null) {
            $options['body'] = $body;
        }

        try {
            $response = $this->clientService->newClient()->request($method, $url, $options);
        } catch (LocalServerException $e) {
            // Nextcloud refuses requests to loopback and private addresses
            // unless an administrator opts in. That is a sensible default
            // against SSRF, but it also blocks a perfectly reasonable setup:
            // a CardDAV server on the same LAN, such as Baikal or Radicale on
            // a NAS. The raw message ("violates local access rules") does not
            // tell anyone what to do about it, so say it plainly.
            throw new DavException(
                "Nextcloud refused to connect to {$url} because it is on a local or private network. "
                . 'An administrator can allow this by setting allow_local_remote_servers to true in '
                . "config.php. Original error: {$e->getMessage()}",
                0,
                $e,
            );
        } catch (\Throwable $e) {
            if (self::looksLikeTimeout($e)) {
                throw new DavTimeout("HTTP request failed for {$method} {$url}: {$e->getMessage()}", 0, $e);
            }
            throw new DavException("HTTP request failed for {$method} {$url}: {$e->getMessage()}", 0, $e);
        }

        $normalised = [];
        foreach ($response->getHeaders() as $name => $values) {
            // Guzzle hands back a list per header; the transport contract is
            // one string per name, last value winning, matching curl's
            // header callback.
            $normalised[strtolower((string) $name)] = is_array($values)
                ? (string) end($values)
                : (string) $values;
        }

        return new HttpResponse($response->getStatusCode(), $normalised, (string) $response->getBody());
    }

    /**
     * Whether a transport failure was a timeout rather than a refusal.
     *
     * This distinction is not cosmetic: Client::fetchAllVCards() falls back
     * from one whole-collection REPORT to chunked multigets when, and only
     * when, the big request timed out. Misclassifying a 4xx/5xx as a timeout
     * would retry a refusal in sixteen pieces.
     *
     * Guzzle does not expose a typed timeout, so this reads the message
     * chain. "cURL error 28" is curl's own CURLE_OPERATION_TIMEDOUT and is
     * the reliable signal; the wording check is a fallback for non-curl
     * handlers. Public and static so it can be unit-tested without a
     * Nextcloud runtime.
     */
    public static function looksLikeTimeout(\Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            $message = $current->getMessage();
            if (str_contains($message, 'cURL error 28')) {
                return true;
            }
            if (preg_match('/\btimed?[ -]?out\b/i', $message) === 1) {
                return true;
            }
        }

        return false;
    }
}
