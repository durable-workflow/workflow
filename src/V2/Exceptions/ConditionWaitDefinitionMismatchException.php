<?php

declare(strict_types=1);

namespace Workflow\V2\Exceptions;

use LogicException;

final class ConditionWaitDefinitionMismatchException extends LogicException
{
    public function __construct(
        public readonly int $workflowSequence,
        public readonly string $recordedConditionKey,
        public readonly ?string $currentConditionKey,
    ) {
        parent::__construct(sprintf(
            'Condition wait at workflow sequence %d was recorded with condition key [%s], but the current workflow yielded [%s]. Keep condition keys stable across deployments or run this workflow on a compatible build.',
            $workflowSequence,
            $recordedConditionKey,
            $currentConditionKey ?? 'none',
        ));
    }
}
