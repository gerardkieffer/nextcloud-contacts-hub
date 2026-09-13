<?php

declare(strict_types=1);

namespace OCA\ContactHub\Sync;

/**
 * Translates between the Planner's generic "side A / side B" vocabulary
 * and the roles actually stored in the database (hub / endpoint).
 *
 * The Planner only ever pushes A -> B. Rather than teach it a second,
 * mirror-image mode, a from_endpoint job simply puts the endpoint in
 * slot A and the hub in slot B:
 *
 *   to_endpoint    A = hub       B = endpoint
 *   from_endpoint  A = endpoint  B = hub
 *
 * State columns are named for the *roles*, never for a/b, because which
 * role plays A depends on the job's direction -- storing raw a/b would
 * silently invert the meaning of every existing row the moment someone
 * edited a job from "to endpoint" to "from endpoint".
 */
final class SideMap
{
    private const array FIELDS = ['href', 'etag', 'hash', 'rev'];

    private function __construct(
        public readonly string $roleA,
        public readonly string $roleB,
    ) {
    }

    public static function forDirection(string $direction): self
    {
        return $direction === SyncJob::FROM_ENDPOINT
            ? new self('endpoint', 'hub')
            : new self('hub', 'endpoint');
    }

    public function roleFor(string $side): string
    {
        return $side === 'a' ? $this->roleA : $this->roleB;
    }

    public function sideFor(string $role): string
    {
        return $role === $this->roleA ? 'a' : 'b';
    }

    /** True when the hub is side A (i.e. the source of a one-way job). */
    public function hubIsA(): bool
    {
        return $this->roleA === 'hub';
    }

    /**
     * Project a stored state row onto the a_ and b_ prefixed keys the
     * Planner reads. Unknown/extra keys (id, job_id, uid, updated_at,
     * payload_json) are passed through untouched.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function toPlanner(array $row): array
    {
        $out = $row;
        foreach (['a', 'b'] as $side) {
            $role = $this->roleFor($side);
            foreach (self::FIELDS as $field) {
                $out["{$side}_{$field}"] = $row["{$role}_{$field}"] ?? null;
            }
            $out["archived_{$side}"] = $row["archived_{$role}"] ?? 0;
        }
        return $out;
    }

    /**
     * @param array<string, array<string, mixed>> $rows keyed by uid
     * @return array<string, array<string, mixed>>
     */
    public function toPlannerAll(array $rows): array
    {
        return array_map($this->toPlanner(...), $rows);
    }

    /**
     * Rewrite an update keyed by a_ and b_ prefixes into the role-named
     * columns the state tables actually have.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function toStorage(array $fields): array
    {
        $out = [];
        foreach ($fields as $key => $value) {
            $out[$this->storageKey($key)] = $value;
        }
        return $out;
    }

    private function storageKey(string $key): string
    {
        foreach (['a', 'b'] as $side) {
            $role = $this->roleFor($side);
            foreach (self::FIELDS as $field) {
                if ($key === "{$side}_{$field}") {
                    return "{$role}_{$field}";
                }
            }
            if ($key === "archived_{$side}") {
                return "archived_{$role}";
            }
        }
        return $key;
    }
}
