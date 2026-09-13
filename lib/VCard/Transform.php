<?php

declare(strict_types=1);

namespace OCA\ContactHub\VCard;

final class Transform
{
    private const string MEMBER_PREFIX = 'urn:uuid:';

    public static function stripPhoto(string $vcardText): string
    {
        $doc = Document::parse($vcardText);
        if ($doc->first('PHOTO') === null) {
            return $vcardText;
        }
        $doc->remove('PHOTO');
        return $doc->serialize();
    }

    /**
     * Re-embed a URI-referenced PHOTO as real inline base64.
     *
     * Some CardDAV servers (confirmed: iCloud, 100% of photographed
     * contacts in testing) represent PHOTO as a standards-compliant
     * `PHOTO;VALUE=uri` reference to an authenticated asset URL rather
     * than inline data. A passthrough copy of that reference is
     * meaningless to any other client -- several third-party consumers
     * don't check the VALUE parameter and assume every PHOTO is base64,
     * producing a broken image. $fetchBinary is the *owning* endpoint's
     * authenticated GET (the reference is only resolvable with that
     * endpoint's own credentials), so this works for whichever side of
     * a sync job actually has the quirk, not just one hard-coded origin.
     *
     * @param callable(string): string $fetchBinary
     */
    public static function resolvePhotoUri(string $vcardText, callable $fetchBinary): string
    {
        $doc = Document::parse($vcardText);
        $photos = $doc->all('PHOTO');
        if ($photos === []) {
            return $vcardText;
        }

        $changed = false;
        foreach ($photos as $photo) {
            $value = trim($photo->value);

            // An empty URI-valued PHOTO is junk that real address books
            // genuinely contain -- Nextcloud's own example contact ships two
            // of them alongside a perfectly good inline photo. Fetching ""
            // can never succeed, and because one failed reference aborts the
            // whole transform, not skipping these costs the contact the real
            // photo it did have.
            if ($value === '') {
                continue;
            }

            $isUriValued = strtolower($photo->param('VALUE') ?? '') === 'uri';
            $looksLikeUrl = str_starts_with($value, 'http://') || str_starts_with($value, 'https://');
            if (!$isUriValued && !$looksLikeUrl) {
                continue;
            }

            $bytes = $fetchBinary($value);
            $photo->value = base64_encode($bytes);
            unset($photo->params['VALUE']);
            $photo->params['ENCODING'] = ['b'];
            self::labelPhotoMediaType($doc, $photo, $bytes);
            $changed = true;
        }

        return $changed ? $doc->serialize() : $vcardText;
    }

    /**
     * Say what kind of image an inlined PHOTO is.
     *
     * A URI-valued PHOTO carries its media type in the HTTP response, and
     * inlining it throws that away. Nothing in the vCard spec makes the
     * label mandatory, and every consumer that matters needs it anyway:
     * Nextcloud's PhotoCache::getBinaryType() reads TYPE or MEDIATYPE and
     * returns an empty string without either, at which point the avatar is
     * served as application/octet-stream and no browser will render it. The
     * photo is in the address book, intact, and every contact shows blank --
     * which is exactly how this was reported, from an iCloud pull where the
     * photos arrive as authenticated https references rather than inline.
     *
     * The label has to come from the bytes: the source property has no type
     * parameter to copy, which is the whole point of it being a reference.
     * An unrecognised image is left unlabelled rather than guessed at -- a
     * wrong Content-Type renders no better than none, and the bytes are
     * still there for anything that sniffs for itself.
     *
     * vCard 3.0 spells this TYPE=JPEG and 4.0 spells it MEDIATYPE=image/jpeg;
     * both are written in their own dialect rather than picking one, because
     * this app syncs 3.0 and 4.0 address books to each other.
     */
    private static function labelPhotoMediaType(Document $doc, PropertyLine $photo, string $bytes): void
    {
        $subtype = self::sniffImageSubtype($bytes);
        if ($subtype === null) {
            return;
        }

        $version = trim($doc->first('VERSION')?->value ?? '3.0');
        if (str_starts_with($version, '4')) {
            unset($photo->params['TYPE']);
            $photo->params['MEDIATYPE'] = ['image/' . $subtype];
            return;
        }

        unset($photo->params['MEDIATYPE']);
        $photo->params['TYPE'] = [strtoupper($subtype)];
    }

    /**
     * The image subtype (as in image/<subtype>) these bytes start with.
     *
     * Magic numbers rather than finfo or getimagesizefromstring: this is a
     * handful of signatures, it keeps the vCard layer free of extension
     * requirements like the rest of the parser, and the answer is needed
     * only to fill in a label. Covers what Nextcloud's PhotoCache is willing
     * to serve, which is the consumer that made this necessary.
     */
    private static function sniffImageSubtype(string $bytes): ?string
    {
        return match (true) {
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'jpeg',
            str_starts_with($bytes, "\x89PNG\x0D\x0A\x1A\x0A") => 'png',
            str_starts_with($bytes, 'GIF87a'), str_starts_with($bytes, 'GIF89a') => 'gif',
            str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => 'webp',
            substr($bytes, 4, 8) === 'ftypavif' => 'avif',
            str_starts_with($bytes, "\x00\x00\x01\x00") => 'vnd.microsoft.icon',
            default => null,
        };
    }

    /** @param string[] $categories */
    public static function setCategories(string $vcardText, array $categories): string
    {
        $doc = Document::parse($vcardText);
        $doc->remove('CATEGORIES');
        if ($categories !== []) {
            $escaped = implode(',', array_map(self::escapeText(...), $categories));
            $doc->append(new PropertyLine('', 'CATEGORIES', [], $escaped));
        }
        return $doc->serialize();
    }

    /**
     * Render a contact's vCard for writing to an endpoint.
     *
     * $categories === null leaves CATEGORIES untouched (passthrough/
     * collections group strategies); a list (possibly empty) replaces
     * CATEGORIES entirely (categories strategy, where group membership
     * is folded in here). When neither photo-stripping nor a category
     * rewrite is needed, the raw text is returned completely unchanged
     * -- not reparsed and reserialized -- so an untouched vCard stays
     * byte-for-byte identical to what was read.
     *
     * @param string[]|null $categories
     */
    public static function renderForDestination(string $rawText, bool $includePhotos, ?array $categories): string
    {
        $text = $rawText;
        if (!$includePhotos) {
            $text = self::stripPhoto($text);
        }
        if ($categories !== null) {
            $text = self::setCategories($text, $categories);
        }
        return $text;
    }

    /** Construct a fresh Apple-style group vCard from scratch. @param string[] $memberUids */
    public static function buildGroupVCard(string $uid, string $name, array $memberUids): string
    {
        $escapedName = self::escapeText($name);
        $properties = [
            new PropertyLine('', 'BEGIN', [], 'VCARD'),
            new PropertyLine('', 'VERSION', [], '3.0'),
            new PropertyLine('', 'FN', [], $escapedName),
            new PropertyLine('', 'N', [], $escapedName . ';;;;'),
            new PropertyLine('', 'X-ADDRESSBOOKSERVER-KIND', [], 'group'),
        ];
        foreach ($memberUids as $memberUid) {
            $properties[] = new PropertyLine('', 'X-ADDRESSBOOKSERVER-MEMBER', [], self::MEMBER_PREFIX . $memberUid);
        }
        $properties[] = new PropertyLine('', 'UID', [], $uid);
        $properties[] = new PropertyLine('', 'END', [], 'VCARD');

        return Document::fromProperties($properties)->serialize();
    }

    public static function withMemberAdded(string $vcardText, string $memberUid): string
    {
        $doc = Document::parse($vcardText);
        $prefixed = self::MEMBER_PREFIX . $memberUid;
        $existing = array_map(
            static fn(PropertyLine $p): string => trim($p->value),
            $doc->all('X-ADDRESSBOOKSERVER-MEMBER'),
        );
        if (!in_array($prefixed, $existing, true)) {
            $doc->append(new PropertyLine('', 'X-ADDRESSBOOKSERVER-MEMBER', [], $prefixed));
        }
        return $doc->serialize();
    }

    public static function withMemberRemoved(string $vcardText, string $memberUid): string
    {
        $doc = Document::parse($vcardText);
        $prefixed = self::MEMBER_PREFIX . $memberUid;
        $remaining = array_values(array_filter(
            $doc->all('X-ADDRESSBOOKSERVER-MEMBER'),
            static fn(PropertyLine $p): bool => trim($p->value) !== $prefixed,
        ));
        $doc->remove('X-ADDRESSBOOKSERVER-MEMBER');
        foreach ($remaining as $p) {
            $doc->append($p);
        }
        return $doc->serialize();
    }

    /** Replace a vCard's UID -- used when archiving a duplicate copy of a contact under a fresh, non-colliding identity. */
    public static function withUid(string $vcardText, string $newUid): string
    {
        $doc = Document::parse($vcardText);
        $doc->remove('UID');
        $doc->append(new PropertyLine('', 'UID', [], $newUid));
        return $doc->serialize();
    }

    public static function escapeText(string $text): string
    {
        return str_replace(['\\', ',', ';', "\n"], ['\\\\', '\\,', '\\;', '\\n'], $text);
    }

    public static function unescapeText(string $text): string
    {
        $out = '';
        $len = strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $c = $text[$i];
            if ($c === '\\' && $i + 1 < $len) {
                $next = $text[$i + 1];
                $out .= match ($next) {
                    'n', 'N' => "\n",
                    default => $next,
                };
                $i++;
                continue;
            }
            $out .= $c;
        }
        return $out;
    }

    /**
     * Split a compound property value (e.g. N's Family;Given;... parts)
     * on unescaped occurrences of $separator, unescaping each part.
     *
     * @return string[]
     */
    public static function splitUnescaped(string $value, string $separator): array
    {
        $parts = [];
        $current = '';
        $len = strlen($value);
        for ($i = 0; $i < $len; $i++) {
            $c = $value[$i];
            if ($c === '\\' && $i + 1 < $len) {
                $current .= $c . $value[$i + 1];
                $i++;
                continue;
            }
            if ($c === $separator) {
                $parts[] = $current;
                $current = '';
                continue;
            }
            $current .= $c;
        }
        $parts[] = $current;
        return array_map(self::unescapeText(...), $parts);
    }
}
