<?php

declare(strict_types=1);

namespace OCA\ContactHub\Controller;

use OCA\ContactHub\Presets\Preset;
use OCA\ContactHub\Presets\Registry;
use OCA\ContactHub\Service\EndpointService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class EndpointController extends ApiController
{
    public function __construct(
        IRequest $request,
        IUserSession $userSession,
        LoggerInterface $logger,
        private readonly EndpointService $service,
    ) {
        parent::__construct($request, $userSession, $logger);
    }

    #[NoAdminRequired]
    public function index(): DataResponse
    {
        return $this->respond(fn(): array => $this->service->listFor($this->userId()));
    }

    #[NoAdminRequired]
    public function show(int $id): DataResponse
    {
        return $this->respond(fn(): array => $this->service->get($id, $this->userId()));
    }

    #[NoAdminRequired]
    public function create(
        string $name = '',
        string $preset = 'generic',
        string $baseUrl = '',
        string $username = '',
        string $password = '',
        string $collectionName = '',
        string $groupStrategy = 'passthrough',
    ): DataResponse {
        return $this->respond(function () use ($name, $preset, $baseUrl, $username, $password, $collectionName, $groupStrategy): array {
            $id = $this->service->create($this->userId(), [
                'name' => $name,
                'preset' => $preset,
                'base_url' => $baseUrl,
                'username' => $username,
                'password' => $password,
                'collection_name' => $collectionName,
                'group_strategy' => $groupStrategy,
            ]);

            return $this->service->get($id, $this->userId());
        });
    }

    /**
     * An omitted password means "leave the stored one alone", which is why
     * every field is nullable here: the SPA sends only what changed, and a
     * blank password must not be mistaken for a request to blank it.
     */
    #[NoAdminRequired]
    public function update(
        int $id,
        ?string $name = null,
        ?string $preset = null,
        ?string $baseUrl = null,
        ?string $username = null,
        ?string $password = null,
        ?string $collectionName = null,
        ?string $collectionHref = null,
        ?string $groupStrategy = null,
    ): DataResponse {
        $input = array_filter([
            'name' => $name,
            'preset' => $preset,
            'base_url' => $baseUrl,
            'username' => $username,
            'password' => $password,
            'collection_name' => $collectionName,
            'collection_href' => $collectionHref,
            'group_strategy' => $groupStrategy,
        ], static fn(?string $v): bool => $v !== null);

        return $this->respond(function () use ($id, $input): array {
            $this->service->update($id, $this->userId(), $input);

            return $this->service->get($id, $this->userId());
        });
    }

    #[NoAdminRequired]
    public function destroy(int $id): DataResponse
    {
        return $this->respond(function () use ($id): array {
            $this->service->delete($id, $this->userId());

            return ['deleted' => $id];
        });
    }

    /**
     * Discover what this server supports.
     *
     * The probes are opt-in because they are not read-only: the write probe
     * creates and deletes a throwaway contact and group, and the mkcol probe
     * creates and deletes a collection.
     */
    #[NoAdminRequired]
    public function test(int $id, bool $writeProbe = false, bool $mkcolProbe = false): DataResponse
    {
        return $this->respond(
            fn(): array => $this->service->test($id, $this->userId(), $writeProbe, $mkcolProbe),
        );
    }

    /** @param array<string, mixed> $capabilities */
    #[NoAdminRequired]
    public function applySuggestions(
        int $id,
        array $capabilities = [],
        ?string $collectionHref = null,
        ?string $collectionName = null,
        ?string $groupStrategy = null,
        ?string $baseUrl = null,
    ): DataResponse {
        return $this->respond(function () use ($id, $capabilities, $collectionHref, $collectionName, $groupStrategy, $baseUrl): array {
            $this->service->applySuggestions($id, $this->userId(), [
                'capabilities' => $capabilities,
                'collection_href' => $collectionHref,
                'collection_name' => $collectionName,
                'group_strategy' => $groupStrategy,
                'base_url' => $baseUrl,
            ]);

            return $this->service->get($id, $this->userId());
        });
    }

    /** The preset catalogue, so the UI never hardcodes one. */
    #[NoAdminRequired]
    public function presets(): DataResponse
    {
        return $this->respond(static fn(): array => array_values(array_map(
            static fn(Preset $p): array => [
                'key' => $p->key,
                'label' => $p->label,
                'default_base_url' => $p->defaultBaseUrl,
                'default_group_strategy' => $p->defaultGroupStrategy,
                'auth_hint' => $p->authHint,
                'quirks' => $p->quirks,
                'unsupported_reason' => $p->unsupportedReason,
            ],
            Registry::all(),
        )));
    }
}
