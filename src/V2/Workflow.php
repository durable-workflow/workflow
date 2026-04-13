<?php

declare(strict_types=1);

namespace Workflow\V2;

use Throwable;
use Workflow\Traits\ResolvesMethodDependencies;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\ChildWorkflowHandles;
use Workflow\V2\Support\HistoryBudget;

abstract class Workflow
{
    use ResolvesMethodDependencies;

    public ?string $connection = null;

    public ?string $queue = null;

    private int $visibleSequence = 1;

    private bool $commandDispatchEnabled = true;

    /** @var list<callable> */
    private array $compensations = [];

    private bool $parallelCompensation = false;

    private bool $continueWithError = false;

    final public function __construct(
        public readonly WorkflowRun $run,
    ) {
    }

    public function workflowId(): string
    {
        return $this->run->workflow_instance_id;
    }

    public function runId(): string
    {
        return $this->run->id;
    }

    public function child(): ?ChildWorkflowHandle
    {
        $children = $this->children();

        if ($children === []) {
            return null;
        }

        return $children[array_key_last($children)];
    }

    /**
     * @return list<ChildWorkflowHandle>
     */
    public function children(): array
    {
        return ChildWorkflowHandles::forRun($this->run, $this->visibleSequence, $this->commandDispatchEnabled);
    }

    public function historyLength(): int
    {
        return HistoryBudget::forRun($this->run)['history_event_count'];
    }

    public function historySize(): int
    {
        return HistoryBudget::forRun($this->run)['history_size_bytes'];
    }

    public function shouldContinueAsNew(): bool
    {
        return HistoryBudget::forRun($this->run)['continue_as_new_recommended'];
    }

    public function addCompensation(callable $compensation): static
    {
        $this->compensations[] = $compensation;

        return $this;
    }

    public function setParallelCompensation(bool $parallel): static
    {
        $this->parallelCompensation = $parallel;

        return $this;
    }

    public function setContinueWithError(bool $continueWithError): static
    {
        $this->continueWithError = $continueWithError;

        return $this;
    }

    public function compensate(): void
    {
        $reversed = array_reverse($this->compensations);

        if ($this->parallelCompensation) {
            $calls = [];

            foreach ($reversed as $compensation) {
                $calls[] = $compensation();
            }

            if ($this->continueWithError) {
                try {
                    all($calls);
                } catch (Throwable) {
                    // continueWithError applies uniformly: swallow parallel compensation failures
                }
            } else {
                all($calls);
            }
        } else {
            foreach ($reversed as $compensation) {
                try {
                    $compensation();
                } catch (Throwable $e) {
                    if (! $this->continueWithError) {
                        throw $e;
                    }
                }
            }
        }
    }

    public function syncExecutionCursor(int $visibleSequence): void
    {
        $this->visibleSequence = max(1, $visibleSequence);
    }

    public function setCommandDispatchEnabled(bool $enabled): void
    {
        $this->commandDispatchEnabled = $enabled;
    }
}
