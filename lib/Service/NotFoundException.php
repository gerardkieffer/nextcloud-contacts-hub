<?php

declare(strict_types=1);

namespace OCA\ContactHub\Service;

/**
 * A record does not exist, or belongs to another user.
 *
 * The two cases are deliberately one exception. Distinguishing them would
 * let a user probe for the existence of other people's endpoints and jobs by
 * watching which id returns which error.
 */
class NotFoundException extends \RuntimeException
{
}
