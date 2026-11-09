<?php

declare(strict_types=1);

namespace Workflow\V2\StateMachine\Persistence;

use InvalidArgumentException;
use Workflow\V2\StateMachine\Execution\ExecutionReference;
use Workflow\V2\StateMachine\Execution\TransitionStatus;

/** Acknowledges the application's protected commit, including an idempotent replay of it. */
final readonly class CommitResult
{
    public function __construct(
        public ExecutionReference $execution,
        public TransitionStatus $status,
        public string $reason,
    ) {
        if (! in_array(
            $status,
            [TransitionStatus::Committed, TransitionStatus::Conflict, TransitionStatus::CancellationRequested],
            true
        )
            || trim($reason) === '') {
            throw new InvalidArgumentException('Commit must explicitly report committed, conflict or cancellation.');
        }
    }
}
