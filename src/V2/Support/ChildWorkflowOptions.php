<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Enums\ParentClosePolicy;

/**
 * Per-call child workflow configuration overrides.
 *
 * When provided, these values take precedence over the defaults.
 * This allows workflow code to specify parent-close policy and
 * routing for individual child workflow calls.
 */
final class ChildWorkflowOptions
{
    public function __construct(
        public readonly ParentClosePolicy $parentClosePolicy = ParentClosePolicy::Abandon,
        public readonly ?string $connection = null,
        public readonly ?string $queue = null,
    ) {
    }

    /**
     * @return array{
     *     parent_close_policy: string,
     *     connection: string|null,
     *     queue: string|null,
     * }
     */
    public function toSnapshot(): array
    {
        return [
            'parent_close_policy' => $this->parentClosePolicy->value,
            'connection' => $this->connection,
            'queue' => $this->queue,
        ];
    }

    public function hasRoutingOverrides(): bool
    {
        return $this->connection !== null || $this->queue !== null;
    }
}
