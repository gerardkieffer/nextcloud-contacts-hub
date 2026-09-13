<?php

declare(strict_types=1);

namespace OCA\ContactHub\CardDav;

/**
 * The request hit the local curl time limit before the server finished
 * answering (CURLE_OPERATION_TIMEDOUT) -- as opposed to the server
 * responding with an error.
 *
 * Distinct class because one caller genuinely branches on it:
 * Client::fetchAllVCards() falls back from the single whole-collection
 * REPORT to chunked multigets when -- and only when -- the big request
 * timed out. A 4xx/5xx must NOT trigger that fallback; retrying a
 * refused request in 16 smaller pieces would just hammer a server
 * that already said no.
 */
final class DavTimeout extends DavException
{
}
