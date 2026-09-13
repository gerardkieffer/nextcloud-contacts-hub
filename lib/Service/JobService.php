<?php

declare(strict_types=1);

namespace OCA\ContactHub\Service;

use OCA\ContactHub\Db\EndpointMapper;
use OCA\ContactHub\Db\JobMapper;
use OCA\ContactHub\Db\StateMapper;
use OCA\ContactHub\Sync\JobValidator;
use OCA\ContactHub\Sync\SyncJob;

/**
 * Sync jobs: validation, CRUD, and the advisory configuration warnings.
 *
 * The warnings are the interesting part. JobValidator does not block a save
 * -- it explains setups that are legal but probably not what the user meant,
 * such as two jobs pushing different address books into the same endpoint
 * collection. Surfacing them beats silently misbehaving, which is what the
 * app did before they existed.
 */
class JobService
{
    public function __construct(
        private readonly JobMapper $mapper,
        private readonly StateMapper $state,
        private readonly AddressBookService $addressBooks,
        private readonly EndpointMapper $endpoints,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function listFor(string $userId): array
    {
        $jobs = $this->mapper->allForUser($userId);

        // Carried on the listing so the screen can reopen a run the user
        // left behind. Switching tabs unmounts the run panel, and without
        // this the app forgot a paused run existed -- it would have been
        // picked up eventually by cron, but the user was shown nothing at
        // all and reasonably assumed the sync had stopped.
        $open = $this->state->openRunsFor(array_map(static fn(SyncJob $j): int => $j->id, $jobs));

        return array_map(
            fn(SyncJob $job): array => $this->present($job) + ['open_run' => $open[$job->id] ?? null],
            $jobs,
        );
    }

    /** @return array<string, mixed> */
    public function get(int $id, string $userId): array
    {
        $job = $this->require($id, $userId);

        return $this->present($job) + [
            'warnings' => $this->warningsFor($job, $userId),
            'open_run' => $this->state->findOpenRun($job->id),
            'unresolved_conflicts' => count($this->state->unresolvedConflicts($job->id)),
        ];
    }

    /** @param array<string, mixed> $input */
    public function create(string $userId, array $input): int
    {
        $data = $this->validate($input, $userId, isCreate: true);

        return $this->mapper->create($userId, $data);
    }

    /** @param array<string, mixed> $input */
    public function update(int $id, string $userId, array $input): void
    {
        $this->require($id, $userId);
        $this->mapper->update($id, $userId, $this->validate($input, $userId, isCreate: false));
    }

    public function delete(int $id, string $userId): void
    {
        $this->require($id, $userId);
        // State, runs, run items and conflicts all hang off the job. There
        // are no database-level cascades (Nextcloud migrations avoid foreign
        // keys), so the cleanup is explicit.
        $this->state->deleteEverythingForJob($id);
        $this->mapper->delete($id, $userId);
    }

    public function require(int $id, string $userId): SyncJob
    {
        $job = $this->mapper->find($id, $userId);
        if ($job === null) {
            throw new NotFoundException("Sync job {$id} does not exist or is not yours.");
        }

        return $job;
    }

    /** @return list<array{severity: string, message: string}> */
    public function warningsFor(SyncJob $job, string $userId): array
    {
        return (new JobValidator($this->mapper->allForUser($userId)))->check($job);
    }

    /** @return array<string, mixed> */
    private function present(SyncJob $job): array
    {
        return [
            'id' => $job->id,
            'name' => $job->name,
            'address_book_id' => $job->addressBookId,
            'address_book_name' => $job->addressBookName,
            'endpoint_id' => $job->endpoint->id,
            'endpoint_name' => $job->endpoint->name,
            'direction' => $job->direction,
            'direction_label' => SyncJob::directionLabel($job->direction),
            'deletion_policy' => $job->deletionPolicy,
            'archive_group_name' => $job->archiveGroupName,
            'include_photos' => $job->includePhotos,
            'interval_seconds' => $job->intervalSeconds,
            'enabled' => $job->enabled,
            'last_run_at' => $job->lastRunAt,
            'is_due' => $job->isDue(),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function validate(array $input, string $userId, bool $isCreate): array
    {
        $errors = [];
        $data = [];

        if ($isCreate || array_key_exists('name', $input)) {
            $name = trim((string) ($input['name'] ?? ''));
            if ($name === '') {
                $errors['name'] = 'This field is required.';
            } else {
                $data['name'] = $name;
            }
        }

        if ($isCreate || array_key_exists('address_book_id', $input)) {
            $bookId = (int) ($input['address_book_id'] ?? 0);
            try {
                // Proves the user can actually reach this book, which is the
                // access-control check for the hub side of the job.
                $this->addressBooks->requireAccess($bookId, $userId);
                $data['address_book_id'] = $bookId;
            } catch (AddressBookNotAccessible) {
                $errors['address_book_id'] = 'Pick an address book you have access to.';
            }
        }

        if ($isCreate || array_key_exists('endpoint_id', $input)) {
            $endpointId = (int) ($input['endpoint_id'] ?? 0);
            // Existence and ownership, not just a positive number. A job
            // saved against a missing or someone else's endpoint id is
            // permanently unusable: JobMapper::hydrate() throws when it
            // cannot resolve the endpoint, so the job 500s in the UI and
            // takes down the background job's allEnabled() hydration with
            // it. The address book beside it is checked the same way.
            if ($endpointId <= 0) {
                $errors['endpoint_id'] = 'This field is required.';
            } elseif ($this->endpoints->find($endpointId, $userId) === null) {
                $errors['endpoint_id'] = 'Pick an endpoint you have access to.';
            } else {
                $data['endpoint_id'] = $endpointId;
            }
        }

        $enums = [
            'direction' => SyncJob::DIRECTIONS,
            'deletion_policy' => SyncJob::DELETION_POLICIES,
        ];
        foreach ($enums as $field => $allowed) {
            if (!$isCreate && !array_key_exists($field, $input)) {
                continue;
            }
            $value = (string) ($input[$field] ?? '');
            if ($field !== 'direction' && $value === '' && !$isCreate) {
                continue;
            }
            if (!in_array($value, $allowed, true)) {
                // direction is the only one without a default worth assuming.
                if ($isCreate && $field !== 'direction') {
                    continue;
                }
                $errors[$field] = 'Unknown value.';
                continue;
            }
            $data[$field] = $value;
        }

        if (array_key_exists('interval_seconds', $input)) {
            $interval = (int) $input['interval_seconds'];
            if ($interval < 60) {
                $errors['interval_seconds'] = 'Must be at least 60 seconds.';
            } else {
                $data['interval_seconds'] = $interval;
            }
        }

        if (array_key_exists('archive_group_name', $input)) {
            $archive = trim((string) $input['archive_group_name']);
            if ($archive === '') {
                $errors['archive_group_name'] = 'This field is required when archiving.';
            } else {
                $data['archive_group_name'] = $archive;
            }
        }

        foreach (['include_photos', 'enabled'] as $flag) {
            if (array_key_exists($flag, $input)) {
                $data[$flag] = (bool) $input[$flag];
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $data;
    }
}
