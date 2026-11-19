<?php

declare(strict_types=1);

namespace Workflow\V2\EntityReferences;

use RuntimeException;

/**
 * Thrown by a resolver when the trusted context may not load the referenced entity (#996).
 * Whether it is retried follows the activity's retry policy; mark it
 * non-retryable there if a retry can't succeed.
 *
 * @experimental Draft API for workflow issue 996.
 */
final class EntityAccessDenied extends RuntimeException
{
    public static function for(EntityReference $reference): self
    {
        return new self(sprintf('Access to entity [%s:%s] was denied.', $reference->type, $reference->id));
    }
}
