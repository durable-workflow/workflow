<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Fiber;
use FiberError;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use WeakReference;
use function Workflow\V2\activity;
use Workflow\V2\Support\ActivityCall;
use Workflow\V2\Support\TimerCall;

use Workflow\V2\Support\WorkflowExecution;
use function Workflow\V2\timer;
use function Workflow\V2\upsertMemo;

final class WorkflowExecutionDisposalTest extends TestCase
{
    public function testDisposalDoesNotEmitDurableCleanupOrRetainSuspendedFibers(): void
    {
        foreach (['activity', 'timer', 'memo'] as $kind) {
            $afterCleanup = false;
            $execution = WorkflowExecution::startCallback(static function () use ($kind, &$afterCleanup): void {
                try {
                    timer(1);
                } finally {
                    self::cleanup($kind);
                    $afterCleanup = true;
                }
            });
            $reference = WeakReference::create($execution->fiber());

            $this->assertInstanceOf(TimerCall::class, $execution->current());
            $this->assertSame(1, $execution->current()->seconds);
            unset($execution);

            $this->assertFalse($afterCleanup);
            $this->assertNull($reference->get());
        }
    }

    public function testGarbageCollectionDiscardsCyclicWorkflowFibers(): void
    {
        $owner = new \stdClass();
        $owner->execution = WorkflowExecution::startCallback(static function () use ($owner): void {
            try {
                timer(1);
            } finally {
                activity('cleanup', [$owner]);
            }
        });
        $reference = WeakReference::create($owner->execution->fiber());
        unset($owner);

        gc_collect_cycles();

        $this->assertNull($reference->get());
    }

    public function testNestedFinallyBlocksAreSafeToDiscard(): void
    {
        $execution = WorkflowExecution::startCallback(static function (): void {
            try {
                try {
                    timer(1);
                } finally {
                    activity('cleanup', []);
                }
            } finally {
                upsertMemo([
                    'cleaned' => true,
                ]);
            }
        });
        $reference = WeakReference::create($execution->fiber());

        unset($execution);

        $this->assertNull($reference->get());
    }

    public function testFinallyStillSuspendsAndCompletesAfterSuccessAndHandledFailure(): void
    {
        foreach ([false, true] as $fail) {
            $execution = WorkflowExecution::startCallback(static function (): string {
                try {
                    timer(1);
                    $result = 'success';
                } catch (RuntimeException) {
                    $result = 'handled';
                } finally {
                    activity('cleanup', []);
                    timer(2);
                    upsertMemo([
                        'cleaned' => true,
                    ]);
                }

                return $result;
            });

            $cleanup = $fail ? $execution->throw(new RuntimeException('failure')) : $execution->send(null);
            $this->assertInstanceOf(ActivityCall::class, $cleanup);
            $this->assertSame('cleanup', $cleanup->activity);
            $this->assertSame(2, $execution->send('cleaned')->seconds);
            $this->assertTrue($execution->valid());
            $execution->send(null);
            $this->assertTrue($execution->valid());
            $execution->send(null);

            $this->assertFalse($execution->valid());
            $this->assertSame($fail ? 'handled' : 'success', $execution->getReturn());
        }
    }

    public function testAnUnhandledFailureIsNotSwallowedAfterCleanup(): void
    {
        $failure = new RuntimeException('business failure');
        $execution = WorkflowExecution::startCallback(static function () use ($failure): void {
            try {
                throw $failure;
            } finally {
                timer(1);
            }
        });

        $this->expectExceptionObject($failure);
        $execution->send(null);
    }

    public function testOtherFiberErrorsAreNotSwallowed(): void
    {
        $this->expectException(FiberError::class);
        $this->expectExceptionMessage('Cannot resume a fiber that is not suspended');

        WorkflowExecution::startCallback(static function (): void {
            Fiber::getCurrent()->resume();
        });
    }

    public function testAForceCloseErrorFromAnotherFiberIsNotSwallowed(): void
    {
        $other = new Fiber(static function (): void {
            try {
                Fiber::suspend();
            } finally {
                Fiber::suspend();
            }
        });
        $other->start();
        $failure = null;
        try {
            unset($other);
        } catch (FiberError $error) {
            $failure = $error;
        }
        $this->assertInstanceOf(FiberError::class, $failure);
        $execution = WorkflowExecution::startCallback(static fn (): mixed => timer(1));

        try {
            $execution->throw($failure);
            $this->fail('A force-close error from another Fiber must remain observable.');
        } catch (FiberError $error) {
            $this->assertSame($failure, $error);
        }
    }

    private static function cleanup(string $kind): void
    {
        match ($kind) {
            'activity' => activity('cleanup', []),
            'timer' => timer(2),
            'memo' => upsertMemo([
                'cleaned' => true,
            ]),
        };
    }
}
