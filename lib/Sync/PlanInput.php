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
     * @param bool $bGroupsAsCategories B expresses groups as CATEGORIES on
     *        each contact (Nextcloud, or an endpoint configured that way)
     *        rather than as group resources of its own. A group then has no
     *        location on B to check or delete, and a membership change has
     *        to reach B as a change to its member contacts instead.
     */
    public function __construct(
        public readonly AddressBook $bookA,
        public readonly AddressBook $bookB,
        public readonly array $contactStates,
        public readonly array $groupStates,
        public readonly array $liveBHrefs,
        public readonly bool $force = false,
        public readonly bool $bGroupsAsCategories = false,
    ) {
    }
}
