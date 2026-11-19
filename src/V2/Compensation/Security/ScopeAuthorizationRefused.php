<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Security;

use LogicException;
use Throwable;

/**
 * The only exception a ScopeAuthorization throws to refuse permanently.
 *
 * Any other exception from assertAllowed() is treated as transient: activity
 * boundaries retry it, and automatic recovery tries again on a later watchdog
 * pass. Refuse unknown operation names with this exception. The message is
 * host-provided diagnostic text and is not machine-stable.
 */
final class ScopeAuthorizationRefused extends LogicException
{
    public function __construct(
        string $message = 'The compensation scope operation was refused.',
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}
