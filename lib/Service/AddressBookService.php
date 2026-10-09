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
 *
 * # CardDavBackend enforces no permissions
 *
 * Nextcloud checks share permissions in the DAV layer above the backend, not
 * in the backend itself: createCard() and deleteCard() write to any book id
 * they are handed. Everything this app writes goes straight to the backend,
 * so the checks that DAV would have made have to be made here instead:
 *
 *  * **read-only shares.** requireAccess() used to accept any book in the
 *    user's list, and a book shared read-only is in that list. A pull job
 *    into one then wrote into, and mirror-deleted from, another user's
 *    address book -- verified against a real share, not inferred.
 *    requireWritable() is the check for anything that writes.
 *
 *  * **access that has since ended.** A job stores a book id. Checking it
 *    only when the job is saved leaves a revoked share syncing for ever, and
 *    a deleted book reading as an empty one -- which a push job mirrors to
 *    the endpoint by deleting everything there. SideFactory checks again on
 *    every run for that reason.
 *
 * Shared books also come back with `principaluri` rewritten to the sharee,
 * so ownership has to be read from `owner-principal`; comparing principaluri
 * called every shared book "owned".
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

    private const string OWNER_PRINCIPAL = '{http://owncloud.org/ns}owner-principal';
    private const string READ_ONLY = '{http://owncloud.org/ns}read-only';

    /**
     * Every address book $userId can reach, owned or shared.
     *
     * @return list<array{id: int, uri: string, displayName: string, owned: bool, readOnly: bool}>
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
                'owned' => ($book[self::OWNER_PRINCIPAL] ?? $book['principaluri'] ?? null) === $principal,
                'readOnly' => (bool) ($book[self::READ_ONLY] ?? false),
            ];
        }

        return $out;
    }

    /**
     * @return array{id: int, uri: string, displayName: string, owned: bool, readOnly: bool}
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
     * requireAccess(), plus the guarantee that the user may write to it.
     *
     * @return array{id: int, uri: string, displayName: string, owned: bool, readOnly: bool}
     * @throws AddressBookNotAccessible
     * @throws AddressBookReadOnly
     */
    public function requireWritable(int $addressBookId, string $userId): array
    {
        $book = $this->requireAccess($addressBookId, $userId);
        if ($book['readOnly']) {
            throw new AddressBookReadOnly($addressBookId, $book['displayName']);
        }

        return $book;
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
