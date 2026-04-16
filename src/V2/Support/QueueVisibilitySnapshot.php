<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

final class QueueVisibilitySnapshot
{
    /**
     * @param list<QueueVisibilityDetail> $taskQueues
     */
    public function __construct(
        public readonly string $namespace,
        private readonly array $taskQueues,
    ) {
    }

    /**
     * @return list<QueueVisibilityDetail>
     */
    public function taskQueues(): array
    {
        return $this->taskQueues;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'namespace' => $this->namespace,
            'task_queues' => array_map(
                static fn (QueueVisibilityDetail $detail): array => $detail->toSummaryArray(),
                $this->taskQueues,
            ),
        ];
    }
}
