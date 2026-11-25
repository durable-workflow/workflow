<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;

/**
 * Workflow #1286: a child start's consecutive events share one history append
 * and store exactly what separate appends store.
 */
final class ChildStartHistoryAppendTest extends TestCase
{
    public function testAChildStartAppendStoresWhatSeparateAppendsStore(): void
    {
        $this->freezeTime();
        $shared = $this->createRun();
        $separate = $this->createRun();
        $events = $this->childStartEvents();

        $sharedWrites = $this->runWrites(static function () use ($shared, $events): void {
            DB::transaction(static fn () => WorkflowHistoryEvent::recordChildStart($shared, $events));
        });
        $separateWrites = $this->runWrites(static function () use ($separate, $events): void {
            DB::transaction(static function () use ($separate, $events): void {
                foreach ($events as [$type, $payload]) {
                    WorkflowHistoryEvent::record($separate, $type, $payload);
                }
            });
        });

        $this->assertSame($this->storedHistory($separate), $this->storedHistory($shared));
        $this->assertSame($this->runPosition($separate->fresh()), $this->runPosition($shared->fresh()));
        $this->assertSame($this->runPosition($shared->fresh()), $this->runPosition($shared));
        $this->assertSame(2, (int) $shared->last_history_sequence);
        // One run row write for the pair instead of one per event.
        $this->assertSame(1, $sharedWrites);
        $this->assertSame(2, $separateWrites);
    }

    public function testANewChildRunsStartEventsShareOneAppend(): void
    {
        $this->freezeTime();
        $shared = $this->createRun();
        $separate = $this->createRun();
        $events = [
            [HistoryEventType::StartAccepted, [
                'workflow_class' => TestGreetingWorkflow::class,
                'workflow_type' => 'test-greeting-workflow',
            ]],
            [HistoryEventType::WorkflowStarted, [
                'workflow_class' => TestGreetingWorkflow::class,
                'workflow_type' => 'test-greeting-workflow',
            ]],
        ];

        DB::transaction(static fn () => WorkflowHistoryEvent::recordChildRunStartWhileRunLocked($shared, $events));
        DB::transaction(static function () use ($separate, $events): void {
            foreach ($events as [$type, $payload]) {
                WorkflowHistoryEvent::recordWhileRunLocked($separate, $type, $payload);
            }
        });

        $this->assertSame($this->storedHistory($separate), $this->storedHistory($shared));
        $this->assertSame($this->runPosition($separate->fresh()), $this->runPosition($shared->fresh()));
    }

    public function testOnlyChildStartEventsShareAnAppend(): void
    {
        $run = $this->createRun();

        try {
            WorkflowHistoryEvent::recordChildStart($run, [
                [HistoryEventType::ChildWorkflowScheduled, $this->childStartEvents()[0][1]],
                [HistoryEventType::WorkflowCompleted, []],
            ]);
            $this->fail('A terminal event shared a child start append.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('WorkflowCompleted', $exception->getMessage());
        }

        $this->assertSame(0, WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->count());
        $this->assertSame(0, (int) $run->fresh()?->last_history_sequence);
    }

    /**
     * @return list<array{HistoryEventType, array<string, mixed>}>
     */
    private function childStartEvents(): array
    {
        $identity = [
            'sequence' => 1,
            'workflow_link_id' => 'link-1',
            'child_call_id' => 'call-1',
            'child_workflow_instance_id' => 'child-instance-1',
            'child_workflow_run_id' => 'child-run-1',
            'child_workflow_class' => TestGreetingWorkflow::class,
            'child_workflow_type' => 'test-greeting-workflow',
            'parent_close_policy' => 'abandon',
            'cancellation_policy' => 'abandon',
        ];

        return [
            [HistoryEventType::ChildWorkflowScheduled, $identity],
            [HistoryEventType::ChildRunStarted, $identity + [
                'child_run_number' => 1,
            ]],
        ];
    }

    private function runWrites(callable $operation): int
    {
        $writes = 0;
        $listening = true;
        DB::listen(static function (QueryExecuted $query) use (&$writes, &$listening): void {
            if ($listening && preg_match('/^\s*update\s+.workflow_runs./i', $query->sql) === 1) {
                $writes++;
            }
        });
        $operation();
        $listening = false;

        return $writes;
    }

    /**
     * @return list<array{sequence: int, event_type: string, payload: mixed, indexed_step_sequence: mixed}>
     */
    private function storedHistory(WorkflowRun $run): array
    {
        return WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->orderBy('sequence')
            ->get()
            ->map(static fn (WorkflowHistoryEvent $event): array => [
                'sequence' => $event->sequence,
                'event_type' => $event->event_type->value,
                'payload' => $event->payload,
                'indexed_step_sequence' => $event->getAttribute('indexed_step_sequence'),
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function runPosition(?WorkflowRun $run): array
    {
        $this->assertInstanceOf(WorkflowRun::class, $run);

        $position = [
            'last_history_sequence' => (int) $run->last_history_sequence,
        ];
        foreach ([
            'activity_history_index_through',
            'step_history_index_through',
            'activity_open_index_through',
        ] as $column) {
            $value = $run->getAttribute($column);
            $position[$column] = $value === null ? null : (int) $value;
        }
        $position['last_progress_at'] = $run->last_progress_at?->toJSON();

        return $position;
    }

    private function createRun(): WorkflowRun
    {
        $instance = WorkflowInstance::query()->create([
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'run_count' => 1,
            'reserved_at' => now(),
            'started_at' => now(),
        ]);
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now(),
            'last_progress_at' => now(),
            'last_history_sequence' => 0,
        ]);
        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        return $run->fresh() ?? $run;
    }
}
