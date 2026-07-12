<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Tests\Fixtures\V2\TestQueryWorkflow;
use Tests\TestCase;
use Workflow\QueryMethod;
use Workflow\Serializers\Serializer;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Contracts\ExternalPayloadStorageDriver;
use Workflow\V2\Contracts\ExternalPayloadStoragePolicy;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Exceptions\InvalidQueryArgumentsException;
use Workflow\V2\Support\ExternalPayloads;
use Workflow\V2\Support\ExternalPayloadStorage;
use Workflow\V2\Support\HistoryExport;
use Workflow\V2\Support\LocalFilesystemExternalPayloadStorage;
use Workflow\V2\Support\ServiceOperationResult;
use Workflow\V2\Workflow;
use Workflow\V2\Worker\WorkflowQueryTaskExecutor;
use function Workflow\V2\signal;

final class WorkflowQueryTaskExecutorTest extends TestCase
{
    private ?string $externalPayloadRoot = null;

    protected function tearDown(): void
    {
        ExternalPayloadStorage::flushVerifiedCache();

        if ($this->externalPayloadRoot !== null) {
            $this->removeDirectory($this->externalPayloadRoot);
            $this->externalPayloadRoot = null;
        }

        parent::tearDown();
    }

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

    public function testExecutorReplaysCompletedServiceOperationResponsePayloadForQuery(): void
    {
        $codec = 'json';
        $driver = new LocalFilesystemExternalPayloadStorage($this->makeExternalPayloadRoot());
        $this->bindExternalPayloadPolicy($driver);
        $responsePayload = ['authorized' => true, 'auth_code' => 'A-42'];
        $storedResponse = ExternalPayloads::externalize(
            Serializer::serializeWithCodec($codec, $responsePayload),
            $codec,
            $driver,
            1,
        );

        $result = (new WorkflowQueryTaskExecutor())->execute($this->queryTask([
            'workflow_type' => 'service-operation-query-workflow',
            'workflow_class' => WorkflowQueryTaskExecutorServiceOperationWorkflow::class,
            'query_name' => 'authorization',
            'history_export' => [
                'workflow' => [
                    'workflow_type' => 'service-operation-query-workflow',
                    'workflow_class' => WorkflowQueryTaskExecutorServiceOperationWorkflow::class,
                    'last_history_sequence' => 2,
                ],
                'history_events' => [
                    [
                        'id' => 'event-started',
                        'sequence' => 1,
                        'type' => HistoryEventType::WorkflowStarted->value,
                        'payload' => [
                            'workflow_type' => 'service-operation-query-workflow',
                            'workflow_class' => WorkflowQueryTaskExecutorServiceOperationWorkflow::class,
                            'payload_codec' => 'avro',
                        ],
                        'recorded_at' => '2026-05-17T00:00:00+00:00',
                    ],
                    [
                        'id' => 'event-service-completed',
                        'sequence' => 2,
                        'type' => HistoryEventType::ServiceCallCompleted->value,
                        'payload' => [
                            'sequence' => 1,
                            'service_call_id' => 'svc-completed-1',
                            'endpoint_name' => 'payments',
                            'service_name' => 'PythonPayments',
                            'operation_name' => 'authorize',
                            'status' => 'completed',
                            'outcome' => 'completed',
                            'payload_codec' => $codec,
                            'response_payload' => ExternalPayloads::historyValue($storedResponse, $codec, null),
                        ],
                        'recorded_at' => '2026-05-17T00:00:01+00:00',
                    ],
                ],
            ],
        ]));

        $this->assertSame('completed', $result['outcome'] ?? null);
        $this->assertSame($responsePayload, $result['result'] ?? null);
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

    public function testExecutorReplaysServerSequencedSignalAppliedForCounterMirror(): void
    {
        $result = (new WorkflowQueryTaskExecutor([
            'conformance.counter.php' => WorkflowQueryTaskExecutorCounterWorkflow::class,
        ]))->execute($this->queryTask([
            'workflow_type' => 'conformance.counter.php',
            'workflow_class' => 'conformance.counter.php',
            'query_name' => 'current',
            'history_export' => [
                'workflow' => [
                    'workflow_type' => 'conformance.counter.php',
                    'workflow_class' => 'conformance.counter.php',
                    'last_history_sequence' => 7,
                ],
                'history_events' => [
                    [
                        'id' => 'event-start-accepted',
                        'sequence' => 1,
                        'type' => HistoryEventType::StartAccepted->value,
                        'payload' => [
                            'sequence' => 1,
                            'workflow_type' => 'conformance.counter.php',
                            'workflow_class' => 'conformance.counter.php',
                            'workflow_run_id' => 'run-1',
                            'workflow_instance_id' => 'workflow-1',
                            'payload_codec' => 'avro',
                        ],
                        'recorded_at' => '2026-05-17T00:00:00+00:00',
                    ],
                    [
                        'id' => 'event-started',
                        'sequence' => 2,
                        'type' => HistoryEventType::WorkflowStarted->value,
                        'payload' => [
                            'workflow_type' => 'conformance.counter.php',
                            'workflow_class' => 'conformance.counter.php',
                            'payload_codec' => 'avro',
                        ],
                        'recorded_at' => '2026-05-17T00:00:01+00:00',
                    ],
                    [
                        'id' => 'event-signal-wait-opened-1',
                        'sequence' => 3,
                        'type' => HistoryEventType::SignalWaitOpened->value,
                        'payload' => [
                            'sequence' => 3,
                            'signal_name' => 'increment',
                            'signal_wait_id' => 'wait-1',
                        ],
                        'recorded_at' => '2026-05-17T00:00:10+00:00',
                    ],
                    [
                        'id' => 'event-signal-received-1',
                        'sequence' => 4,
                        'type' => HistoryEventType::SignalReceived->value,
                        'payload' => [
                            'signal_id' => 'signal-1',
                            'signal_name' => 'increment',
                            'signal_wait_id' => 'wait-1',
                        ],
                        'recorded_at' => '2026-05-17T00:00:20+00:00',
                    ],
                    [
                        'id' => 'event-message-cursor-advanced',
                        'sequence' => 5,
                        'type' => HistoryEventType::MessageCursorAdvanced->value,
                        'payload' => [
                            'stream_key' => 'instance:workflow-1',
                            'previous_position' => 0,
                            'new_position' => 1,
                        ],
                        'recorded_at' => '2026-05-17T00:00:21+00:00',
                    ],
                    [
                        'id' => 'event-signal-applied-1',
                        'sequence' => 6,
                        'type' => HistoryEventType::SignalApplied->value,
                        'payload' => [
                            'sequence' => 3,
                            'signal_id' => 'signal-1',
                            'signal_name' => 'increment',
                            'signal_wait_id' => 'wait-1',
                            'value' => Serializer::serializeWithCodec('avro', 3),
                            'payload_codec' => 'avro',
                        ],
                        'recorded_at' => '2026-05-17T00:00:22+00:00',
                    ],
                    [
                        'id' => 'event-signal-wait-opened-2',
                        'sequence' => 7,
                        'type' => HistoryEventType::SignalWaitOpened->value,
                        'payload' => [
                            'sequence' => 5,
                            'signal_name' => 'increment',
                            'signal_wait_id' => 'wait-2',
                        ],
                        'recorded_at' => '2026-05-17T00:00:30+00:00',
                    ],
                ],
                'signals' => [
                    [
                        'id' => 'signal-1',
                        'name' => 'increment',
                        'signal_wait_id' => 'wait-1',
                        'status' => 'applied',
                        'workflow_sequence' => 3,
                        'payload_codec' => 'avro',
                        'arguments' => Serializer::serializeWithCodec('avro', [3]),
                    ],
                ],
            ],
        ]));

        $this->assertSame('completed', $result['outcome'] ?? null);
        $this->assertSame(3, $result['result'] ?? null);
    }

    public function testExecutorKeepsRapidSignalQueriesOnCommittedPrefixesWhenSequencesDrift(): void
    {
        $events = [
            [
                'id' => 'event-start-accepted',
                'sequence' => 1,
                'type' => HistoryEventType::StartAccepted->value,
                'payload' => [
                    'sequence' => 1,
                    'workflow_type' => 'conformance.counter.php',
                    'workflow_class' => 'conformance.counter.php',
                ],
                'recorded_at' => '2026-05-17T00:00:00+00:00',
            ],
            [
                'id' => 'event-started',
                'sequence' => 2,
                'type' => HistoryEventType::WorkflowStarted->value,
                'payload' => [
                    'workflow_type' => 'conformance.counter.php',
                    'workflow_class' => 'conformance.counter.php',
                    'payload_codec' => 'avro',
                ],
                'recorded_at' => '2026-05-17T00:00:01+00:00',
            ],
            [
                'id' => 'event-signal-wait-opened-1',
                'sequence' => 3,
                'type' => HistoryEventType::SignalWaitOpened->value,
                'payload' => [
                    'sequence' => 3,
                    'signal_name' => 'increment',
                    'signal_wait_id' => 'wait-1',
                ],
                'recorded_at' => '2026-05-17T00:00:10+00:00',
            ],
            [
                'id' => 'event-signal-received-1',
                'sequence' => 4,
                'type' => HistoryEventType::SignalReceived->value,
                'payload' => [
                    'signal_id' => 'signal-1',
                    'signal_name' => 'increment',
                    'signal_wait_id' => 'wait-1',
                ],
                'recorded_at' => '2026-05-17T00:00:20+00:00',
            ],
            [
                'id' => 'event-message-cursor-advanced-1',
                'sequence' => 5,
                'type' => HistoryEventType::MessageCursorAdvanced->value,
                'payload' => [
                    'previous_position' => 0,
                    'new_position' => 1,
                ],
                'recorded_at' => '2026-05-17T00:00:21+00:00',
            ],
            [
                'id' => 'event-signal-applied-1',
                'sequence' => 6,
                'type' => HistoryEventType::SignalApplied->value,
                'payload' => [
                    'sequence' => 5,
                    'signal_id' => 'signal-1',
                    'signal_name' => 'increment',
                    'signal_wait_id' => 'wait-1',
                    'value' => Serializer::serializeWithCodec('avro', 4),
                    'payload_codec' => 'avro',
                ],
                'recorded_at' => '2026-05-17T00:00:22+00:00',
            ],
            [
                'id' => 'event-signal-wait-opened-2',
                'sequence' => 7,
                'type' => HistoryEventType::SignalWaitOpened->value,
                'payload' => [
                    'sequence' => 5,
                    'signal_name' => 'increment',
                    'signal_wait_id' => 'wait-2',
                ],
                'recorded_at' => '2026-05-17T00:00:30+00:00',
            ],
            [
                'id' => 'event-signal-received-2',
                'sequence' => 8,
                'type' => HistoryEventType::SignalReceived->value,
                'payload' => [
                    'signal_id' => 'signal-2',
                    'signal_name' => 'increment',
                    'signal_wait_id' => 'wait-2',
                ],
                'recorded_at' => '2026-05-17T00:00:40+00:00',
            ],
        ];
        $partialSignals = [
            $this->counterSignalExport('signal-1', 'wait-1', 3, 4, 'applied'),
            $this->counterSignalExport('signal-2', 'wait-2', null, 6, 'received'),
        ];
        $committedEvents = [
            ...$events,
            [
                'id' => 'event-signal-applied-2',
                'sequence' => 9,
                'type' => HistoryEventType::SignalApplied->value,
                'payload' => [
                    'sequence' => 7,
                    'signal_id' => 'signal-2',
                    'signal_name' => 'increment',
                    'signal_wait_id' => 'wait-2',
                    'value' => Serializer::serializeWithCodec('avro', 6),
                    'payload_codec' => 'avro',
                ],
                'recorded_at' => '2026-05-17T00:00:41+00:00',
            ],
            [
                'id' => 'event-signal-wait-opened-3',
                'sequence' => 10,
                'type' => HistoryEventType::SignalWaitOpened->value,
                'payload' => [
                    'sequence' => 7,
                    'signal_name' => 'increment',
                    'signal_wait_id' => 'wait-3',
                ],
                'recorded_at' => '2026-05-17T00:00:50+00:00',
            ],
        ];
        $committedSignals = [
            $this->counterSignalExport('signal-1', 'wait-1', 3, 4, 'applied'),
            $this->counterSignalExport('signal-2', 'wait-2', 5, 6, 'applied'),
        ];

        $observed = [
            $this->counterQueryResult($events, $partialSignals),
            $this->counterQueryResult($committedEvents, $committedSignals),
            $this->counterQueryResult($committedEvents, $committedSignals),
        ];

        $this->assertSame([4, 10, 10], $observed);
        foreach ($observed as $value) {
            $this->assertContains($value, [0, 4, 10]);
            $this->assertLessThanOrEqual(10, $value);
        }
    }

    public function testExecutorKeepsEveryRapidSignalQueryAtItsDurableHistoryCutoff(): void
    {
        $acceptedEvents = [
            [
                'id' => 'event-started',
                'sequence' => 1,
                'event_type' => HistoryEventType::WorkflowStarted->value,
                'payload' => [
                    'workflow_type' => 'conformance.counter.php',
                    'workflow_class' => 'conformance.counter.php',
                    'payload_codec' => 'avro',
                ],
                'recorded_at' => '2026-05-17T00:00:00+00:00',
            ],
            [
                'id' => 'event-signal-wait-opened-1',
                'sequence' => 2,
                'event_type' => HistoryEventType::SignalWaitOpened->value,
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
                'event_type' => HistoryEventType::SignalReceived->value,
                'payload' => [
                    'signal_id' => 'signal-1',
                    'signal_name' => 'increment',
                    'signal_wait_id' => 'wait-1',
                    'workflow_sequence' => 1,
                    'payload_codec' => 'avro',
                    'arguments' => [
                        'codec' => 'avro',
                        'blob' => Serializer::serializeWithCodec('avro', [4]),
                    ],
                ],
                'recorded_at' => '2026-05-17T00:00:20+00:00',
            ],
            [
                'id' => 'event-signal-received-2',
                'sequence' => 4,
                'event_type' => HistoryEventType::SignalReceived->value,
                'payload' => [
                    'signal_id' => 'signal-2',
                    'signal_name' => 'increment',
                    'signal_wait_id' => 'wait-2',
                    'workflow_sequence' => 2,
                    'payload_codec' => 'avro',
                    'arguments' => [
                        'codec' => 'avro',
                        'blob' => Serializer::serializeWithCodec('avro', [6]),
                    ],
                ],
                'recorded_at' => '2026-05-17T00:00:21+00:00',
            ],
        ];
        $firstCommittedEvents = [
            ...$acceptedEvents,
            [
                'id' => 'event-signal-applied-1',
                'sequence' => 5,
                'event_type' => HistoryEventType::SignalApplied->value,
                'payload' => [
                    'sequence' => 1,
                    'signal_id' => 'signal-1',
                    'signal_name' => 'increment',
                    'signal_wait_id' => 'wait-1',
                    'value' => Serializer::serializeWithCodec('avro', 4),
                    'payload_codec' => 'avro',
                ],
                'recorded_at' => '2026-05-17T00:00:22+00:00',
            ],
            [
                'id' => 'event-signal-wait-opened-2',
                'sequence' => 6,
                'event_type' => HistoryEventType::SignalWaitOpened->value,
                'payload' => [
                    'sequence' => 2,
                    'signal_name' => 'increment',
                    'signal_wait_id' => 'wait-2',
                ],
                'recorded_at' => '2026-05-17T00:00:23+00:00',
            ],
        ];
        $fullyCommittedEvents = [
            ...$firstCommittedEvents,
            [
                'id' => 'event-signal-applied-2',
                'sequence' => 7,
                'event_type' => HistoryEventType::SignalApplied->value,
                'payload' => [
                    'sequence' => 2,
                    'signal_id' => 'signal-2',
                    'signal_name' => 'increment',
                    'signal_wait_id' => 'wait-2',
                    'value' => Serializer::serializeWithCodec('avro', 6),
                    'payload_codec' => 'avro',
                ],
                'recorded_at' => '2026-05-17T00:00:24+00:00',
            ],
            [
                'id' => 'event-signal-wait-opened-3',
                'sequence' => 8,
                'event_type' => HistoryEventType::SignalWaitOpened->value,
                'payload' => [
                    'sequence' => 3,
                    'signal_name' => 'increment',
                    'signal_wait_id' => 'wait-3',
                ],
                'recorded_at' => '2026-05-17T00:00:25+00:00',
            ],
        ];

        $observed = [
            $this->counterCutoffQueryResult($acceptedEvents),
            $this->counterCutoffQueryResult($firstCommittedEvents),
            $this->counterCutoffQueryResult($fullyCommittedEvents),
            $this->counterCutoffQueryResult($fullyCommittedEvents),
        ];

        $this->assertSame([0, 4, 10, 10], $observed);
    }

    public function testExecutorNeverReusesAReceivedSignalAtAConflictingProjectedSequence(): void
    {
        $events = [
            [
                'id' => 'event-started',
                'sequence' => 1,
                'type' => HistoryEventType::WorkflowStarted->value,
                'payload' => [
                    'workflow_type' => 'conformance.counter.php',
                    'workflow_class' => 'conformance.counter.php',
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
                    'signal_id' => 'signal-1',
                    'signal_name' => 'increment',
                    'signal_wait_id' => 'wait-1',
                    'workflow_sequence' => 2,
                    'payload_codec' => 'avro',
                    'arguments' => [
                        'codec' => 'avro',
                        'blob' => Serializer::serializeWithCodec('avro', [4]),
                    ],
                ],
                'recorded_at' => '2026-05-17T00:00:20+00:00',
            ],
            [
                'id' => 'event-signal-applied-1',
                'sequence' => 4,
                'type' => HistoryEventType::SignalApplied->value,
                'payload' => [
                    'sequence' => 1,
                    'signal_id' => 'signal-1',
                    'signal_name' => 'increment',
                    'signal_wait_id' => 'wait-1',
                    'value' => Serializer::serializeWithCodec('avro', 4),
                    'payload_codec' => 'avro',
                ],
                'recorded_at' => '2026-05-17T00:00:21+00:00',
            ],
            [
                'id' => 'event-signal-wait-opened-2',
                'sequence' => 5,
                'type' => HistoryEventType::SignalWaitOpened->value,
                'payload' => [
                    'sequence' => 2,
                    'signal_name' => 'increment',
                ],
                'recorded_at' => '2026-05-17T00:00:22+00:00',
            ],
            [
                'id' => 'event-signal-received-2',
                'sequence' => 6,
                'type' => HistoryEventType::SignalReceived->value,
                'payload' => [
                    'signal_id' => 'signal-2',
                    'signal_name' => 'increment',
                    'workflow_sequence' => 3,
                    'payload_codec' => 'avro',
                    'arguments' => [
                        'codec' => 'avro',
                        'blob' => Serializer::serializeWithCodec('avro', [6]),
                    ],
                ],
                'recorded_at' => '2026-05-17T00:00:23+00:00',
            ],
            [
                'id' => 'event-signal-wait-opened-3',
                'sequence' => 7,
                'type' => HistoryEventType::SignalWaitOpened->value,
                'payload' => [
                    'sequence' => 3,
                    'signal_name' => 'increment',
                ],
                'recorded_at' => '2026-05-17T00:00:24+00:00',
            ],
        ];

        $this->assertSame(4, $this->counterQueryResult($events, []));
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

    public function testExecutorAnswersInitialCounterMirrorQueryFromSparseServerHistoryExport(): void
    {
        $task = $this->queryTask([
            'workflow_type' => 'polyglot.php.counter',
            'workflow_class' => 'polyglot.php.counter',
            'query_name' => 'state',
            'history_export' => [
                'workflow' => [
                    'workflow_type' => 'polyglot.php.counter',
                    'workflow_class' => 'polyglot.php.counter',
                ],
            ],
        ]);
        $task['history_export']['history_events'] = [];

        $result = (new WorkflowQueryTaskExecutor([
            'polyglot.php.counter' => WorkflowQueryTaskExecutorCounterWorkflow::class,
        ]))->execute($task);

        $this->assertSame('completed', $result['outcome'] ?? null);
        $this->assertSame(0, $result['result'] ?? null);
    }

    public function testExecutorAnswersCounterMirrorQueryAfterSignalsFromSparseServerHistoryExport(): void
    {
        $task = $this->queryTask([
            'workflow_type' => 'polyglot.php.counter',
            'workflow_class' => 'polyglot.php.counter',
            'query_name' => 'current',
            'history_export' => [
                'workflow' => [
                    'workflow_type' => 'polyglot.php.counter',
                    'workflow_class' => 'polyglot.php.counter',
                ],
            ],
        ]);
        $task['history_export']['history_events'] = [
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
                        'blob' => Serializer::serializeWithCodec('avro', [4]),
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
        ];

        $result = (new WorkflowQueryTaskExecutor([
            'polyglot.php.counter' => WorkflowQueryTaskExecutorCounterWorkflow::class,
        ]))->execute($task);

        $this->assertSame('completed', $result['outcome'] ?? null);
        $this->assertSame(4, $result['result'] ?? null);
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

    /**
     * @param list<array<string, mixed>> $events
     * @param list<array<string, mixed>> $signals
     */
    private function counterQueryResult(array $events, array $signals): int
    {
        $result = (new WorkflowQueryTaskExecutor([
            'conformance.counter.php' => WorkflowQueryTaskExecutorCounterWorkflow::class,
        ]))->execute($this->queryTask([
            'workflow_type' => 'conformance.counter.php',
            'workflow_class' => 'conformance.counter.php',
            'query_name' => 'current',
            'history_export' => [
                'workflow' => [
                    'workflow_type' => 'conformance.counter.php',
                    'workflow_class' => 'conformance.counter.php',
                    'last_history_sequence' => count($events),
                ],
                'history_events' => $events,
                'signals' => $signals,
            ],
        ]));

        $this->assertSame('completed', $result['outcome'] ?? null);
        $value = $result['result'] ?? null;
        $this->assertIsInt($value);

        return $value;
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function counterCutoffQueryResult(array $events): int
    {
        $task = $this->queryTask([
            'workflow_type' => 'conformance.counter.php',
            'workflow_class' => 'conformance.counter.php',
            'query_name' => 'current',
            'last_history_sequence' => count($events),
            'history_cutoff_sequence' => count($events),
            'history_events' => $events,
        ]);
        unset($task['history_export']);

        $result = (new WorkflowQueryTaskExecutor([
            'conformance.counter.php' => WorkflowQueryTaskExecutorCounterWorkflow::class,
        ]))->execute($task);

        $this->assertSame('completed', $result['outcome'] ?? null);
        $value = $result['result'] ?? null;
        $this->assertIsInt($value);

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function counterSignalExport(
        string $id,
        string $waitId,
        ?int $workflowSequence,
        int $amount,
        string $status,
    ): array {
        return [
            'id' => $id,
            'name' => 'increment',
            'signal_wait_id' => $waitId,
            'status' => $status,
            'workflow_sequence' => $workflowSequence,
            'payload_codec' => 'avro',
            'arguments' => Serializer::serializeWithCodec('avro', [$amount]),
        ];
    }

    private function bindExternalPayloadPolicy(ExternalPayloadStorageDriver $driver): void
    {
        $this->app->instance(
            ExternalPayloadStoragePolicy::class,
            new class($driver) implements ExternalPayloadStoragePolicy {
                public function __construct(
                    private readonly ExternalPayloadStorageDriver $driver,
                ) {
                }

                public function driverFor(?string $namespace): ?ExternalPayloadStorageDriver
                {
                    return $this->driver;
                }

                public function thresholdBytesFor(?string $namespace): ?int
                {
                    return 1;
                }
            },
        );
    }

    private function makeExternalPayloadRoot(): string
    {
        $this->externalPayloadRoot = sys_get_temp_dir().'/dw-query-service-response-'.bin2hex(random_bytes(6));

        return $this->externalPayloadRoot;
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = scandir($directory);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$item;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } elseif (is_file($path)) {
                unlink($path);
            }
        }

        rmdir($directory);
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

final class WorkflowQueryTaskExecutorServiceOperationWorkflow extends Workflow
{
    private mixed $authorization = null;

    public function handle(): void
    {
        $result = Workflow::serviceOperation(
            'payments',
            'PythonPayments',
            'authorize',
            ['amount' => 4200, 'currency' => 'USD'],
        );

        $this->authorization = $result instanceof ServiceOperationResult
            ? $result->responsePayload
            : null;
    }

    #[QueryMethod]
    public function authorization(): mixed
    {
        return $this->authorization;
    }
}
