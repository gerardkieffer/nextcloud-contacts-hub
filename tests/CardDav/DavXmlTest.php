<?php

declare(strict_types=1);

namespace OCA\ContactHub\Tests\CardDav;

use OCA\ContactHub\CardDav\DavXml;
use PHPUnit\Framework\TestCase;

final class DavXmlTest extends TestCase
{
    public function testExtractTextFindsPrincipalHref(): void
    {
        $xml = <<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <D:multistatus xmlns:D="DAV:">
              <D:response>
                <D:href>/</D:href>
                <D:propstat>
                  <D:prop><D:current-user-principal><D:href>/10000001/principal/</D:href></D:current-user-principal></D:prop>
                  <D:status>HTTP/1.1 200 OK</D:status>
                </D:propstat>
              </D:response>
            </D:multistatus>
            XML;

        self::assertSame('/10000001/principal/', DavXml::extractText($xml, 'current-user-principal', 'href'));
    }

    /**
     * Regression test for the exact bug documented in
     * docs/server-capabilities.md: Infomaniak/SabreDAV nests privilege
     * names two levels deep (current-user-privilege-set > privilege >
     * write), not as a direct child of current-user-privilege-set. A
     * direct-children-only check produces a false "not writable".
     */
    public function testHasChildSearchesAllDescendantsNotJustDirectChildren(): void
    {
        $xml = <<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <D:multistatus xmlns:D="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav">
              <D:response>
                <D:href>/10000001/carddavhome/card/</D:href>
                <D:propstat>
                  <D:prop>
                    <D:resourcetype><D:collection/><card:addressbook/></D:resourcetype>
                    <D:displayname>GK Contacts</D:displayname>
                    <D:current-user-privilege-set>
                      <D:privilege><D:write/></D:privilege>
                      <D:privilege><D:read/></D:privilege>
                    </D:current-user-privilege-set>
                    <D:supported-report-set>
                      <D:supported-report><D:report><card:sync-collection/></D:report></D:supported-report>
                    </D:supported-report-set>
                  </D:prop>
                  <D:status>HTTP/1.1 200 OK</D:status>
                </D:propstat>
              </D:response>
              <D:response>
                <D:href>/10000001/carddavhome/org-directory/</D:href>
                <D:propstat>
                  <D:prop>
                    <D:resourcetype><D:collection/><card:addressbook/></D:resourcetype>
                    <D:displayname>Annuaire de l'organisation</D:displayname>
                    <D:current-user-privilege-set>
                      <D:privilege><D:read/></D:privilege>
                    </D:current-user-privilege-set>
                  </D:prop>
                  <D:status>HTTP/1.1 200 OK</D:status>
                </D:propstat>
              </D:response>
            </D:multistatus>
            XML;

        $responses = DavXml::parseMultistatus($xml);
        self::assertCount(2, $responses);

        $writable = $responses[0];
        self::assertSame('GK Contacts', $writable->text('displayname'));
        self::assertTrue($writable->hasChild('resourcetype', 'addressbook'));
        self::assertTrue($writable->hasChild('current-user-privilege-set', 'write'));
        self::assertTrue($writable->hasChild('supported-report-set', 'sync-collection'));

        $readonly = $responses[1];
        self::assertFalse($readonly->hasChild('current-user-privilege-set', 'write'));
        self::assertSame("Annuaire de l'organisation", $readonly->text('displayname'));
    }

    public function testExtractAllFindsAddressData(): void
    {
        $xml = <<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <D:multistatus xmlns:D="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav">
              <D:response>
                <D:href>/card/1.vcf</D:href>
                <D:propstat><D:prop><card:address-data>BEGIN:VCARD
            UID:1
            END:VCARD
            </card:address-data></D:prop><D:status>HTTP/1.1 200 OK</D:status></D:propstat>
              </D:response>
            </D:multistatus>
            XML;

        $vcards = DavXml::extractAll($xml, 'address-data');

        self::assertCount(1, $vcards);
        self::assertStringContainsString('UID:1', $vcards[0]);
    }

    public function testAssertOkThrowsOn4xxAnd5xx(): void
    {
        $resp = new \OCA\ContactHub\CardDav\HttpResponse(404, [], 'not found');

        $this->expectException(\OCA\ContactHub\CardDav\DavException::class);
        DavXml::assertOk($resp, 'GET something');
    }

    public function testAssertOkPassesOn2xx(): void
    {
        $resp = new \OCA\ContactHub\CardDav\HttpResponse(204, [], '');
        DavXml::assertOk($resp, 'DELETE something');
        $this->addToAssertionCount(1);
    }
}
