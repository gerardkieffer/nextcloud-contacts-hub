<?php

declare(strict_types=1);

namespace OCA\ContactHub\Sync;

/**
 * The job's Nextcloud address book cannot be used for this run: it has been
 * deleted, it is no longer shared with the job's owner, or the job would
 * write into a book shared with them read-only.
 *
 * Raised before anything is fetched, because the first of those is
 * indistinguishable from an empty address book once fetching starts --
 * CardDavBackend::getCards() on a deleted book's id simply returns nothing,
 * and a push job reading "nothing" as its source deletes every contact on
 * the endpoint. That shipped: verified against a real deleted book.
 */
final class HubUnavailable extends \RuntimeException
{
}
