<?php

declare(strict_types=1);

namespace Workflow\V2\Exceptions;

use LogicException;

final class HistoryEventShapeMismatchException extends LogicException
{
    /**
     * @param list<string> $recordedEventTypes
     */
    public function __construct(
        public readonly int $workflowSequence,
        public readonly string $expectedHistoryShape,
        public readonly array $recordedEventTypes,
    ) {
        parent::__construct(sprintf(
            'Workflow history at workflow sequence %d recorded [%s], but the current workflow yielded %s. Keep yielded workflow steps stable across deployments or run this workflow on a compatible build.',
            $workflowSequence,
            implode(', ', $recordedEventTypes),
            $expectedHistoryShape,
        ));
    }
}
