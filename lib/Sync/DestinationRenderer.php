<?php

declare(strict_types=1);

namespace OCA\ContactHub\Sync;

use OCA\ContactHub\VCard\Transform;

/**
 * The one way a contact's vCard is prepared for writing to the other side
 * of a job: photo references resolved (or the photo dropped, per the job's
 * setting) and, for a categories-strategy destination, group membership
 * folded into CATEGORIES.
 *
 * It exists as its own class because there are two write paths -- a run
 * (Runner) and a conflict resolution (ConflictApplier) -- and the second
 * used to push the stored snapshot raw. A resolved conflict then landed with
 * a photo the job said to leave out, an iCloud photo still as an
 * authenticated URL nothing else can open, and no categories at all; and
 * because the source had not changed, no later run ever fixed it. Verified.
 */
final class DestinationRenderer
{
    /**
     * @param SyncSide $source the side the contact comes from, whose
     *        credentials a photo reference needs
     * @param string[]|null $categories group names to fold in, or null to
     *        leave CATEGORIES untouched (anything but a categories destination)
     * @param null|callable(string): void $onPhotoFailure told why a referenced
     *        photo could not be fetched; the contact is written without it
     */
    public static function render(
        SyncSide $source,
        string $rawText,
        bool $includePhotos,
        ?array $categories,
        ?callable $onPhotoFailure = null,
    ): string {
        $text = $rawText;
        if ($includePhotos) {
            try {
                $text = Transform::resolvePhotoUri($rawText, fn(string $url): string => $source->fetchBinary($url));
            } catch (\Throwable $e) {
                if ($onPhotoFailure !== null) {
                    $onPhotoFailure($e->getMessage());
                }
                $text = Transform::stripPhoto($rawText);
            }
        }

        return Transform::renderForDestination($text, $includePhotos, $categories);
    }
}
