<?php

declare(strict_types=1);

namespace OCA\ContactHub\VCard;

/** Name-based UUIDs (RFC 4122 section 4.3), for identities that must be stable without being stored. */
final class Uuid
{
    /** SHA-1 of namespace bytes plus name, version 5. */
    public static function v5(string $namespace, string $name): string
    {
        $bytes = hex2bin(str_replace('-', '', $namespace));
        if ($bytes === false) {
            throw new \LogicException("Invalid namespace UUID: {$namespace}");
        }

        $hash = sha1($bytes . $name);

        return sprintf(
            '%08s-%04s-%04x-%04x-%12s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            // Version 5: keep the low 12 bits, force the version nibble.
            (hexdec(substr($hash, 12, 4)) & 0x0fff) | 0x5000,
            // Variant RFC 4122: clear the top two bits, set bit 7.
            (hexdec(substr($hash, 16, 4)) & 0x3fff) | 0x8000,
            substr($hash, 20, 12),
        );
    }
}
