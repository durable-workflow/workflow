<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Queue;
use LogicException;
use Tests\Fixtures\V2\TestUndeclaredUpdateValidatorWorkflow;
use Tests\Fixtures\V2\TestValidatedUpdateWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Exceptions\UpdateValidatorCommandException;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Support\WorkflowDefinition;
use Workflow\V2\WorkflowStub;

final class V2UpdateValidatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        TestValidatedUpdateWorkflow::$validatorCalls = 0;
        TestValidatedUpdateWorkflow::$handlerCalls = 0;
    }

    public function testValidatorRejectionLeavesHistoryUnchangedAndNeverRunsTheHandler(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestValidatedUpdateWorkflow::class, 'validated-update-rejected');
        $workflow->start(2);
        $runId = $workflow->runId();
        $this->runReadyWorkflowTask($runId);

        $historyBefore = $this->historyRows($runId);
        $tasksBefore = WorkflowTask::query()->where('workflow_run_id', $runId)->count();

        $result = $workflow->attemptUpdate('add-item', '');

        $this->assertTrue($result->rejected());
        $this->assertTrue($result->rejectedByValidator());
        $this->assertFalse($result->accepted());
        $this->assertFalse($result->completed());
        $this->assertFalse($result->failed());
        $this->assertSame('update_validator_rejected', $result->rejectionReason());
        $this->assertSame('rejected_invalid_arguments', $result->outcome());
        $this->assertSame('rejected', $result->updateStatus());
        $this->assertSame([
            'item' => ['The item is required.'],
        ], $result->validationErrors());
        $this->assertSame('The item is required.', $result->rejectionMessage());
        $this->assertNull($result->failureMessage());

        $this->assertSame(1, TestValidatedUpdateWorkflow::$validatorCalls);
        $this->assertSame(0, TestValidatedUpdateWorkflow::$handlerCalls);

        // The replay-visible history is unchanged: the only new row is the
        // UpdateRejected audit entry every embedded rejection records.
        $historyAfter = $this->historyRows($runId);
        $this->assertSame($historyBefore, array_slice($historyAfter, 0, count($historyBefore)));
        $this->assertSame(['UpdateRejected'], array_column(array_slice($historyAfter, count($historyBefore)), 'type'));
        $this->assertSame(0, $this->eventCount($runId, ['UpdateAccepted', 'UpdateApplied', 'UpdateCompleted']));
        $this->assertSame($tasksBefore, WorkflowTask::query()->where('workflow_run_id', $runId)->count());

        $rejected = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', 'UpdateRejected')
            ->firstOrFail();
        $this->assertSame($result->updateId(), $rejected->payload['update_id']);
        $this->assertSame([
            'item' => ['The item is required.'],
        ], $rejected->payload['validation_errors']);

        /** @var WorkflowCommand $command */
        $command = WorkflowCommand::query()->findOrFail($result->commandId());
        $payload = Serializer::unserializeWithCodec($command->payload_codec, $command->payload);
        $this->assertSame('The item is required.', $payload['rejection_message']);
        $this->assertSame(\Illuminate\Validation\ValidationException::class, $payload['rejection_exception_type']);

        $this->assertDatabaseHas('workflow_updates', [
            'id' => $result->updateId(),
            'status' => 'rejected',
            'outcome' => 'rejected_invalid_arguments',
            'rejection_reason' => 'update_validator_rejected',
            'failure_message' => 'The item is required.',
        ]);

        $this->assertSame([
            'capacity' => 2,
            'items' => [],
            'validator_touched_state' => false,
        ], $workflow->cart());

        try {
            $workflow->update('add-item', '');
            $this->fail('A validator rejection must throw from update().');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('update_validator_rejected', $exception->getMessage());
            $this->assertStringContainsString('The item is required', $exception->getMessage());
        }

        $this->assertSame(0, TestValidatedUpdateWorkflow::$handlerCalls);
    }

    public function testApprovedUpdateProceedsAndLaterValidationReadsReplayedState(): void
    {
        $workflow = WorkflowStub::make(TestValidatedUpdateWorkflow::class, 'validated-update-accepted');
        $workflow->start(2);

        $this->waitForWorkflow(
            $workflow,
            static fn (WorkflowStub $workflow): bool => $workflow->refresh()
                ->status() === 'waiting',
            'the validated update workflow to begin waiting',
        );

        $first = $workflow->attemptUpdate('add-item', 'apple');

        $this->assertTrue($first->accepted());
        $this->assertTrue($first->completed());
        $this->assertSame(['apple'], $first->result());
        $this->assertNull($first->rejectionMessage());
        $this->assertFalse($first->rejectedByValidator());
        // The validator runs in the caller before acceptance; the handler
        // runs on the worker that applies the accepted update.
        $this->assertSame(1, TestValidatedUpdateWorkflow::$validatorCalls);

        $this->assertSame(['apple', 'banana'], $workflow->update('add-item', 'banana'));
        $this->assertSame(2, TestValidatedUpdateWorkflow::$validatorCalls);

        // The validator sees the state rebuilt from history, including the two
        // applied updates, and refuses a third item.
        $third = $workflow->attemptUpdate('add-item', 'cherry');

        $this->assertTrue($third->rejectedByValidator());
        $this->assertSame('The cart already holds 2 items.', $third->rejectionMessage());
        $this->assertSame([], $third->validationErrors());
        $this->assertSame(3, TestValidatedUpdateWorkflow::$validatorCalls);

        // The same history gives the same decision.
        $again = $workflow->attemptUpdate('add-item', 'cherry');
        $this->assertTrue($again->rejectedByValidator());
        $this->assertSame('The cart already holds 2 items.', $again->rejectionMessage());

        $this->assertSame(['apple', 'banana'], $workflow->cart()['items']);
        $this->assertFalse($workflow->cart()['validator_touched_state']);
        $this->assertSame(2, $this->eventCount($workflow->runId(), ['UpdateAccepted']));
        $this->assertSame(2, $this->eventCount($workflow->runId(), ['UpdateRejected']));

        $workflow->signal('close');

        $this->waitForWorkflow(
            $workflow,
            static fn (WorkflowStub $workflow): bool => $workflow->refresh()
                ->completed(),
            'the validated update workflow to complete',
        );

        $this->assertSame(['apple', 'banana'], $workflow->output());
    }

    public function testValidatorThatSchedulesWorkIsRefusedAndSchedulesNothing(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestValidatedUpdateWorkflow::class, 'validated-update-scheduling');
        $workflow->start(2);
        $runId = $workflow->runId();
        $this->runReadyWorkflowTask($runId);

        $historyBefore = $this->historyRows($runId);
        $tasksBefore = WorkflowTask::query()->where('workflow_run_id', $runId)->count();

        $activity = $workflow->attemptUpdate('add-item', 'schedule-activity');

        $this->assertTrue($activity->rejectedByValidator());
        $this->assertSame(
            'Update validator for [add-item] cannot schedule durable work [ActivityCall]; update validators are read-only.',
            $activity->rejectionMessage(),
        );

        $timer = $workflow->attemptUpdate('add-item', 'start-timer');

        $this->assertTrue($timer->rejectedByValidator());
        $this->assertStringContainsString('[TimerCall]', (string) $timer->rejectionMessage());

        /** @var WorkflowCommand $command */
        $command = WorkflowCommand::query()->findOrFail($timer->commandId());
        $payload = Serializer::unserializeWithCodec($command->payload_codec, $command->payload);
        $this->assertSame(UpdateValidatorCommandException::class, $payload['rejection_exception_type']);

        $this->assertSame(0, ActivityExecution::query()->where('workflow_run_id', $runId)->count());
        $this->assertSame(0, WorkflowTimer::query()->where('workflow_run_id', $runId)->count());
        $this->assertSame($tasksBefore, WorkflowTask::query()->where('workflow_run_id', $runId)->count());
        $this->assertSame(0, TestValidatedUpdateWorkflow::$handlerCalls);

        $historyAfter = $this->historyRows($runId);
        $this->assertSame($historyBefore, array_slice($historyAfter, 0, count($historyBefore)));
        $this->assertSame(
            ['UpdateRejected', 'UpdateRejected'],
            array_column(array_slice($historyAfter, count($historyBefore)), 'type'),
        );
    }

    public function testValidatorReturningFalseRejectsTheUpdate(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestValidatedUpdateWorkflow::class, 'validated-update-false');
        $workflow->start(2);
        $this->runReadyWorkflowTask($workflow->runId());

        $result = $workflow->attemptUpdate('add-item', 'refuse');

        $this->assertTrue($result->rejectedByValidator());
        $this->assertSame('Update validator for [add-item] returned false.', $result->rejectionMessage());
        $this->assertSame(0, TestValidatedUpdateWorkflow::$handlerCalls);
        $this->assertSame(0, $this->eventCount($workflow->runId(), ['UpdateAccepted']));
    }

    public function testValidatorsAreDeclaredByUpdateNameOrMethod(): void
    {
        $this->assertSame(
            [
                'add-item' => 'validateAddItem',
            ],
            WorkflowDefinition::updateValidators(TestValidatedUpdateWorkflow::class),
        );
        $this->assertSame(
            'validateAddItem',
            WorkflowDefinition::updateValidator(TestValidatedUpdateWorkflow::class, 'add-item'),
        );
        $this->assertNull(WorkflowDefinition::updateValidator(TestValidatedUpdateWorkflow::class, 'addItem'));
    }

    public function testValidatorForUndeclaredUpdateIsADefinitionError(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'declares update validator [validateMissing] for undeclared update [missing]'
        );

        WorkflowDefinition::updateValidators(TestUndeclaredUpdateValidatorWorkflow::class);
    }

    public function testWorkflowWithUndeclaredValidatorTargetCannotStart(): void
    {
        Queue::fake();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('for undeclared update [missing]');

        WorkflowStub::make(TestUndeclaredUpdateValidatorWorkflow::class, 'undeclared-validator')->start();
    }

    /**
     * @return list<array{sequence: int, type: string, payload: mixed}>
     */
    private function historyRows(string $runId): array
    {
        return WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->orderBy('sequence')
            ->get()
            ->map(static fn (WorkflowHistoryEvent $event): array => [
                'sequence' => (int) $event->sequence,
                'type' => $event->event_type->value,
                'payload' => $event->payload,
            ])
            ->values()
            ->all();
    }

    /**
     * @param list<string> $types
     */
    private function eventCount(string $runId, array $types): int
    {
        return WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->whereIn('event_type', $types)
            ->count();
    }

    private function runReadyWorkflowTask(string $runId): void
    {
        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->orderBy('created_at')
            ->firstOrFail();

        $this->app->call([new RunWorkflowTask($task->id), 'handle']);
    }
}
