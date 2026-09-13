<?php

declare(strict_types=1);

namespace OCA\ContactHub\CardDav;

use DOMElement;
use DOMXPath;

final class DavResponse
{
    /** @param array<string, DOMElement> $props keyed by the prop's local name */
    public function __construct(
        public readonly ?string $href,
        private readonly array $props,
        private readonly DOMXPath $xpath,
    ) {
    }

    /**
     * True if $propName's element contains a $childName descendant
     * anywhere below it, not just as a direct child. Some servers (e.g.
     * SabreDAV/Infomaniak) nest privilege names two levels deep inside
     * <current-user-privilege-set>, so a direct-children-only check
     * produces a false "not writable" -- always search all descendants.
     */
    public function hasChild(string $propName, string $childName): bool
    {
        $prop = $this->props[$propName] ?? null;
        if ($prop === null) {
            return false;
        }
        $matches = $this->xpath->query(".//*[local-name()='{$childName}']", $prop);
        return $matches !== false && $matches->length > 0;
    }

    public function text(string $propName): ?string
    {
        $prop = $this->props[$propName] ?? null;
        return $prop?->textContent;
    }
}
