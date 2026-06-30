<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Tests\Fixtures\V2\TestQueryWorkflow;
use Tests\TestCase;
use Workflow\QueryMethod;
use Workflow\Serializers\Serializer;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Exceptions\InvalidQueryArgumentsException;
use Workflow\V2\Support\HistoryExport;
use Workflow\V2\Workflow;
use Workflow\V2\Worker\WorkflowQueryTaskExecutor;
use function Workflow\V2\signal;

final class WorkflowQueryTaskExecutorTest extends TestCase
{
    public function testExecutorReplaysHistoryExportAndCompletesKnownQuery(): void
    {
        $result = (new WorkflowQueryTaskExecutor())->execute($this->queryTask());

        $this->assertSame('completed', $result['outcome'] ?? null);
        $this->assertSame('query-task-1', $result['query_task_id'] ?? null);
        $this->assertSame(1, $result['query_task_attempt'] ?? null);
        $this->assertSame('waiting-for-name', $result['result'] ?? null);
        $this->assertSame('avro', $result['result_envelope']['codec'] ?? null);
        $this->assertSame(
            'waiting-for-name',
            Serializer::unserializeWithCodec('avro', (string) ($result['result_envelope']['blob'] ?? '')),
        );
    }

    public function testExecutorUsesRegisteredExternalWorkflowClassForQueryDeclarations(): void
    {
        $result = (new WorkflowQueryTaskExecutor([
            'polyglot.php.signal-query' => TestQueryWorkflow::class,
        ]))->execute($this->externalQueryTask());

        $this->assertSame('completed', $result['outcome'] ?? null);
        $this->assertSame('waiting-for-name', $result['result'] ?? null);
    }

    public function testExecutorReplaysSignalsForRegisteredExternalWorkflowClass(): void
    {
        $result = (new WorkflowQueryTaskExecutor([
            'polyglot.php.signal-query' => TestQueryWorkflow::class,
        ]))->execute($this->externalQueryTask([
            'query_name' => 'events-starting-with',
            'query_arguments' => [
                'codec' => 'avro',
                'blob' => Serializer::serializeWithCodec('avro', [
                    'prefix' => 'name:Ada',
                ]),
            ],
            'history_export' => [
                'history_events' => [
                    1 => [
                        'id' => 'event-signal-applied',
                        'sequence' => 2,
                        'type' => HistoryEventType::SignalApplied->value,
                        'payload' => [
                            'sequence' => 1,
                            'signal_name' => 'name-provided',
                            'signal_wait_id' => 'external-name-provided',
                            'value' => Serializer::serializeWithCodec('avro', 'Ada'),
                        ],
                        'recorded_at' => '2026-05-17T00:01:00+00:00',
                    ],
                ],
            ],
        ]));

        $this->assertSame('completed', $result['outcome'] ?? null);
        $this->assertSame(1, $result['result'] ?? null);
    }

    public function testExecutorReplaysReceivedSignalHistoryForRegisteredExternalWorkflowClass(): void
    {
        $result = (new WorkflowQueryTaskExecutor([
            'polyglot.php.signal-query' => TestQueryWorkflow::class,
        ]))->execute($this->externalQueryTask([
            'query_name' => 'currentStage',
            'history_export' => [
                'history_events' => [
                    1 => [
                        'id' => 'event-signal-wait-opened',
                        'sequence' => 2,
                        'type' => HistoryEventType::SignalWaitOpened->value,
                        'payload' => [
                            'sequence' => 1,
                            'signal_name' => 'name-provided',
                            'signal_wait_id' => 'external-name-provided',
                        ],
                        'recorded_at' => '2026-05-17T00:00:30+00:00',
                    ],
                    2 => [
                        'id' => 'event-signal-received',
                        'sequence' => 3,
                        'type' => HistoryEventType::SignalReceived->value,
                        'payload' => [
                            'signal_name' => 'name-provided',
                            'signal_wait_id' => 'external-name-provided',
                            'workflow_sequence' => 1,
                            'payload_codec' => 'avro',
                            'arguments' => [
                                'codec' => 'avro',
                                'blob' => Serializer::serializeWithCodec('avro', ['Ada']),
                            ],
                        ],
                        'recorded_at' => '2026-05-17T00:01:00+00:00',
                    ],
                    3 => [
                        'id' => 'event-signal-applied',
                        'sequence' => 4,
                        'type' => HistoryEventType::SignalApplied->value,
                        'payload' => [
                            'sequence' => 1,
                            'signal_name' => 'name-provided',
                            'signal_wait_id' => 'external-name-provided',
                        ],
                        'recorded_at' => '2026-05-17T00:01:01+00:00',
                    ],
                ],
            ],
        ]));

        $this->assertSame('completed', $result['outcome'] ?? null);
        $this->assertSame('waiting-for-timer', $result['result'] ?? null);
    }

    public function testExecutorCorrelatesReceivedSignalHistoryByWaitIdWhenSequenceIsSparse(): void
    {
        $result = (new WorkflowQueryTaskExecutor([
            'polyglot.php.signal-query' => TestQueryWorkflow::class,
        ]))->execute($this->externalQueryTask([
            'query_name' => 'events-starting-with',
            'query_arguments' => [
                'codec' => 'avro',
                'blob' => Serializer::serializeWithCodec('avro', [
                    'prefix' => 'name:Ada',
                ]),
            ],
            'history_export' => [
                'history_events' => [
                    1 => [
                        'id' => 'event-signal-wait-opened',
                        'sequence' => 2,
                        'type' => HistoryEventType::SignalWaitOpened->value,
                        'payload' => [
                            'sequence' => 1,
                            'signal_name' => 'name-provided',
                            'signal_wait_id' => 'external-name-provided',
                        ],
                        'recorded_at' => '2026-05-17T00:00:30+00:00',
                    ],
                    2 => [
                        'id' => 'event-signal-received',
                        'sequence' => 3,
                        'type' => HistoryEventType::SignalReceived->value,
                        'payload' => [
                            'signal_name' => 'name-provided',
                            'signal_wait_id' => 'external-name-provided',
                            'payload_codec' => 'avro',
                            'arguments' => [
                                'codec' => 'avro',
                                'blob' => Serializer::serializeWithCodec('avro', ['Ada']),
                            ],
                        ],
                        'recorded_at' => '2026-05-17T00:01:00+00:00',
                    ],
                    3 => [
                        'id' => 'event-signal-applied',
                        'sequence' => 4,
                        'type' => HistoryEventType::SignalApplied->value,
                        'payload' => [
                            'sequence' => 1,
                            'signal_name' => 'name-provided',
                            'signal_wait_id' => 'external-name-provided',
                        ],
                        'recorded_at' => '2026-05-17T00:01:01+00:00',
                    ],
                ],
            ],
        ]));

        $this->assertSame('completed', $result['outcome'] ?? null);
        $this->assertSame(1, $result['result'] ?? null);
    }

    public function testExecutorReplaysRepeatedReceivedSignalsForCounterMirror(): void
    {
        $result = (new WorkflowQueryTaskExecutor([
            'polyglot.php.counter' => WorkflowQueryTaskExecutorCounterWorkflow::class,
        ]))->execute($this->queryTask([
            'workflow_type' => 'polyglot.php.counter',
            'workflow_class' => 'polyglot.php.counter',
            'query_name' => 'current',
            'history_export' => [
                'workflow' => [
                    'workflow_type' => 'polyglot.php.counter',
                    'workflow_class' => 'polyglot.php.counter',
                ],
                'history_events' => [
                    [
                        'id' => 'event-started',
                        'sequence' => 1,
                        'type' => HistoryEventType::WorkflowStarted->value,
                        'payload' => [
                            'workflow_type' => 'polyglot.php.counter',
                            'workflow_class' => 'polyglot.php.counter',
                            'payload_codec' => 'avro',
                        ],
                        'recorded_at' => '2026-05-17T00:00:00+00:00',
                    ],
                    [
                        'id' => 'event-signal-wait-opened-1',
                        'sequence' => 2,
                        'type' => HistoryEventType::SignalWaitOpened->value,
                        'payload' => [
                            'sequence' => 1,
                            'signal_name' => 'increment',
                            'signal_wait_id' => 'wait-1',
                        ],
                        'recorded_at' => '2026-05-17T00:00:10+00:00',
                    ],
                    [
                        'id' => 'event-signal-received-1',
                        'sequence' => 3,
                        'type' => HistoryEventType::SignalReceived->value,
                        'payload' => [
                            'signal_name' => 'increment',
                            'signal_wait_id' => 'wait-1',
                            'workflow_sequence' => 1,
                            'payload_codec' => 'avro',
                            'arguments' => [
                                'codec' => 'avro',
                                'blob' => Serializer::serializeWithCodec('avro', [3]),
                            ],
                        ],
                        'recorded_at' => '2026-05-17T00:00:20+00:00',
                    ],
                    [
                        'id' => 'event-signal-wait-opened-2',
                        'sequence' => 4,
                        'type' => HistoryEventType::SignalWaitOpened->value,
                        'payload' => [
                            'sequence' => 2,
                            'signal_name' => 'increment',
                            'signal_wait_id' => 'wait-2',
                        ],
                        'recorded_at' => '2026-05-17T00:00:30+00:00',
                    ],
                    [
                        'id' => 'event-signal-received-2',
                        'sequence' => 5,
                        'type' => HistoryEventType::SignalReceived->value,
                        'payload' => [
                            'signal_name' => 'increment',
                            'signal_wait_id' => 'wait-2',
                            'workflow_sequence' => 2,
                            'payload_codec' => 'avro',
                            'arguments' => [
                                'codec' => 'avro',
                                'blob' => Serializer::serializeWithCodec('avro', [5]),
                            ],
                        ],
                        'recorded_at' => '2026-05-17T00:00:40+00:00',
                    ],
                    [
                        'id' => 'event-signal-wait-opened-3',
                        'sequence' => 6,
                        'type' => HistoryEventType::SignalWaitOpened->value,
                        'payload' => [
                            'sequence' => 3,
                            'signal_name' => 'increment',
                            'signal_wait_id' => 'wait-3',
                        ],
                        'recorded_at' => '2026-05-17T00:00:50+00:00',
                    ],
                ],
            ],
        ]));

        $this->assertSame('completed', $result['outcome'] ?? null);
        $this->assertSame(8, $result['result'] ?? null);
    }

    public function testExecutorAnswersInitialCounterMirrorQueryBeforeAnySignal(): void
    {
        $result = (new WorkflowQueryTaskExecutor([
            'polyglot.php.counter' => WorkflowQueryTaskExecutorCounterWorkflow::class,
        ]))->execute($this->queryTask([
            'workflow_type' => 'polyglot.php.counter',
            'workflow_class' => 'polyglot.php.counter',
            'query_name' => 'state',
            'history_export' => [
                'workflow' => [
                    'workflow_type' => 'polyglot.php.counter',
                    'workflow_class' => 'polyglot.php.counter',
                ],
                'history_events' => [
                    [
                        'id' => 'event-started',
                        'sequence' => 1,
                        'type' => HistoryEventType::WorkflowStarted->value,
                        'payload' => [
                            'workflow_type' => 'polyglot.php.counter',
                            'workflow_class' => 'polyglot.php.counter',
                            'payload_codec' => 'avro',
                        ],
                        'recorded_at' => '2026-05-17T00:00:00+00:00',
                    ],
                ],
            ],
        ]));

        $this->assertSame('completed', $result['outcome'] ?? null);
        $this->assertSame(0, $result['result'] ?? null);
    }

    public function testExecutorAnswersInitialCounterMirrorCurrentQueryBeforeAnySignal(): void
    {
        $result = (new WorkflowQueryTaskExecutor([
            'polyglot.php.counter' => WorkflowQueryTaskExecutorCounterWorkflow::class,
        ]))->execute($this->queryTask([
            'workflow_type' => 'polyglot.php.counter',
            'workflow_class' => 'polyglot.php.counter',
            'query_name' => 'current',
            'history_export' => [
                'workflow' => [
                    'workflow_type' => 'polyglot.php.counter',
                    'workflow_class' => 'polyglot.php.counter',
                ],
                'history_events' => [
                    [
                        'id' => 'event-started',
                        'sequence' => 1,
                        'type' => HistoryEventType::WorkflowStarted->value,
                        'payload' => [
                            'workflow_type' => 'polyglot.php.counter',
                            'workflow_class' => 'polyglot.php.counter',
                            'payload_codec' => 'avro',
                        ],
                        'recorded_at' => '2026-05-17T00:00:00+00:00',
                    ],
                ],
            ],
        ]));

        $this->assertSame('completed', $result['outcome'] ?? null);
        $this->assertSame(0, $result['result'] ?? null);
    }

    public function testExecutorDecodesAppliedSignalValueEnvelopeForRegisteredExternalWorkflowClass(): void
    {
        $result = (new WorkflowQueryTaskExecutor([
            'polyglot.php.signal-query' => TestQueryWorkflow::class,
        ]))->execute($this->externalQueryTask([
            'query_name' => 'events-starting-with',
            'query_arguments' => [
                'codec' => 'avro',
                'blob' => Serializer::serializeWithCodec('avro', [
                    'prefix' => 'name:Ada',
                ]),
            ],
            'history_export' => [
                'history_events' => [
                    1 => [
                        'id' => 'event-signal-applied',
                        'sequence' => 2,
                        'type' => HistoryEventType::SignalApplied->value,
                        'payload' => [
                            'sequence' => 1,
                            'signal_name' => 'name-provided',
                            'signal_wait_id' => 'external-name-provided',
                            'value' => [
                                'codec' => 'avro',
                                'blob' => Serializer::serializeWithCodec('avro', 'Ada'),
                            ],
                        ],
                        'recorded_at' => '2026-05-17T00:01:00+00:00',
                    ],
                ],
            ],
        ]));

        $this->assertSame('completed', $result['outcome'] ?? null);
        $this->assertSame(1, $result['result'] ?? null);
    }

    public function testExecutorDecodesSparseSignalAppliedArgumentsFromHistoryExportSignals(): void
    {
        $result = (new WorkflowQueryTaskExecutor([
            'polyglot.php.signal-query' => TestQueryWorkflow::class,
        ]))->execute($this->externalQueryTask([
            'query_name' => 'events-starting-with',
            'query_arguments' => [
                'codec' => 'avro',
                'blob' => Serializer::serializeWithCodec('avro', [
                    'prefix' => 'name:Ada',
                ]),
            ],
            'history_export' => [
                'history_events' => [
                    1 => [
                        'id' => 'event-signal-applied',
                        'sequence' => 2,
                        'type' => HistoryEventType::SignalApplied->value,
                        'payload' => [
                            'sequence' => 1,
                            'signal_id' => 'signal-history-export-1',
                            'signal_name' => 'name-provided',
                            'signal_wait_id' => 'external-name-provided',
                        ],
                        'recorded_at' => '2026-05-17T00:01:00+00:00',
                    ],
                ],
                'signals' => [
                    [
                        'id' => 'signal-history-export-1',
                        'name' => 'name-provided',
                        'signal_wait_id' => 'external-name-provided',
                        'status' => 'applied',
                        'workflow_sequence' => 1,
                        'payload_codec' => 'avro',
                        'arguments' => Serializer::serializeWithCodec('avro', ['Ada']),
                    ],
                ],
            ],
        ]));

        $this->assertSame('completed', $result['outcome'] ?? null);
        $this->assertSame(1, $result['result'] ?? null);
    }

    public function testExecutorFailsUnknownQueryWithoutThrowing(): void
    {
        $result = (new WorkflowQueryTaskExecutor())->execute($this->queryTask([
            'query_name' => 'missing-query',
        ]));

        $this->assertSame('failed', $result['outcome'] ?? null);
        $this->assertSame('query-task-1', $result['query_task_id'] ?? null);
        $this->assertSame(1, $result['query_task_attempt'] ?? null);
        $this->assertSame('rejected_unknown_query', $result['failure']['reason'] ?? null);
        $this->assertSame('QueryNotFound', $result['failure']['type'] ?? null);
    }

    public function testExecutorNormalizesNamedQueryArgumentsAgainstQueryContract(): void
    {
        $result = (new WorkflowQueryTaskExecutor())->execute($this->queryTask([
            'query_name' => 'events-starting-with',
            'query_arguments' => [
                'blob' => Serializer::serializeWithCodec('avro', [
                    'prefix' => 'start',
                ]),
            ],
        ]));

        $this->assertSame('completed', $result['outcome'] ?? null);
        $this->assertSame(1, $result['result'] ?? null);
    }

    public function testExecutorPreservesInvalidQueryArgumentBoundary(): void
    {
        $result = (new WorkflowQueryTaskExecutor())->execute($this->queryTask([
            'query_name' => 'events-starting-with',
            'query_arguments' => [
                'blob' => Serializer::serializeWithCodec('avro', [
                    'extra' => 'start',
                ]),
            ],
        ]));

        $this->assertSame('failed', $result['outcome'] ?? null);
        $this->assertSame('invalid_query_arguments', $result['failure']['reason'] ?? null);
        $this->assertSame(InvalidQueryArgumentsException::class, $result['failure']['type'] ?? null);
        $this->assertSame(
            'Workflow query [events-starting-with] received invalid arguments.',
            $result['failure']['message'] ?? null,
        );
        $this->assertSame(
            ['The prefix argument is required.'],
            $result['failure']['validation_errors']['prefix'] ?? null,
        );
        $this->assertSame(
            ['Unknown argument [extra].'],
            $result['failure']['validation_errors']['extra'] ?? null,
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function queryTask(array $overrides = []): array
    {
        $workflowArguments = Serializer::serializeWithCodec('avro', []);
        $queryArguments = Serializer::serializeWithCodec('avro', []);

        return array_replace_recursive([
            'query_task_id' => 'query-task-1',
            'query_task_attempt' => 1,
            'workflow_id' => 'workflow-1',
            'run_id' => 'run-1',
            'workflow_type' => 'test-query-workflow',
            'workflow_class' => TestQueryWorkflow::class,
            'query_name' => 'currentStage',
            'payload_codec' => 'avro',
            'query_arguments' => [
                'codec' => 'avro',
                'blob' => $queryArguments,
            ],
            'history_export' => [
                'schema' => HistoryExport::SCHEMA,
                'schema_version' => HistoryExport::SCHEMA_VERSION,
                'exported_at' => '2026-05-17T00:00:00+00:00',
                'history_complete' => false,
                'workflow' => [
                    'instance_id' => 'workflow-1',
                    'run_id' => 'run-1',
                    'run_number' => 1,
                    'workflow_type' => 'test-query-workflow',
                    'workflow_class' => TestQueryWorkflow::class,
                    'status' => 'running',
                    'last_history_sequence' => 1,
                    'started_at' => '2026-05-17T00:00:00+00:00',
                ],
                'payloads' => [
                    'codec' => 'avro',
                    'arguments' => [
                        'available' => true,
                        'data' => $workflowArguments,
                    ],
                    'output' => [
                        'available' => false,
                        'data' => null,
                    ],
                ],
                'history_events' => [
                    [
                        'id' => 'event-started',
                        'sequence' => 1,
                        'type' => HistoryEventType::WorkflowStarted->value,
                        'payload' => [
                            'workflow_type' => 'test-query-workflow',
                            'workflow_class' => TestQueryWorkflow::class,
                            'payload_codec' => 'avro',
                        ],
                        'recorded_at' => '2026-05-17T00:00:00+00:00',
                    ],
                ],
                'commands' => [],
                'signals' => [],
                'updates' => [],
                'tasks' => [],
                'activities' => [],
                'timers' => [],
                'failures' => [],
                'links' => [
                    'parents' => [],
                    'children' => [],
                ],
            ],
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function externalQueryTask(array $overrides = []): array
    {
        return array_replace_recursive($this->queryTask([
            'workflow_type' => 'polyglot.php.signal-query',
            'workflow_class' => 'polyglot.php.signal-query',
            'history_export' => [
                'workflow' => [
                    'workflow_type' => 'polyglot.php.signal-query',
                    'workflow_class' => 'polyglot.php.signal-query',
                ],
                'history_events' => [
                    [
                        'id' => 'event-started',
                        'sequence' => 1,
                        'type' => HistoryEventType::WorkflowStarted->value,
                        'payload' => [
                            'workflow_type' => 'polyglot.php.signal-query',
                            'workflow_class' => 'polyglot.php.signal-query',
                            'payload_codec' => 'avro',
                        ],
                        'recorded_at' => '2026-05-17T00:00:00+00:00',
                    ],
                ],
            ],
        ]), $overrides);
    }
}

#[Signal('increment', [[
    'name' => 'amount',
    'type' => 'int',
]])]
final class WorkflowQueryTaskExecutorCounterWorkflow extends Workflow
{
    private int $count = 0;

    public function handle(): mixed
    {
        while (true) {
            $this->count += (int) signal('increment');
        }
    }

    #[QueryMethod]
    public function current(): int
    {
        return $this->count;
    }

    #[QueryMethod]
    public function state(): int
    {
        return $this->count;
    }
}
