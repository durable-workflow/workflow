<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTimelineEntry;
use Workflow\V2\Support\ConditionWaits;
use Workflow\V2\Support\RunTimelineProjector;
use Workflow\V2\Support\WorkflowStepHistory;

final class CompletionHistoryScopedReadsTest extends TestCase
{
    public function testCompletionReadsOnlyRelevantHistoryWithoutHydratingTheRun(): void
    {
        $instance = WorkflowInstance::query()->create([
            'workflow_class' => 'TestWorkflow',
            'workflow_type' => 'test-workflow',
            'reserved_at' => now(),
            'run_count' => 1,
        ]);
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'TestWorkflow',
            'workflow_type' => 'test-workflow',
            'status' => 'running',
        ]);
        $this->historyEvent($run, 1, HistoryEventType::ConditionWaitOpened, [
            'condition_wait_id' => 'wait-1',
            'condition_key' => 'ready',
            'sequence' => 1,
        ]);

        for ($sequence = 2; $sequence <= 101; $sequence++) {
            $this->historyEvent($run, $sequence, HistoryEventType::SignalReceived, [
                'signal_name' => 'append',
            ]);
        }

        $this->historyEvent($run, 102, HistoryEventType::ConditionWaitSatisfied, [
            'condition_wait_id' => 'wait-1',
            'condition_key' => 'ready',
            'sequence' => 1,
        ]);

        $this->assertFalse($run->relationLoaded('historyEvents'));
        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();

        $this->assertSame(2, WorkflowStepHistory::nextDurableCommandSequence($run));
        $boundedWaits = ConditionWaits::forRun($run);

        $this->assertFalse($run->relationLoaded('historyEvents'));
        $this->assertCount(1, $boundedWaits);
        $this->assertSame('resolved', $boundedWaits[0]['status']);
        $this->assertSame('satisfied', $boundedWaits[0]['source_status']);

        $historySelects = array_values(array_filter(
            DB::connection()->getQueryLog(),
            static fn (array $query): bool => str_contains($query['query'], 'workflow_history_events'),
        ));
        $this->assertCount(2, $historySelects);
        foreach ($historySelects as $query) {
            $this->assertStringContainsString('event_type', $query['query']);
        }

        $loadedRun = $run->fresh(['historyEvents']);
        $this->assertNotNull($loadedRun);
        $this->assertSame(2, WorkflowStepHistory::nextDurableCommandSequence($loadedRun));
        $this->assertEquals(ConditionWaits::forRun($loadedRun), $boundedWaits);

        $this->assertSame([], RunTimelineProjector::project($run, collectRows: false));
        $this->assertSame(102, WorkflowTimelineEntry::query()->where('workflow_run_id', $run->id)->count());
    }

    public function testOlderActiveProjectionCannotPruneNewerTimelineRows(): void
    {
        $instance = WorkflowInstance::query()->create([
            'workflow_class' => 'TestWorkflow',
            'workflow_type' => 'test-workflow',
            'reserved_at' => now(),
            'run_count' => 1,
        ]);
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'TestWorkflow',
            'workflow_type' => 'test-workflow',
            'status' => 'waiting',
        ]);
        $this->historyEvent($run, 1, HistoryEventType::WorkflowStarted, []);
        $olderSnapshot = $run->fresh(['historyEvents']);
        $this->assertNotNull($olderSnapshot);

        $this->historyEvent($run, 2, HistoryEventType::SignalReceived, [
            'signal_name' => 'append',
        ]);
        RunTimelineProjector::project($run->fresh());
        $this->assertSame(2, WorkflowTimelineEntry::query()->where('workflow_run_id', $run->id)->count());

        RunTimelineProjector::project($olderSnapshot);
        $this->assertSame(2, WorkflowTimelineEntry::query()->where('workflow_run_id', $run->id)->count());

        $run->forceFill([
            'status' => 'completed',
        ])->save();
        WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->where('sequence', 2)->delete();
        RunTimelineProjector::project($run->fresh());
        $this->assertSame(1, WorkflowTimelineEntry::query()->where('workflow_run_id', $run->id)->count());
    }

    public function testOlderActiveProjectionCannotPruneCompletedRunTimelineRows(): void
    {
        $instance = WorkflowInstance::query()->create([
            'workflow_class' => 'TestWorkflow',
            'workflow_type' => 'test-workflow',
            'reserved_at' => now(),
            'run_count' => 1,
        ]);
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'TestWorkflow',
            'workflow_type' => 'test-workflow',
            'status' => 'waiting',
        ]);
        $this->historyEvent($run, 1, HistoryEventType::WorkflowStarted, []);
        $olderSnapshot = $run->fresh(['historyEvents']);
        $this->assertNotNull($olderSnapshot);

        $this->historyEvent($run, 2, HistoryEventType::WorkflowCompleted, []);
        $run->forceFill([
            'status' => 'completed',
        ])->save();
        RunTimelineProjector::project($run->fresh());
        $this->assertSame(2, WorkflowTimelineEntry::query()->where('workflow_run_id', $run->id)->count());

        RunTimelineProjector::project($olderSnapshot);
        $this->assertSame(2, WorkflowTimelineEntry::query()->where('workflow_run_id', $run->id)->count());
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function historyEvent(WorkflowRun $run, int $sequence, HistoryEventType $type, array $payload): void
    {
        WorkflowHistoryEvent::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => $sequence,
            'event_type' => $type->value,
            'payload' => $payload,
            'recorded_at' => now(),
        ]);
    }
}
