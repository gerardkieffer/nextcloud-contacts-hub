<?php

declare(strict_types=1);

namespace OCA\ContactHub\CapabilityTest;

use OCA\ContactHub\CardDav\Client;
use OCA\ContactHub\CardDav\DavException;
use OCA\ContactHub\CardDav\AbstractTransport;
use OCA\ContactHub\VCard\Contact;
use OCA\ContactHub\VCard\Document;
use OCA\ContactHub\VCard\Group;
use OCA\ContactHub\VCard\Model;
use OCA\ContactHub\VCard\Transform;

/**
 * Probes a live CardDAV server and reports what it actually supports,
 * so settings can be derived from reality rather than guessed from a
 * preset. Three phases, each strictly more invasive than the last:
 *
 *  1. discover()    -- always read-only: principal/home-set/collection
 *                       discovery, privilege parsing, vCard version.
 *  2. writeProbe()  -- opt-in: creates one throwaway test contact and
 *                       one throwaway test group vCard, checks
 *                       round-trip fidelity and real If-Match
 *                       enforcement, then *always* attempts to delete
 *                       both afterwards, even if an earlier step
 *                       errored, and reports cleanup success/failure
 *                       explicitly.
 *  3. mkcolProbe()  -- opt-in, separate from (2): creates and deletes a
 *                       throwaway collection, to test viability of the
 *                       "collections" group strategy.
 *
 * None of these ever read, modify, or delete the user's real contacts.
 */
final class Tester
{
    private const string TEST_MARKER = 'CardDAV Sync Test';

    public function __construct(private readonly Client $client)
    {
    }

    /**
     * @return array{
     *     home_url: string,
     *     collections: list<array{href: string, displayname: ?string, writable: bool, supports_sync_collection: bool}>,
     *     selected: ?array{href: string, displayname: ?string, writable: bool, supports_sync_collection: bool},
     *     vcard_version: ?string,
     *     warnings: string[],
     * }
     */
    public function discover(string $startUrl, ?string $collectionName = null): array
    {
        $homeUrl = $this->client->discoverHomeSet($startUrl);
        $collections = $this->client->listCollections($homeUrl);

        $warnings = [];
        $selected = null;
        try {
            $href = $this->client->pickCollection($homeUrl, $collectionName);
            foreach ($collections as $c) {
                if ($c['href'] === $href) {
                    $selected = $c;
                    break;
                }
            }
        } catch (DavException $e) {
            $warnings[] = $e->getMessage();
        }

        $vcardVersion = $selected !== null ? $this->detectVCardVersion($selected['href']) : null;

        return [
            'home_url' => $homeUrl,
            'collections' => $collections,
            'selected' => $selected,
            'vcard_version' => $vcardVersion,
            'warnings' => $warnings,
        ];
    }

    private function detectVCardVersion(string $collectionHref): ?string
    {
        try {
            $etags = $this->client->listCollectionEtags($collectionHref);
            if ($etags === []) {
                return null;
            }
            [$text] = $this->client->getVCard(array_key_first($etags));
            if (preg_match('/^VERSION:(.+)$/mi', $text, $m) === 1) {
                return trim($m[1]);
            }
            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{
     *     ran: bool,
     *     contact_roundtrip_ok: bool,
     *     categories_roundtrip_ok: bool,
     *     group_roundtrip_ok: bool,
     *     if_match_enforced: ?bool,
     *     cleanup_ok: bool,
     *     errors: string[],
     * }
     */
    public function writeProbe(string $collectionHref): array
    {
        $errors = [];
        $contactRoundtripOk = false;
        $categoriesRoundtripOk = false;
        $groupRoundtripOk = false;
        $ifMatchEnforced = null;

        $testId = bin2hex(random_bytes(8));
        $contactUid = "carddav-sync-test-contact-{$testId}";
        $groupUid = "carddav-sync-test-group-{$testId}";
        $contactHref = null;
        $groupHref = null;

        try {
            $contactHref = AbstractTransport::resolveUrl($collectionHref, "{$contactUid}.vcf");
            $this->client->putVCard($contactHref, $this->buildTestContact($contactUid));
            [$fetched, $liveEtag] = $this->client->getVCard($contactHref);
            // Parsed structurally (not a raw substring search on the wire
            // text) so a long line getting RFC-compliant folded doesn't
            // register as a false failure -- see the group check below,
            // which hit exactly that bug against a real server.
            $parsedContact = Model::parse($fetched);
            $contactRoundtripOk = $parsedContact instanceof Contact
                && $parsedContact->uid === $contactUid
                && str_contains($parsedContact->fn, self::TEST_MARKER);

            $withCategories = Transform::setCategories($fetched, ['CardDAV-Sync-Test']);
            $this->client->putVCard($contactHref, $withCategories, $liveEtag);
            [$fetched2, $liveEtag2] = $this->client->getVCard($contactHref);
            $categoriesProp = Document::parse($fetched2)->first('CATEGORIES');
            $categoriesRoundtripOk = $categoriesProp !== null && str_contains($categoriesProp->value, 'CardDAV-Sync-Test');

            try {
                $this->client->putVCard($contactHref, $fetched2, 'W/"deliberately-wrong-etag-for-testing"');
                $ifMatchEnforced = false;
            } catch (DavException $e) {
                // The status, not a substring of the message: a server that
                // happens to mention "412" in an error body for some other
                // refusal would otherwise be recorded as enforcing If-Match
                // when it does not.
                $ifMatchEnforced = $e->status === 412;
            }
        } catch (\Throwable $e) {
            $errors[] = "contact probe failed: {$e->getMessage()}";
        }

        try {
            $groupText = Transform::buildGroupVCard(
                $groupUid,
                self::TEST_MARKER . ' Group -- safe to delete',
                [$contactUid],
            );
            $groupHref = AbstractTransport::resolveUrl($collectionHref, "{$groupUid}.vcf");
            $this->client->putVCard($groupHref, $groupText);
            [$fetchedGroup] = $this->client->getVCard($groupHref);
            $parsedGroup = Model::parse($fetchedGroup);
            $groupRoundtripOk = $parsedGroup instanceof Group
                && in_array($contactUid, $parsedGroup->memberUids, true);
        } catch (\Throwable $e) {
            $errors[] = "group probe failed: {$e->getMessage()}";
        }

        $cleanupOk = true;
        foreach ([$contactHref, $groupHref] as $href) {
            if ($href === null) {
                continue;
            }
            try {
                $this->client->delete($href);
            } catch (\Throwable $e) {
                $cleanupOk = false;
                $errors[] = "cleanup failed for {$href}: {$e->getMessage()} -- please remove it manually.";
            }
        }

        return [
            'ran' => true,
            'contact_roundtrip_ok' => $contactRoundtripOk,
            'categories_roundtrip_ok' => $categoriesRoundtripOk,
            'group_roundtrip_ok' => $groupRoundtripOk,
            'if_match_enforced' => $ifMatchEnforced,
            'cleanup_ok' => $cleanupOk,
            'errors' => $errors,
        ];
    }

    private function buildTestContact(string $uid): string
    {
        return "BEGIN:VCARD\r\n"
            . "VERSION:3.0\r\n"
            . 'FN:' . self::TEST_MARKER . " Contact -- safe to delete\r\n"
            . "N:Contact;CardDAV Sync Test;;;\r\n"
            . "UID:{$uid}\r\n"
            . "END:VCARD\r\n";
    }

    /** @return array{ran: bool, ok: bool, cleanup_ok: bool, errors: string[]} */
    public function mkcolProbe(string $homeUrl): array
    {
        $errors = [];
        $ok = false;
        $cleanupOk = true;
        $href = null;

        try {
            $href = $this->client->mkcol($homeUrl, self::TEST_MARKER . ' Collection ' . bin2hex(random_bytes(4)));
            $ok = true;
        } catch (\Throwable $e) {
            $errors[] = "mkcol probe failed: {$e->getMessage()}";
        }

        if ($href !== null) {
            try {
                $this->client->delete($href);
            } catch (\Throwable $e) {
                $cleanupOk = false;
                $errors[] = "cleanup failed for {$href}: {$e->getMessage()} -- please remove it manually.";
            }
        }

        return ['ran' => true, 'ok' => $ok, 'cleanup_ok' => $cleanupOk, 'errors' => $errors];
    }

    /**
     * Suggest a group_strategy from probe results actually observed,
     * rather than assumed from the preset.
     *
     * @param array{group_roundtrip_ok: bool, categories_roundtrip_ok: bool} $writeProbe
     * @param array{ok: bool}|null $mkcolProbe
     */
    public static function suggestGroupStrategy(array $writeProbe, ?array $mkcolProbe): string
    {
        if ($writeProbe['group_roundtrip_ok']) {
            return 'passthrough';
        }
        if ($mkcolProbe !== null && $mkcolProbe['ok']) {
            return 'collections';
        }
        if ($writeProbe['categories_roundtrip_ok']) {
            return 'categories';
        }
        return 'categories';
    }
}
