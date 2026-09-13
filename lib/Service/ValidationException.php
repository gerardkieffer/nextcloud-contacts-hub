<?php

declare(strict_types=1);

namespace OCA\ContactHub\Service;

/**
 * A user-correctable problem with submitted data.
 *
 * Carries per-field messages rather than one string, because the SPA shows
 * errors next to the field that caused them. The old app re-rendered the
 * whole form with a single error line at the top; this is the shape that
 * replaces it.
 */
class ValidationException extends \RuntimeException
{
    /** @param array<string, string> $errors field name => message */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }

    public static function field(string $field, string $message): self
    {
        return new self([$field => $message]);
    }
}
