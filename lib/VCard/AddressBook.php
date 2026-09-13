<?php

declare(strict_types=1);

namespace OCA\ContactHub\VCard;

final class AddressBook
{
    /**
     * @param array<string, Contact> $contacts keyed by uid
     * @param array<string, Group> $groups keyed by uid
     */
    public function __construct(
        public array $contacts = [],
        public array $groups = [],
    ) {
    }

    /** @return Contact[] */
    public function groupMembers(string $groupUid): array
    {
        $out = [];
        foreach ($this->groups[$groupUid]->memberUids as $uid) {
            if (isset($this->contacts[$uid])) {
                $out[] = $this->contacts[$uid];
            }
        }
        return $out;
    }
}
