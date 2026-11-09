<?php

declare(strict_types=1);

namespace Workflow\V2\StateMachine\Execution;

use Workflow\V2\StateMachine\Rules\Decision;

final readonly class TransitionResult
{
    public function __construct(
        public ExecutionReference $execution,
        public TransitionStatus $status,
        public string $reason,
        public ?Decision $assessment = null,
        public ?string $transitionKey = null,
    ) {
    }

    /**
     * Portable workflow result. Business DTO objects must not cross activity/history boundaries.
     */
    public function toArray(): array
    {
        return [
            'execution' => $this->execution->toArray(),
            'status' => $this->status->value,
            'reason' => $this->reason,
            'assessment' => $this->assessment?->toArray(),
            'transitionKey' => $this->transitionKey,
        ];
    }
}
