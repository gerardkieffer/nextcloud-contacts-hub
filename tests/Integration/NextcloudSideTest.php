<?php

declare(strict_types=1);

namespace OCA\ContactHub\Tests\Integration;

use OCA\ContactHub\Sync\NextcloudSide;
use OCA\ContactHub\VCard\Model;

/**
 * The hub side, against a real CardDavBackend.
 *
 * This is the file that matters most in the migration. The entire sync diff
 * decides "did this change?" by comparing a hash of a side's text against
 * the hash stored at the last sync, so anything that makes a card's text
 * differ between write and read makes every affected contact look modified
 * on every run and push forever.
 *
 * Nextcloud does rewrite content on read: CardDavBackend::getCard() passes
 * stored bytes through readBlob(), which strips PHOTO properties carrying
 * non-image data: URIs and rejoins lines with CRLF. The design survives that
 * only because putVCard() re-reads after writing and Model::textHash()
 * normalises line endings. These tests are what keep both true.
 */
final class NextcloudSideTest extends IntegrationTestCase
{
    private function side(): NextcloudSide
    {
        return new NextcloudSide($this->backend, $this->addressBookId, 'Nextcloud');
    }

    /** A real, tiny, valid JPEG. */
    private function jpegBytes(int $padding = 0): string
    {
        $jpeg = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00"
            . "\xFF\xDB\x00\x43\x00" . str_repeat("\x08", 64)
            . str_repeat("\x00", $padding)
            . "\xFF\xD9";

        return $jpeg;
    }

    // -------------------------------------------------------- the contract

    public function testGroupStrategyIsCategories(): void
    {
        // Nextcloud's Contacts app models groups as CATEGORIES, so this side
        // must report that or CategoryGroups never runs.
        self::assertSame('categories', $this->side()->groupStrategy());
    }

    public function testPutReturnsTheTextNextcloudActuallyStored(): void
    {
        $side = $this->side();
        $vcard = $this->vcard('c1', 'Ada Lovelace');

        [, $stored] = $side->putVCard($side->hrefFor('c1'), $vcard, null);
        [$fetched] = $side->getVCard($side->hrefFor('c1'));

        // Not "equals what we sent" -- equals what a subsequent read returns.
        // That is the only property the hashing discipline needs.
        self::assertSame($fetched, $stored);
    }

    public function testHashOfTheReturnedTextMatchesTheNextReadSoNothingLooksModified(): void
    {
        $side = $this->side();
        $href = $side->hrefFor('c1');

        [, $stored] = $side->putVCard($href, $this->vcard('c1', 'Ada'), null);
        $recordedHash = Model::textHash($stored);

        // Simulate the next run: fetch everything and hash it the same way.
        $all = $side->fetchAll();
        self::assertCount(1, $all);

        self::assertSame(
            $recordedHash,
            Model::textHash($all[0]['vcard']),
            'A freshly written card already looks modified; this is the phantom-update bug.',
        );
    }

    public function testAnUnmodifiedCardHashesTheSameAcrossRepeatedReads(): void
    {
        $side = $this->side();
        $side->putVCard($side->hrefFor('c1'), $this->vcard('c1', 'Ada'), null);

        [$first] = $side->getVCard($side->hrefFor('c1'));
        [$second] = $side->getVCard($side->hrefFor('c1'));

        self::assertSame(Model::textHash($first), Model::textHash($second));
    }

    // -------------------------------------------------------------- photos

    public function testAContactWithAnInlineBase64PhotoRoundTripsWithItsBytesIntact(): void
    {
        $side = $this->side();
        $photo = base64_encode($this->jpegBytes(200_000));
        $vcard = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:c1\r\nFN:Ada\r\n"
            . "PHOTO;ENCODING=b;TYPE=JPEG:{$photo}\r\nEND:VCARD\r\n";

        [, $stored] = $side->putVCard($side->hrefFor('c1'), $vcard, null);

        // Parse and compare the property, never substring-match: a 200 KB
        // base64 payload is RFC-folded and a naive comparison would fail on
        // the fold boundaries alone.
        $doc = \OCA\ContactHub\VCard\Document::parse($stored);
        $prop = $doc->first('PHOTO');
        self::assertNotNull($prop);
        self::assertSame(
            $photo,
            preg_replace('/\s+/', '', $prop->value),
            'Photo bytes did not survive a write/read round trip.',
        );
    }

    public function testALargePhotoDoesNotDriftAcrossASecondWrite(): void
    {
        $side = $this->side();
        $href = $side->hrefFor('c1');
        $photo = base64_encode($this->jpegBytes(200_000));
        $vcard = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:c1\r\nFN:Ada\r\n"
            . "PHOTO;ENCODING=b;TYPE=JPEG:{$photo}\r\nEND:VCARD\r\n";

        [$etag, $first] = $side->putVCard($href, $vcard, null);
        // Write the very same bytes back, as a re-sync would.
        [, $second] = $side->putVCard($href, $first, $etag);

        self::assertSame(Model::textHash($first), Model::textHash($second));
    }

    public function testAnImageDataUriPhotoIsPreserved(): void
    {
        $side = $this->side();
        $photo = 'data:image/jpeg;base64,' . base64_encode($this->jpegBytes());
        $vcard = "BEGIN:VCARD\r\nVERSION:4.0\r\nUID:c1\r\nFN:Ada\r\nPHOTO:{$photo}\r\nEND:VCARD\r\n";

        [, $stored] = $side->putVCard($side->hrefFor('c1'), $vcard, null);

        self::assertNotNull(
            \OCA\ContactHub\VCard\Document::parse($stored)->first('PHOTO'),
            'An image data: URI must survive; only non-image ones are filtered.',
        );
    }

    public function testANonImageDataUriPhotoIsStrippedByNextcloud(): void
    {
        // Documents Nextcloud's behaviour rather than endorsing it. A
        // contact arriving from an endpoint with such a PHOTO loses it on
        // the way in, so it looks changed exactly once and then settles.
        // If this ever starts passing a PHOTO through, the docblock on
        // NextcloudSide needs revisiting, not this test.
        $side = $this->side();
        $vcard = "BEGIN:VCARD\r\nVERSION:4.0\r\nUID:c1\r\nFN:Ada\r\n"
            . "PHOTO:data:application/octet-stream;base64,QUJD\r\nEND:VCARD\r\n";

        [, $stored] = $side->putVCard($side->hrefFor('c1'), $vcard, null);

        self::assertNull(\OCA\ContactHub\VCard\Document::parse($stored)->first('PHOTO'));
    }

    public function testAStrippedPhotoStillSettlesToAStableHash(): void
    {
        // The important half of the previous test: whatever Nextcloud
        // decides to store, the *second* run must agree with the first.
        $side = $this->side();
        $href = $side->hrefFor('c1');
        $vcard = "BEGIN:VCARD\r\nVERSION:4.0\r\nUID:c1\r\nFN:Ada\r\n"
            . "PHOTO:data:application/octet-stream;base64,QUJD\r\nEND:VCARD\r\n";

        [, $stored] = $side->putVCard($href, $vcard, null);
        [$reread] = $side->getVCard($href);

        self::assertSame(Model::textHash($stored), Model::textHash($reread));
    }

    // ------------------------------------------------------- preconditions

    public function testCreatingOverAnExistingCardIsRefused(): void
    {
        $side = $this->side();
        $href = $side->hrefFor('c1');
        $side->putVCard($href, $this->vcard('c1', 'Ada'), null);

        // A null etag means If-None-Match: * -- "this must not exist yet".
        $this->expectException(\RuntimeException::class);
        $side->putVCard($href, $this->vcard('c1', 'Ada again'), null);
    }

    public function testUpdatingWithAStaleEtagIsRefused(): void
    {
        $side = $this->side();
        $href = $side->hrefFor('c1');
        $side->putVCard($href, $this->vcard('c1', 'Ada'), null);

        $this->expectException(\RuntimeException::class);
        $side->putVCard($href, $this->vcard('c1', 'Changed'), '"not-the-current-etag"');
    }

    public function testUpdatingWithTheCurrentEtagSucceeds(): void
    {
        $side = $this->side();
        $href = $side->hrefFor('c1');
        [$etag] = $side->putVCard($href, $this->vcard('c1', 'Ada'), null);

        [, $stored] = $side->putVCard($href, $this->vcard('c1', 'Ada Lovelace'), $etag);

        self::assertStringContainsString('Ada Lovelace', $stored);
    }

    public function testDeletingWithAStaleEtagIsRefused(): void
    {
        $side = $this->side();
        $href = $side->hrefFor('c1');
        $side->putVCard($href, $this->vcard('c1', 'Ada'), null);

        $this->expectException(\RuntimeException::class);
        $side->delete($href, '"not-the-current-etag"');
    }

    public function testDeleteRemovesTheCard(): void
    {
        $side = $this->side();
        $href = $side->hrefFor('c1');
        [$etag] = $side->putVCard($href, $this->vcard('c1', 'Ada'), null);

        $side->delete($href, $etag);

        self::assertSame([], $side->fetchAll());
    }

    // ------------------------------------------------------------ listings

    public function testFetchAllAndListEtagsAgreeOnHrefs(): void
    {
        $side = $this->side();
        $side->putVCard($side->hrefFor('c1'), $this->vcard('c1', 'Ada'), null);
        $side->putVCard($side->hrefFor('c2'), $this->vcard('c2', 'Grace'), null);

        $fromFetch = array_column($side->fetchAll(), 'href');
        $fromEtags = array_keys($side->listEtags());

        sort($fromFetch);
        sort($fromEtags);
        self::assertSame($fromFetch, $fromEtags);
    }

    public function testListEtagsTracksContentChanges(): void
    {
        $side = $this->side();
        $href = $side->hrefFor('c1');
        [$etag] = $side->putVCard($href, $this->vcard('c1', 'Ada'), null);

        $side->putVCard($href, $this->vcard('c1', 'Ada Lovelace'), $etag);

        self::assertNotSame($etag, $side->listEtags()[$href]);
    }

    public function testHrefForEncodesAwkwardUids(): void
    {
        $side = $this->side();
        $uid = 'weird uid/with?chars';
        $href = $side->hrefFor($uid);

        self::assertStringNotContainsString('/', substr($href, 0, -4));
        self::assertStringNotContainsString('?', $href);

        $side->putVCard($href, $this->vcard($uid, 'Odd'), null);
        self::assertArrayHasKey($href, $side->listEtags());
    }

    public function testFetchBinaryIsRefused(): void
    {
        // Nextcloud stores photo bytes inline, so there is never an external
        // reference to dereference here. Only RemoteSide needs that, for
        // iCloud's PHOTO;VALUE=uri.
        $this->expectException(\RuntimeException::class);
        $this->side()->fetchBinary('https://example.invalid/photo.jpg');
    }
}
