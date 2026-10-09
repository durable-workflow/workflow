<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\V2\TestQueryServiceOperationWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Exceptions\RestoredWorkflowException;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\QueryStateReplayer;
use Workflow\V2\Support\ServiceOperationCall;
use Workflow\V2\Support\TimerCall;

final class QueryStateReplayerServiceOperationTest extends TestCase
{
    #[DataProvider('pendingPrefixes')]
    public function testSynchronousCallWaitsForTerminalHistory(?array $payload): void
    {
        $run = $this->createRun('sync');
        if ($payload !== null) {
            $this->appendEvent($run, HistoryEventType::ServiceCallStarted, $payload + $this->servicePayload());
        }

        $this->assertReadOnlyState($run, [
            'stage' => 'waiting-for-service',
            'result' => null,
            'failure' => null,
        ], ServiceOperationCall::class, 1);
    }

    public static function pendingPrefixes(): array
    {
        return [
            'no admission' => [null],
            'started without resume metadata' => [[]],
            'started with synchronous metadata' => [[
                'operation_mode' => 'sync',
                'wait_for' => 'completed',
                'service_call' => [
                    'operation_mode' => 'sync',
                    'wait_for' => 'completed',
                ],
            ]],
        ];
    }

    #[DataProvider('visibleAdmissions')]
    public function testAcceptedAdmissionRestoresAQueryableResult(string $mode, array $metadata): void
    {
        $run = $this->createRun($mode);
        $payload = $metadata + $this->servicePayload();
        $this->appendEvent($run, HistoryEventType::ServiceCallStarted, $payload);
        $surface = [
            'service_call_id' => 'service-call-1',
            'status' => 'started',
            'outcome' => 'accepted',
            'endpoint_name' => 'payments',
            'service_name' => 'Payments',
            'operation_name' => 'authorize',
        ];
        $expectedSurface = ($metadata['service_call'] ?? []) + $surface;
        foreach (['operation_mode', 'wait_for'] as $field) {
            if (isset($metadata[$field]) && ! isset($expectedSurface[$field])) {
                $expectedSurface[$field] = $metadata[$field];
            }
        }

        $this->assertReadOnlyState($run, [
            'stage' => 'waiting-for-timer',
            'result' => $surface + [
                'response_payload' => null,
                'service_call' => $expectedSurface,
            ],
            'failure' => null,
        ], TimerCall::class, 2);
    }

    public static function visibleAdmissions(): array
    {
        return [
            'authored async acceptance' => ['async', []],
            'historical accepted wait' => [
                'sync', [
                    'wait_for' => 'accepted',
                ]],
            'historical async mode' => [
                'sync', [
                    'operation_mode' => 'async',
                ]],
            'nested accepted wait' => [
                'sync', [
                    'service_call' => [
                        'wait_for' => 'accepted',
                    ],
                ]],
            'nested async mode' => [
                'sync', [
                    'service_call' => [
                        'operation_mode' => 'async',
                    ],
                ]],
        ];
    }

    #[DataProvider('recordedFailures')]
    public function testTerminalFailureRestoresCatchableQueryState(
        string $eventType,
        array $payload,
        string $class,
        string $originalClass,
        string $message,
        int $code,
        ?string $type,
    ): void {
        $run = $this->createRun('sync');
        $this->appendEvent($run, HistoryEventType::ServiceCallStarted, $this->servicePayload());
        $this->appendEvent($run, HistoryEventType::from($eventType), $payload + $this->servicePayload());

        $this->assertReadOnlyState($run, [
            'stage' => 'waiting-for-timer',
            'result' => null,
            'failure' => [
                'class' => $class,
                'original_class' => $originalClass,
                'message' => $message,
                'code' => $code,
                'type' => $type,
            ],
        ], TimerCall::class, 2);
    }

    public static function recordedFailures(): array
    {
        $cases = [
            'no exception metadata' => [
                [], RuntimeException::class, RuntimeException::class, 'Service operation failed.', 0, null,
            ],
            'malformed nested exception' => [
                [
                    'exception' => 'not-an-object',
                ], RuntimeException::class, RuntimeException::class,
                'Service operation failed.', 0, null,
            ],
            'portable fields' => [
                [
                    'exception_class' => RuntimeException::class,
                    'message' => 'declined',
                    'code' => '7',
                ],
                RuntimeException::class, RuntimeException::class, 'declined', 7, null,
            ],
            'nested fields take precedence' => [
                [
                    'exception_class' => RuntimeException::class,
                    'message' => 'fallback',
                    'code' => 9,
                    'exception' => [
                        'class' => LogicException::class,
                        'message' => 'nested',
                        'code' => 3,
                    ],
                ], LogicException::class, LogicException::class, 'nested', 3, null,
            ],
            'invalid nested fields use portable metadata' => [
                [
                    'message' => 'recorded',
                    'code' => -2,
                    'exception' => [
                        'class' => 99,
                        'message' => null,
                        'code' => '3',
                    ],
                ], RuntimeException::class, RuntimeException::class, 'recorded', -2, null,
            ],
            'unknown external class' => [
                [
                    'exception_class' => 'Payments\\Declined',
                    'message' => 'external',
                    'code' => 12,
                ],
                RestoredWorkflowException::class, 'Payments\\Declined', 'external', 12, null,
            ],
            'portable type' => [
                [
                    'exception_type' => 'payments-declined',
                    'message' => 'typed',
                ],
                RestoredWorkflowException::class, RuntimeException::class, 'typed', 0, 'payments-declined',
            ],
            'nested type takes precedence' => [
                [
                    'exception_type' => 'ignored',
                    'exception' => [
                        'class' => 'Payments\\Declined',
                        'type' => 'payments-declined',
                        'message' => 'nested external',
                        'code' => 13,
                    ],
                ], RestoredWorkflowException::class, 'Payments\\Declined', 'nested external', 13,
                'payments-declined',
            ],
        ];
        $rows = [];
        foreach ([HistoryEventType::ServiceCallFailed, HistoryEventType::ServiceCallCancelled] as $eventType) {
            foreach ($cases as $name => $arguments) {
                $rows[$eventType->value . ': ' . $name] = [$eventType->value, ...$arguments];
            }
        }

        return $rows;
    }

    public function testCompletedHistoryWinsOverUnresolvedAdmissionAndPreservesTypedResponse(): void
    {
        $run = $this->createRun('sync');
        $this->appendEvent($run, HistoryEventType::ServiceCallStarted, $this->servicePayload());
        $this->appendEvent($run, HistoryEventType::ServiceCallCompleted, [
            'status' => 'completed',
            'outcome' => 'success',
            'response_payload' => [
                'accepted' => false,
                'attempt' => 0,
                'token' => null,
            ],
        ] + $this->servicePayload());

        $surface = [
            'service_call_id' => 'service-call-1',
            'status' => 'completed',
            'outcome' => 'success',
            'endpoint_name' => 'payments',
            'service_name' => 'Payments',
            'operation_name' => 'authorize',
        ];
        $this->assertReadOnlyState($run, [
            'stage' => 'waiting-for-timer',
            'result' => $surface + [
                'response_payload' => [
                    'accepted' => false,
                    'attempt' => 0,
                    'token' => null,
                ],
                'service_call' => $surface,
            ],
            'failure' => null,
        ], TimerCall::class, 2);
    }

    private function createRun(string $mode): WorkflowRun
    {
        $instance = WorkflowInstance::query()->create([
            'id' => 'query-service-' . strtolower((string) Str::ulid()),
            'namespace' => 'query-services',
            'workflow_class' => TestQueryServiceOperationWorkflow::class,
            'workflow_type' => TestQueryServiceOperationWorkflow::class,
            'run_count' => 1,
        ]);
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'namespace' => 'query-services',
            'run_number' => 1,
            'workflow_class' => TestQueryServiceOperationWorkflow::class,
            'workflow_type' => TestQueryServiceOperationWorkflow::class,
            'status' => RunStatus::Waiting->value,
            'payload_codec' => 'avro',
            'arguments' => Serializer::serializeWithCodec('avro', [$mode]),
            'connection' => 'redis',
            'queue' => 'workflow',
            'started_at' => now()
                ->subMinute(),
            'last_progress_at' => now(),
        ]);
        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        return $run;
    }

    private function appendEvent(WorkflowRun $run, HistoryEventType $eventType, array $payload): void
    {
        WorkflowHistoryEvent::record($run, $eventType, $payload);
    }

    private function servicePayload(): array
    {
        return [
            'sequence' => 1,
            'service_call_id' => 'service-call-1',
            'status' => 'started',
            'outcome' => 'accepted',
            'endpoint_name' => 'payments',
            'service_name' => 'Payments',
            'operation_name' => 'authorize',
        ];
    }

    /**
     * @param class-string<ServiceOperationCall|TimerCall> $currentClass
     */
    private function assertReadOnlyState(
        WorkflowRun $run,
        array $expected,
        string $currentClass,
        int $sequence,
    ): void {
        $before = $this->runtimeSnapshot();
        for ($repeat = 0; $repeat < 2; ++$repeat) {
            $inspected = WorkflowRun::query()->findOrFail($run->id);
            $attributes = $inspected->getAttributes();
            $state = (new QueryStateReplayer())->replayState($inspected);

            $this->assertInstanceOf($currentClass, $state->current);
            $this->assertSame($sequence, $state->sequence);
            $this->assertInstanceOf(TestQueryServiceOperationWorkflow::class, $state->workflow);
            $this->assertSame($this->ordered($expected), $this->ordered($state->workflow->currentState()));
            $this->assertSame(
                $this->ordered($expected),
                $this->ordered((new QueryStateReplayer())->query($run->fresh(), 'currentState')),
            );
            $this->assertSame($attributes, $inspected->getAttributes());
            $this->assertSame($before, $this->runtimeSnapshot());
        }
    }

    private function runtimeSnapshot(): array
    {
        $snapshot = [];
        foreach ([
            'workflow_instances',
            'workflow_runs',
            'workflow_history_events',
            'workflow_commands',
            'workflow_tasks',
            'workflow_run_timers',
            'activity_executions',
            'workflow_service_calls',
        ] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()
                ->map(static fn (object $row): array => (array) $row)
                ->all();
        }

        return $snapshot;
    }

    private function ordered(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->ordered($item);
        }

        return $value;
    }
}
