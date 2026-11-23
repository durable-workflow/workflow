<?php

declare(strict_types=1);

namespace Workflow\V2\Exceptions;

use LogicException;

final class HistoryEventShapeMismatchException extends LogicException
{
    /**
     * @var array{function: string, file: string, line: int}|null
     */
    private ?array $workflowSource = null;

    /**
     * @param list<string> $recordedEventTypes
     */
    public function __construct(
        public readonly int $workflowSequence,
        public readonly string $expectedHistoryShape,
        public readonly array $recordedEventTypes,
        ?string $detail = null,
    ) {
        $detail = is_string($detail) && $detail !== ''
            ? " {$detail}"
            : '';

        parent::__construct(sprintf(
            'Workflow history at workflow sequence %d recorded [%s], but the current workflow yielded %s.%s Keep yielded workflow steps stable across deployments or run this workflow on a compatible build.',
            $workflowSequence,
            implode(', ', $recordedEventTypes),
            $expectedHistoryShape,
            $detail,
        ));
    }

    /**
     * @return array{function: string, file: string, line: int}|null
     */
    public function workflowSource(): ?array
    {
        return $this->workflowSource;
    }

    /**
     * @param array{function: string, file: string, line: int} $source
     */
    public function recordWorkflowSource(array $source): void
    {
        if ($this->workflowSource !== null) {
            return;
        }

        $this->workflowSource = $source;
        $this->message .= sprintf(' Authored at %s (%s:%d).', $source['function'], $source['file'], $source['line']);
    }
}
