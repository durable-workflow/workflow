<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestExternalSignalArgumentsWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\CommandContext;
use Workflow\V2\Enums\CommandStatus;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Enums\SignalStatus;
use Workflow\V2\Exceptions\WorkflowExecutionUnavailableException;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\SignalWithStartResult;
use Workflow\V2\StartOptions;
use Workflow\V2\Support\WorkerCompatibilityFleet;
use Workflow\V2\WorkflowStub;

final class WorkflowStubSignalWithStartAdmissionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-08 08:20:00');
        Queue::fake();
        config()
            ->set([
                'queue.default' => 'redis',
                'workflows.v2.compatibility.current' => 'build-a',
                'workflows.v2.compatibility.supported' => ['build-a'],
                'workflows.v2.compatibility.namespace' => 'signal-start-guards',
                'workflows.v2.structural_limits.pending_signal_count' => 5000,
            ]);
        WorkerCompatibilityFleet::clear();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        WorkerCompatibilityFleet::clear();
        parent::tearDown();
    }

    #[DataProvider('unknownSignals')]
    public function testUnknownSignalsPersistOnlyCorrelatedRejectionAudit(
        bool $active,
        bool $strict,
        string $name
    ): void {
        $workflow = $this->workflow($active);
        $this->refuse($workflow, $strict, $name, ['Taylor', 'Lee'], 'unknown_signal');
    }

    /**
     * @return iterable<string, array{bool, bool, string}>
     */
    public static function unknownSignals(): iterable
    {
        foreach ([false, true] as $active) {
            foreach ([false, true] as $strict) {
                foreach (['undeclared', WorkflowStub::MESSAGE_STREAM_RUNTIME_SIGNAL] as $name) {
                    yield ($active ? 'active' : 'reserved') . ' ' . ($strict ? 'strict' : 'attempt') . ' ' . $name => [
                        $active,
                        $strict,
                        $name,
                    ];
                }
            }
        }
    }

    #[DataProvider('invalidArguments')]
    public function testInvalidArgumentsAreRejectedBeforeSignalOrStartAdmission(
        bool $active,
        bool $strict,
        array $arguments,
        string $field
    ): void {
        $workflow = $this->workflow($active);
        $result = $this->refuse($workflow, $strict, 'pair', $arguments, 'invalid_signal_arguments');
        $this->assertArrayHasKey($field, $result->validationErrors());
        $this->assertNotEmpty($result->validationErrors()[$field]);
        $this->assertTrue($result->rejectedInvalidArguments());
    }

    /**
     * @return iterable<string, array{bool, bool, array<int|string, mixed>, string}>
     */
    public static function invalidArguments(): iterable
    {
        $cases = [
            'missing both' => [[], 'first'],
            'missing second' => [[
                'first' => 'Taylor',
            ], 'second'],
            'wrong first type' => [[false, 'Lee'], 'first'],
            'wrong second type' => [['Taylor', 7], 'second'],
            'unknown named key' => [[
                'first' => 'Taylor',
                'second' => 'Lee',
                'nickname' => 'ignored',
            ], 'nickname'],
            'too many positional' => [['Taylor', 'Lee', 'extra'], 'arguments'],
        ];
        foreach ([false, true] as $active) {
            foreach ([false, true] as $strict) {
                foreach ($cases as $label => [$arguments, $field]) {
                    yield ($active ? 'active' : 'reserved') . ' ' . ($strict ? 'strict' : 'attempt') . ' ' . $label => [
                        $active,
                        $strict,
                        $arguments,
                        $field,
                    ];
                }
            }
        }
    }

    #[DataProvider('modes')]
    public function testPendingSignalLimitRefusesTheEntireCombinedIntake(bool $strict): void
    {
        $workflow = $this->workflow(true);
        $buffered = $workflow->attemptSignalWithArguments('pair', ['First', 'Buffered']);
        $this->assertTrue($buffered->accepted());
        config()
            ->set('workflows.v2.structural_limits.pending_signal_count', 1);
        Queue::fake();
        $result = $this->refuse($workflow, $strict, 'pair', ['Second', 'Refused'], 'structural_limit_exceeded');
        $this->assertSame([], $result->validationErrors());
        $this->assertSame(1, WorkflowSignal::query()->where('status', SignalStatus::Received->value)->count());
    }

    #[DataProvider('modes')]
    public function testIncompatibleWorkersProduceStructuredStartRefusal(bool $strict): void
    {
        $workflow = $this->workflow(false);
        config()
            ->set('workflows.v2.fleet.validation_mode', 'fail');
        WorkerCompatibilityFleet::record(['build-b'], 'redis', 'default', 'worker-build-b');
        $result = $this->refuse($workflow, $strict, 'pair', ['Taylor', 'Lee'], 'compatibility_blocked');
        $this->assertSame('compatibility_blocked', $result->reason());
        $this->assertIsString($result->message());
        $this->assertStringContainsString('Start blocked under fail validation mode.', $result->message());
        $this->assertStringContainsString('compatibility [build-a]', $result->message());
        $this->assertNull($workflow->runId());
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function modes(): iterable
    {
        yield 'attempt result' => [false];
        yield 'strict exception' => [true];
    }

    #[DataProvider('invalidInputs')]
    public function testInputGuardsFailBeforeRecordingAnIntake(string $case, bool $strict): void
    {
        $workflow = $this->workflow($case === 'run targeted');
        if ($case === 'run targeted') {
            $workflow = WorkflowStub::loadRun((string) $workflow->runId());
        }
        $before = $this->allRecords();
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            try {
                $name = $case === 'empty name' ? '' : 'pair';
                $arguments = $case === 'duplicate policy' ? [StartOptions::rejectDuplicate()] : [];
                $strict ? $workflow->signalWithStart(
                    $name,
                    ['Taylor', 'Lee'],
                    ...$arguments
                ) : $workflow->attemptSignalWithStart($name, ['Taylor', 'Lee'], ...$arguments);
                $this->fail('Invalid intake was recorded.');
            } catch (LogicException $exception) {
                $this->assertSame(match ($case) {
                    'empty name' => 'Signal name cannot be empty.',
                    'duplicate policy' => 'Workflow v2 signalWithStart requires StartOptions::returnExistingActive() semantics.',
                    'run targeted' => 'Workflow v2 signalWithStart only supports instance-targeted workflow stubs.',
                }, $exception->getMessage());
            }
        }
        $this->assertSame($before, $this->allRecords());
        Queue::assertNothingPushed();
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function invalidInputs(): iterable
    {
        foreach (['empty name', 'duplicate policy', 'run targeted'] as $case) {
            yield $case . ' attempt' => [$case, false];
            yield $case . ' strict' => [$case, true];
        }
    }

    private function workflow(bool $active): WorkflowStub
    {
        $workflow = WorkflowStub::make(
            TestExternalSignalArgumentsWorkflow::class,
            'signal-start-intake',
            'signal-start-guards'
        )
            ->withCommandContext(CommandContext::phpApi()->withPrincipal('test-user', 'caller-42'));
        if ($active) {
            $workflow->start();
        }
        Queue::fake();
        return $workflow;
    }

    /**
     * @param array<int|string, mixed> $arguments
     */
    private function refuse(
        WorkflowStub $workflow,
        bool $strict,
        string $name,
        array $arguments,
        string $reason
    ): SignalWithStartResult {
        $before = $this->executionRecords();
        $runId = $workflow->runId();
        $runCount = WorkflowRun::query()->count();
        $lastMessage = (int) WorkflowInstance::query()->findOrFail($workflow->id())->last_message_sequence;
        $lastCommand = $runId === null ? null : (int) WorkflowRun::query()->findOrFail($runId)->last_command_sequence;
        $previousGroup = null;
        $result = null;
        for ($attempt = 1; $attempt <= 2; ++$attempt) {
            $ids = WorkflowCommand::query()->pluck('id')->all();
            if ($strict) {
                try {
                    $workflow->signalWithStart($name, $arguments);
                    $this->fail('Strict signal-with-start accepted a refused intake.');
                } catch (WorkflowExecutionUnavailableException $exception) {
                    $this->assertSame('compatibility_blocked', $reason);
                    $this->assertSame('signal_with_start', $exception->operation());
                    $this->assertSame($name, $exception->targetName());
                    $this->assertSame($reason, $exception->blockedReason());
                } catch (LogicException $exception) {
                    $this->assertSame(
                        'Workflow instance [signal-start-intake] cannot receive signal-with-start [' . $name . ']: ' . $reason . '.',
                        $exception->getMessage()
                    );
                }
            } else {
                $result = $workflow->attemptSignalWithStart($name, $arguments);
            }
            $command = WorkflowCommand::query()->whereNotIn('id', $ids)->sole();
            if ($strict) {
                $result = SignalWithStartResult::fromCommands($command, null, $command->intakeGroupId());
            }
            $this->assertInstanceOf(SignalWithStartResult::class, $result);
            $this->assertSame($command->id, $result->commandId());
            $this->assertTrue($result->rejected());
            $this->assertFalse($result->accepted());
            $this->assertSame($reason, $result->rejectionReason());
            $this->assertSame('signal', $result->type());
            $this->assertSame('instance', $result->targetScope());
            $this->assertSame($workflow->id(), $result->instanceId());
            $this->assertSame($runId, $result->runId());
            $this->assertNull($result->startCommandId());
            $this->assertNull($result->startCommandSequence());
            $this->assertNull($result->startOutcome());
            $this->assertNull($result->startStatus());
            $this->assertNull($result->startResult());
            $this->assertFalse($result->startAccepted());
            $this->assertFalse($result->startedNew());
            $this->assertFalse($result->returnedExistingActive());
            $this->assertSame('php', $result->source());
            $this->assertSameJsonObject([
                'type' => 'test-user',
                'id' => 'caller-42',
            ], $result->context()['principal']);
            $this->assertSame('signal_with_start', $result->context()['intake']['mode']);
            $this->assertNotEmpty($result->intakeGroupId());
            $this->assertSame($result->intakeGroupId(), $result->context()['intake']['group_id']);
            $this->assertNotSame($previousGroup, $result->intakeGroupId());
            $previousGroup = $result->intakeGroupId();
            $this->assertSameJsonObject([
                'arguments' => $arguments,
            ], $result->payloadValues(['arguments']));
            $this->assertSame(CommandType::Signal, $command->command_type);
            $this->assertSame(CommandStatus::Rejected, $command->status);
            $signal = WorkflowSignal::query()->where('workflow_command_id', $command->id)->sole();
            $this->assertSame(SignalStatus::Rejected, $signal->status);
            $this->assertSame($name, $signal->signal_name);
            $this->assertSame($reason, $signal->rejection_reason);
            $this->assertSame($result->outcome(), $signal->outcome?->value);
            $this->assertSame($runId, $signal->workflow_run_id);
            $this->assertSameJsonObject([
                'arguments' => array_values($arguments),
            ], [
                'arguments' => $signal->signalArguments(),
            ]);
            $this->assertSameJsonObject([
                'arguments' => $arguments,
            ], [
                'arguments' => Serializer::unserializeWithCodec(
                    (string) $signal->payload_codec,
                    (string) $signal->arguments
                ),
            ]);
            $this->assertSameJsonObject($result->validationErrors(), $signal->normalizedValidationErrors());
            $this->assertNotNull($signal->rejected_at);
            $this->assertNotNull($signal->closed_at);
            $this->assertSame(
                $lastMessage + $attempt,
                (int) WorkflowInstance::query()->findOrFail($workflow->id())->last_message_sequence
            );
            if ($runId !== null) {
                $this->assertSame($lastCommand + $attempt, $result->commandSequence());
                $this->assertSame(
                    $lastCommand + $attempt,
                    (int) WorkflowRun::query()->findOrFail($runId)->last_command_sequence
                );
            } else {
                $this->assertNull($result->commandSequence());
            }
            $this->assertSame($runCount, WorkflowRun::query()->count());
            $this->assertSame($before, $this->executionRecords());
            Queue::assertNothingPushed();
        }
        $this->assertInstanceOf(SignalWithStartResult::class, $result);
        return $result;
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function executionRecords(): array
    {
        $records = $this->allRecords();
        unset($records[WorkflowCommand::class], $records[WorkflowSignal::class]);
        foreach ($records[WorkflowInstance::class] as &$row) {
            unset($row['last_message_sequence']);
        }
        unset($row);
        foreach ($records[WorkflowRun::class] as &$row) {
            unset($row['last_command_sequence']);
        }
        unset($row);
        $records[WorkflowSignal::class] = WorkflowSignal::query()->where(
            'status',
            '!=',
            SignalStatus::Rejected->value
        )->orderBy('id')
            ->get()
            ->map(static fn (WorkflowSignal $signal): array => $signal->getRawOriginal())
            ->all();
        return $records;
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function allRecords(): array
    {
        $records = [];
        foreach ([
            WorkflowInstance::class,
            WorkflowRun::class,
            WorkflowTask::class,
            WorkflowHistoryEvent::class,
            WorkflowFailure::class,
            ActivityExecution::class,
            WorkflowRunSummary::class,
            WorkflowCommand::class,
            WorkflowSignal::class,
        ] as $model) {
            $records[$model] = $model::query()->orderBy((new $model())->getKeyName())->get()->map(
                static function ($model): array {
                    $row = $model->getRawOriginal();
                    if (is_string($row['updated_at'] ?? null)) {
                        $row['updated_at'] = Carbon::parse($row['updated_at'])->format('Y-m-d H:i:s.u');
                    }
                    return $row;
                }
            )->all();
        }
        return $records;
    }
}
