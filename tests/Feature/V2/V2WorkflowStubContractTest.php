<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Queue;
use LogicException;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\Fixtures\V2\TestSignalWorkflow;
use Tests\TestCase;
use Workflow\Serializers\CodecRegistry;
use Workflow\V2\StartOptions;
use Workflow\V2\WorkflowStub;

final class V2WorkflowStubContractTest extends TestCase
{
    public function testReservedHandleExposesAnExplicitPreStartInspectionContract(): void
    {
        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'reserved-inspection-contract');

        $this->assertSame('reserved-inspection-contract', $workflow->id());
        $this->assertSame($workflow->id(), $workflow->workflowId());
        $this->assertNull($workflow->run());
        $this->assertNull($workflow->runId());
        $this->assertSame(CodecRegistry::defaultCodec(), $workflow->payloadCodec());
        $this->assertNull($workflow->currentRunId());
        $this->assertFalse($workflow->currentRunIsSelected());
        $this->assertNull($workflow->businessKey());
        $this->assertSame([], $workflow->visibilityLabels());
        $this->assertSame([], $workflow->memo());
        $this->assertSame([], $workflow->searchAttributes());
        $this->assertSame('reserved', $workflow->status());
        $this->assertFalse($workflow->running());
        $this->assertFalse($workflow->completed());
        $this->assertFalse($workflow->failed());
        $this->assertFalse($workflow->cancelled());
        $this->assertFalse($workflow->terminated());
        $this->assertNull($workflow->output());
        $this->assertNull($workflow->resolveQueryTarget('status'));
        $this->assertNull($workflow->summary());
    }

    public function testReservedHandleRejectsOperationsThatRequireAStartedRun(): void
    {
        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'reserved-run-operation-contract');

        $this->assertLogicException(
            'Workflow instance [reserved-run-operation-contract] has not started yet.',
            static fn (): mixed => $workflow->query('status'),
        );
        $this->assertLogicException(
            'Workflow instance [reserved-run-operation-contract] has not started yet.',
            static fn (): mixed => $workflow->historyExport(),
        );
    }

    public function testStrictCommandsExposeReservedInstanceRejectionReasons(): void
    {
        $commands = [
            'cancel' => [
                static fn (WorkflowStub $workflow): mixed => $workflow->cancel('no longer needed'),
                'Workflow instance [reserved-strict-cancel] cannot be cancelled: instance_not_started.',
            ],
            'terminate' => [
                static fn (WorkflowStub $workflow): mixed => $workflow->terminate('operator request'),
                'Workflow instance [reserved-strict-terminate] cannot be terminated: instance_not_started.',
            ],
            'archive' => [
                static fn (WorkflowStub $workflow): mixed => $workflow->archive('retention policy'),
                'Workflow instance [reserved-strict-archive] cannot be archived: instance_not_started.',
            ],
            'repair' => [
                static fn (WorkflowStub $workflow): mixed => $workflow->repair(),
                'Workflow instance [reserved-strict-repair] cannot be repaired: instance_not_started.',
            ],
            'signal' => [
                static fn (WorkflowStub $workflow): mixed => $workflow->signal('name-provided', 'Taylor'),
                'Workflow instance [reserved-strict-signal] cannot receive signal [name-provided]: instance_not_started.',
            ],
            'update' => [
                static fn (WorkflowStub $workflow): mixed => $workflow->update('approve', true),
                'Workflow instance [reserved-strict-update] cannot apply update [approve]: instance_not_started.',
            ],
        ];

        foreach ($commands as $name => [$command, $message]) {
            $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'reserved-strict-' . $name);

            $this->assertLogicException($message, static fn (): mixed => $command($workflow));
        }
    }

    public function testCommandInputsFailBeforeRecordingAmbiguousRequests(): void
    {
        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'invalid-command-contract');

        $this->assertLogicException(
            'Workflow v2 update wait timeout must be a positive integer.',
            static fn (): WorkflowStub => $workflow->withUpdateWaitTimeout(0),
        );
        $this->assertLogicException(
            'Update id cannot be empty.',
            static fn (): mixed => $workflow->inspectUpdate(''),
        );
        $this->assertLogicException(
            'Update [missing-update] was not found for workflow instance [invalid-command-contract].',
            static fn (): mixed => $workflow->inspectUpdate('missing-update'),
        );
        $this->assertLogicException(
            'Signal name cannot be empty.',
            static fn (): mixed => $workflow->attemptSignalWithArguments('', []),
        );
        $this->assertLogicException(
            'Signal [user-signal] is not runtime-reserved.',
            static fn (): mixed => $workflow->attemptRuntimeSignalWithArguments('user-signal', []),
        );
        $this->assertLogicException(
            'Workflow v2 signalWithStart requires StartOptions::returnExistingActive() semantics.',
            static fn (): mixed => $workflow->attemptSignalWithStart(
                'name-provided',
                ['Taylor'],
                StartOptions::rejectDuplicate(),
            ),
        );
    }

    public function testStartedHandleRejectsUnknownQueriesAndRunTargetedSignalWithStart(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestSignalWorkflow::class, 'started-command-contract');
        $workflow->start();

        $this->assertLogicException(
            sprintf('Workflow query [missing-query] is not declared on run [%s].', $workflow->runId()),
            static fn (): mixed => $workflow->query('missing-query'),
        );

        $selectedRun = WorkflowStub::loadRun((string) $workflow->runId());

        $this->assertLogicException(
            'Workflow v2 signalWithStart only supports instance-targeted workflow stubs.',
            static fn (): mixed => $selectedRun->attemptSignalWithStart('name-provided', ['Taylor']),
        );
    }

    /**
     * @param callable(): mixed $operation
     */
    private function assertLogicException(string $message, callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected a LogicException to be thrown.');
        } catch (LogicException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }
    }
}
