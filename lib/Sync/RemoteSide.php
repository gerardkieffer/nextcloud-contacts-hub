<?php

declare(strict_types=1);

namespace OCA\ContactHub\Sync;

use OCA\ContactHub\CardDav\Client;
use OCA\ContactHub\CardDav\AbstractTransport;
use OCA\ContactHub\CardDav\Href;

/** A remote CardDAV collection acting as one side of a sync job. */
final class RemoteSide implements SyncSide
{
    public function __construct(
        private readonly Client $client,
        public readonly Endpoint $endpoint,
    ) {
        if ($endpoint->collectionHref === null) {
            throw new \RuntimeException(
                "Endpoint '{$endpoint->name}' has no collection selected yet -- run its capability test first."
            );
        }
    }

    public function label(): string
    {
        return $this->endpoint->name;
    }

    public function groupStrategy(): string
    {
        return $this->endpoint->groupStrategy;
    }

    public function collectionHref(): string
    {
        return (string) $this->endpoint->collectionHref;
    }

    public function client(): Client
    {
        return $this->client;
    }

    public function fetchAll(?Progress $progress = null): array
    {
        $label = $this->label();
        return $this->client->fetchAllVCards(
            $this->collectionHref(),
            $progress === null ? null : static function (int $done, int $total) use ($progress, $label): void {
                $progress->step($done, $total, "Downloaded {$done} of {$total} from {$label}");
            },
        );
    }

    public function listEtags(): array
    {
        return $this->client->listCollectionEtags($this->collectionHref());
    }

    public function etagFor(string $href): ?string
    {
        $this->assertOwns($href);

        return $this->client->etagFor($href);
    }

    public function getVCard(string $href): array
    {
        $this->assertOwns($href);

        return $this->client->getVCard($href);
    }

    public function putVCard(string $href, string $vcardText, ?string $etag): array
    {
        $this->assertOwns($href);

        // A CardDAV server stores the bytes verbatim, so what was sent is
        // what is now there.
        return [$this->client->putVCard($href, $vcardText, $etag), $vcardText];
    }

    public function delete(string $href, ?string $etag): void
    {
        $this->assertOwns($href);
        $this->client->delete($href, $etag);
    }

    /**
     * Inside this endpoint's collection: same scheme, host and port, and a
     * path under the collection's.
     *
     * Compared structurally rather than as a string prefix, because servers
     * are free to spell the same location differently -- an explicit :443,
     * a host in another case, percent-encoding -- and reading one of those
     * as foreign would make a contact look absent and get created twice.
     */
    public function owns(string $href): bool
    {
        $mine = parse_url($this->collectionHref());
        $theirs = parse_url($href);
        if ($mine === false || $theirs === false || !isset($theirs['host'])) {
            return false;
        }

        $origin = static function (array $p): array {
            $scheme = strtolower((string) ($p['scheme'] ?? 'https'));

            return [$scheme, strtolower((string) ($p['host'] ?? '')), (int) ($p['port'] ?? ($scheme === 'http' ? 80 : 443))];
        };
        if ($origin($mine) !== $origin($theirs)) {
            return false;
        }

        $base = rtrim(rawurldecode((string) ($mine['path'] ?? '')), '/') . '/';

        return str_starts_with(rawurldecode((string) ($theirs['path'] ?? '')), $base);
    }

    /**
     * The last line of defence: no request carrying this endpoint's
     * credentials goes to a resource outside its collection. Photo fetches
     * are exempt on purpose -- iCloud serves photos from another host, and
     * those references are authenticated with exactly these credentials.
     */
    private function assertOwns(string $href): void
    {
        if (!$this->owns($href)) {
            throw new \RuntimeException(
                "Refusing to send a request for {$href} with the credentials of endpoint '{$this->endpoint->name}': "
                . "it is outside that endpoint's collection ({$this->collectionHref()})."
            );
        }
    }

    public function fetchBinary(string $url): string
    {
        return $this->client->fetchBinary($url);
    }

    /**
     * "<uid>.vcf", escaped as one path segment: unescaped, a space came back
     * from the server spelled differently and never matched again, and `#`,
     * `?`, `/` or `../` named some other resource entirely. See Href.
     *
     * Some UIDs cannot be a file name even escaped. Apache -- in front of
     * Nextcloud, and of many other servers -- refuses an encoded slash in a
     * path with a 404 before the DAV server sees it (verified against
     * Nextcloud's own endpoint), and a backslash, a control character or a
     * very long UID are no better. Those get a name derived from the UID
     * instead: stable, so the same contact always lands at the same href,
     * and harmless, since the UID itself is inside the vCard.
     */
    public function hrefFor(string $uid): string
    {
        // A slash, a backslash, or a control character.
        $name = preg_match('~[/\\\\\x00-\x1F\x7F]~', $uid) === 1 || strlen($uid) > 200
            ? 'uid-' . sha1($uid)
            : Href::encodeSegment($uid);

        return Href::canonical(AbstractTransport::resolveUrl($this->collectionHref(), $name . '.vcf'));
    }

    public function normalizeHref(string $href): string
    {
        return Href::canonical($href);
    }
}
