<?php

declare(strict_types=1);

namespace OCA\ContactHub\Tests\Integration;

use OCA\ContactHub\Service\EndpointService;
use OCA\ContactHub\Service\ValidationException;
use OCA\ContactHub\Tests\Support\FakeClientFactory;
use OCA\ContactHub\Tests\Support\FakeHttpTransport;

/**
 * Endpoints are not saved until their credentials have been proven to work.
 *
 * An endpoint saved with a typo in the password looked completely healthy in
 * the list and then failed on the first scheduled run, where the only trace
 * is a log line nobody is watching. One PROPFIND at the moment the form is
 * in front of the user is worth that.
 *
 * The distinctions that matter here are which field the error lands on. A
 * 401 belongs on the password; an unreachable host belongs on the URL.
 * Telling someone their password is wrong when the server was simply down
 * sends them off resetting credentials that were never the problem.
 */
final class EndpointCredentialCheckTest extends IntegrationTestCase
{
    private FakeHttpTransport $probe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->probe = new FakeHttpTransport('https://new.example/');
    }

    private function service(): EndpointService
    {
        // Every endpoint resolves to the one probe: an endpoint is checked as
        // id 0 before it exists and under its real id afterwards, so pinning
        // specific ids would leave half the checks talking to a different,
        // always-healthy server.
        return new EndpointService($this->endpoints, new FakeClientFactory([], $this->probe));
    }

    /** @return array<string, mixed> */
    private function input(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'New endpoint',
            'preset' => 'generic',
            'base_url' => $this->probe->base,
            'username' => 'user',
            'password' => 'pass',
        ];
    }

    public function testAWorkingEndpointIsCreated(): void
    {
        $id = $this->service()->create(self::USER_ID, $this->input());

        self::assertGreaterThan(0, $id);
        self::assertSame('New endpoint', $this->service()->get($id, self::USER_ID)['name']);
    }

    public function testRejectedCredentialsPreventCreationEntirely(): void
    {
        $this->probe->rejectCredentials = true;
        $before = count($this->service()->listFor(self::USER_ID));

        try {
            $this->service()->create(self::USER_ID, $this->input());
            self::fail('expected the create to be refused');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('password', $e->errors, 'a 401 belongs on the password field');
        }

        self::assertCount(
            $before,
            $this->service()->listFor(self::USER_ID),
            'nothing may be stored when verification failed',
        );
    }

    public function testAnUnreachableServerBlamesTheUrlNotThePassword(): void
    {
        $this->probe->failNext = true;

        try {
            $this->service()->create(self::USER_ID, $this->input());
            self::fail('expected the create to be refused');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('base_url', $e->errors);
            self::assertArrayNotHasKey('password', $e->errors);
        }
    }

    public function testChangingThePasswordIsVerifiedBeforeItReplacesTheStoredOne(): void
    {
        $id = $this->service()->create(self::USER_ID, $this->input());

        $this->probe->rejectCredentials = true;
        try {
            $this->service()->update($id, self::USER_ID, ['password' => 'wrong']);
            self::fail('expected the update to be refused');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('password', $e->errors);
        }

        // The stored password must be untouched: a rejected new one replacing
        // a working one would break an endpoint that was fine a moment ago.
        $this->probe->rejectCredentials = false;
        $stored = $this->endpoints->find($id, self::USER_ID);
        self::assertNotNull($stored);
        self::assertSame('pass', $stored->password());
    }

    public function testRenamingDoesNotContactTheServer(): void
    {
        // Re-checking on every save would mean a rename could not be saved
        // while the far side happened to be down, which is a bad trade: a
        // name has no bearing on whether the credentials still work.
        $id = $this->service()->create(self::USER_ID, $this->input());

        $this->probe->rejectCredentials = true;
        $this->service()->update($id, self::USER_ID, ['name' => 'Renamed']);

        self::assertSame('Renamed', $this->service()->get($id, self::USER_ID)['name']);
    }

    public function testGoogleIsRefusedWithItsReasonRatherThanAPasswordPrompt(): void
    {
        // Google's CardDAV requires OAuth 2.0 and answers Basic auth with a
        // 401 unconditionally, so no password would help. The refusal has to
        // say that instead of asking for a password the user cannot supply.
        try {
            $this->service()->create(self::USER_ID, $this->input(['preset' => 'google', 'password' => '']));
            self::fail('expected the create to be refused');
        } catch (ValidationException $e) {
            self::assertSame(['preset'], array_keys($e->errors));
            self::assertStringContainsString('OAuth', $e->errors['preset']);
        }
    }
}
