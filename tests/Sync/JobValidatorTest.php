<?php

declare(strict_types=1);

namespace OCA\ContactHub\Tests\Sync;

use OCA\ContactHub\Sync\Endpoint;
use OCA\ContactHub\Sync\JobValidator;
use OCA\ContactHub\Sync\SyncJob;
use PHPUnit\Framework\TestCase;

final class JobValidatorTest extends TestCase
{
    private function endpoint(int $id, string $name = 'Endpoint', ?string $collectionHref = 'https://x/ab/', string $groupStrategy = 'passthrough'): Endpoint
    {
        return new Endpoint($id, $name, 'generic', 'https://x/', 'u', 'p', $collectionHref, 'Contacts', $groupStrategy, []);
    }

    private function job(
        int $id,
        string $direction,
        int $bookId = 1,
        int $endpointId = 1,
        string $name = 'Job',
        string $deletionPolicy = 'mirror',
        int $intervalSeconds = 1800,
        ?Endpoint $endpoint = null,
    ): SyncJob {
        return new SyncJob(
            id: $id,
            userId: 'alice',
            name: $name,
            addressBookId: $bookId,
            addressBookName: 'Contacts',
            endpoint: $endpoint ?? $this->endpoint($endpointId),
            direction: $direction,
            deletionPolicy: $deletionPolicy,
            archiveGroupName: 'Deleted',
            includePhotos: true,
            intervalSeconds: $intervalSeconds,
            enabled: true,
            lastRunAt: null,
        );
    }

    /** @param list<array{severity: string, message: string}> $warnings */
    private function messages(array $warnings): string
    {
        return implode(' | ', array_column($warnings, 'message'));
    }

    /**
     * The case the user explicitly asked to be detected: pushing to an
     * endpoint and pulling from it, same address book.
     */
    public function testOpposingOneWayJobsAreFlagged(): void
    {
        $push = $this->job(1, SyncJob::TO_ENDPOINT, name: 'Push');
        $pull = $this->job(2, SyncJob::FROM_ENDPOINT, name: 'Pull');

        $warnings = (new JobValidator([$push, $pull]))->check($push);

        self::assertStringContainsString('opposite direction', $this->messages($warnings));
        self::assertStringContainsString('Pull', $this->messages($warnings));
        self::assertContains(JobValidator::SEVERITY_WARNING, array_column($warnings, 'severity'));
    }

    public function testOpposingJobsOnDifferentAddressBooksAreNotFlagged(): void
    {
        $push = $this->job(1, SyncJob::TO_ENDPOINT, bookId: 1, name: 'Push');
        $pull = $this->job(2, SyncJob::FROM_ENDPOINT, bookId: 2, name: 'Pull');

        $warnings = (new JobValidator([$push, $pull]))->check($push);

        self::assertStringNotContainsString('opposite direction', $this->messages($warnings));
    }

    public function testOpposingJobsOnDifferentEndpointsAreNotFlagged(): void
    {
        $push = $this->job(1, SyncJob::TO_ENDPOINT, endpointId: 1, name: 'Push');
        $pull = $this->job(2, SyncJob::FROM_ENDPOINT, endpointId: 2, name: 'Pull');

        $warnings = (new JobValidator([$push, $pull]))->check($push);

        self::assertStringNotContainsString('opposite direction', $this->messages($warnings));
    }

    public function testIdenticalJobsAreFlagged(): void
    {
        $a = $this->job(1, SyncJob::TO_ENDPOINT, name: 'First');
        $b = $this->job(2, SyncJob::TO_ENDPOINT, name: 'Second');

        $warnings = (new JobValidator([$a, $b]))->check($a);

        self::assertStringContainsString('same direction', $this->messages($warnings));
    }

    public function testTwoAddressBooksWritingIntoOneEndpointAreFlagged(): void
    {
        $a = $this->job(1, SyncJob::TO_ENDPOINT, bookId: 1, name: 'Personal');
        $b = $this->job(2, SyncJob::TO_ENDPOINT, bookId: 2, name: 'Work');

        $warnings = (new JobValidator([$a, $b]))->check($a);
        $text = $this->messages($warnings);

        self::assertStringContainsString('same endpoint collection', $text);
        self::assertStringContainsString("delete the other's contacts", $text, 'mirror deletion makes this actively destructive');
    }

    public function testTwoAddressBooksPullingFromOneEndpointAreNotFlaggedAsCompetingWriters(): void
    {
        $a = $this->job(1, SyncJob::FROM_ENDPOINT, bookId: 1, name: 'Personal');
        $b = $this->job(2, SyncJob::FROM_ENDPOINT, bookId: 2, name: 'Work');

        $warnings = (new JobValidator([$a, $b]))->check($a);

        self::assertStringNotContainsString('same endpoint collection', $this->messages($warnings));
    }

    public function testUnselectedCollectionIsFlagged(): void
    {
        $job = $this->job(1, SyncJob::TO_ENDPOINT, endpoint: $this->endpoint(1, collectionHref: null));

        $warnings = (new JobValidator([$job]))->check($job);

        self::assertStringContainsString('no address-book collection selected', $this->messages($warnings));
    }

    public function testCollectionsGroupStrategyIsNoted(): void
    {
        $job = $this->job(1, SyncJob::TO_ENDPOINT, endpoint: $this->endpoint(1, groupStrategy: 'collections'));

        $warnings = (new JobValidator([$job]))->check($job);

        self::assertStringContainsString('not implemented for live sync', $this->messages($warnings));
    }

    public function testAggressiveIntervalIsNoted(): void
    {
        $job = $this->job(1, SyncJob::TO_ENDPOINT, intervalSeconds: 60);

        $warnings = (new JobValidator([$job]))->check($job);

        self::assertStringContainsString('rate-limit', $this->messages($warnings));
    }

    public function testAPlainSensiblyConfiguredJobProducesNoWarnings(): void
    {
        $job = $this->job(1, SyncJob::TO_ENDPOINT);

        $warnings = (new JobValidator([$job]))->check($job);

        self::assertSame([], $warnings, 'an ordinary push job should not nag the user');
    }

    public function testAJobIsNeverComparedAgainstItself(): void
    {
        $job = $this->job(1, SyncJob::TO_ENDPOINT);

        $warnings = (new JobValidator([$job, $job]))->check($job);

        self::assertSame([], $warnings);
    }
}
