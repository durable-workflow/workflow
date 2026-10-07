<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestQuerySignalPayloadWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\ExternalPayloadStoragePolicy;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\SignalStatus;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Support\ExternalPayloads;
use Workflow\V2\Support\ExternalPayloadStorage;
use Workflow\V2\Support\LocalFilesystemExternalPayloadStorage;
use Workflow\V2\Support\QueryStateReplayer;
use Workflow\V2\Support\SignalCall;

final class QueryStateReplayerSignalTest extends TestCase
{
    #[DataProvider('signalPayloads')]
    public function testCommittedSignalRestoresItsPayloadWithoutChangingRuntimeRecords(
        string $field,
        mixed $value,
        string $storage,
        mixed $expected,
    ): void {
        $run = $this->createRun();
        $this->openWait($run, 10, 'approval-one');
        $serialized = Serializer::serializeWithCodec('avro', $value);
        if ($storage === 'external') {
            $root = sys_get_temp_dir() . '/query-signal-payload-' . Str::ulid();
            $driver = new LocalFilesystemExternalPayloadStorage($root);
            $policy = $this->createMock(ExternalPayloadStoragePolicy::class);
            $policy->method('driverFor')
                ->with('query-signals')
                ->willReturn($driver);
            $this->app->instance(ExternalPayloadStoragePolicy::class, $policy);
            $this->beforeApplicationDestroyed(static function () use ($root): void {
                ExternalPayloadStorage::flushVerifiedCache();
                (new Filesystem())->deleteDirectory($root);
            });
            $serialized = ExternalPayloads::externalize($serialized, 'avro', $driver, 1);
        }
        $payload = match ($storage) {
            'envelope' => [
                'codec' => 'avro',
                'blob' => $serialized,
            ],
            'external' => ExternalPayloads::historyValue($serialized, 'avro', $run->namespace),
            default => $serialized,
        };
        $this->appendEvent($run, HistoryEventType::SignalApplied, [
            'sequence' => 10,
            'signal_name' => 'approval',
            'signal_wait_id' => 'approval-one',
            $field => $payload,
        ]);
        $this->openWait($run, 20, 'approval-two');

        $this->assertReadOnlyState($run, 2, [$expected]);
    }

    public static function signalPayloads(): array
    {
        return [
            'serialized value' => ['value', 'recorded', 'inline', 'recorded'],
            'inline value envelope' => [
                'value', [
                    'approved' => true,
                ], 'envelope', [
                    'approved' => true,
                ]],
            'external value envelope' => [
                'value',
                str_repeat('approved', 100),
                'external',
                str_repeat('approved', 100),
            ],
            'one argument envelope' => ['arguments', ['recorded'], 'envelope', 'recorded'],
            'multiple arguments' => ['arguments', ['Ada', true], 'inline', ['Ada', true]],
            'empty arguments' => ['arguments', [], 'envelope', true],
            'null argument' => ['arguments', [null], 'inline', null],
            'legacy scalar arguments' => ['arguments', 'recorded', 'inline', 'recorded'],
            'external arguments' => [
                'arguments',
                [str_repeat('recorded', 100)],
                'external',
                str_repeat('recorded', 100),
            ],
        ];
    }

    #[DataProvider('signalCorrelations')]
    public function testAppliedHistoryCanRestoreArgumentsFromItsCorrelatedSignalRecord(string $correlation): void
    {
        $run = $this->createRun();
        $this->openWait($run, 10, 'approval-one');
        $signal = $this->createSignal($run, SignalStatus::Applied);
        if ($correlation !== 'wait-id') {
            // IDs still identify the delivery if its mutable wait projection drifts.
            $signal->forceFill([
                'signal_wait_id' => 'different-projection-wait',
            ])->save();
        }
        $payload = [
            'sequence' => 10,
            'signal_name' => 'approval',
            'signal_wait_id' => 'approval-one',
        ];
        if ($correlation === 'signal-id') {
            $payload['signal_id'] = $signal->id;
        } elseif ($correlation === 'payload-command-id') {
            $payload['signal_id'] = 'missing-signal';
            $payload['workflow_command_id'] = $signal->workflow_command_id;
        } elseif ($correlation === 'wait-id') {
            $payload['signal_id'] = 'missing-signal';
            $payload['workflow_command_id'] = 'missing-command';
        }
        $event = $this->appendEvent($run, HistoryEventType::SignalApplied, $payload);
        if ($correlation === 'event-command-id') {
            $event->forceFill([
                'workflow_command_id' => $signal->workflow_command_id,
            ])->save();
        }
        $this->openWait($run, 20, 'approval-two');
        $this->assertReadOnlyState($run, 2, ['projection-arguments']);

        $event->forceFill([
            'payload' => $payload + [
                'value' => Serializer::serialize('committed-history'),
            ],
        ])->save();
        $signal->forceFill([
            'status' => SignalStatus::Received->value,
            'arguments' => Serializer::serialize(['contradictory-projection']),
        ])->save();

        $this->assertReadOnlyState($run, 2, ['committed-history']);
    }

    public static function signalCorrelations(): array
    {
        return [
            'signal ID' => ['signal-id'],
            'payload command ID after missing signal ID' => ['payload-command-id'],
            'event command ID' => ['event-command-id'],
            'wait identity after missing IDs' => ['wait-id'],
        ];
    }

    #[DataProvider('commitEvidence')]
    public function testReceivedSignalWaitsForDurableApplicationEvidence(string $evidence): void
    {
        $run = $this->createRun();
        $this->openWait($run, 10, 'approval-one');
        $signal = $this->createSignal($run, SignalStatus::Received);
        $received = $this->appendEvent($run, HistoryEventType::SignalReceived, [
            'signal_name' => 'approval',
            'signal_wait_id' => 'approval-one',
            'signal_id' => $signal->id,
            'arguments' => Serializer::serialize(['received-history']),
        ]);
        $this->assertReadOnlyState($run, 1, []);

        if ($evidence === 'projection') {
            $signal->forceFill([
                'status' => SignalStatus::Applied->value,
            ])->save();
        } elseif ($evidence === 'received-sequence') {
            $received->forceFill([
                'payload' => $received->payload + [
                    'workflow_sequence' => '10',
                ],
            ])->save();
        } else {
            $this->appendEvent($run, HistoryEventType::SignalApplied, [
                'sequence' => 10,
                'signal_name' => 'approval',
                'signal_wait_id' => 'approval-one',
            ]);
            // The legacy marker has no payload mirror in the projection.
            $signal->delete();
        }
        $this->openWait($run, 20, 'approval-two');

        $this->assertReadOnlyState($run, 2, ['received-history']);
    }

    public static function commitEvidence(): array
    {
        return [
            'applied projection' => ['projection'],
            'committed received workflow sequence' => ['received-sequence'],
            'legacy applied marker' => ['applied-marker'],
        ];
    }

    public function testWaitIdentityPreventsReusingAReceivedSignalAtAConflictingProjectionSequence(): void
    {
        $run = $this->createRun();
        $this->openWait($run, 10, 'approval-one');
        $this->openWait($run, 20, 'approval-two');
        $this->appendEvent($run, HistoryEventType::SignalReceived, [
            'signal_name' => 'approval',
            'signal_wait_id' => 'approval-one',
            'workflow_sequence' => 20,
            'arguments' => Serializer::serialize(['first-approval']),
        ]);
        $this->appendEvent($run, HistoryEventType::SignalApplied, [
            'sequence' => 10,
            'signal_name' => 'approval',
            'signal_wait_id' => 'approval-one',
        ]);

        $this->assertReadOnlyState($run, 2, ['first-approval']);
    }

    public function testMissingValueEnvelopeFallsBackToRecordedArguments(): void
    {
        $run = $this->createRun();
        $this->openWait($run, 10, 'approval-one');
        $this->appendEvent($run, HistoryEventType::SignalApplied, [
            'sequence' => 10,
            'signal_name' => 'approval',
            'signal_wait_id' => 'approval-one',
            'value' => null,
            'arguments' => Serializer::serialize(['recorded-arguments']),
        ]);
        $this->openWait($run, 20, 'approval-two');

        $this->assertReadOnlyState($run, 2, ['recorded-arguments']);
    }

    private function createRun(): WorkflowRun
    {
        $instance = WorkflowInstance::query()->create([
            'id' => 'query-signals-' . strtolower((string) Str::ulid()),
            'workflow_class' => TestQuerySignalPayloadWorkflow::class,
            'workflow_type' => TestQuerySignalPayloadWorkflow::class,
            'run_count' => 1,
        ]);
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestQuerySignalPayloadWorkflow::class,
            'workflow_type' => TestQuerySignalPayloadWorkflow::class,
            'namespace' => 'query-signals',
            'status' => RunStatus::Waiting->value,
            'payload_codec' => 'avro',
            'arguments' => Serializer::serialize([]),
            'started_at' => now()
                ->subMinute(),
            'last_progress_at' => now(),
        ]);
        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        return $run;
    }

    private function createSignal(WorkflowRun $run, SignalStatus $status): WorkflowSignal
    {
        $command = WorkflowCommand::query()->create([
            'workflow_instance_id' => $run->workflow_instance_id,
            'workflow_run_id' => $run->id,
            'command_type' => 'signal',
            'target_scope' => 'run',
            'status' => 'accepted',
        ]);

        return WorkflowSignal::query()->create([
            'workflow_instance_id' => $run->workflow_instance_id,
            'workflow_run_id' => $run->id,
            'workflow_command_id' => $command->id,
            'signal_name' => 'approval',
            'signal_wait_id' => 'approval-one',
            'status' => $status->value,
            'payload_codec' => 'avro',
            'arguments' => Serializer::serialize(['projection-arguments']),
        ]);
    }

    private function openWait(WorkflowRun $run, int $sequence, string $waitId): void
    {
        $this->appendEvent($run, HistoryEventType::SignalWaitOpened, [
            'sequence' => $sequence,
            'signal_name' => 'approval',
            'signal_wait_id' => $waitId,
        ]);
    }

    private function appendEvent(WorkflowRun $run, HistoryEventType $type, array $payload): WorkflowHistoryEvent
    {
        $run->increment('last_history_sequence');

        return WorkflowHistoryEvent::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => $run->last_history_sequence,
            'event_type' => $type->value,
            'payload' => $payload,
            'recorded_at' => now(),
        ]);
    }

    private function assertReadOnlyState(WorkflowRun $run, int $wait, array $values): void
    {
        $expected = [
            'stage' => 'waiting-for-approval-' . $wait,
            'values' => $values,
        ];
        $before = $this->runtimeSnapshot($run);

        for ($repetition = 0; $repetition < 2; ++$repetition) {
            $state = (new QueryStateReplayer())->replayState($run->fresh());
            $this->assertInstanceOf(SignalCall::class, $state->current);
            $this->assertSame('approval', $state->current->name);
            $this->assertSame($wait, $state->sequence);
            $this->assertSame($expected, $state->workflow->currentState());
            $this->assertSame($expected, (new QueryStateReplayer())->query($run->fresh(), 'currentState'));
            $this->assertSame($before, $this->runtimeSnapshot($run));
        }
    }

    private function runtimeSnapshot(WorkflowRun $run): array
    {
        $snapshot = [
            'run' => $run->fresh()
                ->getAttributes(),
        ];
        foreach ([WorkflowHistoryEvent::class, WorkflowCommand::class, WorkflowSignal::class,
            ActivityExecution::class, WorkflowTask::class, WorkflowTimer::class] as $model) {
            $snapshot[$model] = $model::query()->where('workflow_run_id', $run->id)->orderBy('id')->get()->toArray();
        }

        return $snapshot;
    }
}
