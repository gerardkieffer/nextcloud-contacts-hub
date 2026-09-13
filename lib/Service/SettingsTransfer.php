<?php

declare(strict_types=1);

namespace OCA\ContactHub\Service;

use OCA\ContactHub\Db\EndpointMapper;
use OCA\ContactHub\Db\JobMapper;
use OCA\ContactHub\Files\HubFolder;
use OCA\ContactHub\Sync\Endpoint;
use OCA\ContactHub\Sync\SyncJob;

/**
 * Export and import this app's configuration: endpoints and sync jobs.
 *
 * What it is for: moving a working setup to another server, keeping a copy
 * before changing something, and rebuilding after a reinstall. It is NOT a
 * backup of contacts -- BackupService does that, and the two are deliberately
 * separate files with separate lifetimes.
 *
 * Both directions go through the user's own "Contacts Hub/Settings" folder,
 * so exporting produces something downloadable from the Files app and
 * importing can read a file the user put there, as well as one they paste
 * from their own machine.
 *
 * Three things shape the format:
 *
 *  - **Jobs reference endpoints by name, not id.** Ids are per-installation
 *    and meaningless on the far side of an export. Names are what the user
 *    typed and recognises, and a name collision is something they can see and
 *    resolve, unlike an id collision.
 *  - **Address books are referenced by URI as well as name.** A URI is stable
 *    for the life of the book and survives a rename; the name is the fallback
 *    when importing into an instance where the URI does not exist.
 *  - **Passwords are opt-in.** See export().
 */
class SettingsTransfer
{
    public const string FORMAT = 'contacthub-settings';
    public const int FORMAT_VERSION = 1;

    public function __construct(
        private readonly EndpointMapper $endpoints,
        private readonly JobMapper $jobs,
        private readonly EndpointService $endpointService,
        private readonly JobService $jobService,
        private readonly AddressBookService $addressBooks,
        private readonly HubFolder $files,
    ) {
    }

    /**
     * Write the current configuration into the user's Settings folder.
     *
     * $includePasswords defaults to false and the UI leaves it unticked,
     * because the file lands somewhere a person can download, sync to a
     * laptop and attach to an email. Without passwords it is harmless if it
     * leaks and needs each password typed once on import; with them it is a
     * credential store that restores in one click. That is a real trade and
     * the user makes it per export, which is why it is a parameter here
     * rather than a policy baked in.
     *
     * @return array{path: string, name: string, endpoints: int, jobs: int, includes_passwords: bool}
     */
    public function export(string $userId, bool $includePasswords = false): array
    {
        $endpoints = [];
        foreach ($this->endpoints->allForUser($userId) as $endpoint) {
            $endpoints[] = $this->presentEndpoint($endpoint, $includePasswords);
        }

        // Listed once. presentJob() used to resolve the URI itself, which
        // meant a fresh CardDavBackend query per job to answer the same
        // question with the same answer every time.
        $uriById = [];
        foreach ($this->addressBooks->listForUser($userId) as $book) {
            $uriById[$book['id']] = $book['uri'];
        }

        $jobs = [];
        foreach ($this->jobs->allForUser($userId) as $job) {
            $jobs[] = $this->presentJob($job, $uriById);
        }

        $payload = json_encode([
            'format' => self::FORMAT,
            'format_version' => self::FORMAT_VERSION,
            'exported_at' => gmdate('c'),
            'includes_passwords' => $includePasswords,
            'endpoints' => $endpoints,
            'jobs' => $jobs,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        // Pretty-printed and uncompressed on purpose: this is a small file
        // whose whole point is being readable and editable by the person who
        // exported it.
        $name = sprintf(
            'contacthub-settings %s%s.json',
            gmdate('Y-m-d His'),
            $includePasswords ? ' (with passwords)' : '',
        );
        $path = $this->files->write($userId, HubFolder::SETTINGS, $name, $payload);

        return [
            'path' => $path,
            'name' => $name,
            'endpoints' => count($endpoints),
            'jobs' => count($jobs),
            'includes_passwords' => $includePasswords,
        ];
    }

    /** Files already in the Settings folder, so the UI can offer them to import. */
    public function available(string $userId): array
    {
        return $this->files->listFiles($userId, HubFolder::SETTINGS);
    }

    /**
     * Read an export without applying it, so the UI can show what is in it and
     * collect any missing passwords first.
     *
     * @return array<string, mixed>
     */
    public function inspect(string $userId, ?string $content, ?string $path): array
    {
        $payload = $this->decode($this->fetch($userId, $content, $path));

        // Name lookups, not hydrated objects: hydrating an endpoint decrypts
        // its password, and nothing here reads one.
        $existingEndpoints = $this->endpoints->idsByNameForUser($userId);
        $existingJobs = array_flip($this->jobs->namesForUser($userId));

        $books = $this->addressBooks->listForUser($userId);

        $endpoints = [];
        foreach ($payload['endpoints'] ?? [] as $entry) {
            $name = (string) ($entry['name'] ?? '');
            $endpoints[] = [
                'name' => $name,
                'preset' => (string) ($entry['preset'] ?? 'generic'),
                'base_url' => (string) ($entry['base_url'] ?? ''),
                'username' => (string) ($entry['username'] ?? ''),
                'has_password' => ($entry['password'] ?? '') !== '',
                'exists' => isset($existingEndpoints[$name]),
            ];
        }

        $jobs = [];
        foreach ($payload['jobs'] ?? [] as $entry) {
            $name = (string) ($entry['name'] ?? '');
            $jobs[] = [
                'name' => $name,
                'endpoint_name' => (string) ($entry['endpoint_name'] ?? ''),
                'address_book_name' => (string) ($entry['address_book_name'] ?? ''),
                'address_book_found' => $this->matchBook($books, $entry) !== null,
                'direction' => (string) ($entry['direction'] ?? ''),
                'exists' => isset($existingJobs[$name]),
            ];
        }

        return [
            'exported_at' => (string) ($payload['exported_at'] ?? ''),
            'includes_passwords' => (bool) ($payload['includes_passwords'] ?? false),
            'endpoints' => $endpoints,
            'jobs' => $jobs,
        ];
    }

    /**
     * Apply an export.
     *
     * Only ever creates. Anything whose name already exists is skipped and
     * reported, never overwritten: an import that silently replaced a working
     * endpoint with an older copy of itself would be a data-loss bug wearing
     * a convenience feature's clothes. Renaming the existing one first is a
     * decision only the user can make.
     *
     * Endpoints go through EndpointService::create(), so every imported
     * endpoint has its credentials verified exactly like a hand-typed one.
     * An import is a normal way to end up with a stale password, which makes
     * that check more useful here than anywhere else.
     *
     * @param array<string, string> $passwords endpoint name => password, for
     *     entries the file does not carry one for
     * @return array<string, mixed>
     */
    public function import(string $userId, ?string $content, ?string $path, array $passwords = []): array
    {
        $payload = $this->decode($this->fetch($userId, $content, $path));

        $created = ['endpoints' => [], 'jobs' => []];
        $skipped = ['endpoints' => [], 'jobs' => []];
        $failed = ['endpoints' => [], 'jobs' => []];

        $byName = $this->endpoints->idsByNameForUser($userId);

        foreach ($payload['endpoints'] ?? [] as $entry) {
            $name = trim((string) ($entry['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            if (isset($byName[$name])) {
                $skipped['endpoints'][] = ['name' => $name, 'reason' => 'An endpoint with this name already exists.'];
                continue;
            }

            $password = (string) ($passwords[$name] ?? $entry['password'] ?? '');
            if ($password === '') {
                $failed['endpoints'][] = ['name' => $name, 'reason' => 'No password was supplied for this endpoint.'];
                continue;
            }

            try {
                $id = $this->endpointService->create($userId, [
                    'name' => $name,
                    'preset' => (string) ($entry['preset'] ?? 'generic'),
                    'base_url' => (string) ($entry['base_url'] ?? ''),
                    'username' => (string) ($entry['username'] ?? ''),
                    'password' => $password,
                    'collection_name' => (string) ($entry['collection_name'] ?? ''),
                    'group_strategy' => (string) ($entry['group_strategy'] ?? 'passthrough'),
                ]);
                // collection_href is not settable through create(): it is a
                // capability-test result, not user input. Applying it here
                // saves re-running the test for an endpoint that was already
                // tested on the instance this file came from.
                if (($entry['collection_href'] ?? '') !== '') {
                    $this->endpointService->applySuggestions($id, $userId, [
                        'capabilities' => is_array($entry['capabilities'] ?? null) ? $entry['capabilities'] : [],
                        'collection_href' => (string) $entry['collection_href'],
                        'collection_name' => (string) ($entry['collection_name'] ?? ''),
                        'group_strategy' => (string) ($entry['group_strategy'] ?? ''),
                    ]);
                }
                $byName[$name] = $id;
                $created['endpoints'][] = $name;
            } catch (ValidationException $e) {
                $failed['endpoints'][] = ['name' => $name, 'reason' => implode(' ', $e->errors)];
            } catch (\Throwable $e) {
                $failed['endpoints'][] = ['name' => $name, 'reason' => $e->getMessage()];
            }
        }

        $existingJobs = array_flip($this->jobs->namesForUser($userId));
        $books = $this->addressBooks->listForUser($userId);

        foreach ($payload['jobs'] ?? [] as $entry) {
            $name = trim((string) ($entry['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            if (isset($existingJobs[$name])) {
                $skipped['jobs'][] = ['name' => $name, 'reason' => 'A job with this name already exists.'];
                continue;
            }

            $endpointName = (string) ($entry['endpoint_name'] ?? '');
            if (!isset($byName[$endpointName])) {
                $failed['jobs'][] = [
                    'name' => $name,
                    'reason' => "Its endpoint \"{$endpointName}\" was not imported and does not exist here.",
                ];
                continue;
            }

            $book = $this->matchBook($books, $entry);
            if ($book === null) {
                $failed['jobs'][] = [
                    'name' => $name,
                    'reason' => sprintf(
                        'No address book matching "%s" exists here. Create it first, then import again.',
                        (string) ($entry['address_book_name'] ?? $entry['address_book_uri'] ?? '?'),
                    ),
                ];
                continue;
            }

            try {
                $this->jobService->create($userId, [
                    'name' => $name,
                    'address_book_id' => $book['id'],
                    'endpoint_id' => $byName[$endpointName],
                    'direction' => (string) ($entry['direction'] ?? 'to_endpoint'),
                    'deletion_policy' => (string) ($entry['deletion_policy'] ?? 'mirror'),
                    'archive_group_name' => (string) ($entry['archive_group_name'] ?? 'Deleted'),
                    'include_photos' => (bool) ($entry['include_photos'] ?? true),
                    'interval_seconds' => (int) ($entry['interval_seconds'] ?? 3600),
                    // Imported jobs arrive switched off whatever the file says.
                    // A file may describe a setup pointing at data this
                    // instance has never seen, and a mirror deletion policy
                    // is the expensive one to get wrong. The user turns them
                    // on after looking.
                    'enabled' => false,
                ]);
                $existingJobs[$name] = true;
                $created['jobs'][] = $name;
            } catch (ValidationException $e) {
                $failed['jobs'][] = ['name' => $name, 'reason' => implode(' ', $e->errors)];
            } catch (\Throwable $e) {
                $failed['jobs'][] = ['name' => $name, 'reason' => $e->getMessage()];
            }
        }

        return [
            'created' => $created,
            'skipped' => $skipped,
            'failed' => $failed,
            'jobs_are_disabled' => $created['jobs'] !== [],
        ];
    }

    /**
     * @param list<array{id: int, uri: string, displayName: string, owned: bool}> $books
     * @param array<string, mixed> $entry
     * @return array{id: int, uri: string, displayName: string, owned: bool}|null
     */
    private function matchBook(array $books, array $entry): ?array
    {
        $uri = (string) ($entry['address_book_uri'] ?? '');
        foreach ($books as $book) {
            if ($uri !== '' && $book['uri'] === $uri) {
                return $book;
            }
        }

        // Falling back to the display name is what makes an export usable on
        // a different instance at all, where the URIs were assigned locally.
        $name = (string) ($entry['address_book_name'] ?? '');
        foreach ($books as $book) {
            if ($name !== '' && $book['displayName'] === $name) {
                return $book;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function presentEndpoint(Endpoint $endpoint, bool $includePasswords): array
    {
        $out = [
            'name' => $endpoint->name,
            'preset' => $endpoint->preset,
            'base_url' => $endpoint->baseUrl,
            'username' => $endpoint->username,
            'collection_href' => $endpoint->collectionHref,
            'collection_name' => $endpoint->collectionName,
            'group_strategy' => $endpoint->groupStrategy,
            'capabilities' => $endpoint->capabilities,
        ];

        if ($includePasswords) {
            $out['password'] = $endpoint->password();
        }

        return $out;
    }

    /**
     * @param array<int, string> $uriById address book id => uri
     * @return array<string, mixed>
     */
    private function presentJob(SyncJob $job, array $uriById): array
    {
        return [
            'name' => $job->name,
            'endpoint_name' => $job->endpoint->name,
            'address_book_name' => $job->addressBookName,
            'address_book_uri' => $uriById[$job->addressBookId] ?? '',
            'direction' => $job->direction,
            'deletion_policy' => $job->deletionPolicy,
            'archive_group_name' => $job->archiveGroupName,
            'include_photos' => $job->includePhotos,
            'interval_seconds' => $job->intervalSeconds,
            'enabled' => $job->enabled,
        ];
    }

    /**
     * Where the JSON comes from: pasted content from a file the user picked in
     * the browser, or a path in their Nextcloud files.
     */
    private function fetch(string $userId, ?string $content, ?string $path): string
    {
        if ($content !== null && trim($content) !== '') {
            return $content;
        }

        if ($path !== null && trim($path) !== '') {
            $read = $this->files->readUserPath($userId, $path);
            if ($read === null) {
                throw ValidationException::field('path', "Could not read \"{$path}\" from your files.");
            }

            return $read;
        }

        throw ValidationException::field('file', 'Choose a file to import.');
    }

    /** @return array<string, mixed> */
    private function decode(string $raw): array
    {
        try {
            $payload = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw ValidationException::field('file', "That file is not valid JSON: {$e->getMessage()}");
        }

        if (!is_array($payload) || ($payload['format'] ?? null) !== self::FORMAT) {
            throw ValidationException::field(
                'file',
                'That file is not a Contacts Hub settings export.',
            );
        }

        $version = (int) ($payload['format_version'] ?? 0);
        if ($version > self::FORMAT_VERSION) {
            throw ValidationException::field(
                'file',
                sprintf(
                    'That file was written by a newer version of Contacts Hub (format %d, this one reads %d).',
                    $version,
                    self::FORMAT_VERSION,
                ),
            );
        }

        return $payload;
    }
}
