<?php

declare(strict_types=1);

namespace OCA\ContactHub\Sync;

/**
 * Shared normalization for identity comparisons across sides: names,
 * emails and phone numbers each need their own notion of "the same value",
 * and every caller comparing them must use the identical rule or two
 * differently-cased/formatted copies of the same value silently fail to
 * match.
 */
final class Normalize
{
    public static function name(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    public static function email(string $email): string
    {
        return strtolower(trim($email));
    }

    public static function phone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }
}
