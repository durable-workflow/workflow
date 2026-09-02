<?php

declare(strict_types=1);

namespace Workflow;

final class WorkflowOptions
{
    public function __construct(
        public readonly ?string $connection = null,
        public readonly ?string $queue = null,
    ) {
    }

    /**
     * @param array{connection?: string|null, queue?: string|null} $options
     */
    public static function set(array $options): self
    {
        return new self($options['connection'] ?? null, $options['queue'] ?? null);
    }
}
