<?php

declare(strict_types=1);

namespace OCA\ContactHub\VCard;

/**
 * One unfolded vCard property line: `[group.]NAME[;PARAM=VALUE...]:VALUE`.
 * Deliberately not a full RFC 6350 value-type parser -- properties this
 * app doesn't need to inspect/mutate are round-tripped through
 * parse()/toLine() unchanged, byte-for-byte, which is the point: we
 * only ever touch UID/FN/REV/KIND/MEMBER/CATEGORIES/PHOTO and leave
 * everything else opaque.
 */
final class PropertyLine
{
    /** @param array<string, string[]> $params keyed by upper-cased param name */
    public function __construct(
        public string $group,
        public string $name,
        public array $params,
        public string $value,
    ) {
    }

    public static function parse(string $line): self
    {
        [$head, $value] = self::splitOnUnquotedColon($line);

        $firstSemi = self::indexOfUnquoted($head, ';');
        $namePart = $firstSemi === null ? $head : substr($head, 0, $firstSemi);
        $paramsPart = $firstSemi === null ? '' : substr($head, $firstSemi + 1);

        $group = '';
        $dotPos = strpos($namePart, '.');
        if ($dotPos !== false) {
            $group = substr($namePart, 0, $dotPos);
            $namePart = substr($namePart, $dotPos + 1);
        }

        $params = [];
        if ($paramsPart !== '') {
            foreach (self::splitUnquoted($paramsPart, ';') as $paramChunk) {
                if ($paramChunk === '') {
                    continue;
                }
                $eq = self::indexOfUnquoted($paramChunk, '=');
                if ($eq === null) {
                    $pName = strtoupper($paramChunk);
                    $params[$pName][] = '';
                    continue;
                }
                $pName = strtoupper(substr($paramChunk, 0, $eq));
                $pValueRaw = substr($paramChunk, $eq + 1);
                foreach (self::splitUnquoted($pValueRaw, ',') as $v) {
                    $params[$pName][] = trim($v, '"');
                }
            }
        }

        return new self($group, $namePart, $params, $value);
    }

    public function param(string $name): ?string
    {
        $values = $this->params[strtoupper($name)] ?? [];
        return $values[0] ?? null;
    }

    public function toLine(): string
    {
        $namePart = ($this->group !== '' ? $this->group . '.' : '') . $this->name;
        $paramStr = '';
        foreach ($this->params as $pName => $values) {
            $joined = implode(',', array_map(
                static fn(string $v): string => self::needsQuoting($v) ? '"' . $v . '"' : $v,
                $values,
            ));
            $paramStr .= ';' . $pName . '=' . $joined;
        }
        return $namePart . $paramStr . ':' . $this->value;
    }

    private static function needsQuoting(string $v): bool
    {
        return str_contains($v, ':') || str_contains($v, ';') || str_contains($v, ',');
    }

    /** @return array{0: string, 1: string} */
    private static function splitOnUnquotedColon(string $line): array
    {
        $pos = self::indexOfUnquoted($line, ':');
        if ($pos === null) {
            return [$line, ''];
        }
        return [substr($line, 0, $pos), substr($line, $pos + 1)];
    }

    private static function indexOfUnquoted(string $s, string $char): ?int
    {
        $inQuotes = false;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            if ($c === '"') {
                $inQuotes = !$inQuotes;
            } elseif ($c === $char && !$inQuotes) {
                return $i;
            }
        }
        return null;
    }

    /** @return string[] */
    private static function splitUnquoted(string $s, string $char): array
    {
        $parts = [];
        $inQuotes = false;
        $start = 0;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            if ($c === '"') {
                $inQuotes = !$inQuotes;
            } elseif ($c === $char && !$inQuotes) {
                $parts[] = substr($s, $start, $i - $start);
                $start = $i + 1;
            }
        }
        $parts[] = substr($s, $start);
        return $parts;
    }
}
