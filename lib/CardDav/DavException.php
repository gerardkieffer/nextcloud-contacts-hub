<?php

declare(strict_types=1);

namespace OCA\ContactHub\CardDav;

class DavException extends \RuntimeException
{
    /**
     * The HTTP status that caused this, or 0 when the failure happened
     * before a response existed (transport error, unparseable XML, a
     * missing property in an otherwise-200 body).
     *
     * Carried as a real property because callers branch on it: telling
     * "the server rejected your credentials" apart from "the server is
     * unreachable" is the difference between a useful error next to the
     * password field and a stack trace. Reading it back out of the message
     * string would be the same substring-matching mistake this codebase
     * has already paid for elsewhere.
     */
    public function __construct(
        string $message,
        public readonly int $status = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    /** True for 401/403: the request reached the server and was refused. */
    public function isAuthFailure(): bool
    {
        return $this->status === 401 || $this->status === 403;
    }
}
