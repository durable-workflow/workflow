<?php

declare(strict_types=1);

namespace Workflow;

use Attribute;
use LogicException;

/**
 * Marks a workflow method as the pre-acceptance validator for an update.
 *
 * The validator receives the same arguments as the update handler and runs
 * against the workflow state reconstructed from history before the update is
 * accepted. Throwing (or returning `false`) rejects the update with the
 * `update_validator_rejected` reason: the update is never accepted, the
 * handler never runs and the workflow is not woken. Validators are read-only:
 * scheduling durable work from a validator throws.
 *
 * `$update` is the update's durable name (or its PHP method name when the
 * update declares no alias).
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class UpdateValidator
{
    public function __construct(
        public readonly string $update,
    ) {
        if ($this->update === '') {
            throw new LogicException('Update validator targets must be non-empty update names.');
        }
    }
}
