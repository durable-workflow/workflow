<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestRepeatedSignalSelectionWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\WorkflowStub;

final class SelectionSignalOccurrenceReplayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-01-01T00:00:00Z');
        config()
            ->set('queue.default', 'database');
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public static function signalOrders(): iterable
    {
        yield 'earlier then later' => ['earlier', 'later'];
        yield 'later then earlier' => ['later', 'earlier'];
    }

    #[DataProvider('signalOrders')]
    public function testALaterSelectionSignalCannotBeConsumedByAnEarlierCancelledMember(
        string $first,
        string $second
    ): void {
        $workflow = WorkflowStub::make(TestRepeatedSignalSelectionWorkflow::class);
        $workflow->start();
        $runId = $workflow->runId();
        $this->assertIsString($runId);
        $this->runTask($runId);
        $workflow->withRequestId('first-selection')
            ->signal($first);
        $this->runTask($runId);
        $prefix = WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)->orderBy('sequence')->get();
        $latestWait = $prefix->filter(static fn (WorkflowHistoryEvent $event): bool =>
            $event->event_type === HistoryEventType::SignalWaitOpened && $event->payload['signal_name'] === $second)->last();
        $this->assertInstanceOf(WorkflowHistoryEvent::class, $latestWait);
        $original = $prefix->map(static fn (WorkflowHistoryEvent $event): array => $event->getRawOriginal())
            ->all();
        $workflow->withRequestId('second-selection')
            ->signal($second);
        $this->runTask($runId);
        $this->assertTrue(
            $workflow->refresh()
                ->completed(),
            'A cancelled earlier member must not steal the later signal.'
        );
        $this->assertSame([$first, $second], $workflow->output());
        $applied = WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::SignalApplied)->orderByDesc('sequence')->firstOrFail();
        $this->assertSame($latestWait->payload['sequence'], $applied->payload['sequence']);
        $this->assertSame($latestWait->payload['signal_wait_id'], $applied->payload['signal_wait_id']);
        $this->assertSame($original, WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)
            ->where('sequence', '<=', count($original))
            ->orderBy('sequence')
            ->get()
            ->map(static fn (WorkflowHistoryEvent $event): array => $event->getRawOriginal())
            ->all());
        $this->assertSame(
            2,
            WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)->where(
                'event_type',
                HistoryEventType::SignalApplied
            )->count()
        );
        $this->assertSame(
            0,
            WorkflowTask::query()->where('workflow_run_id', $runId)->where('status', TaskStatus::Failed)->count()
        );
    }

    #[DataProvider('signalOrders')]
    public function testACorruptedRecordedWinnerFailsBeforeConsumingAnotherSignal(string $first, string $second): void
    {
        $workflow = WorkflowStub::make(TestRepeatedSignalSelectionWorkflow::class);
        $workflow->start();
        $runId = $workflow->runId();
        $this->assertIsString($runId);
        $this->runTask($runId);
        $workflow->withRequestId('original-winner')
            ->signal($first);
        $this->runTask($runId);
        $marker = WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::SelectionResolved)->firstOrFail();
        $payload = $marker->payload;
        $payload['member_key'] = 'not-an-authored-member';
        $marker->update([
            'payload' => $payload,
        ]);
        $applied = WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::SignalApplied)->orderBy('sequence')->pluck('id')->all();
        $this->assertCount(1, $applied);
        $workflow->withRequestId('signal-after-corrupted-winner')
            ->signal($second);
        $this->runTask($runId);
        $this->assertSame('waiting', $workflow->refresh()->run()->status->value);
        $blocked = WorkflowTask::query()->where('workflow_run_id', $runId)->where('status', TaskStatus::Failed)->sole();
        $this->assertTrue($blocked->payload['replay_blocked']);
        $this->assertSame('history_shape_mismatch', $blocked->payload['replay_blocked_reason']);
        $this->assertSame($payload['member_base_sequence'], $blocked->payload['replay_blocked_workflow_sequence']);
        $this->assertSame(
            [HistoryEventType::SelectionResolved->value],
            $blocked->payload['replay_blocked_recorded_event_types']
        );
        $this->assertSame($applied, WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::SignalApplied)->orderBy('sequence')->pluck('id')->all());
    }

    private function runTask(string $runId): void
    {
        $task = WorkflowTask::query()->where('workflow_run_id', $runId)->where('task_type', TaskType::Workflow)
            ->where('status', TaskStatus::Ready)->orderBy('created_at')->firstOrFail();
        $this->app->call([new RunWorkflowTask($task->id), 'handle']);
    }
}
