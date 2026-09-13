<?php

declare(strict_types=1);

namespace OCA\ContactHub\CardDav;

use DOMDocument;
use DOMElement;
use DOMXPath;

final class DavXml
{
    public static function assertOk(HttpResponse $resp, string $context): void
    {
        if ($resp->status >= 400) {
            throw new DavException(
                "{$context} failed: HTTP {$resp->status}: " . substr($resp->body, 0, 500),
                $resp->status,
            );
        }
    }

    private static function parse(string $xml): DOMDocument
    {
        $doc = new DOMDocument();
        $doc->resolveExternals = false;
        $doc->substituteEntities = false;

        $previous = libxml_use_internal_errors(true);
        try {
            $ok = $doc->loadXML($xml, LIBXML_NONET);
            if (!$ok) {
                $errors = libxml_get_errors();
                $message = $errors !== [] ? $errors[0]->message : 'unknown parse error';
                throw new DavException("Failed to parse DAV XML response: {$message}");
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $doc;
    }

    /** Finds the first element named $propName; if $childName given, returns that descendant's text instead. */
    public static function extractText(string $xml, string $propName, ?string $childName = null): ?string
    {
        $doc = self::parse($xml);
        $xpath = new DOMXPath($doc);
        $nodes = $xpath->query("//*[local-name()='{$propName}']");
        if ($nodes === false) {
            return null;
        }
        foreach ($nodes as $node) {
            if ($childName === null) {
                $text = trim($node->textContent);
                return $text !== '' ? $text : null;
            }
            $children = $xpath->query(".//*[local-name()='{$childName}']", $node);
            if ($children !== false && $children->length > 0) {
                $text = $children->item(0)->textContent;
                return $text !== '' ? $text : null;
            }
        }
        return null;
    }

    /** @return string[] */
    public static function extractAll(string $xml, string $tagName): array
    {
        $doc = self::parse($xml);
        $xpath = new DOMXPath($doc);
        $nodes = $xpath->query("//*[local-name()='{$tagName}']");
        if ($nodes === false) {
            return [];
        }
        $out = [];
        foreach ($nodes as $node) {
            if ($node->textContent !== '') {
                $out[] = $node->textContent;
            }
        }
        return $out;
    }

    /** @return DavResponse[] */
    public static function parseMultistatus(string $xml): array
    {
        $doc = self::parse($xml);
        $xpath = new DOMXPath($doc);
        $responseNodes = $xpath->query("//*[local-name()='response']");
        if ($responseNodes === false) {
            return [];
        }

        $responses = [];
        foreach ($responseNodes as $responseEl) {
            if (!$responseEl instanceof DOMElement) {
                continue;
            }
            $href = null;
            /** @var array<string, DOMElement> $props */
            $props = [];

            foreach ($responseEl->childNodes as $child) {
                if (!$child instanceof DOMElement) {
                    continue;
                }
                if ($child->localName === 'href') {
                    $href = $child->textContent;
                } elseif ($child->localName === 'propstat') {
                    $statusText = '';
                    $propEl = null;
                    foreach ($child->childNodes as $pchild) {
                        if (!$pchild instanceof DOMElement) {
                            continue;
                        }
                        if ($pchild->localName === 'status') {
                            $statusText = $pchild->textContent;
                        } elseif ($pchild->localName === 'prop') {
                            $propEl = $pchild;
                        }
                    }
                    if (str_contains($statusText, '200') && $propEl !== null) {
                        foreach ($propEl->childNodes as $p) {
                            if ($p instanceof DOMElement) {
                                $props[$p->localName] = $p;
                            }
                        }
                    }
                }
            }

            $responses[] = new DavResponse($href, $props, $xpath);
        }

        return $responses;
    }
}
