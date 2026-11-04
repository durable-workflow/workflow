<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Uid\Ulid;
use Tests\Fixtures\V2\TestUpdateReceiveOrdinalProofWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\MessageChannel;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowMessage;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\MessageService;
use Workflow\V2\WorkflowStub;

final class V2UpdateReceiveIdentityDiagnosticTest extends TestCase
{
    public function testLegacyMessageHistoryCannotIdentifyRepeatedReceiveOrdinal(): void
    {
        self::stopWorkers();
        Queue::fake();
        config(['workflows.v2.task_dispatch_mode' => 'poll']);
        Carbon::setTestNow('2026-11-04 12:00:00.000000');
        $this->beforeApplicationDestroyed(static function (): void {
            Carbon::setTestNow();
            Str::createUlidsNormally();
            TestUpdateReceiveOrdinalProofWorkflow::$arrival = null;
        });
        $observations = [];
        $snapshots = [];

        foreach ([0, 1] as $arrivalBeforeCall) {
            foreach (['workflow_run_summaries', 'workflow_run_timeline_entries', 'workflow_run_timer_entries',
                'workflow_run_lineage_entries', 'workflow_history_events', 'workflow_tasks', 'workflow_updates', 'workflow_commands',
                'workflow_messages', 'workflow_run_waits', 'workflow_runs', 'workflow_instances'] as $table) {
                DB::table($table)->delete();
            }
            DB::table('sqlite_sequence')->where('name', 'workflow_messages')->delete();
            $identity = 0;
            Str::createUlidsUsing(static function () use (&$identity): Ulid {
                return new Ulid(str_repeat('0', 20) . sprintf('%06X', ++$identity));
            });
            TestUpdateReceiveOrdinalProofWorkflow::$arrivalBeforeCall = $arrivalBeforeCall;
            TestUpdateReceiveOrdinalProofWorkflow::$observed = [];
            $stub = WorkflowStub::make(TestUpdateReceiveOrdinalProofWorkflow::class, 'legacy-ordinal-proof');
            $stub->start();
            $this->drainTasks();
            $run = WorkflowRun::query()->where('workflow_instance_id', 'legacy-ordinal-proof')->sole();
            TestUpdateReceiveOrdinalProofWorkflow::$arrival = static function () use ($run): void {
                (new MessageService())->sendMessage($run, MessageChannel::WorkflowMessage,
                    $run->workflow_instance_id, 'j1', 'chat');
            };
            $receipt = $stub->submitUpdate('take');
            $this->drainTasks();
            $this->assertSame('ack', $stub->inspectUpdate($receipt->updateId())->result());
            $observations[] = TestUpdateReceiveOrdinalProofWorkflow::$observed;
            $applied = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)
                ->where('event_type', HistoryEventType::UpdateApplied->value)->sole();
            $command = WorkflowCommand::query()->findOrFail($applied->workflow_command_id);
            $this->assertSame($command->id, $applied->payload['workflow_command_id']);
            $snapshot = [
                'command' => $command->only(['id', 'workflow_run_id', 'sequence', 'message_sequence', 'payload']),
                'update' => $applied->only(['id', 'workflow_run_id', 'sequence', 'payload', 'recorded_at']),
                'cursor_history' => WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)
                    ->where('sequence', '<', $applied->sequence)
                    ->where('event_type', HistoryEventType::MessageCursorAdvanced->value)
                    ->orderBy('sequence')->get()->map(static fn ($event): array =>
                        $event->only(['sequence', 'payload', 'recorded_at']))->all(),
                'messages' => WorkflowMessage::query()->orderBy('sequence')->get()
                    ->map(static fn ($message): array => $message->only(['id', 'workflow_run_id',
                        'direction', 'stream_key', 'sequence', 'payload_reference', 'consume_state',
                        'consumed_by_sequence', 'consumed_at', 'created_at']))->all(),
                'completed' => WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)
                    ->where('event_type', HistoryEventType::UpdateCompleted->value)->sole()
                    ->only(['sequence', 'payload', 'recorded_at']),
            ];
            $snapshots[] = json_decode(json_encode($snapshot, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
            Str::createUlidsNormally();
        }

        $this->assertSame([['j1', null], [null, 'j1']], $observations);
        $this->assertSame($snapshots[0], $snapshots[1]);
        file_put_contents('/results/identity-proof.json', json_encode([
            'live_receive_observations' => $observations,
            'durable_mapping_inputs' => $snapshots,
            'mapping_inputs_identical' => $snapshots[0] === $snapshots[1],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    private function drainTasks(): void
    {
        for ($turn = 0; $turn < 25; ++$turn) {
            $task = \Workflow\V2\Models\WorkflowTask::query()->where('status', TaskStatus::Ready->value)
                ->orderBy('created_at')->first();
            if ($task === null) {
                return;
            }
            $this->app->call([new RunWorkflowTask($task->id), 'handle']);
        }
        $this->fail('Identity diagnostic did not reach a stable wait.');
    }
}
