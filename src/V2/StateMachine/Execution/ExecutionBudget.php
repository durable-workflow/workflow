<?php

declare(strict_types=1);

namespace Workflow\V2\StateMachine\Execution;

use Fiber;
use LogicException;
use Throwable;
use WeakMap;
use WeakReference;
use Workflow\V2\StateMachine\Definition\ExecutionLimits;
use Workflow\V2\Support\AllCall;
use Workflow\V2\Support\WorkflowFiberContext;
use Workflow\V2\Workflow;

/** @internal Reconstructed by replay; no scheduler, retry loop or persistent side ledger. */
final class ExecutionBudget
{
    /**
     * @var WeakMap<Fiber, array<string, self>>|null
     */
    private static ?WeakMap $fibers = null;

    private int $spent = 0;

    private ?Throwable $tripped = null;

    /**
     * @param WeakReference<Workflow> $workflow
     */
    private function __construct(
        private readonly ExecutionLimits $limits,
        private readonly WeakReference $workflow,
    ) {
    }

    public function __invoke(mixed $call): void
    {
        if ($this->tripped !== null) {
            throw $this->tripped;
        }
        try {
            $this->history();
            $cost = 0;
            $this->cost($call, $cost, 0);
            $this->spent += $cost;
        } catch (Throwable $error) {
            // Catching a refusal inside a handler must not permit later work.
            $this->tripped = $error;
            throw $error;
        }
    }

    public static function forExecution(
        Workflow $workflow,
        ExecutionReference $execution,
        ExecutionLimits $limits,
    ): self {
        $fiber = Fiber::getCurrent();
        if (! $fiber instanceof Fiber || ! WorkflowFiberContext::active()) {
            throw new LogicException('Transition budgets require an active workflow Fiber.');
        }
        self::$fibers ??= new WeakMap();
        $key = hash('sha256', json_encode($execution->toArray(), JSON_THROW_ON_ERROR));
        $budgets = self::$fibers[$fiber] ?? [];
        $budget = $budgets[$key] ?? new self($limits, WeakReference::create($workflow));
        if ($budget->limits->toArray() !== $limits->toArray()) {
            throw new LogicException('An original execution cannot replace its pinned resource budget.');
        }
        $budgets[$key] = $budget;
        self::$fibers[$fiber] = $budgets;
        return $budget;
    }

    public function caused(ResourceLimitExceeded $error): bool
    {
        return $this->tripped === $error;
    }

    private function history(): void
    {
        if ($this->limits->historyEvents === null && $this->limits->historyBytes === null) {
            return;
        }
        $workflow = $this->workflow->get();
        if (! $workflow instanceof Workflow) {
            throw new LogicException('The original transition workflow is unavailable.');
        }
        $history = $workflow->historyBudgetAtStep();
        foreach ([
            'history_events' => ['history_event_count', $this->limits->historyEvents],
            'history_bytes' => ['history_size_bytes', $this->limits->historyBytes],
        ] as $dimension => [$key, $limit]) {
            if ($limit === null) {
                continue;
            }
            $observed = $history[$key] ?? null;
            if (! is_int($observed) || $observed < 0) {
                throw new LogicException('A replay-stable workflow history measurement is unavailable.');
            }
            if ($observed >= $limit) {
                throw new ResourceLimitExceeded($dimension, $observed, $limit);
            }
        }
    }

    private function cost(mixed $call, int &$count, int $depth): void
    {
        ++$count;
        if ($count > $this->limits->commands - $this->spent) {
            throw new ResourceLimitExceeded('commands', $this->spent + $count, $this->limits->commands);
        }
        if (! $call instanceof AllCall) {
            return;
        }
        if ($depth >= $this->limits->groupDepth) {
            throw new ResourceLimitExceeded('command_depth', $depth + 1, $this->limits->groupDepth);
        }
        // Count groups and leaves before emitting the group. An oversized
        // batch cannot dispatch a prefix, and nesting cannot evade the cap.
        foreach ($call->calls as $member) {
            $this->cost($member, $count, $depth + 1);
        }
    }
}
