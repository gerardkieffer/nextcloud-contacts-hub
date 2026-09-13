<?php

declare(strict_types=1);

namespace OCA\ContactHub\Service;

use OCA\DAV\CardDAV\CardDavBackend;

/**
 * The app's only doorway to Nextcloud's address books.
 *
 * CardDavBackend lives in the bundled `dav` app and is not public API, so
 * every use of it is funnelled through this class and NextcloudSide. If a
 * future Nextcloud major changes it, those two files are the whole blast
 * radius.
 *
 * It is also the access-control boundary for address books: a job may only
 * name a book the owning user can actually reach, and requireAccess() is
 * what proves that. getAddressBooksForUser() already includes books shared
 * with the user, so sharing works without any extra handling here.
 */
class AddressBookService
{
    public function __construct(
        private readonly CardDavBackend $backend,
    ) {
    }

    public static function principalFor(string $userId): string
    {
        return 'principals/users/' . $userId;
    }

    /**
     * Every address book $userId can reach, owned or shared.
     *
     * @return list<array{id: int, uri: string, displayName: string, owned: bool}>
     */
    public function listForUser(string $userId): array
    {
        $principal = self::principalFor($userId);
        $out = [];

        foreach ($this->backend->getAddressBooksForUser($principal) as $book) {
            $out[] = [
                'id' => (int) $book['id'],
                'uri' => (string) $book['uri'],
                // Nextcloud omits the displayname property rather than storing
                // an empty one, so fall back to the URI slug.
                'displayName' => (string) ($book['{DAV:}displayname'] ?? $book['uri']),
                'owned' => ($book['principaluri'] ?? null) === $principal,
            ];
        }

        return $out;
    }

    /**
     * @return array{id: int, uri: string, displayName: string, owned: bool}
     * @throws AddressBookNotAccessible
     */
    public function requireAccess(int $addressBookId, string $userId): array
    {
        foreach ($this->listForUser($userId) as $book) {
            if ($book['id'] === $addressBookId) {
                return $book;
            }
        }

        // Deliberately the same failure whether the book is missing or merely
        // someone else's: distinguishing them would leak its existence.
        throw new AddressBookNotAccessible($addressBookId);
    }

    /**
     * Display name for a book the caller has already authorised, for labelling
     * only. Returns a placeholder rather than throwing, so a job whose address
     * book was deleted still renders in a list instead of breaking the page.
     */
    public function displayName(int $addressBookId): string
    {
        $book = $this->backend->getAddressBookById($addressBookId);
        if ($book === null) {
            return '(deleted address book)';
        }

        return (string) ($book['{DAV:}displayname'] ?? $book['uri'] ?? (string) $addressBookId);
    }
}
