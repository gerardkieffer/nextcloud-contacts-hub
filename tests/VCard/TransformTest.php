<?php

declare(strict_types=1);

namespace OCA\ContactHub\Tests\VCard;

use OCA\ContactHub\VCard\Document;
use OCA\ContactHub\VCard\Model;
use OCA\ContactHub\VCard\Transform;
use PHPUnit\Framework\TestCase;

final class TransformTest extends TestCase
{
    private const string CONTACT = "BEGIN:VCARD\r\n"
        . "VERSION:3.0\r\n"
        . "FN:John Doe\r\n"
        . "EMAIL:john@example.com\r\n"
        . "PHOTO;VALUE=uri;TYPE=JPEG:https://example.com/photo.jpg\r\n"
        . "UID:contact-1\r\n"
        . "END:VCARD\r\n";

    public function testStripPhotoRemovesPhotoButKeepsOtherProperties(): void
    {
        $stripped = Transform::stripPhoto(self::CONTACT);

        self::assertStringNotContainsString('PHOTO', $stripped);
        self::assertStringContainsString('john@example.com', $stripped);
        self::assertFalse(Model::parse($stripped)->hasPhoto);
    }

    public function testStripPhotoIsNoopWhenNoPhotoPresent(): void
    {
        $noPhoto = "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:No Photo\r\nUID:c2\r\nEND:VCARD\r\n";

        self::assertSame($noPhoto, Transform::stripPhoto($noPhoto));
    }

    public function testResolvePhotoUriEmbedsBase64AndDropsValueUri(): void
    {
        $seenUrl = null;
        $resolved = Transform::resolvePhotoUri(self::CONTACT, function (string $url) use (&$seenUrl): string {
            $seenUrl = $url;
            return 'FAKEJPEGBYTES';
        });

        self::assertSame('https://example.com/photo.jpg', $seenUrl);
        self::assertStringContainsString('ENCODING=b', $resolved);
        self::assertStringContainsString(base64_encode('FAKEJPEGBYTES'), $resolved);
        self::assertStringNotContainsString('VALUE=uri', $resolved);
        self::assertTrue(Model::parse($resolved)->hasPhoto);
    }

    public function testResolvePhotoUriIsByteIdenticalNoopWhenNoPhoto(): void
    {
        $noPhoto = "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:No Photo\r\nUID:c2\r\nEND:VCARD\r\n";

        $result = Transform::resolvePhotoUri($noPhoto, fn(string $u): string => 'x');

        self::assertSame($noPhoto, $result);
    }

    /**
     * The bug, reported from a live iCloud pull: iCloud serves contact
     * photos as authenticated https references, this transform inlined them
     * correctly, and every one of those contacts showed a blank avatar in
     * Nextcloud. Nextcloud's PhotoCache::getBinaryType() reads TYPE or
     * MEDIATYPE off a binary PHOTO and returns '' without either, after
     * which the avatar is served as application/octet-stream and no browser
     * renders it. The bytes were never the problem; the missing label was.
     */
    public function testAnInlinedPhotoIsLabelledWithItsMediaType(): void
    {
        $raw = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:c1\r\nFN:Ada\r\n"
            . "PHOTO;VALUE=uri:https://example.org/photo\r\nEND:VCARD\r\n";
        $jpeg = "\xFF\xD8\xFF\xE0" . str_repeat('x', 32);

        $out = Transform::resolvePhotoUri($raw, static fn(string $u): string => $jpeg);

        $photo = Document::parse($out)->first('PHOTO');
        self::assertNotNull($photo);
        self::assertSame(['JPEG'], $photo->params['TYPE'] ?? null, 'vCard 3.0 spells the media type TYPE=JPEG');
        self::assertSame(base64_encode($jpeg), trim($photo->value));
    }

    public function testAVCard4InlinedPhotoUsesMediatypeInstead(): void
    {
        // TYPE means something else entirely in 4.0; the media type lives in
        // MEDIATYPE there. Both dialects are written because this app syncs
        // 3.0 and 4.0 address books to each other.
        $raw = "BEGIN:VCARD\r\nVERSION:4.0\r\nUID:c1\r\nFN:Ada\r\n"
            . "PHOTO;VALUE=uri:https://example.org/photo\r\nEND:VCARD\r\n";
        $png = "\x89PNG\x0D\x0A\x1A\x0A" . str_repeat('x', 32);

        $out = Transform::resolvePhotoUri($raw, static fn(string $u): string => $png);

        $photo = Document::parse($out)->first('PHOTO');
        self::assertNotNull($photo);
        self::assertSame(['image/png'], $photo->params['MEDIATYPE'] ?? null);
        self::assertArrayNotHasKey('TYPE', $photo->params);
    }

    public static function imageSignatures(): array
    {
        return [
            'jpeg' => ["\xFF\xD8\xFF\xE1", 'JPEG'],
            'png' => ["\x89PNG\x0D\x0A\x1A\x0A", 'PNG'],
            'gif87' => ['GIF87a', 'GIF'],
            'gif89' => ['GIF89a', 'GIF'],
            'webp' => ["RIFF\x00\x00\x00\x00WEBP", 'WEBP'],
            'avif' => ["\x00\x00\x00\x20ftypavif", 'AVIF'],
            'ico' => ["\x00\x00\x01\x00", 'VND.MICROSOFT.ICON'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('imageSignatures')]
    public function testEachRecognisedSignatureGetsItsOwnLabel(string $magic, string $expected): void
    {
        $raw = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:c1\r\nFN:Ada\r\n"
            . "PHOTO;VALUE=uri:https://example.org/photo\r\nEND:VCARD\r\n";

        $out = Transform::resolvePhotoUri($raw, static fn(string $u): string => $magic . str_repeat('x', 64));

        self::assertSame([$expected], Document::parse($out)->first('PHOTO')->params['TYPE'] ?? null);
    }

    public function testAnUnrecognisedImageIsLeftUnlabelledRatherThanGuessed(): void
    {
        // A wrong Content-Type renders no better than none, and the bytes
        // still reach anything that sniffs for itself. Losing the photo
        // over an unknown format would be strictly worse.
        $raw = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:c1\r\nFN:Ada\r\n"
            . "PHOTO;VALUE=uri:https://example.org/photo\r\nEND:VCARD\r\n";

        $out = Transform::resolvePhotoUri($raw, static fn(string $u): string => 'not-an-image-at-all');

        $photo = Document::parse($out)->first('PHOTO');
        self::assertArrayNotHasKey('TYPE', $photo->params);
        self::assertArrayNotHasKey('MEDIATYPE', $photo->params);
        self::assertSame(base64_encode('not-an-image-at-all'), trim($photo->value));
    }

    public function testAnAlreadyInlinePhotoKeepsItsOwnTypeUntouched(): void
    {
        // Only references are relabelled. An inline photo was never fetched,
        // so its existing TYPE is the source's word and stays as it is.
        $photo = base64_encode('whatever');
        $raw = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:c1\r\nFN:Ada\r\n"
            . "PHOTO;ENCODING=b;TYPE=PNG:{$photo}\r\nEND:VCARD\r\n";

        self::assertSame($raw, Transform::resolvePhotoUri($raw, static fn(string $u): string => 'x'));
    }

    public function testSetCategoriesAddsAndRemoves(): void
    {
        $withCategories = Transform::setCategories(self::CONTACT, ['Family', 'Work']);
        self::assertStringContainsString('CATEGORIES:Family,Work', $withCategories);

        $withoutCategories = Transform::setCategories($withCategories, []);
        self::assertStringNotContainsString('CATEGORIES', $withoutCategories);
    }

    public function testRenderForDestinationIsUntouchedPassthroughWhenNothingToDo(): void
    {
        // include_photos=true and categories=null means neither stripPhoto
        // nor setCategories ever run -- the raw text must come back
        // completely unchanged, not reparsed and reserialized.
        $result = Transform::renderForDestination(self::CONTACT, true, null);

        self::assertSame(self::CONTACT, $result);
    }

    public function testRenderForDestinationStripsPhotoWhenDisabled(): void
    {
        $result = Transform::renderForDestination(self::CONTACT, false, null);

        self::assertStringNotContainsString('PHOTO', $result);
    }

    public function testBuildGroupVCardRoundTrips(): void
    {
        $built = Transform::buildGroupVCard('new-group-uid', 'Friends', ['aaa', 'bbb']);
        $group = Model::parse($built);

        self::assertSame('Friends', $group->name);
        self::assertSame(['aaa', 'bbb'], $group->memberUids);
    }

    public function testWithMemberAddedIsIdempotent(): void
    {
        $built = Transform::buildGroupVCard('g', 'Friends', ['aaa']);

        $added = Transform::withMemberAdded($built, 'bbb');
        self::assertSame(['aaa', 'bbb'], Model::parse($added)->memberUids);

        $addedAgain = Transform::withMemberAdded($added, 'bbb');
        self::assertSame(['aaa', 'bbb'], Model::parse($addedAgain)->memberUids);
    }

    public function testWithMemberRemoved(): void
    {
        $built = Transform::buildGroupVCard('g', 'Friends', ['aaa', 'bbb', 'ccc']);

        $removed = Transform::withMemberRemoved($built, 'bbb');

        self::assertSame(['aaa', 'ccc'], Model::parse($removed)->memberUids);
    }

    /**
     * Regression test: a long X-ADDRESSBOOKSERVER-MEMBER line gets
     * RFC-compliant folded mid-UID. Any verification code must parse
     * before comparing, not substring-match the raw (possibly folded)
     * wire text -- this exact bug shipped once in the capability
     * tester before being caught by end-to-end testing.
     */
    public function testLongMemberUidSurvivesFoldingRoundTrip(): void
    {
        $longUid = 'carddav-sync-test-contact-' . str_repeat('a', 40);
        $built = Transform::buildGroupVCard('group-uid', 'Test Group', [$longUid]);

        self::assertStringContainsString("\r\n ", $built, 'expected the long member line to actually be folded');

        $reparsed = Model::parse($built);
        self::assertSame([$longUid], $reparsed->memberUids);
    }

    public function testFoldedLongLineIsIdempotentAcrossRepeatedSerialization(): void
    {
        $bigPhotoValue = base64_encode(str_repeat('x', 500));
        $card = "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Big\r\nUID:big-uid\r\n"
            . "PHOTO;ENCODING=b;TYPE=JPEG:{$bigPhotoValue}\r\nEND:VCARD\r\n";

        $doc = Document::parse($card);
        $serialized = $doc->serialize();
        $reserialized = Document::parse($serialized)->serialize();

        self::assertSame($reserialized, Document::parse($reserialized)->serialize());
        self::assertSame($bigPhotoValue, Document::parse($serialized)->first('PHOTO')->value);
    }

    public function testAnEmptyUriValuedPhotoIsSkippedRatherThanFetched(): void
    {
        // Real address books contain these: Nextcloud's own example contact
        // ships two empty PHOTO;VALUE=URI lines. Fetching "" can never
        // succeed, and one failed reference aborts the whole transform.
        $raw = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:c1\r\nFN:Ada\r\n"
            . "PHOTO;VALUE=URI:\r\nEND:VCARD\r\n";

        $out = Transform::resolvePhotoUri($raw, static function (string $url): string {
            self::fail("Should not have tried to fetch an empty reference (got '{$url}').");
        });

        self::assertSame($raw, $out);
    }

    public function testARealPhotoSurvivesAnEmptyUriValuedSibling(): void
    {
        // The bug this guards: the empty reference threw, the caller caught
        // it and synced "without the photo", so a contact that genuinely had
        // an inline photo silently lost it.
        $photo = base64_encode('not-really-an-image-but-bytes');
        $raw = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:c1\r\nFN:Ada\r\n"
            . "PHOTO;ENCODING=b;TYPE=PNG:{$photo}\r\n"
            . "PHOTO;VALUE=URI:\r\nEND:VCARD\r\n";

        $out = Transform::resolvePhotoUri($raw, static fn(string $url): string => 'fetched');

        $kept = Document::parse($out)->all('PHOTO');
        self::assertSame($photo, trim($kept[0]->value));
    }

    public function testAPopulatedUriValuedPhotoIsStillFetched(): void
    {
        $raw = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:c1\r\nFN:Ada\r\n"
            . "PHOTO;VALUE=uri:https://example.org/photo.jpg\r\nEND:VCARD\r\n";

        $out = Transform::resolvePhotoUri($raw, static fn(string $url): string => 'IMAGEBYTES');

        $photo = Document::parse($out)->first('PHOTO');
        self::assertNotNull($photo);
        self::assertSame(base64_encode('IMAGEBYTES'), trim($photo->value));
        self::assertArrayNotHasKey('VALUE', $photo->params);
    }
}
