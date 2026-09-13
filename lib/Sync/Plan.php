<?php

declare(strict_types=1);

namespace OCA\ContactHub\Sync;

/**
 * What a sync run would do. Everything here flows one way, A -> B --
 * direction is enforced by the Planner, not by the caller remembering to
 * ignore fields.
 */
final class Plan
{
    /** @var string[] */
    public array $contactsCreateAToB = [];
    /** @var string[] */
    public array $contactsUpdateAToB = [];
    /** @var string[] uids removed from A -> mirrored/archived on B */
    public array $contactsRemoveOnB = [];
    /** @var DuplicateMatch[] new untracked contacts matching an existing untracked contact on the other side */
    public array $contactDuplicates = [];
    /** @var string[] uids of a declined duplicate pair whose contact is gone, so the cancelled row can be dropped */
    public array $contactsDropState = [];
    /**
     * Diagnostic, not an action: uids a forced run found no longer matching
     * on B what this app last wrote there. Always a subset of
     * contactsUpdateAToB -- drift is what put them in that bucket -- so it
     * changes nothing about isEmpty() or about what the run does. It exists
     * so the run can tell the user *why* a forced run keeps reporting the
     * same contacts, which is usually a server rewriting what it stores.
     *
     * @var string[]
     */
    public array $contactsDriftedOnB = [];

    /** @var string[] */
    public array $groupsCreateAToB = [];
    /** @var string[] */
    public array $groupsUpdateAToB = [];
    /** @var string[] */
    public array $groupsRemoveOnB = [];

    public function isEmpty(): bool
    {
        foreach (get_object_vars($this) as $bucket) {
            if ($bucket !== []) {
                return false;
            }
        }
        return true;
    }
}
