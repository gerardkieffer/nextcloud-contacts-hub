<?php

declare(strict_types=1);

namespace OCA\ContactHub\Sync;

final class Endpoint
{
    /**
     * How a side expresses group membership.
     *
     *   passthrough  discrete KIND:group vCards (iCloud, Infomaniak)
     *   categories   a CATEGORIES property on each contact (Nextcloud)
     *   collections  one real collection per group -- probed for by the
     *                capability tester but not implemented for live sync
     *
     * Kept next to the value object that means them, so validation derives
     * from here rather than repeating a literal list.
     */
    public const array GROUP_STRATEGIES = ['passthrough', 'categories', 'collections'];

    /** @param array<string, mixed> $capabilities */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $preset,
        public readonly string $baseUrl,
        public readonly string $username,
        private readonly string $password,
        public readonly ?string $collectionHref,
        public readonly ?string $collectionName,
        public readonly string $groupStrategy,
        public readonly array $capabilities,
    ) {
    }

    /**
     * The endpoint's password. Private, with a getter, and that is the whole
     * point of it.
     *
     * This object is a constructor argument of most of the sync engine, so it
     * lands in the stack trace of anything that throws mid-run -- and a
     * CardDAV run throws for ordinary reasons, a 412 or a dead endpoint.
     * Nextcloud's ExceptionSerializer::encodeArg() expands every object in
     * every trace frame with get_object_vars() from outside the class, which
     * returns public properties only. As a public promoted property the
     * password was therefore written to nextcloud.log in clear text, several
     * times per exception, once per frame holding a SyncJob or an Endpoint.
     *
     * Found in a log excerpt a user pasted while reporting an unrelated sync
     * error: the credential was sitting in it, and the log is readable from
     * the admin UI and goes wherever logs go. Anything added here later that
     * a server would not want written down must be private for the same
     * reason.
     */
    public function password(): string
    {
        return $this->password;
    }

    /**
     * Keep var_dump() and friends from undoing the above.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'preset' => $this->preset,
            'baseUrl' => $this->baseUrl,
            'username' => $this->username,
            'password' => '*** redacted ***',
            'collectionHref' => $this->collectionHref,
            'collectionName' => $this->collectionName,
            'groupStrategy' => $this->groupStrategy,
            'capabilities' => $this->capabilities,
        ];
    }
}
