<?php

declare(strict_types=1);

namespace OCA\ContactHub\VCard;

final class Document
{
    /** @param PropertyLine[] $properties */
    private function __construct(private array $properties)
    {
    }

    public static function parse(string $text): self
    {
        $lines = LineCodec::unfold($text);
        return new self(array_map(PropertyLine::parse(...), $lines));
    }

    /** @param PropertyLine[] $properties */
    public static function fromProperties(array $properties): self
    {
        return new self($properties);
    }

    public function first(string $name): ?PropertyLine
    {
        foreach ($this->properties as $p) {
            if (strcasecmp($p->name, $name) === 0) {
                return $p;
            }
        }
        return null;
    }

    /** @return PropertyLine[] */
    public function all(string $name): array
    {
        return array_values(array_filter(
            $this->properties,
            static fn(PropertyLine $p): bool => strcasecmp($p->name, $name) === 0,
        ));
    }

    public function remove(string $name): void
    {
        $this->properties = array_values(array_filter(
            $this->properties,
            static fn(PropertyLine $p): bool => strcasecmp($p->name, $name) !== 0,
        ));
    }

    /** Appends immediately before END:VCARD, or at the very end if none is present. */
    public function append(PropertyLine $prop): void
    {
        $endIndex = null;
        foreach ($this->properties as $i => $p) {
            if (strcasecmp($p->name, 'END') === 0) {
                $endIndex = $i;
                break;
            }
        }
        if ($endIndex === null) {
            $this->properties[] = $prop;
        } else {
            array_splice($this->properties, $endIndex, 0, [$prop]);
        }
    }

    public function serialize(): string
    {
        $lines = array_map(
            static fn(PropertyLine $p): string => LineCodec::fold($p->toLine()),
            $this->properties,
        );
        return implode("\r\n", $lines) . "\r\n";
    }
}
