<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use LogicException;
use Tests\Fixtures\V2\TestGreetingActivity;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\Fixtures\V2\TestSignalWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\CommandOutcome;
use Workflow\V2\Enums\CommandStatus;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Enums\DuplicateStartPolicy;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\StartOptions;
use Workflow\V2\WorkflowStub;

final class V2DuplicateStartPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()
            ->set('queue.default', 'sync');
        config()
            ->set('queue.connections.sync.driver', 'sync');
    }

    public function testRejectDuplicateThrowsOnSecondStart(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello, Taylor!');

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'dup-reject');
        $workflow->start('Taylor');

        $this->assertTrue($workflow->refresh()->completed());

        $second = WorkflowStub::load('dup-reject');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('has already started');

        $second->start('Taylor');
    }

    public function testRejectDuplicateAttemptStartReturnsRejectedResult(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello, Taylor!');

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'dup-reject-attempt');
        $result = $workflow->start('Taylor');

        $this->assertTrue($result->startedNew());
        $this->assertTrue($result->accepted());
        $this->assertFalse($result->rejected());
        $this->assertFalse($result->returnedExistingActive());
        $this->assertFalse($result->rejectedDuplicate());

        $second = WorkflowStub::load('dup-reject-attempt');
        $secondResult = $second->attemptStart('Taylor');

        $this->assertTrue($secondResult->rejected());
        $this->assertTrue($secondResult->rejectedDuplicate());
        $this->assertFalse($secondResult->accepted());
        $this->assertFalse($secondResult->startedNew());
        $this->assertFalse($secondResult->returnedExistingActive());
        $this->assertSame(CommandOutcome::RejectedDuplicate->value, $secondResult->outcome());
        $this->assertSame('instance_already_started', $secondResult->rejectionReason());
    }

    public function testRejectDuplicateRecordsDurableCommandAndHistoryEvent(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello, Taylor!');

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'dup-reject-history');
        $workflow->start('Taylor');

        $second = WorkflowStub::load('dup-reject-history');
        $second->attemptStart('Taylor');

        $commands = WorkflowCommand::query()
            ->where('workflow_instance_id', 'dup-reject-history')
            ->where('command_type', CommandType::Start->value)
            ->orderBy('created_at')
            ->get();

        $this->assertCount(2, $commands);

        $firstCommand = $commands->first();
        $this->assertSame(CommandStatus::Accepted, $firstCommand->status);
        $this->assertSame(CommandOutcome::StartedNew, $firstCommand->outcome);

        $secondCommand = $commands->last();
        $this->assertSame(CommandStatus::Rejected, $secondCommand->status);
        $this->assertSame(CommandOutcome::RejectedDuplicate, $secondCommand->outcome);
        $this->assertSame('instance_already_started', $secondCommand->rejection_reason);

        $rejectedEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', HistoryEventType::StartRejected->value)
            ->first();

        $this->assertNotNull($rejectedEvent);
        $this->assertSame(CommandOutcome::RejectedDuplicate->value, $rejectedEvent->payload['outcome'] ?? null);
    }

    public function testReturnExistingActiveAcceptsSecondStartOnActiveRun(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello, Taylor!');

        $workflow = WorkflowStub::make(TestSignalWorkflow::class, 'dup-return-active');
        $workflow->start();

        $this->assertSame('waiting', $workflow->refresh()->status());

        $second = WorkflowStub::load('dup-return-active');
        $result = $second->attemptStart(StartOptions::returnExistingActive());

        $this->assertTrue($result->accepted());
        $this->assertTrue($result->returnedExistingActive());
        $this->assertFalse($result->rejected());
        $this->assertFalse($result->startedNew());
        $this->assertSame(CommandOutcome::ReturnedExistingActive->value, $result->outcome());
        $this->assertSame('dup-return-active', $result->instanceId());
        $this->assertSame($workflow->runId(), $result->runId());
    }

    public function testReturnExistingActiveRejectsWhenRunIsTerminal(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello, Taylor!');

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'dup-return-terminal');
        $workflow->start('Taylor');

        $this->assertTrue($workflow->refresh()->completed());

        $second = WorkflowStub::load('dup-return-terminal');
        $result = $second->attemptStart('Taylor', StartOptions::returnExistingActive());

        $this->assertTrue($result->rejected());
        $this->assertTrue($result->rejectedDuplicate());
        $this->assertFalse($result->returnedExistingActive());
    }

    public function testReturnExistingActiveRecordsAcceptedCommandAndHistoryEvent(): void
    {
        WorkflowStub::fake();

        $workflow = WorkflowStub::make(TestSignalWorkflow::class, 'dup-return-history');
        $workflow->start();

        $this->assertSame('waiting', $workflow->refresh()->status());

        $second = WorkflowStub::load('dup-return-history');
        $second->attemptStart(StartOptions::returnExistingActive());

        $commands = WorkflowCommand::query()
            ->where('workflow_instance_id', 'dup-return-history')
            ->where('command_type', CommandType::Start->value)
            ->orderBy('created_at')
            ->get();

        $this->assertCount(2, $commands);

        $secondCommand = $commands->last();
        $this->assertSame(CommandStatus::Accepted, $secondCommand->status);
        $this->assertSame(CommandOutcome::ReturnedExistingActive, $secondCommand->outcome);
        $this->assertNull($secondCommand->rejection_reason);

        $acceptedEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', HistoryEventType::StartAccepted->value)
            ->orderBy('sequence', 'desc')
            ->first();

        $this->assertNotNull($acceptedEvent);
        $this->assertSame(CommandOutcome::ReturnedExistingActive->value, $acceptedEvent->payload['outcome'] ?? null);
    }

    public function testDefaultDuplicateStartPolicyIsRejectDuplicate(): void
    {
        $options = new StartOptions();

        $this->assertSame(DuplicateStartPolicy::RejectDuplicate, $options->duplicateStartPolicy);
    }

    public function testStartOptionsFactoryMethodsSetCorrectPolicies(): void
    {
        $reject = StartOptions::rejectDuplicate();
        $this->assertSame(DuplicateStartPolicy::RejectDuplicate, $reject->duplicateStartPolicy);

        $returnExisting = StartOptions::returnExistingActive();
        $this->assertSame(DuplicateStartPolicy::ReturnExistingActive, $returnExisting->duplicateStartPolicy);
    }

    public function testRejectDuplicateDefaultWorksExplicitly(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello, Taylor!');

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'dup-explicit-reject');
        $workflow->start('Taylor', StartOptions::rejectDuplicate());

        $this->assertTrue($workflow->refresh()->completed());

        $second = WorkflowStub::load('dup-explicit-reject');
        $result = $second->attemptStart('Taylor', StartOptions::rejectDuplicate());

        $this->assertTrue($result->rejected());
        $this->assertTrue($result->rejectedDuplicate());
    }
}
