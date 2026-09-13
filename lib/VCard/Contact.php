<?php

declare(strict_types=1);

namespace OCA\ContactHub\VCard;

final class Contact
{
    /**
     * @param string[] $emails
     * @param string[] $phones
     * @param string[] $categories CATEGORIES values, split and unescaped.
     *     This is how a categories-strategy side (Nextcloud, and endpoints
     *     configured that way) expresses group membership, since it has no
     *     discrete group vCards. Sync\CategoryGroups turns these back into
     *     Group objects so the Planner can diff them like any other group.
     */
    public function __construct(
        public readonly string $uid,
        public readonly string $fn,
        public readonly string $rawText,
        public readonly bool $hasPhoto,
        public readonly ?string $rev = null,
        public readonly string $firstName = '',
        public readonly string $lastName = '',
        public readonly array $emails = [],
        public readonly array $phones = [],
        public readonly array $categories = [],
    ) {
    }
}
