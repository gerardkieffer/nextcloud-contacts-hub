<?php

declare(strict_types=1);

namespace OCA\ContactHub\Service;

use OCA\ContactHub\CapabilityTest\Tester;
use OCA\ContactHub\CardDav\DavException;
use OCA\ContactHub\CardDav\DavTimeout;
use OCA\ContactHub\Db\EndpointMapper;
use OCA\ContactHub\Presets\Registry;
use OCA\ContactHub\Sync\ClientFactory;
use OCA\ContactHub\Sync\Endpoint;

/**
 * CardDAV endpoints: validation, CRUD and the capability-test flow.
 *
 * Validation lives here rather than in the controller so the rules are
 * stated once and the controller stays a translation layer between HTTP and
 * this. The allowed values are always derived from Registry and the value
 * objects; a hardcoded preset list anywhere else is a bug.
 */
class EndpointService
{
    public function __construct(
        private readonly EndpointMapper $mapper,
        private readonly ClientFactory $clientFactory,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function listFor(string $userId): array
    {
        return array_map(
            $this->present(...),
            $this->mapper->allForUser($userId),
        );
    }

    /** @return array<string, mixed> */
    public function get(int $id, string $userId): array
    {
        return $this->present($this->require($id, $userId));
    }

    /** @param array<string, mixed> $input */
    public function create(string $userId, array $input): int
    {
        $data = $this->validate($input, isCreate: true);
        $this->verifyCredentials($this->candidate($data));

        return $this->mapper->create($userId, $data);
    }

    /** @param array<string, mixed> $input */
    public function update(int $id, string $userId, array $input): void
    {
        $existing = $this->require($id, $userId);
        $data = $this->validate($input, isCreate: false);

        if (self::touchesConnection($data)) {
            $this->verifyCredentials($this->candidate($data, $existing));
        }

        $this->mapper->update($id, $userId, $data);
    }

    /**
     * Whether a patch changes anything the server would authenticate on.
     *
     * Re-checking on every save would mean a rename could not be saved while
     * the far side happened to be down, and would put a network round trip
     * in front of edits that cannot possibly invalidate the credentials.
     *
     * @param array<string, mixed> $data
     */
    private static function touchesConnection(array $data): bool
    {
        foreach (['base_url', 'username', 'password', 'preset'] as $field) {
            if (array_key_exists($field, $data)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The endpoint as it would be after this write, for verification before
     * anything is persisted.
     *
     * On update the patch is partial and the password is usually absent
     * entirely (blank means "keep the stored one"), so the stored endpoint
     * supplies whatever the patch omits. Id 0 because it may not exist yet;
     * nothing in the transport looks at it.
     *
     * @param array<string, mixed> $data
     */
    private function candidate(array $data, ?Endpoint $existing = null): Endpoint
    {
        $value = static fn(string $key, string $fallback): string
            => array_key_exists($key, $data) ? (string) $data[$key] : $fallback;

        return new Endpoint(
            id: $existing->id ?? 0,
            name: $value('name', $existing->name ?? ''),
            preset: $value('preset', $existing->preset ?? 'generic'),
            baseUrl: $value('base_url', $existing->baseUrl ?? ''),
            username: $value('username', $existing->username ?? ''),
            password: $value('password', $existing->password ?? ''),
            collectionHref: $existing->collectionHref ?? null,
            collectionName: $existing->collectionName ?? null,
            groupStrategy: $value('group_strategy', $existing->groupStrategy ?? 'passthrough'),
            capabilities: $existing->capabilities ?? [],
        );
    }

    /**
     * Prove the credentials work before storing them.
     *
     * An endpoint saved without this looks fine in the list and then fails at
     * the least convenient moment: on a scheduled background run, where the
     * only trace is a log line. A typo in a password is worth one PROPFIND at
     * the moment someone is looking at the form.
     *
     * Only principal discovery is performed, not the full capability test:
     * reaching current-user-principal already proves the server accepted the
     * credentials, and everything past that point (which collections exist,
     * which is writable, how groups are stored) is what the capability test
     * is for and must not block saving.
     */
    private function verifyCredentials(Endpoint $endpoint): void
    {
        $preset = Registry::get($endpoint->preset);
        $client = $this->clientFactory->create($endpoint);
        $fallback = $preset->fallbackUrlFor($endpoint->username);

        // Deliberately \Throwable and not just DavException. Verification runs
        // on the save path, so *nothing* that happens while talking to a
        // stranger's server may reach the user as a 500: a transport that
        // throws something unwrapped still means "could not connect", which
        // is a field error on a form, not a fault in this app.
        try {
            $client->discoverHomeSet($endpoint->baseUrl);

            return;
        } catch (\Throwable $e) {
            $first = $e;
        }

        if ($fallback !== null && $fallback !== $endpoint->baseUrl) {
            try {
                $client->discoverHomeSet($fallback);

                return;
            } catch (\Throwable $e) {
                // The fallback's verdict is the more informative one when the
                // preset has a fallback at all, since that path is the one the
                // sync will actually take.
                $first = $e;
            }
        }

        throw $this->credentialError($first);
    }

    /**
     * A 401 belongs next to the password field; anything else is about the
     * server or the URL and belongs next to that, because telling someone
     * their password is wrong when the host is unreachable sends them off
     * resetting credentials that were never the problem.
     */
    private function credentialError(\Throwable $e): ValidationException
    {
        if ($e instanceof DavException && $e->isAuthFailure()) {
            return ValidationException::field(
                'password',
                'The server rejected these credentials. Check the username form and the password '
                . 'this service expects: several require an application-specific password rather '
                . 'than your normal one.',
            );
        }

        if ($e instanceof DavTimeout) {
            return ValidationException::field(
                'base_url',
                'The server did not answer in time. Check the URL, and try again if the service is '
                . 'simply slow right now.',
            );
        }

        return ValidationException::field('base_url', "Could not reach this server: {$e->getMessage()}");
    }

    public function delete(int $id, string $userId): void
    {
        $this->require($id, $userId);

        if ($this->mapper->isReferencedByJob($id, $userId)) {
            throw ValidationException::field(
                'id',
                'This endpoint is still used by a sync job. Delete the job first.',
            );
        }

        $this->mapper->delete($id, $userId);
    }

    /**
     * Discover the endpoint's collections, optionally probing writes and
     * collection creation.
     *
     * Returns the raw tester result plus a suggested group strategy, which
     * the UI offers to apply. Nothing is written to the endpoint record here
     * -- applySuggestions() does that, so a user can look at the findings
     * before accepting them.
     *
     * @return array<string, mixed>
     */
    public function test(int $id, string $userId, bool $writeProbe = false, bool $mkcolProbe = false): array
    {
        $endpoint = $this->require($id, $userId);
        $tester = new Tester($this->clientFactory->create($endpoint));

        $result = $this->discoverWithFallback($tester, $endpoint);

        $probeTarget = $endpoint->collectionHref
            ?? ($result['selected']['href'] ?? null)
            ?? ($result['collections'][0]['href'] ?? null);

        if ($writeProbe && $probeTarget !== null) {
            $result['write_probe'] = $tester->writeProbe((string) $probeTarget);
        }
        if ($mkcolProbe && ($result['home_url'] ?? null) !== null) {
            $result['mkcol_probe'] = $tester->mkcolProbe((string) $result['home_url']);
        }

        $result['suggested_group_strategy'] = $this->suggestGroupStrategy($result);

        return $result;
    }

    /**
     * Some providers advertise one entry point but answer discovery only at
     * another derived from the username -- iCloud most notably. The preset
     * carries that pattern, so a failed discovery gets one retry against it
     * before being reported as a failure.
     *
     * @return array<string, mixed>
     */
    private function discoverWithFallback(Tester $tester, Endpoint $endpoint): array
    {
        try {
            $result = $tester->discover($endpoint->baseUrl, $endpoint->collectionName);
            if (($result['collections'] ?? []) !== []) {
                return $result + ['discovered_at' => $endpoint->baseUrl];
            }
        } catch (\Throwable $e) {
            $result = ['errors' => [$e->getMessage()], 'collections' => []];
        }

        $fallback = Registry::get($endpoint->preset)->fallbackUrlFor($endpoint->username);
        if ($fallback === null || $fallback === $endpoint->baseUrl) {
            return $result;
        }

        try {
            $retry = $tester->discover($fallback, $endpoint->collectionName);
            if (($retry['collections'] ?? []) !== []) {
                return $retry + ['discovered_at' => $fallback];
            }
        } catch (\Throwable $e) {
            $result['errors'][] = "Fallback {$fallback}: {$e->getMessage()}";
        }

        return $result;
    }

    /**
     * What the probe results imply about how this server wants groups.
     *
     * Only a write probe can actually tell us: a server that stores and
     * returns a KIND:group vCard intact supports passthrough. Without one
     * there is nothing to go on, so nothing is suggested rather than
     * guessing.
     */
    private function suggestGroupStrategy(array $result): ?string
    {
        $probe = $result['write_probe'] ?? null;
        if (!is_array($probe)) {
            // No write probe, no evidence. Saying nothing beats guessing:
            // the group strategy is the setting people most often get wrong,
            // and a confident wrong answer is worse than none.
            return null;
        }

        $suggested = Tester::suggestGroupStrategy($probe, $result['mkcol_probe'] ?? null);

        // Tester reports what the server *supports*. 'collections' is
        // genuinely supported by some servers and genuinely not implemented
        // for live sync (Runner::pushGroupOne warns and skips), so steering
        // someone into it would be steering them into a broken job. The
        // detection stays in Tester; only this gate lives here.
        return $suggested === 'collections' ? 'categories' : $suggested;
    }

    /** @param array<string, mixed> $input */
    public function applySuggestions(int $id, string $userId, array $input): void
    {
        $this->require($id, $userId);

        $this->mapper->updateCapabilities(
            $id,
            $userId,
            is_array($input['capabilities'] ?? null) ? $input['capabilities'] : [],
            isset($input['collection_href']) ? (string) $input['collection_href'] : null,
            isset($input['group_strategy']) && in_array($input['group_strategy'], Endpoint::GROUP_STRATEGIES, true)
                ? (string) $input['group_strategy']
                : null,
        );

        // Discovery can resolve to a different host than the one configured
        // (iCloud's per-account redirect). Keeping the old one would make
        // every future request take the redirect again.
        $patch = [];
        if (($input['base_url'] ?? '') !== '') {
            $patch['base_url'] = (string) $input['base_url'];
        }
        if (($input['collection_name'] ?? '') !== '') {
            $patch['collection_name'] = (string) $input['collection_name'];
        }
        if ($patch !== []) {
            $this->mapper->update($id, $userId, $patch);
        }
    }

    private function require(int $id, string $userId): Endpoint
    {
        $endpoint = $this->mapper->find($id, $userId);
        if ($endpoint === null) {
            throw new NotFoundException("Endpoint {$id} does not exist or is not yours.");
        }

        return $endpoint;
    }

    /**
     * The endpoint as the API exposes it. The password is never included,
     * in either direction: the edit form sends it only when changing it.
     *
     * @return array<string, mixed>
     */
    private function present(Endpoint $endpoint): array
    {
        return [
            'id' => $endpoint->id,
            'name' => $endpoint->name,
            'preset' => $endpoint->preset,
            'preset_label' => Registry::get($endpoint->preset)->label,
            'base_url' => $endpoint->baseUrl,
            'username' => $endpoint->username,
            'collection_href' => $endpoint->collectionHref,
            'collection_name' => $endpoint->collectionName,
            'group_strategy' => $endpoint->groupStrategy,
            'capabilities' => $endpoint->capabilities,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function validate(array $input, bool $isCreate): array
    {
        $errors = [];
        $data = [];

        foreach (['name', 'base_url', 'username'] as $field) {
            $value = trim((string) ($input[$field] ?? ''));
            if ($value === '') {
                // On update, only validate what was actually submitted.
                if ($isCreate || array_key_exists($field, $input)) {
                    $errors[$field] = 'This field is required.';
                }
                continue;
            }
            $data[$field] = $value;
        }

        if ($isCreate || array_key_exists('preset', $input)) {
            $preset = (string) ($input['preset'] ?? '');
            if (!array_key_exists($preset, Registry::all())) {
                $errors['preset'] = 'Unknown preset.';
            } elseif (!Registry::get($preset)->isUsable()) {
                // Checked here rather than at the connection attempt so the
                // reason is the *only* error shown. Reported alongside "a
                // password is required" it would read as one more field to
                // fill in, when in fact no password exists that would work.
                throw ValidationException::field('preset', (string) Registry::get($preset)->unsupportedReason);
            } else {
                $data['preset'] = $preset;
            }
        }

        if (array_key_exists('group_strategy', $input)) {
            $strategy = (string) $input['group_strategy'];
            if (!in_array($strategy, Endpoint::GROUP_STRATEGIES, true)) {
                $errors['group_strategy'] = 'Unknown group strategy.';
            } else {
                $data['group_strategy'] = $strategy;
            }
        }

        if (isset($data['base_url']) && !self::isAcceptableUrl($data['base_url'])) {
            $errors['base_url'] = 'Must be an https:// URL (http:// is allowed only for localhost).';
        }

        $password = (string) ($input['password'] ?? '');
        if ($password !== '') {
            $data['password'] = $password;
        } elseif ($isCreate) {
            $errors['password'] = 'This field is required.';
        }

        foreach (['collection_href', 'collection_name'] as $optional) {
            if (array_key_exists($optional, $input)) {
                $value = trim((string) $input[$optional]);
                $data[$optional] = $value === '' ? null : $value;
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $data;
    }

    /**
     * Credentials travel on every CardDAV request, so plaintext HTTP would
     * leak them. Loopback is allowed because that is how anyone tests
     * against a local mock server.
     */
    public static function isAcceptableUrl(string $url): bool
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }
        if ($parts['scheme'] === 'https') {
            return true;
        }

        return $parts['scheme'] === 'http'
            && in_array($parts['host'], ['localhost', '127.0.0.1', '::1', '[::1]'], true);
    }
}
