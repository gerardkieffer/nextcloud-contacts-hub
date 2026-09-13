<?php

declare(strict_types=1);

namespace OCA\ContactHub\Sync;

use OCA\ContactHub\VCard\Model;

/**
 * Full backup/restore of a single endpoint's address book, independent
 * of any sync_jobs/runs bookkeeping -- a one-off, synchronous
 * operation (no resumability attempted here; see CLAUDE.md for why
 * that's a deliberate scope boundary for very large address books).
 *
 * Restore is a mirror by default: after it, the endpoint's collection
 * matches the backup's resource set exactly, including deleting
 * anything live that isn't in the backup. vCard bytes are pushed
 * verbatim from the backup (never re-parsed/re-rendered), so REV is
 * preserved by construction, same as every other write path in this
 * app.
 */
final class EndpointBackup
{
    public function __construct(private readonly ClientFactory $clientFactory)
    {
    }

    /** @return array{format_version: int, exported_at: string, endpoint_name: string, collection_href: string, resources: list<array{uid: string, href: string, vcard: string}>} */
    public function backup(Endpoint $endpoint): array
    {
        $client = $this->clientFactory->create($endpoint);
        $fetched = $client->fetchAllVCards($endpoint->collectionHref);

        $resources = [];
        foreach ($fetched as $pair) {
            $uid = $this->safeUid($pair['vcard']);
            if ($uid === null) {
                continue;
            }
            $resources[] = ['uid' => $uid, 'href' => $pair['href'], 'vcard' => $pair['vcard']];
        }

        return [
            'format_version' => 1,
            'exported_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'endpoint_name' => $endpoint->name,
            'collection_href' => $endpoint->collectionHref,
            'resources' => $resources,
        ];
    }

    /**
     * @param array{resources: list<array{uid: string, href: string, vcard: string}>} $backup
     * @return array{
     *     creates: list<array{uid: string, href: string, vcard: string}>,
     *     updates: list<array{uid: string, href: string, vcard: string}>,
     *     deletes: list<array{uid: string, href: string}>,
     * }
     */
    public function diff(Endpoint $endpoint, array $backup): array
    {
        $client = $this->clientFactory->create($endpoint);
        $live = $client->fetchAllVCards($endpoint->collectionHref);

        $liveByUid = [];
        foreach ($live as $pair) {
            $uid = $this->safeUid($pair['vcard']);
            if ($uid !== null) {
                $liveByUid[$uid] = $pair;
            }
        }

        $backupByUid = [];
        foreach ($backup['resources'] as $res) {
            $backupByUid[$res['uid']] = $res;
        }

        $creates = [];
        $updates = [];
        foreach ($backupByUid as $uid => $res) {
            if (!isset($liveByUid[$uid])) {
                $creates[] = $res;
            } elseif ($liveByUid[$uid]['vcard'] !== $res['vcard']) {
                $updates[] = $res;
            }
        }

        $deletes = [];
        foreach ($liveByUid as $uid => $pair) {
            if (!isset($backupByUid[$uid])) {
                $deletes[] = ['uid' => $uid, 'href' => $pair['href']];
            }
        }

        return ['creates' => $creates, 'updates' => $updates, 'deletes' => $deletes];
    }

    /**
     * Re-diffs against current live state (rather than trusting a diff
     * computed at an earlier preview step) and applies it -- consistent
     * with how this app's sync preview/apply already works (a preview
     * is advisory; applying always acts on live truth at that moment).
     * $mirror=false skips the deletes (additive-only restore).
     *
     * @param array{resources: list<array{uid: string, href: string, vcard: string}>} $backup
     * @return array{created: int, updated: int, deleted: int, errors: string[]}
     */
    public function restore(Endpoint $endpoint, array $backup, bool $mirror = true): array
    {
        $client = $this->clientFactory->create($endpoint);
        $diff = $this->diff($endpoint, $backup);
        $liveEtags = $client->listCollectionEtags($endpoint->collectionHref);

        $created = 0;
        $updated = 0;
        $deleted = 0;
        $errors = [];

        foreach ($diff['creates'] as $res) {
            try {
                $client->putVCard($res['href'], $res['vcard'], null);
                $created++;
            } catch (\Throwable $e) {
                $errors[] = "create {$res['uid']}: {$e->getMessage()}";
            }
        }
        foreach ($diff['updates'] as $res) {
            try {
                $client->putVCard($res['href'], $res['vcard'], $liveEtags[$res['href']] ?? null);
                $updated++;
            } catch (\Throwable $e) {
                $errors[] = "update {$res['uid']}: {$e->getMessage()}";
            }
        }
        if ($mirror) {
            foreach ($diff['deletes'] as $res) {
                try {
                    $client->delete($res['href'], $liveEtags[$res['href']] ?? null);
                    $deleted++;
                } catch (\Throwable $e) {
                    $errors[] = "delete {$res['uid']}: {$e->getMessage()}";
                }
            }
        }

        return ['created' => $created, 'updated' => $updated, 'deleted' => $deleted, 'errors' => $errors];
    }

    private function safeUid(string $vcard): ?string
    {
        try {
            return Model::parse($vcard)->uid;
        } catch (\Throwable) {
            return null; // unparseable -- skip, matches Model::buildAddressBook's tolerance elsewhere
        }
    }
}
