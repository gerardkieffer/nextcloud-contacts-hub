<?php

declare(strict_types=1);

namespace OCA\ContactHub\Presets;

/**
 * A starting point for a new endpoint, not a source of truth: the
 * capability test always re-verifies live against the actual server and
 * can override anything a preset suggests.
 */
final class Preset
{
    /**
     * @param string[] $quirks
     * @param string|null $urlFallbackPattern Alternate discovery URL to try, with a
     *     {username} placeholder, when the default base URL's discovery fails outright
     *     (some servers -- Mailo confirmed -- don't support well-known/root discovery
     *     and need a per-account path instead).
     * @param string|null $unsupportedReason Set when this service cannot work with this
     *     app at all, explaining why. The preset is still listed, because someone who
     *     wants to sync that service needs to be told *why* they cannot rather than
     *     being left to conclude it was forgotten. Creating an endpoint on it is
     *     refused in EndpointService, so the reason cannot be bypassed by talking to
     *     the API directly.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $defaultBaseUrl,
        public readonly string $defaultGroupStrategy,
        public readonly string $authHint,
        public readonly array $quirks,
        public readonly ?string $urlFallbackPattern = null,
        public readonly ?string $unsupportedReason = null,
    ) {
    }

    public function isUsable(): bool
    {
        return $this->unsupportedReason === null;
    }

    public function fallbackUrlFor(string $username): ?string
    {
        return $this->urlFallbackPattern === null
            ? null
            : str_replace('{username}', $username, $this->urlFallbackPattern);
    }
}
