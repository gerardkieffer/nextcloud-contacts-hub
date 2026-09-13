<?php

declare(strict_types=1);

namespace OCA\ContactHub\Sync;

use OCA\DAV\CardDAV\CardDavBackend;

/**
 * A Nextcloud address book acting as one side of a sync job. This is the
 * hub: the place contacts actually live.
 *
 * Replaces the standalone app's HubSide, which spoke to its own MySQL
 * tables. The href/ETag vocabulary is unchanged, so the Runner cannot tell
 * the difference -- a card's DAV uri is its href, and Nextcloud's own ETag
 * (an md5 of the stored bytes, returned wrapped in double quotes) is its
 * ETag.
 *
 * # putVCard must read back. This is not optional.
 *
 * CardDavBackend::getCards()/getCard() pass stored bytes through a private
 * readBlob(), which strips PHOTO properties carrying non-image data: URIs
 * and rejoins lines with CRLF. So what comes out of Nextcloud is not always
 * byte-identical to what went in.
 *
 * That is survivable, but only because of two rules:
 *
 *  1. putVCard() returns the text Nextcloud *actually stored*, re-read after
 *     the write -- never the text it was handed. The SyncSide contract has
 *     always required this because the old hub canonicalised on write; here
 *     the reason is readBlob(). Runner hashes the returned text, so hashing
 *     the input instead would make every affected contact look modified on
 *     the next run and push forever.
 *
 *  2. All hashing goes through Model::textHash(), which normalises line
 *     endings before hashing. readBlob() is deterministic, so the hash of a
 *     given stored card is stable run over run even though it differs from
 *     the hash of the bytes originally uploaded.
 *
 * A visible consequence worth knowing: a contact arriving from an endpoint
 * with a non-image data: PHOTO loses that property on the way into
 * Nextcloud. It will look changed exactly once, then settle.
 *
 * # Groups are CATEGORIES here
 *
 * Nextcloud's Contacts app models groups as a CATEGORIES property on each
 * contact rather than as discrete KIND:group vCards, so this side reports
 * the 'categories' strategy. Nextcloud's CardDAV store would happily hold
 * group vCards -- iOS pushes them -- but the Contacts UI ignores them, so
 * they would be invisible to the user. Sync\CategoryGroups turns those
 * categories back into group objects for passthrough endpoints.
 */
class NextcloudSide implements SyncSide
{
    public function __construct(
        private readonly CardDavBackend $backend,
        private readonly int $addressBookId,
        private readonly string $label = 'Nextcloud',
    ) {
    }

    public function label(): string
    {
        return $this->label;
    }

    public function addressBookId(): int
    {
        return $this->addressBookId;
    }

    public function groupStrategy(): string
    {
        return 'categories';
    }

    public function fetchAll(?Progress $progress = null): array
    {
        // A local database read: fast enough that per-step reporting would
        // cost more than it tells anyone.
        $progress?->step(0, 0, "Reading {$this->label()}");

        $out = [];
        foreach ($this->backend->getCards($this->addressBookId) as $card) {
            $out[] = [
                'href' => (string) $card['uri'],
                'vcard' => (string) $card['carddata'],
            ];
        }

        return $out;
    }

    public function listEtags(): array
    {
        $out = [];
        foreach ($this->backend->getCards($this->addressBookId) as $card) {
            $out[(string) $card['uri']] = (string) $card['etag'];
        }

        return $out;
    }

    public function etagFor(string $href): ?string
    {
        $card = $this->backend->getCard($this->addressBookId, $href);

        return $card === false ? null : (string) $card['etag'];
    }

    public function getVCard(string $href): array
    {
        $card = $this->backend->getCard($this->addressBookId, $href);
        if ($card === false) {
            throw new \RuntimeException("No card at {$href} in address book {$this->addressBookId}.");
        }

        return [(string) $card['carddata'], (string) $card['etag']];
    }

    public function putVCard(string $href, string $vcardText, ?string $etag): array
    {
        $existing = $this->backend->getCard($this->addressBookId, $href);

        if ($etag === null) {
            // Mirrors a CardDAV server answering If-None-Match: * with 412.
            // A caller passing null means "this must not exist yet".
            if ($existing !== false) {
                throw new \RuntimeException("Card {$href} already exists (create was expected).");
            }
            $this->backend->createCard($this->addressBookId, $href, $vcardText);
        } else {
            if ($existing === false) {
                throw new \RuntimeException("Card {$href} vanished before an update could be applied.");
            }
            if ((string) $existing['etag'] !== $etag) {
                throw new \RuntimeException("Card {$href} changed underneath this run (ETag mismatch).");
            }
            $this->backend->updateCard($this->addressBookId, $href, $vcardText);
        }

        // Read back rather than trusting createCard/updateCard's return: see
        // the class docblock. The ETag they hand back is correct, but the
        // *bytes* are not necessarily what we sent.
        $stored = $this->backend->getCard($this->addressBookId, $href);
        if ($stored === false) {
            throw new \RuntimeException("Card {$href} could not be read back after writing.");
        }

        return [(string) $stored['etag'], (string) $stored['carddata']];
    }

    public function delete(string $href, ?string $etag): void
    {
        if ($etag !== null) {
            $existing = $this->backend->getCard($this->addressBookId, $href);
            if ($existing !== false && (string) $existing['etag'] !== $etag) {
                throw new \RuntimeException("Card {$href} changed underneath this run (ETag mismatch on delete).");
            }
        }

        $this->backend->deleteCard($this->addressBookId, $href);
    }

    /**
     * Nextcloud stores photo bytes inline in the card, so there is never an
     * external reference here to dereference. Only RemoteSide needs this,
     * for iCloud's PHOTO;VALUE=uri.
     */
    public function fetchBinary(string $url): string
    {
        throw new \RuntimeException("Nextcloud cannot dereference the external photo reference {$url}.");
    }

    public function hrefFor(string $uid): string
    {
        return rawurlencode($uid) . '.vcf';
    }
}
