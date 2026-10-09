<?php

declare(strict_types=1);

namespace OCA\ContactHub\VCard;

final class Model
{
    private const string MEMBER_PREFIX = 'urn:uuid:';

    /** Namespace of Model::derivedUid(); fixed, because changing it re-identifies every UID-less card. */
    private const string HREF_NAMESPACE = '6f1d7c3e-2b54-4c8a-9d0e-7a3b5e1c4f20';

    /**
     * An identity for a card that has none.
     *
     * UID is optional in vCard (RFC 6350 section 6.7.6), and some servers --
     * Mailo's contacts are an example -- keep cards without one. The only
     * identity such a card has is where the server keeps it, and that is
     * stable across runs, so the identity is derived from it. It is never
     * written into a card on its own side: the card stays exactly as the
     * server has it.
     *
     * @param string $href the canonical href Client hands out
     */
    public static function derivedUid(string $href): string
    {
        return Uuid::v5(self::HREF_NAMESPACE, $href);
    }

    /**
     * @param string|null $fallbackUid used when the card has no UID of its own, in
     *        which case the result is flagged uidDerived. Without it a card with no
     *        UID is unreadable, as it always was.
     */
    public static function parse(string $rawText, ?string $fallbackUid = null): Contact|Group
    {
        $doc = Document::parse($rawText);

        $uidProp = $doc->first('UID');
        $uid = $uidProp !== null ? trim($uidProp->value) : '';
        $derived = false;
        if ($uid === '') {
            if ($fallbackUid === null || $fallbackUid === '') {
                throw new VCardParseException('vCard has no UID');
            }
            $uid = $fallbackUid;
            $derived = true;
        }

        $revProp = $doc->first('REV');
        $rev = $revProp !== null ? trim($revProp->value) : null;
        $rev = $rev !== '' ? $rev : null;

        if (self::isGroup($doc)) {
            $fnProp = $doc->first('FN');
            $name = $fnProp !== null ? trim($fnProp->value) : $uid;
            return new Group($uid, $name, self::memberUids($doc), $rawText, $rev, $derived);
        }

        $fnProp = $doc->first('FN');
        $fn = $fnProp !== null ? trim($fnProp->value) : '';

        $firstName = '';
        $lastName = '';
        $nProp = $doc->first('N');
        if ($nProp !== null) {
            // N is Family;Given;Additional;Prefixes;Suffixes.
            $parts = Transform::splitUnescaped($nProp->value, ';');
            $lastName = trim($parts[0] ?? '');
            $firstName = trim($parts[1] ?? '');
        }

        return new Contact(
            $uid,
            $fn,
            $rawText,
            $doc->first('PHOTO') !== null,
            $rev,
            $firstName,
            $lastName,
            self::propertyValues($doc, 'EMAIL'),
            self::propertyValues($doc, 'TEL'),
            self::categories($doc),
            $derived,
        );
    }

    /**
     * CATEGORIES values, split on unescaped commas and unescaped.
     *
     * A vCard may carry more than one CATEGORIES line, and each holds a
     * comma-separated list in which a literal comma is backslash-escaped --
     * so this must not be a plain explode(). Duplicates are collapsed
     * because two lines naming the same group mean one membership.
     *
     * @return string[]
     */
    private static function categories(Document $doc): array
    {
        $names = [];
        foreach ($doc->all('CATEGORIES') as $prop) {
            // splitUnescaped already unescapes every part it returns.
            // Unescaping again would eat a literal backslash and the
            // character after it, so "Foo\\Bar" would arrive as "FooBar".
            foreach (Transform::splitUnescaped($prop->value, ',') as $piece) {
                $name = trim($piece);
                if ($name !== '') {
                    $names[] = $name;
                }
            }
        }

        return array_values(array_unique($names));
    }

    /** @return string[] */
    private static function propertyValues(Document $doc, string $name): array
    {
        $values = [];
        foreach ($doc->all($name) as $prop) {
            $value = trim(Transform::unescapeText($prop->value));
            if ($value !== '') {
                $values[] = $value;
            }
        }
        return $values;
    }

    private static function isGroup(Document $doc): bool
    {
        $kind = $doc->first('X-ADDRESSBOOKSERVER-KIND') ?? $doc->first('KIND');
        return $kind !== null && strtolower(trim($kind->value)) === 'group';
    }

    /** @return string[] */
    private static function memberUids(Document $doc): array
    {
        $uids = [];
        foreach ($doc->all('X-ADDRESSBOOKSERVER-MEMBER') as $prop) {
            $value = trim($prop->value);
            if (str_starts_with($value, self::MEMBER_PREFIX)) {
                $value = substr($value, strlen(self::MEMBER_PREFIX));
            }
            $uids[] = $value;
        }
        return $uids;
    }

    /**
     * How many missing member UIDs a warning spells out before summarising.
     * Enough to recognise which contacts are meant, few enough that the line
     * stays one line.
     */
    private const int MEMBERS_LISTED = 3;

    /**
     * @param string[] $rawTexts
     * @param string $sideLabel names the address book these came from, so a
     *        warning says where to go and look. Optional only because this
     *        is a pure parser with tests that have no sides.
     * @param int|null $listedCount how many entries that address book says it
     *        holds, when the caller knows. See danglingMembersWarning().
     * @param list<string>|null $hrefs where each of $rawTexts lives, in the same
     *        order. A card with no UID of its own takes its identity from this
     *        (see derivedUid()); without it such a card is unreadable.
     * @return array{0: AddressBook, 1: string[]} address book + warnings
     */
    public static function buildAddressBook(
        array $rawTexts,
        string $sideLabel = '',
        ?int $listedCount = null,
        ?array $hrefs = null,
    ): array {
        $book = new AddressBook();
        $warnings = [];
        $unreadable = [];

        foreach (array_values($rawTexts) as $i => $rawText) {
            $href = $hrefs[$i] ?? null;
            try {
                $item = self::parse($rawText, $href !== null ? self::derivedUid($href) : null);
            } catch (VCardParseException $e) {
                $unreadable[$e->getMessage()][] = $href;
                continue;
            }
            if ($item instanceof Group) {
                $book->groups[$item->uid] = $item;
            } else {
                $book->contacts[$item->uid] = $item;
            }
        }

        // One warning per reason, never one per card: a server whose cards are
        // all unreadable the same way would otherwise write hundreds of
        // identical lines on every run -- and, before UID-less cards were
        // readable, did, with nothing to say which card was meant.
        foreach ($unreadable as $reason => $where) {
            $count = count($where);
            $label = $sideLabel !== '' ? " in {$sideLabel}" : '';
            $known = array_values(array_filter($where, 'is_string'));
            $warnings[] = sprintf(
                '%d card%s%s could not be read (%s) and %s skipped%s',
                $count,
                $count === 1 ? '' : 's',
                $label,
                $reason,
                $count === 1 ? 'was' : 'were',
                $known !== [] ? ': ' . self::truncatedList($known, 3) : '.',
            );
        }

        foreach ($book->groups as $group) {
            $missing = [];
            foreach ($group->memberUids as $memberUid) {
                if (!isset($book->contacts[$memberUid]) && !isset($book->groups[$memberUid])) {
                    $missing[] = $memberUid;
                }
            }
            if ($missing !== []) {
                $warnings[] = self::danglingMembersWarning($group, $missing, $sideLabel, $listedCount, count($rawTexts));
            }
        }

        return [$book, $warnings];
    }

    /**
     * One warning per group, not one per member.
     *
     * This is an observation about an address book's own consistency, and a
     * group that has outlived some of its members is an ordinary thing to
     * find in a real one -- nothing about the sync goes wrong because of it,
     * which is exactly why it is a warning and not an error. Per member it
     * was also unreadable and unbounded: a user reported a Nextcloud log
     * taking roughly five hundred lines per sync from it, one for every
     * contact in every group, several times an hour. The same volume goes
     * into the run report the panel renders, a note card apiece.
     *
     * The side label matters as much as the aggregation. Both address books
     * in a job can hold groups, and without saying which one this is about,
     * the message named a UID and left the reader no way to tell whether to
     * look in Nextcloud or at the endpoint -- which is what happened when
     * the report arrived and made it undiagnosable from the log alone.
     *
     * @param string[] $missing
     */
    private static function danglingMembersWarning(
        Group $group,
        array $missing,
        string $sideLabel,
        ?int $listedCount = null,
        int $readCount = 0,
    ): string {
        $where = $sideLabel !== '' ? " in {$sideLabel}" : '';
        $count = count($missing);
        $shownAndRest = self::truncatedList($missing, self::MEMBERS_LISTED);

        // How much of that address book was actually read, when the caller
        // knows. "Not in that address book" and "not in the part of it we
        // managed to read" are very different findings and the message could
        // not tell them apart, which cost a full round trip with a user
        // before Runner::assertWholeBookFetched() existed to rule the second
        // one out. Stating the numbers means the warning answers the
        // question on its own, whatever version produced it.
        $read = $listedCount !== null
            ? " ({$readCount} of {$listedCount} entries read)"
            : '';

        return $count === 1
            ? "Group '{$group->name}' ({$group->uid}){$where}{$read} lists a member that is not in that address book: {$shownAndRest}"
            : "Group '{$group->name}' ({$group->uid}){$where}{$read} lists {$count} members that are not in that address book: {$shownAndRest}";
    }

    /**
     * "a, b, c and N more" -- the first $limit items joined, plus a count of
     * whatever didn't fit. Shared by every warning that lists an unbounded
     * set of names in one aggregated line (see CLAUDE.md on bounded
     * warnings): the aggregation itself is the safety property, so it must
     * stay one implementation rather than being redefined per caller with
     * its own limit and wording that can drift from this one.
     *
     * @param string[] $items
     */
    public static function truncatedList(array $items, int $limit): string
    {
        $count = count($items);
        $shown = implode(', ', array_slice($items, 0, $limit));
        $rest = $count > $limit ? ' and ' . ($count - $limit) . ' more' : '';

        return $shown . $rest;
    }

    public static function contactContentHash(Contact $contact): string
    {
        return self::textHash($contact->rawText);
    }

    /**
     * Canonical content hash for raw vCard text: line endings are
     * normalized to LF first, because the same resource yields CRLF
     * from Document::serialize()/GET but LF from a REPORT fetch (XML
     * line-ending normalization is mandatory per spec). Every hash
     * stored for later diffing must go through here, or an unchanged
     * resource can look modified on the next run.
     */
    public static function textHash(string $vcardText): string
    {
        return hash('sha256', str_replace(["\r\n", "\r"], "\n", $vcardText));
    }

    /**
     * Content hash that ignores the modification date, or null when the text
     * cannot be parsed.
     *
     * For asking whether two copies of a card say the same thing, never for
     * stored state: every stored hash is textHash(), REV included, and
     * changing what it means would make every tracked contact look edited at
     * once. Both texts are re-serialised the same way first, so folding and
     * line endings cannot make equal cards differ.
     */
    public static function contentHashIgnoringRev(string $vcardText): ?string
    {
        try {
            $doc = Document::parse($vcardText);
        } catch (\Throwable) {
            return null;
        }
        $doc->remove('REV');

        return self::textHash($doc->serialize());
    }

    public static function groupContentHash(Group $group): string
    {
        $sorted = $group->memberUids;
        sort($sorted);
        return hash('sha256', $group->name . "\n" . implode("\n", $sorted));
    }
}
