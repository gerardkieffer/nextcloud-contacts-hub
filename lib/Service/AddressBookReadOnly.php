<?php

declare(strict_types=1);

namespace OCA\ContactHub\Service;

/**
 * Raised when something would write into an address book the user can only
 * read: a book shared with them read-only.
 *
 * Separate from AddressBookNotAccessible because this one is safe to say out
 * loud -- the user can already see the book, so naming it leaks nothing --
 * and because the remedy is different: pick another book, or ask its owner
 * for write access.
 */
class AddressBookReadOnly extends \RuntimeException
{
    public function __construct(public readonly int $addressBookId, string $displayName)
    {
        parent::__construct(
            "The address book \"{$displayName}\" is shared with you read-only, so nothing may be written to it.",
        );
    }
}
