<?php

declare(strict_types=1);

namespace OCA\ContactHub\Tests\Sync;

use OCA\ContactHub\Sync\Endpoint;
use PHPUnit\Framework\TestCase;

/**
 * The endpoint password must not be reachable by the reflection-free object
 * dumping that logging does.
 *
 * Not a style preference. Endpoint is a constructor argument of most of the
 * sync engine, so it sits in the stack trace of anything that throws during a
 * run -- which a CardDAV run does for ordinary reasons. Nextcloud's
 * ExceptionSerializer::encodeArg() walks every object in every frame with
 * get_object_vars() called from outside the class, and as a public promoted
 * property the password went to nextcloud.log in clear text. Found in a log
 * excerpt a user pasted while reporting a different bug.
 */
final class EndpointSecrecyTest extends TestCase
{
    private function endpoint(): Endpoint
    {
        return new Endpoint(
            id: 4,
            name: 'Infomaniak',
            preset: 'infomaniak',
            baseUrl: 'https://sync.example.com/',
            username: 'user',
            password: 'hunter2',
            collectionHref: 'https://sync.example.com/addressbooks/user/book/',
            collectionName: 'Contacts',
            groupStrategy: 'passthrough',
            capabilities: [],
        );
    }

    public function testPublicObjectVarsCarryNoPassword(): void
    {
        // get_object_vars() from outside the class: exactly what the log
        // serializer does, and the reason this test exists at all.
        $vars = get_object_vars($this->endpoint());

        self::assertArrayNotHasKey('password', $vars);
        self::assertNotContains('hunter2', $vars, 'no public property may carry the password under any name');
    }

    public function testTheWholeSerializedObjectNeverSpellsThePasswordOut(): void
    {
        // Belt and braces against a future property that happens to embed it
        // -- a credentialed URL, a cached auth header.
        self::assertStringNotContainsString('hunter2', json_encode($this->endpoint()) ?: '');
        self::assertStringNotContainsString('hunter2', print_r($this->endpoint()->__debugInfo(), true));
    }

    public function testTheTransportCanStillHaveIt(): void
    {
        self::assertSame('hunter2', $this->endpoint()->password());
    }
}
