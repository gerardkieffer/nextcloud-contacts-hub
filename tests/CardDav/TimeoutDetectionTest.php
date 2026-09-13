<?php

declare(strict_types=1);

namespace OCA\ContactHub\Tests\CardDav;

use OCA\ContactHub\CardDav\NextcloudHttpTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Client::fetchAllVCards() falls back from one whole-collection REPORT to
 * chunked multigets when, and only when, the big request timed out. Getting
 * this classification wrong in either direction is expensive: a missed
 * timeout means large address books simply fail on servers like Mailo, and a
 * false positive means retrying a server's outright refusal in sixteen
 * pieces.
 *
 * Guzzle exposes no typed timeout, so the check reads the exception message
 * chain. These tests pin the shapes that actually occur.
 */
final class TimeoutDetectionTest extends TestCase
{
    public function testCurlTimeoutErrorIsDetected(): void
    {
        // What Guzzle's curl handler actually produces on a timeout.
        $e = new \RuntimeException(
            'cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received',
        );

        self::assertTrue(NextcloudHttpTransport::looksLikeTimeout($e));
    }

    public function testTimeoutIsDetectedThroughTheExceptionChain(): void
    {
        $inner = new \RuntimeException('cURL error 28: Operation timed out');
        $outer = new \RuntimeException('Request failed', 0, $inner);

        self::assertTrue(NextcloudHttpTransport::looksLikeTimeout($outer));
    }

    /** @return iterable<string, array{0: string}> */
    public static function timeoutWordings(): iterable
    {
        yield 'timed out' => ['Connection timed out'];
        yield 'timeout' => ['Read timeout reached'];
        yield 'time-out' => ['Gateway time-out from upstream'];
    }

    #[DataProvider('timeoutWordings')]
    public function testCommonTimeoutWordingsAreDetected(string $message): void
    {
        self::assertTrue(NextcloudHttpTransport::looksLikeTimeout(new \RuntimeException($message)));
    }

    /** @return iterable<string, array{0: string}> */
    public static function nonTimeouts(): iterable
    {
        // A refusal. Retrying this in sixteen pieces is still a refusal.
        yield 'http 403' => ['Client error: 403 Forbidden'];
        yield 'http 500' => ['Server error: 500 Internal Server Error'];
        yield 'dns' => ['cURL error 6: Could not resolve host: carddav.example'];
        yield 'tls' => ['cURL error 60: SSL certificate problem'];
        yield 'refused' => ['cURL error 7: Failed to connect: Connection refused'];
    }

    #[DataProvider('nonTimeouts')]
    public function testFailuresThatAreNotTimeoutsAreNotMisread(string $message): void
    {
        self::assertFalse(NextcloudHttpTransport::looksLikeTimeout(new \RuntimeException($message)));
    }

    public function testTheWordTimeoutInAnUnrelatedContextIsStillTreatedAsATimeout(): void
    {
        // Documented rather than defended: the wording fallback is
        // deliberately broad, because a missed timeout breaks large address
        // books outright while a spurious one costs only a retry.
        self::assertTrue(
            NextcloudHttpTransport::looksLikeTimeout(new \RuntimeException('server reported: idle timeout policy')),
        );
    }
}
