<?php

declare(strict_types=1);

namespace OCA\ContactHub\VCard;

final class Model
{
    private const string MEMBER_PREFIX = 'urn:uuid:';

    public static function parse(string $rawText): Contact|Group
    {
        $doc = Document::parse($rawText);

        $uidProp = $doc->first('UID');
        $uid = $uidProp !== null ? trim($uidProp->value) : '';
        if ($uid === '') {
            throw new VCardParseException('vCard has no UID');
        }

        $revProp = $doc->first('REV');
        $rev = $revProp !== null ? trim($revProp->value) : null;
        $rev = $rev !== '' ? $rev : null;

        if (self::isGroup($doc)) {
            $fnProp = $doc->first('FN');
            $name = $fnProp !== null ? trim($fnProp->value) : $uid;
            return new Group($uid, $name, self::memberUids($doc), $rawText, $rev);
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
     * @return array{0: AddressBook, 1: string[]} address book + warnings
     */
    public static function buildAddressBook(array $rawTexts, string $sideLabel = '', ?int $listedCount = null): array
    {
        $book = new AddressBook();
        $warnings = [];

        foreach ($rawTexts as $rawText) {
            try {
                $item = self::parse($rawText);
            } catch (VCardParseException $e) {
                $warnings[] = $e->getMessage();
                continue;
            }
            if ($item instanceof Group) {
                $book->groups[$item->uid] = $item;
            } else {
                $book->contacts[$item->uid] = $item;
            }
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
        $shown = implode(', ', array_slice($missing, 0, self::MEMBERS_LISTED));
        $rest = $count > self::MEMBERS_LISTED
            ? ' and ' . ($count - self::MEMBERS_LISTED) . ' more'
            : '';

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
            ? "Group '{$group->name}' ({$group->uid}){$where}{$read} lists a member that is not in that address book: {$shown}"
            : "Group '{$group->name}' ({$group->uid}){$where}{$read} lists {$count} members that are not in that address book: {$shown}{$rest}";
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

    public static function groupContentHash(Group $group): string
    {
        $sorted = $group->memberUids;
        sort($sorted);
        return hash('sha256', $group->name . "\n" . implode("\n", $sorted));
    }
}
