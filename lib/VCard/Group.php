<?php

declare(strict_types=1);

namespace OCA\ContactHub\VCard;

final class Group
{
    /** @param string[] $memberUids */
    public function __construct(
        public readonly string $uid,
        public readonly string $name,
        public readonly array $memberUids,
        public readonly string $rawText,
        public readonly ?string $rev = null,
    ) {
    }
}
