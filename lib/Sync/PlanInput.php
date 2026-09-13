<?php

declare(strict_types=1);

namespace OCA\ContactHub\Sync;

use OCA\ContactHub\VCard\AddressBook;

final class PlanInput
{
    /**
     * @param array<string, array<string, mixed>> $contactStates keyed by uid, DB rows from contact_state
     * @param array<string, array<string, mixed>> $groupStates keyed by uid, DB rows from group_state
     * @param array<string, string> $liveBHrefs href => etag, from a fresh listing of B
     */
    public function __construct(
        public readonly AddressBook $bookA,
        public readonly AddressBook $bookB,
        public readonly array $contactStates,
        public readonly array $groupStates,
        public readonly array $liveBHrefs,
        public readonly bool $force = false,
    ) {
    }
}
