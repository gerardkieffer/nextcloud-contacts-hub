<?php

declare(strict_types=1);

namespace OCA\ContactHub\Sync;

/**
 * One side of a sync job: either a remote CardDAV collection or the
 * hub's own address book.
 *
 * The Runner talks only to this, so the diff/apply machinery does not
 * care which side is local. Both implementations use the same
 * href/ETag vocabulary; the hub simply mints synthetic hrefs and uses
 * its content hash as the ETag.
 */
interface SyncSide
{
    /** Human-readable name for warnings and error messages. */
    public function label(): string;

    /** How this side represents groups: passthrough, categories, or collections. */
    public function groupStrategy(): string;

    /**
     * Every vCard on this side, paired with its href.
     *
     * Takes an optional Progress because on a remote side this is the
     * single slowest thing a run does (chunked multiget against a
     * server that can't answer a whole-collection REPORT), and a run
     * that reports nothing for 85 seconds looks hung.
     *
     * @return list<array{href: string, vcard: string}>
     */
    public function fetchAll(?Progress $progress = null): array;

    /** Live href => ETag for everything on this side. @return array<string, string> */
    public function listEtags(): array;

    /**
     * One resource's live ETag, or null when this side does not have it.
     *
     * The same answer as listEtags()[$href] ?? null, without paying for
     * the whole collection to get it. A run wants the full listing
     * (it is about to diff everything); anything resolving items one at
     * a time -- manual conflict resolution, above all a batch of them --
     * wants this instead.
     */
    public function etagFor(string $href): ?string;

    /** @return array{0: string, 1: ?string} vCard text, ETag */
    public function getVCard(string $href): array;

    /**
     * Write a vCard. $etag guards an update (If-Match); null means
     * "must not exist yet" (If-None-Match: *).
     *
     * Returns the new ETag *and the text actually stored*, which is not
     * always the text passed in: the hub canonicalizes on write. Callers
     * must hash the returned text rather than what they sent, or a side
     * that rewrites on store would look changed again on the next run.
     *
     * @return array{0: ?string, 1: string} new ETag, stored text
     */
    public function putVCard(string $href, string $vcardText, ?string $etag): array;

    public function delete(string $href, ?string $etag): void;

    /** Fetch a binary referenced by this side (a PHOTO;VALUE=uri target). */
    public function fetchBinary(string $url): string;

    /** Where a resource for $uid should live on this side. */
    public function hrefFor(string $uid): string;
}
