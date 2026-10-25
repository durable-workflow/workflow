<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Events\WorkflowCompleted;
use Workflow\V2\Events\WorkflowFailed;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\LifecycleEventDispatcher;

/**
 * Lifecycle events held back by a transaction attempt are dispatched once,
 * after the transaction that produced them commits.
 */
final class LifecycleEventDispatcherCollectTest extends TestCase
{
    public function testARetriedTransactionDispatchesOnlyTheCommittedAttemptsEvents(): void
    {
        Event::fake([WorkflowCompleted::class]);
        $run = $this->createRun();
        $attempt = 0;

        [$result, $events] = DB::transaction(static function () use (&$attempt, $run): array {
            return LifecycleEventDispatcher::collect(static function () use (&$attempt, $run): string {
                LifecycleEventDispatcher::workflowCompleted($run);
                if (++$attempt === 1) {
                    // Laravel retries a transaction attempt that fails with a deadlock.
                    throw new RuntimeException('Deadlock found when trying to get lock; try restarting transaction');
                }

                return 'committed';
            });
        }, 2);

        $this->assertSame(2, $attempt);
        $this->assertSame('committed', $result);
        Event::assertNotDispatched(WorkflowCompleted::class);

        LifecycleEventDispatcher::release($events);

        Event::assertDispatchedTimes(WorkflowCompleted::class, 1);
    }

    public function testAFailedAttemptDispatchesNothing(): void
    {
        Event::fake([WorkflowCompleted::class]);
        $run = $this->createRun();

        $body = static function () use ($run): void {
            LifecycleEventDispatcher::workflowCompleted($run);

            throw new RuntimeException('rolled back');
        };

        try {
            DB::transaction(static fn (): array => LifecycleEventDispatcher::collect($body));
            $this->fail('The failed attempt returned.');
        } catch (RuntimeException $error) {
            $this->assertSame('rolled back', $error->getMessage());
        }

        // A later dispatch outside any collecting attempt is immediate.
        Event::assertNotDispatched(WorkflowCompleted::class);
        LifecycleEventDispatcher::workflowCompleted($run);
        Event::assertDispatchedTimes(WorkflowCompleted::class, 1);
    }

    public function testEventsReleasedInsideAnOpenTransactionWaitForItsCommit(): void
    {
        Event::fake([WorkflowCompleted::class]);
        $run = $this->createRun();

        DB::transaction(static function () use ($run): void {
            [, $events] = LifecycleEventDispatcher::collect(
                static fn () => LifecycleEventDispatcher::workflowCompleted($run)
            );
            LifecycleEventDispatcher::release($events);
            Event::assertNotDispatched(WorkflowCompleted::class);
        });
        Event::assertDispatchedTimes(WorkflowCompleted::class, 1);

        try {
            DB::transaction(static function () use ($run): void {
                [, $events] = LifecycleEventDispatcher::collect(
                    static fn () => LifecycleEventDispatcher::workflowCompleted($run)
                );
                LifecycleEventDispatcher::release($events);

                throw new RuntimeException('outer rolled back');
            });
        } catch (RuntimeException) {
        }
        Event::assertDispatchedTimes(WorkflowCompleted::class, 1);
    }

    public function testANestedAttemptThatRollsBackPublishesNothingWhenTheOuterCommits(): void
    {
        Event::fake([WorkflowCompleted::class, WorkflowFailed::class]);
        $run = $this->createRun();

        [$result, $events] = DB::transaction(static fn (): array => LifecycleEventDispatcher::collect(
            static function () use ($run): string {
                LifecycleEventDispatcher::workflowCompleted($run);
                try {
                    DB::transaction(static fn (): array => LifecycleEventDispatcher::collect(static function () use (
                        $run
                    ): void {
                        LifecycleEventDispatcher::workflowFailed($run, RuntimeException::class, 'nested');

                        throw new RuntimeException('nested attempt rolled back');
                    }));
                } catch (RuntimeException) {
                    // The outer attempt carries on and commits.
                }

                return 'outer committed';
            }
        ));
        LifecycleEventDispatcher::release($events);

        $this->assertSame('outer committed', $result);
        Event::assertDispatchedTimes(WorkflowCompleted::class, 1);
        Event::assertNotDispatched(WorkflowFailed::class);
    }

    public function testARetriedNestedTransactionPublishesOnlyItsCommittedAttempt(): void
    {
        Event::fake([WorkflowCompleted::class]);
        $run = $this->createRun();

        [, $events] = DB::transaction(static fn (): array => LifecycleEventDispatcher::collect(
            static function () use ($run): void {
                for ($attempt = 1; ; $attempt++) {
                    try {
                        [, $nested] = DB::transaction(
                            static fn (): array => LifecycleEventDispatcher::collect(static function () use (
                                $run,
                                $attempt
                            ): void {
                                LifecycleEventDispatcher::workflowCompleted($run);
                                if ($attempt === 1) {
                                    throw new RuntimeException('nested attempt failed');
                                }
                            })
                        );
                        LifecycleEventDispatcher::release($nested);

                        return;
                    } catch (RuntimeException) {
                        // Retry the nested transaction.
                    }
                }
            }
        ));

        // The committed nested attempt's event joined the outer attempt.
        Event::assertNotDispatched(WorkflowCompleted::class);
        $this->assertCount(1, $events);
        LifecycleEventDispatcher::release($events);
        Event::assertDispatchedTimes(WorkflowCompleted::class, 1);
    }

    public function testAnEnclosingAttemptThatFailsDiscardsItsNestedEvents(): void
    {
        Event::fake([WorkflowCompleted::class]);
        $run = $this->createRun();

        try {
            LifecycleEventDispatcher::collect(static function () use ($run): void {
                [, $nested] = LifecycleEventDispatcher::collect(
                    static fn () => LifecycleEventDispatcher::workflowCompleted($run)
                );
                LifecycleEventDispatcher::release($nested);

                throw new RuntimeException('outer attempt failed');
            });
        } catch (RuntimeException) {
        }

        Event::assertNotDispatched(WorkflowCompleted::class);
        // Nothing is left collecting: a later dispatch is immediate.
        LifecycleEventDispatcher::workflowCompleted($run);
        Event::assertDispatchedTimes(WorkflowCompleted::class, 1);
    }

    private function createRun(): WorkflowRun
    {
        $instance = WorkflowInstance::query()->create([
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'run_count' => 1,
            'started_at' => now()
                ->subMinute(),
        ]);
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'status' => RunStatus::Completed->value,
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'started_at' => now()
                ->subMinute(),
        ]);
        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        return $run;
    }
}
