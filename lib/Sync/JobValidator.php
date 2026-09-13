<?php

declare(strict_types=1);

namespace OCA\ContactHub\Sync;

/**
 * Flags sync job configurations that are legal but almost certainly not
 * what the user meant -- most importantly a pair of one-way jobs
 * pointing in opposite directions between the same address book and
 * endpoint, which quietly resurrects deleted contacts and silently
 * overwrites simultaneous edits.
 *
 * These are warnings, never hard errors. The user is allowed to build an
 * odd setup on purpose; the job of this class is to make sure they did
 * it knowingly. Nothing here blocks saving or running a job.
 */
final class JobValidator
{
    public const string SEVERITY_WARNING = 'warning';
    public const string SEVERITY_NOTE = 'note';

    /** @param SyncJob[] $allJobs every job in the system, including $job itself */
    public function __construct(private readonly array $allJobs)
    {
    }

    /**
     * @return list<array{severity: string, message: string}>
     */
    public function check(SyncJob $job): array
    {
        return [
            ...$this->checkOpposingJobs($job),
            ...$this->checkDuplicateJobs($job),
            ...$this->checkCompetingWriters($job),
            ...$this->checkEndpointReadiness($job),
            ...$this->checkPolicyCombinations($job),
            ...$this->checkInterval($job),
        ];
    }

    /**
     * The case the user called out: one job pushing the hub to an
     * endpoint and another pulling the same endpoint back into the same
     * address book.
     *
     * @return list<array{severity: string, message: string}>
     */
    private function checkOpposingJobs(SyncJob $job): array
    {
        $out = [];
        foreach ($this->others($job) as $other) {
            if ($other->addressBookId !== $job->addressBookId || $other->endpoint->id !== $job->endpoint->id) {
                continue;
            }
            $opposed = ($job->direction === SyncJob::TO_ENDPOINT && $other->direction === SyncJob::FROM_ENDPOINT)
                || ($job->direction === SyncJob::FROM_ENDPOINT && $other->direction === SyncJob::TO_ENDPOINT);
            if (!$opposed) {
                continue;
            }
            $out[] = [
                'severity' => self::SEVERITY_WARNING,
                'message' => "'{$other->name}' syncs the same address book and endpoint in the opposite "
                    . 'direction. Together these two jobs move contacts both ways with none of the '
                    . 'safeguards a real two-way sync would need: a contact deleted on one side is '
                    . 'restored by the other, and simultaneous edits silently overwrite each other.',
            ];
        }
        return $out;
    }

    /** @return list<array{severity: string, message: string}> */
    private function checkDuplicateJobs(SyncJob $job): array
    {
        $out = [];
        foreach ($this->others($job) as $other) {
            if (
                $other->addressBookId === $job->addressBookId
                && $other->endpoint->id === $job->endpoint->id
                && $other->direction === $job->direction
            ) {
                $out[] = [
                    'severity' => self::SEVERITY_WARNING,
                    'message' => "'{$other->name}' already syncs this address book with this endpoint in the "
                        . 'same direction. The two jobs keep separate sync state, so each will re-detect and '
                        . "re-apply the other's work.",
                ];
            }
        }
        return $out;
    }

    /**
     * Several address books writing into one endpoint collection: with a
     * mirror deletion policy each job treats the others' contacts as
     * "deleted from my side" and removes them.
     *
     * @return list<array{severity: string, message: string}>
     */
    private function checkCompetingWriters(SyncJob $job): array
    {
        if ($job->direction === SyncJob::FROM_ENDPOINT) {
            return [];
        }

        $out = [];
        foreach ($this->others($job) as $other) {
            if (
                $other->endpoint->id !== $job->endpoint->id
                || $other->addressBookId === $job->addressBookId
                || $other->direction === SyncJob::FROM_ENDPOINT
            ) {
                continue;
            }
            $out[] = [
                'severity' => self::SEVERITY_WARNING,
                'message' => "'{$other->name}' writes a different address book into the same endpoint "
                    . "collection. Both address books' contacts end up mixed together there"
                    . ($job->deletionPolicy === 'mirror'
                        ? ", and with the mirror deletion policy each job will delete the other's contacts."
                        : '.'),
            ];
        }
        return $out;
    }

    /** @return list<array{severity: string, message: string}> */
    private function checkEndpointReadiness(SyncJob $job): array
    {
        $out = [];
        if ($job->endpoint->collectionHref === null) {
            $out[] = [
                'severity' => self::SEVERITY_WARNING,
                'message' => "Endpoint '{$job->endpoint->name}' has no address-book collection selected yet. "
                    . 'Run its capability test and pick one, or this job will fail as soon as it runs.',
            ];
        }
        if ($job->endpoint->groupStrategy === 'collections') {
            $out[] = [
                'severity' => self::SEVERITY_NOTE,
                'message' => "Endpoint '{$job->endpoint->name}' uses the 'collections' group strategy, which is "
                    . 'not implemented for live sync. Contacts sync normally, but group membership is not '
                    . 'propagated to this endpoint.',
            ];
        }
        return $out;
    }

    /** @return list<array{severity: string, message: string}> */
    private function checkPolicyCombinations(SyncJob $job): array
    {
        $out = [];

        if (!$job->includePhotos && $job->direction !== SyncJob::TO_ENDPOINT) {
            $out[] = [
                'severity' => self::SEVERITY_NOTE,
                'message' => 'Photos are excluded, so contacts arriving from the endpoint are stored without '
                    . 'their pictures.',
            ];
        }

        return $out;
    }

    /** @return list<array{severity: string, message: string}> */
    private function checkInterval(SyncJob $job): array
    {
        if ($job->intervalSeconds >= 300) {
            return [];
        }
        return [[
            'severity' => self::SEVERITY_NOTE,
            'message' => 'A sync interval under five minutes means a full fetch of both sides that often. '
                . 'Some providers rate-limit or temporarily lock accounts that poll this aggressively.',
        ]];
    }

    /** @return SyncJob[] */
    private function others(SyncJob $job): array
    {
        return array_values(array_filter(
            $this->allJobs,
            static fn(SyncJob $other): bool => $other->id !== $job->id,
        ));
    }
}
