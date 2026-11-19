<?php

declare(strict_types=1);

namespace Tests\Unit\V2\StateMachine;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WeakReference;
use Workflow\V2\StateMachine\Definition\ExecutionLimits;
use Workflow\V2\StateMachine\Execution\ExecutionBudget;
use Workflow\V2\StateMachine\Execution\ExecutionReference;
use Workflow\V2\StateMachine\Execution\ResourceLimitExceeded;
use Workflow\V2\Support\ActivityCall;
use Workflow\V2\Support\AllCall;
use Workflow\V2\Support\WorkflowExecution;
use Workflow\V2\Support\WorkflowFiberContext;
use Workflow\V2\Workflow;

final class ExecutionBudgetTest extends TestCase
{
    public function testBudgetCannotBeInstalledOutsideRecordedWorkflowExecution(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('active workflow Fiber');
        ExecutionBudget::forExecution(new BudgetWorkflow(), $this->execution(), new ExecutionLimits(1));
    }

    public function testTheSameOriginalExecutionCannotSelectALargerAllowance(): void
    {
        $workflow = new BudgetWorkflow();
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('replace its pinned resource budget');
        $this->runner(function () use ($workflow): void {
            ExecutionBudget::forExecution($workflow, $this->execution(), new ExecutionLimits(1));
            ExecutionBudget::forExecution($workflow, $this->execution(), new ExecutionLimits(2));
        });
    }

    public function testRepeatingTheSameOriginalExecutionDoesNotRenewItsCommandBudget(): void
    {
        $workflow = new BudgetWorkflow();
        $execution = $this->execution();
        $fiber = $this->runner(function () use ($workflow, $execution): string {
            $budget = ExecutionBudget::forExecution($workflow, $execution, new ExecutionLimits(1));
            WorkflowFiberContext::withForwardBudget($budget, static fn () =>
                WorkflowFiberContext::suspend(new ActivityCall('first', [])));
            $again = ExecutionBudget::forExecution($workflow, $execution, new ExecutionLimits(1));
            $this->assertSame($budget, $again);
            try {
                WorkflowFiberContext::withForwardBudget($again, static fn () =>
                    WorkflowFiberContext::suspend(new ActivityCall('second', [])));
            } catch (ResourceLimitExceeded $error) {
                $this->assertTrue($budget->caused($error));
                $this->assertSame(2, $error->observed);
                return $error->reason();
            }
            return 'incorrectly-dispatched';
        });
        $this->assertSame('first', $fiber->current()->activity);
        $this->assertNull($fiber->send('recorded'));
        $this->assertFalse($fiber->valid());
        $this->assertSame('state_machine_commands_limit_exceeded', $fiber->getReturn());
    }

    public function testOversizedNestedBatchEmitsNoPrefix(): void
    {
        $workflow = new BudgetWorkflow();
        $group = new AllCall([new ActivityCall('first', []), new AllCall([new ActivityCall('second', [])])]);
        $fiber = $this->runner(function () use ($workflow, $group): array {
            $budget = ExecutionBudget::forExecution($workflow, $this->execution(), new ExecutionLimits(3));
            try {
                WorkflowFiberContext::withForwardBudget($budget, static fn () => WorkflowFiberContext::suspend($group));
            } catch (ResourceLimitExceeded $error) {
                return [$error->dimension, $error->observed, $error->limit];
            }
            return [];
        });
        $this->assertNull($fiber->current());
        $this->assertSame(['commands', 4, 3], $fiber->getReturn());
    }

    public function testNestedGroupDepthIsBoundedBeforeDispatch(): void
    {
        $workflow = new BudgetWorkflow();
        $group = new AllCall([new AllCall([new ActivityCall('first', [])])]);
        $fiber = $this->runner(function () use ($workflow, $group): string {
            $budget = ExecutionBudget::forExecution(
                $workflow,
                $this->execution(),
                new ExecutionLimits(10, groupDepth: 1)
            );
            try {
                WorkflowFiberContext::withForwardBudget($budget, static fn () => WorkflowFiberContext::suspend($group));
            } catch (ResourceLimitExceeded $error) {
                return $error->reason();
            }
            return 'incorrectly-dispatched';
        });
        $this->assertNull($fiber->current());
        $this->assertSame('state_machine_command_depth_limit_exceeded', $fiber->getReturn());
    }

    public static function historyThresholds(): array
    {
        return [['history_events', new ExecutionLimits(10, historyEvents: 10), 10, 0],
            ['history_bytes', new ExecutionLimits(10, historyBytes: 100), 0, 100]];
    }

    #[DataProvider('historyThresholds')]
    public function testHistoryAdmissionStopsAtTheMeasuredThreshold(
        string $dimension,
        ExecutionLimits $limits,
        int $events,
        int $bytes
    ): void {
        $workflow = new BudgetWorkflow();
        $workflow->observed = [
            'history_event_count' => $events,
            'history_size_bytes' => $bytes,
        ];
        $fiber = $this->runner(function () use ($workflow, $limits): string {
            $budget = ExecutionBudget::forExecution($workflow, $this->execution(), $limits);
            try {
                WorkflowFiberContext::withForwardBudget($budget, static fn () =>
                    WorkflowFiberContext::suspend(new ActivityCall('must-not-run', [])));
            } catch (ResourceLimitExceeded $error) {
                return $error->reason();
            }
            return 'incorrectly-dispatched';
        });
        $this->assertNull($fiber->current());
        $this->assertSame('state_machine_' . $dimension . '_limit_exceeded', $fiber->getReturn());
    }

    public function testCaughtRefusalStaysLatchedWhileNativeCompensationStillRuns(): void
    {
        $workflow = new BudgetWorkflow();
        $workflow->addCompensation(
            static fn () => WorkflowFiberContext::suspend(new ActivityCall('undo-original', []))
        );
        $fiber = $this->runner(function () use ($workflow): string {
            $budget = ExecutionBudget::forExecution($workflow, $this->execution(), new ExecutionLimits(1));
            return WorkflowFiberContext::withForwardBudget($budget, function () use ($workflow): string {
                WorkflowFiberContext::suspend(new ActivityCall('first', []));
                $firstRefusal = null;
                try {
                    WorkflowFiberContext::suspend(new ActivityCall('second', []));
                } catch (ResourceLimitExceeded $error) {
                    $firstRefusal = $error;
                }
                $workflow->compensate();
                try {
                    WorkflowFiberContext::suspend(new ActivityCall('third', []));
                } catch (ResourceLimitExceeded $error) {
                    $this->assertSame($firstRefusal, $error);
                    return $error->reason();
                }
                return 'incorrectly-dispatched';
            });
        });
        $this->assertSame('first', $fiber->current()->activity);
        $this->assertSame('undo-original', $fiber->send('created')->activity);
        $fiber->send('verified');
        $this->assertSame('state_machine_commands_limit_exceeded', $fiber->getReturn());
    }

    public function testDifferentFibersCannotSpendEachOthersOriginalBudget(): void
    {
        $workflow = new BudgetWorkflow();
        $body = function () use ($workflow): void {
            $budget = ExecutionBudget::forExecution($workflow, $this->execution(), new ExecutionLimits(1));
            WorkflowFiberContext::withForwardBudget($budget, static fn () =>
                WorkflowFiberContext::suspend(new ActivityCall('one-per-fiber', [])));
        };
        $first = $this->runner($body);
        $second = $this->runner($body);
        $this->assertSame('one-per-fiber', $first->current()->activity);
        $this->assertSame('one-per-fiber', $second->current()->activity);
        $first->send('recorded');
        $second->send('recorded');
        $this->assertFalse($first->valid());
        $this->assertFalse($second->valid());
    }

    public function testDiscardedFiberDoesNotLeaveAForwardGuardOrKeepItsMeterAlive(): void
    {
        $workflow = new BudgetWorkflow();
        $body = function () use ($workflow): void {
            $budget = ExecutionBudget::forExecution($workflow, $this->execution(), new ExecutionLimits(1));
            WorkflowFiberContext::withForwardBudget($budget, static fn () =>
                WorkflowFiberContext::suspend(new ActivityCall('first', [])));
        };
        $first = $this->runner($body);
        $reference = WeakReference::create($first->fiber());
        unset($first);
        $this->assertNull($reference->get());
        $second = $this->runner($body);
        $this->assertSame('first', $second->current()->activity);
        $second->send('recorded');
        $this->assertFalse($second->valid());
    }

    public function testDiscardingBudgetedFinallyDoesNotRaiseANewBusinessFailure(): void
    {
        $workflow = new BudgetWorkflow();
        $afterCleanup = false;
        $execution = $this->runner(function () use ($workflow, &$afterCleanup): void {
            $budget = ExecutionBudget::forExecution($workflow, $this->execution(), new ExecutionLimits(1));
            WorkflowFiberContext::withForwardBudget($budget, static function () use (&$afterCleanup): void {
                try {
                    WorkflowFiberContext::suspend(new ActivityCall('first', []));
                } finally {
                    WorkflowFiberContext::suspend(new ActivityCall('must-not-emit', []));
                    $afterCleanup = true;
                }
            });
        });
        $reference = WeakReference::create($execution->fiber());
        $this->assertSame('first', $execution->current()->activity);
        unset($execution);
        $this->assertFalse($afterCleanup);
        $this->assertNull($reference->get());
    }

    public function testUnavailableHistoryIsNotTreatedAsZeroAndCannotBeCaughtToAllowWork(): void
    {
        $workflow = new BudgetWorkflow();
        $workflow->observed = [];
        $fiber = $this->runner(function () use ($workflow): string {
            $budget = ExecutionBudget::forExecution(
                $workflow,
                $this->execution(),
                new ExecutionLimits(5, historyEvents: 10)
            );
            return WorkflowFiberContext::withForwardBudget($budget, function () use ($workflow): string {
                $first = null;
                try {
                    WorkflowFiberContext::suspend(new ActivityCall('first', []));
                } catch (LogicException $error) {
                    $first = $error;
                }
                $workflow->observed = [
                    'history_event_count' => 0,
                ];
                try {
                    WorkflowFiberContext::suspend(new ActivityCall('second', []));
                } catch (LogicException $error) {
                    $this->assertSame($first, $error);
                    return $error->getMessage();
                }
                return 'incorrectly-dispatched';
            });
        });
        $this->assertNull($fiber->current());
        $this->assertStringContainsString('measurement is unavailable', $fiber->getReturn());
    }

    private function runner(callable $callback): WorkflowExecution
    {
        return WorkflowExecution::startCallback($callback);
    }

    private function execution(): ExecutionReference
    {
        return new ExecutionReference('app', 'subject', 'original-operation', 'pinned-budget', 'Received', '1');
    }
}

final class BudgetWorkflow extends Workflow
{
    public array $observed = [
        'history_event_count' => 0,
        'history_size_bytes' => 0,
    ];

    public function historyBudgetAtStep(): array
    {
        return $this->observed;
    }
}
