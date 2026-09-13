<?php

declare(strict_types=1);

namespace OCA\ContactHub\Service;

/**
 * Raised when a user names an address book that either does not exist or is
 * not shared with them. The two cases are deliberately indistinguishable --
 * telling them apart would confirm the existence of another user's book.
 */
class AddressBookNotAccessible extends \RuntimeException
{
    public function __construct(public readonly int $addressBookId)
    {
        parent::__construct("Address book {$addressBookId} does not exist or is not accessible.");
    }
}
