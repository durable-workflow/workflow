<?php

declare(strict_types=1);

namespace Workflow\V2\EntityReferences;

use RuntimeException;

/**
 * Thrown by a resolver when the referenced entity no longer exists (#996).
 * Whether it is retried follows the activity's retry policy; mark it
 * non-retryable there if a retry can't succeed.
 *
 * @experimental Draft API for workflow issue 996.
 */
final class EntityNotFound extends RuntimeException
{
    public static function for(EntityReference $reference): self
    {
        return new self(sprintf('Entity [%s:%s] was not found.', $reference->type, $reference->id));
    }
}
