<?php

declare(strict_types=1);

namespace OCA\ContactHub\Sync;

use OCA\ContactHub\CardDav\Client;
use OCA\ContactHub\CardDav\AbstractTransport;

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
        return $this->client->etagFor($href);
    }

    public function getVCard(string $href): array
    {
        return $this->client->getVCard($href);
    }

    public function putVCard(string $href, string $vcardText, ?string $etag): array
    {
        // A CardDAV server stores the bytes verbatim, so what was sent is
        // what is now there.
        return [$this->client->putVCard($href, $vcardText, $etag), $vcardText];
    }

    public function delete(string $href, ?string $etag): void
    {
        $this->client->delete($href, $etag);
    }

    public function fetchBinary(string $url): string
    {
        return $this->client->fetchBinary($url);
    }

    public function hrefFor(string $uid): string
    {
        return AbstractTransport::resolveUrl($this->collectionHref(), "{$uid}.vcf");
    }
}
