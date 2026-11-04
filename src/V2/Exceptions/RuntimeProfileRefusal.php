<?php

declare(strict_types=1);

namespace Workflow\V2\Exceptions;

use LogicException;
use Throwable;

/** A compatibility refusal, never a workflow or task failure. */
final class RuntimeProfileRefusal extends LogicException
{
    public function __construct(
        public readonly string $reason,
        ?Throwable $previous = null,
    ) {
        parent::__construct(match ($reason) {
            'runtime_profile_assignment' => 'Runtime profiles are engine-owned and cannot be assigned or changed.',
            'runtime_profile_invalid' => 'The workflow run has an invalid runtime profile.',
            'runtime_profile_unsupported' => 'The workflow run requires a runtime profile unsupported by this build.',
            default => 'The authoritative workflow runtime profile could not be verified. Check the configured storage and guard migration.',
        }, 0, $previous);
    }
}
