<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestQueryContinueAsNewWorkflow;
use Tests\Fixtures\V2\TestUpdateWorkflow;
use Tests\TestCase;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowUpdate;
use Workflow\V2\Support\WorkerCompatibilityFleet;
use Workflow\V2\Webhooks;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;

final class WebhookRunControlTest extends TestCase
{
    private WorkflowRun $run;

    private WorkflowTask $task;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-08T01:00:00Z');
        config([
            'workflows.webhook_auth.method' => 'none',
            'workflows.v2.task_dispatch_mode' => 'poll',
        ]);
        Queue::fake();
        WorkerCompatibilityFleet::clear();
        WebhookControlProbeWorkflow::$calls = 0;
        Webhooks::routes([
            WebhookControlProbeWorkflow::class,
            TestUpdateWorkflow::class,
            TestQueryContinueAsNewWorkflow::class,
        ], '/control');

        $workflow = WorkflowStub::make(WebhookControlProbeWorkflow::class, 'control-existing');
        $this->assertTrue($workflow->attemptStart('Existing')->accepted());
        $this->run = WorkflowRun::query()->findOrFail($workflow->runId());
        $this->task = WorkflowTask::query()->where('workflow_run_id', $this->run->id)->firstOrFail();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        WorkerCompatibilityFleet::clear();
        WebhookControlProbeWorkflow::$calls = 0;

        parent::tearDown();
    }

    #[DataProvider('terminalControls')]
    public function testSelectedRunTerminalControlRecordsItsReasonAndRejectsRedeliveryAfterClosing(
        string $operation,
        string $outcome,
        string $requestedEvent,
        string $terminalEvent,
        mixed $reason,
        ?string $expectedReason,
    ): void {
        $path = '/control/instances/control-existing/runs/' . $this->run->id . '/' . $operation;
        $first = $this->postJson($path, [
            'reason' => $reason,
        ], [
            'X-Request-Id' => 'selected-terminal-first',
        ]);
        $first->assertOk()
            ->assertJsonPath('outcome', $outcome)
            ->assertJsonPath('target_scope', 'run')
            ->assertJsonPath('requested_run_id', $this->run->id)
            ->assertJsonPath('resolved_run_id', $this->run->id)
            ->assertJsonPath('command_status', 'accepted')
            ->assertJsonPath('reason', $expectedReason);

        $command = WorkflowCommand::query()->findOrFail($first->json('command_id'));
        $this->assertSame($expectedReason, $command->commandReason());
        $this->assertSame($path, $command->requestPath());
        $this->assertSame('workflows.v2.runs.' . $operation, $command->requestRouteName());
        $this->assertSame($outcome, $this->run->fresh()->status->value);
        $this->assertSame($outcome, $this->run->fresh()->closed_reason);
        $this->assertNotNull($this->run->fresh()->closed_at);
        $this->assertSame(TaskStatus::Cancelled, $this->task->fresh()->status);

        $history = WorkflowHistoryEvent::query()->where('workflow_command_id', $command->id)->orderBy(
            'sequence'
        )->get();
        $this->assertSame(
            [$requestedEvent, $terminalEvent],
            $history->map(static fn (WorkflowHistoryEvent $event): string => $event->event_type->value)
                ->all()
        );
        foreach ($history as $event) {
            $this->assertSame($expectedReason, $event->payload['reason'] ?? null);
            $this->assertSame($command->id, $event->payload['workflow_command_id']);
        }
        $beforeHistory = $this->rows(WorkflowHistoryEvent::class);
        $beforeTasks = $this->rows(WorkflowTask::class);
        $beforeFailures = $this->rows(WorkflowFailure::class);
        $beforeRun = $this->run->fresh()
            ->getRawOriginal();

        $second = $this->postJson($path, [
            'reason' => 'later request',
        ]);
        $second->assertStatus(409)
            ->assertJsonPath('outcome', 'rejected_not_active')
            ->assertJsonPath('rejection_reason', 'run_not_active')
            ->assertJsonPath('target_scope', 'run')
            ->assertJsonPath('run_id', $this->run->id)
            ->assertJsonPath('command_status', 'rejected');
        $this->assertNotSame($first->json('command_id'), $second->json('command_id'));
        $this->assertSame($first->json('command_sequence') + 1, $second->json('command_sequence'));
        $afterRun = $this->run->fresh()
            ->getRawOriginal();
        $this->assertSame((int) $beforeRun['last_command_sequence'] + 1, (int) $afterRun['last_command_sequence']);
        unset($beforeRun['last_command_sequence'], $afterRun['last_command_sequence']);
        $this->assertSame($beforeRun, $afterRun);
        $this->assertSame($beforeHistory, $this->rows(WorkflowHistoryEvent::class));
        $this->assertSame($beforeTasks, $this->rows(WorkflowTask::class));
        $this->assertSame($beforeFailures, $this->rows(WorkflowFailure::class));
        $this->assertSame(0, WebhookControlProbeWorkflow::$calls);
        Queue::assertNothingPushed();
    }

    /**
     * @return iterable<string, array{string, string, string, string, mixed, ?string}>
     */
    public static function terminalControls(): iterable
    {
        foreach ([
            ['cancel', 'cancelled', 'CancelRequested', 'WorkflowCancelled'],
            ['terminate', 'terminated', 'TerminateRequested', 'WorkflowTerminated'],
        ] as [$operation, $outcome, $requested, $terminal]) {
            foreach ([
                'string reason' => 'Customer requested closure',
                'non-string reason' => 42,
                'null reason' => null,
            ] as $name => $reason) {
                yield $operation . ' ' . $name => [
                    $operation,
                    $outcome,
                    $requested,
                    $terminal,
                    $reason,
                    is_string($reason) ? $reason : null,
                ];
            }
        }
    }

    #[DataProvider('historicalControls')]
    public function testHistoricalRunControlRecordsTheRejectionWithoutChangingTheNewerExecution(string $operation): void
    {
        $workflow = WorkflowStub::make(TestQueryContinueAsNewWorkflow::class, 'continued-owner');
        $workflow->start(0, 1);
        $historicalId = $workflow->runId();
        $this->runReadyTask($historicalId);
        $currentId = $workflow->refresh()
            ->runId();
        $this->assertNotSame($historicalId, $currentId);
        $historical = WorkflowRun::query()->findOrFail($historicalId);
        $this->assertSame(RunStatus::Completed, $historical->status);
        $this->assertSame('continued', $historical->closed_reason);
        $beforeCurrent = $this->currentExecution($currentId);
        $beforeHistory = $this->rows(WorkflowHistoryEvent::class);
        $beforeTasks = $this->rows(WorkflowTask::class);
        $sequence = $historical->last_command_sequence;
        Queue::fake();
        $path = '/control/instances/continued-owner/runs/' . $historicalId . '/' . $operation;

        foreach ([1, 2] as $delivery) {
            $response = $this->postJson($path, [
                'reason' => 'Must preserve successor',
            ]);
            $response->assertStatus(409)
                ->assertJsonPath('outcome', 'rejected_not_current')
                ->assertJsonPath('rejection_reason', 'selected_run_not_current')
                ->assertJsonPath('target_scope', 'run')
                ->assertJsonPath('requested_run_id', $historicalId)
                ->assertJsonPath('resolved_run_id', $currentId)
                ->assertJsonPath('command_status', 'rejected')
                ->assertJsonPath('command_sequence', $sequence + $delivery);
            $command = WorkflowCommand::query()->findOrFail($response->json('command_id'));
            $this->assertSame($historicalId, $command->workflow_run_id);
            $this->assertSame($historicalId, $command->requestedRunId());
            $this->assertSame($currentId, $command->resolvedRunId());
            $this->assertSame($path, $command->requestPath());
            $this->assertSame('workflows.v2.runs.' . $operation, $command->requestRouteName());
            $this->assertSame($beforeCurrent, $this->currentExecution($currentId));
            $this->assertSame($beforeHistory, $this->rows(WorkflowHistoryEvent::class));
            $this->assertSame($beforeTasks, $this->rows(WorkflowTask::class));
            Queue::assertNothingPushed();
        }
        $this->assertSame(0, WebhookControlProbeWorkflow::$calls);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function historicalControls(): iterable
    {
        foreach (['repair', 'cancel', 'terminate'] as $operation) {
            yield $operation => [$operation];
        }
    }

    public function testSelectedWaitingRunRepairRecordsAnAcceptedNoWorkResultWithoutReplaying(): void
    {
        $workflow = WorkflowStub::make(TestUpdateWorkflow::class, 'waiting-owner');
        $workflow->start();
        $this->runReadyTask($workflow->runId());
        $this->assertSame('waiting', $workflow->refresh()->status());
        $beforeTasks = $this->rows(WorkflowTask::class);
        $beforeHistory = $this->rows(WorkflowHistoryEvent::class);
        Queue::fake();

        foreach ([1, 2] as $delivery) {
            $response = $this->postJson('/control/instances/waiting-owner/runs/' . $workflow->runId() . '/repair');
            $response->assertOk()
                ->assertJsonPath('outcome', 'repair_not_needed')
                ->assertJsonPath('target_scope', 'run')
                ->assertJsonPath('run_id', $workflow->runId())
                ->assertJsonPath('command_status', 'accepted');
            $command = WorkflowCommand::query()->findOrFail($response->json('command_id'));
            $this->assertSame('workflows.v2.runs.repair', $command->requestRouteName());
            $this->assertSame($beforeTasks, $this->rows(WorkflowTask::class));
            $originalHistory = WorkflowHistoryEvent::query()->whereIn('id', array_column($beforeHistory, 'id'))
                ->orderBy('id')
                ->get()
                ->map(static fn (Model $row): array => $row->getRawOriginal())
                ->all();
            $this->assertSame($beforeHistory, $originalHistory);
            $this->assertSame(count($beforeHistory) + $delivery, WorkflowHistoryEvent::query()->count());
            $event = WorkflowHistoryEvent::query()->where('workflow_command_id', $command->id)->sole();
            $this->assertSame('RepairRequested', $event->event_type->value);
            $this->assertSame('repair_not_needed', $event->payload['outcome']);
            $this->assertSame('waiting_for_signal', $event->payload['liveness_state']);
            $this->assertNull($event->payload['task_id']);
            $this->assertSame('waiting', $workflow->refresh()->status());
            Queue::assertNothingPushed();
        }
    }

    #[DataProvider('missingUpdates')]
    public function testUpdateLookupReportsItsScopeWithoutReadingAnotherOwnersUpdateOrMutatingRecords(
        bool $selected,
        bool $foreign,
    ): void {
        $other = WorkflowStub::make(TestUpdateWorkflow::class, 'other-update-owner');
        $other->start();
        $accepted = $other->submitUpdate('approve', true, 'foreign owner');
        $this->assertSame('accepted', $accepted->updateStatus());
        $updateId = $foreign ? $accepted->updateId() : 'missing-update';
        $scope = $selected ? 'run [' . $this->run->id . ']' : 'workflow instance [control-existing]';
        $path = '/control/instances/control-existing'
            . ($selected ? '/runs/' . $this->run->id : '')
            . '/updates/' . $updateId;
        $before = $this->records();
        $writes = [];
        DB::listen(static function (QueryExecuted $event) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace|truncate|alter|create|drop)\b/i', $event->sql) === 1) {
                $writes[] = $event->sql;
            }
        });
        Queue::fake();

        foreach ([1, 2] as $_) {
            $this->getJson($path)
                ->assertStatus(404)
                ->assertExactJson([
                    'message' => 'Update [' . $updateId . '] was not found for ' . $scope . '.',
                ]);
            $this->assertSame($before, $this->records());
            $this->assertSame([], $writes);
            Queue::assertNothingPushed();
        }
    }

    /**
     * @return iterable<string, array{bool, bool}>
     */
    public static function missingUpdates(): iterable
    {
        foreach ([false, true] as $selected) {
            yield ($selected ? 'selected run' : 'instance') . ' missing update' => [$selected, false];
            yield ($selected ? 'selected run' : 'instance') . ' foreign update' => [$selected, true];
        }
    }

    /**
     * @param list<mixed> $arguments
     */
    #[DataProvider('selectedUpdateOutcomes')]
    public function testSelectedUpdateCanBeInspectedBeforeAndAfterItsRecordedOutcomeWithoutMutation(
        string $method,
        array $arguments,
        string $status,
        string $outcome,
    ): void {
        $workflow = WorkflowStub::make(TestUpdateWorkflow::class, 'selected-update-owner');
        $workflow->start();
        $this->runReadyTask($workflow->runId());
        $this->assertSame('waiting', $workflow->refresh()->status());
        Queue::fake();
        $path = '/control/instances/selected-update-owner/runs/' . $workflow->runId() . '/updates/';
        $accepted = $this->postJson($path . $method, [
            'wait_for' => 'accepted',
            'arguments' => $arguments,
        ]);
        $accepted->assertStatus(202)
            ->assertJsonPath('update_status', 'accepted')
            ->assertJsonPath('target_scope', 'run')
            ->assertJsonPath('requested_run_id', $workflow->runId())
            ->assertJsonPath('resolved_run_id', $workflow->runId());
        $command = WorkflowCommand::query()->findOrFail($accepted->json('command_id'));
        $this->assertSame('workflows.v2.runs.update', $command->requestRouteName());
        $lookup = $path . $accepted->json('update_id');

        foreach (['accepted', $status] as $phase) {
            if ($phase !== 'accepted') {
                $this->runReadyTask($workflow->runId());
            }
            $before = $this->records();
            foreach ([1, 2] as $_) {
                $response = $this->getJson($lookup);
                $response->assertStatus($phase === 'accepted' ? 202 : 200)
                    ->assertJsonPath('update_status', $phase)
                    ->assertJsonPath('update_id', $accepted->json('update_id'))
                    ->assertJsonPath('command_id', $command->id)
                    ->assertJsonPath('requested_run_id', $workflow->runId());
                if ($phase !== 'accepted') {
                    $response->assertJsonPath('outcome', $outcome);
                    if ($status === 'failed') {
                        $response->assertJsonPath('failure_message', 'selected update failure');
                        $this->assertIsString($response->json('failure_id'));
                    } else {
                        $response->assertJsonPath('result.approved', true)
                            ->assertJsonPath('failure_id', null);
                    }
                }
                $this->assertSame($before, $this->records());
                Queue::assertNothingPushed();
            }
        }
        $this->assertSame('waiting', $workflow->refresh()->status());
        $this->assertSame(0, WebhookControlProbeWorkflow::$calls);
    }

    /**
     * @return iterable<string, array{string, list<mixed>, string, string}>
     */
    public static function selectedUpdateOutcomes(): iterable
    {
        yield 'completed update' => ['approve', [true, 'selected owner'], 'completed', 'update_completed'];
        yield 'failed update' => ['explode', ['selected update failure'], 'failed', 'update_failed'];
    }

    private function runReadyTask(string $runId): void
    {
        $task = WorkflowTask::query()->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)->firstOrFail();
        $this->app->call([new RunWorkflowTask($task->id), 'handle']);
    }

    /**
     * @return array<string, mixed>
     */
    private function currentExecution(string $runId): array
    {
        $run = WorkflowRun::query()->findOrFail($runId);

        return [
            'run' => $run->getRawOriginal(),
            'instance' => WorkflowInstance::query()->findOrFail($run->workflow_instance_id)->getRawOriginal(),
            'commands' => WorkflowCommand::query()->where('workflow_run_id', $runId)->orderBy('id')->get()->map(
                static fn (Model $row): array => $row->getRawOriginal()
            )->all(),
        ];
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function records(): array
    {
        $records = [];
        foreach ([
            WorkflowInstance::class,
            WorkflowRun::class,
            WorkflowTask::class,
            WorkflowCommand::class,
            WorkflowHistoryEvent::class,
            WorkflowFailure::class,
            WorkflowRunSummary::class,
            WorkflowUpdate::class,
        ] as $model) {
            $records[$model] = $this->rows($model);
        }

        return $records;
    }

    /**
     * @param class-string<Model> $model
     * @return list<array<string, mixed>>
     */
    private function rows(string $model): array
    {
        return $model::query()->orderBy('id')->get()->map(
            static fn (Model $row): array => $row->getRawOriginal()
        )->all();
    }
}

#[Type('webhook-control-probe')]
final class WebhookControlProbeWorkflow extends Workflow
{
    public static int $calls = 0;

    public function handle(string $name): string
    {
        ++self::$calls;

        return $name;
    }
}
