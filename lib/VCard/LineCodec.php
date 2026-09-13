<?php

declare(strict_types=1);

namespace OCA\ContactHub\VCard;

/** RFC 6350 §3.2 line folding/unfolding. Operates byte-wise (octets), not characters. */
final class LineCodec
{
    /** @return string[] unfolded logical lines; blank lines dropped */
    public static function unfold(string $text): array
    {
        $rawLines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $logical = [];
        foreach ($rawLines as $line) {
            if ($line === '') {
                continue;
            }
            if (($line[0] === ' ' || $line[0] === "\t") && $logical !== []) {
                $logical[array_key_last($logical)] .= substr($line, 1);
            } else {
                $logical[] = $line;
            }
        }
        return $logical;
    }

    public static function fold(string $line, int $maxOctets = 75): string
    {
        $len = strlen($line);
        if ($len <= $maxOctets) {
            return $line;
        }

        $out = [];
        $pos = 0;
        $first = true;
        while ($pos < $len) {
            $budget = $first ? $maxOctets : $maxOctets - 1;
            $take = min($budget, $len - $pos);
            // don't split in the middle of a multi-byte UTF-8 sequence
            while ($take > 0 && $pos + $take < $len && (ord($line[$pos + $take]) & 0xC0) === 0x80) {
                $take--;
            }
            if ($take <= 0) {
                $take = min($budget, $len - $pos);
            }
            $out[] = ($first ? '' : ' ') . substr($line, $pos, $take);
            $pos += $take;
            $first = false;
        }
        return implode("\r\n", $out);
    }
}
