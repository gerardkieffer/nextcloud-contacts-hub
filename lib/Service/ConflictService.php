<?php

declare(strict_types=1);

namespace OCA\ContactHub\Service;

use OCA\ContactHub\Db\StateMapper;
use OCA\ContactHub\Sync\ConflictApplier;
use OCA\ContactHub\Sync\SyncJob;
use OCA\ContactHub\VCard\Contact;
use OCA\ContactHub\VCard\Model;

/**
 * Unresolved conflicts, and applying a human's pick.
 *
 * The presentation half matters as much as the applying half: a conflict row
 * holds two raw vCards, and the UI needs comparable fields rather than two
 * walls of text. Parsing happens here so the SPA never has to understand
 * vCard, and never has to substring-match text that may be RFC-folded.
 */
class ConflictService
{
    /** Abstract batch choices; see resolutionForChoice() for the role mapping. */
    private const array BATCH_CHOICES = ['merge', 'keep_both', 'archive', 'cancel'];

    public function __construct(
        private readonly StateMapper $state,
        private readonly ConflictApplier $applier,
        private readonly JobService $jobs,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function listFor(string $userId): array
    {
        $out = [];
        foreach ($this->jobs->listFor($userId) as $job) {
            foreach ($this->state->unresolvedConflicts((int) $job['id']) as $row) {
                $out[] = $this->present($row, $job);
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function listForJob(int $jobId, string $userId): array
    {
        $job = $this->jobs->require($jobId, $userId);
        $summary = ['id' => $job->id, 'name' => $job->name, 'endpoint_name' => $job->endpoint->name];

        return array_map(
            fn(array $row): array => $this->present($row, $summary),
            $this->state->unresolvedConflicts($jobId),
        );
    }

    public function resolve(int $conflictId, string $userId, string $resolution): void
    {
        $allowed = [ConflictApplier::HUB, ConflictApplier::ENDPOINT, ConflictApplier::ARCHIVE, ConflictApplier::CANCEL];
        if (!in_array($resolution, $allowed, true)) {
            throw ValidationException::field('resolution', 'Unknown resolution.');
        }

        $row = $this->state->findConflict($conflictId);
        if ($row === null || $row['resolved_at'] !== null) {
            throw new NotFoundException("Conflict {$conflictId} does not exist or is already resolved.");
        }

        // Ownership is proved through the job, since conflicts carry no
        // user_id of their own.
        $job = $this->jobs->require((int) $row['job_id'], $userId);

        $this->applier->apply($job, $row, $resolution);
    }

    /**
     * Applies one choice to every unresolved duplicate-match conflict
     * across the user's jobs -- the only kind of conflict this app raises.
     *
     * Per-conflict resolution is still computed by role (mirroring
     * present() below) -- a fixed literal here would have exactly the
     * "opposite in one direction" bug the single-conflict UI already
     * learned to avoid, see that method's docblock.
     *
     * One failure doesn't abort the batch; each conflict either resolves
     * or contributes one line to the error list, the same way a sync
     * run's per-item errors don't fail the whole run.
     *
     * @return array{resolved: int, errors: list<string>}
     */
    public function resolveBatch(string $userId, string $choice): array
    {
        if (!in_array($choice, self::BATCH_CHOICES, true)) {
            throw ValidationException::field('choice', 'Unknown choice.');
        }

        $resolved = 0;
        $errors = [];

        foreach ($this->jobs->listFor($userId) as $job) {
            $jobObj = null;
            foreach ($this->state->unresolvedConflicts((int) $job['id']) as $row) {
                $duplicate = $row['duplicate_json'] !== null
                    ? json_decode((string) $row['duplicate_json'], true)
                    : null;
                if (!is_array($duplicate)) {
                    continue;
                }

                $resolution = $this->resolutionForChoice($duplicate, $choice);
                if ($resolution === null) {
                    // Reported, not skipped. Silently dropping it meant a
                    // clean "N resolved" for a batch that had quietly left
                    // a row untouched -- and the single-conflict path calls
                    // the same data an error, so the batch saying nothing
                    // was the odd one out.
                    $errors[] = ($job['name'] ?? '?') . " / {$row['uid']}: "
                        . 'this conflict does not record which side the new contact came from, so '
                        . '"merge" and "keep both" cannot be told apart for it. Resolve it individually.';
                    continue;
                }

                try {
                    $jobObj ??= $this->jobs->require((int) $job['id'], $userId);
                    $this->applier->apply($jobObj, $row, $resolution);
                    $resolved++;
                } catch (\Throwable $e) {
                    $errors[] = ($job['name'] ?? '?') . " / {$row['uid']}: {$e->getMessage()}";
                }
            }
        }

        return ['resolved' => $resolved, 'errors' => $errors];
    }

    /** @param array<string, mixed> $duplicate */
    private function resolutionForChoice(array $duplicate, string $choice): ?string
    {
        // Every choice goes through the one helper that reads the roles,
        // including the two that do not need them. 'archive' and 'cancel'
        // used to skip the check and return their literal straight away,
        // which was true to what they do and wrong about what happens
        // next: applyDuplicate() reads the same roles a moment later and
        // throws "Corrupt duplicate_json on conflict row." So one bad row
        // produced a plain explanation under 'merge' and a stack-trace
        // message under 'archive'. Same defect, one message.
        $roles = ConflictApplier::rolesForDuplicate($duplicate);
        if ($roles === null) {
            return null;
        }
        [$sourceRole, $destRole] = $roles;

        return match ($choice) {
            'cancel' => ConflictApplier::CANCEL,
            'archive' => ConflictApplier::ARCHIVE,
            'merge' => $sourceRole,
            default => $destRole,
        };
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private function present(array $row, array $job): array
    {
        $duplicate = $row['duplicate_json'] !== null
            ? json_decode((string) $row['duplicate_json'], true)
            : null;

        $presented = [
            'id' => (int) $row['id'],
            'job_id' => (int) $row['job_id'],
            'job_name' => $job['name'] ?? '',
            'endpoint_name' => $job['endpoint_name'] ?? '',
            'uid' => (string) $row['uid'],
            'kind' => (string) $row['kind'],
            'detected_at' => $row['detected_at'],
            'duplicate' => $duplicate,
            'hub' => $this->summarise($row['hub_snapshot']),
            'endpoint' => $this->summarise($row['endpoint_snapshot']),
        ];

        if (!is_array($duplicate)) {
            // Corrupt or missing duplicate_json -- nothing usable to offer
            // choices for. Say it is not resolvable and let the UI show the
            // diff without the choices.
            return $presented + ['is_resolvable' => false];
        }

        // The roles are NOT fixed. ConflictApplier reads
        // duplicate_json.source_role -- the side the *new* contact appeared
        // on -- and treats the resolution as "keep both" when it names the
        // other side, "merge" when it names the source. Which literal means
        // which therefore flips with the direction the duplicate arrived
        // from, so the UI must never hardcode one.
        //
        // It did, and the consequence was not cosmetic: for a duplicate
        // detected on the endpoint side, the button labelled "keep both"
        // overwrote the Nextcloud contact the user believed they were
        // keeping. ConflictApplier::rolesForDuplicate() is the one place
        // that decides it, so the presenter and the applier cannot drift.
        $roles = ConflictApplier::rolesForDuplicate($duplicate);
        if ($roles === null) {
            // duplicate_json that names no usable source role. This used to
            // fall through to a guess (destRole = hub) and render the full
            // set of buttons on top of it -- offering an action whose
            // meaning nobody could vouch for. Say it is not resolvable and
            // let the UI show the diff without the choices.
            return $presented + ['is_resolvable' => false];
        }
        [$sourceRole, $destRole] = $roles;

        return $presented + [
            'is_resolvable' => true,
            'merge_resolution' => $sourceRole,
            'keep_both_resolution' => $destRole,
            'archive_resolution' => ConflictApplier::ARCHIVE,
            'cancel_resolution' => ConflictApplier::CANCEL,
            // Which column holds the newly-arrived contact, so the diff can
            // say so instead of guessing.
            'new_contact_role' => $sourceRole,
            'existing_contact_role' => $destRole,
        ];
    }

    /**
     * The comparable fields of one side's snapshot.
     *
     * Returns null when the side no longer holds the resource, which the UI
     * shows as "no longer present" rather than an empty column.
     *
     * @return array<string, mixed>|null
     */
    private function summarise(?string $vcardText): ?array
    {
        if ($vcardText === null || $vcardText === '') {
            return null;
        }

        try {
            $parsed = Model::parse($vcardText);
        } catch (\Throwable) {
            return ['unparseable' => true, 'raw' => $vcardText];
        }

        if (!$parsed instanceof Contact) {
            return [
                'name' => $parsed->name,
                'member_count' => count($parsed->memberUids),
                'rev' => $parsed->rev,
                'raw' => $vcardText,
            ];
        }

        return [
            'name' => $parsed->fn,
            'first_name' => $parsed->firstName,
            'last_name' => $parsed->lastName,
            'emails' => $parsed->emails,
            'phones' => $parsed->phones,
            'categories' => $parsed->categories,
            'has_photo' => $parsed->hasPhoto,
            'rev' => $parsed->rev,
            'raw' => $vcardText,
        ];
    }
}
