<?php

declare(strict_types=1);

namespace OCA\ContactHub\Sync;

/**
 * A new, untracked contact on $sourceSide whose content matched an
 * existing untracked contact ($destUid) on the other side -- surfaced
 * as a manual conflict instead of being blindly created as a duplicate.
 */
final class DuplicateMatch
{
    public function __construct(
        public readonly string $uid,
        public readonly string $destUid,
        public readonly string $sourceSide,
    ) {
    }
}
