<?php

declare(strict_types=1);

namespace Workflow\V2\StateMachine\Execution;

use InvalidArgumentException;
use Workflow\V2\StateMachine\Rules\Decision;
use Workflow\V2\StateMachine\Transition\SnapshotPhase;

/**
 * @experimental Part of the business state-machine API, which may change in a minor release.
 * See docs/api-stability.md#state-machines.
 */
final readonly class TransitionResult
{
    public function __construct(
        public ExecutionReference $execution,
        public TransitionStatus $status,
        public string $reason,
        public ?Decision $assessment = null,
        public ?string $transitionKey = null,
        public ?TransitionStage $stage = null,
        public ?string $snapshotId = null,
        public ?SnapshotPhase $phase = null,
    ) {
        if (($snapshotId === null) !== ($phase === null)) {
            throw new InvalidArgumentException('A transition result names both its snapshot and phase, or neither.');
        }
    }

    /**
     * Portable workflow result. Business DTO objects must not cross activity/history boundaries.
     *
     * The shape is unchanged from earlier releases; explain() adds the stage and snapshot.
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

    /**
     * Portable diagnostic: which gate produced this result, from which recorded snapshot.
     *
     * The snapshot ID correlates the result with the host's retained ConditionEvaluation for that
     * snapshot. A result produced before any snapshot was recorded has a null snapshot.
     *
     * @return array{version: int, status: string, reason: string, stage: ?string, transitionKey: ?string, snapshot: ?array{id: string, phase: string}, execution: array<string, string>, assessment: ?array}
     */
    public function explain(): array
    {
        return [
            'version' => 1,
            'status' => $this->status->value,
            'reason' => $this->reason,
            'stage' => $this->stage?->value,
            'transitionKey' => $this->transitionKey,
            'snapshot' => $this->snapshotId === null || $this->phase === null ? null : [
                'id' => $this->snapshotId,
                'phase' => $this->phase->value,
            ],
            'execution' => $this->execution->toArray(),
            'assessment' => $this->assessment?->toArray(),
        ];
    }
}
