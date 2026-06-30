<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Tests\Fixtures\V2\TestQueryWorkflow;
use Tests\TestCase;
use Workflow\QueryMethod;
use Workflow\Serializers\Serializer;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Support\HistoryExport;
use Workflow\V2\Worker\StandaloneWorkflowWorker;
use Workflow\V2\Worker\WorkerProtocolClient;
use Workflow\V2\Workflow;
use function Workflow\V2\signal;

final class StandaloneWorkflowWorkerTest extends TestCase
{
    public function testTickCompletesRoutedQueryBeforePollingWorkflowTasks(): void
    {
        $http = new HttpFactory();
        $requests = [];

        $http->fake(function (Request $request) use ($http, &$requests) {
            $requests[] = [
                'method' => strtoupper($request->method()),
                'url' => $request->url(),
                'body' => $request->data(),
            ];

            if (str_ends_with($request->url(), '/query-tasks/poll')) {
                return $http->response([
                    'task' => $this->queryTask([
                        'query_task_attempt' => 2,
                        'lease_owner' => 'php-worker',
                    ]),
                    'poll_status' => 'leased',
                ]);
            }

            return $http->response(['recorded' => true]);
        });

        $client = new WorkerProtocolClient($http, 'http://server:8080', 'test-token', 'default');
        $worker = new StandaloneWorkflowWorker($client, [
            'polyglot.php.signal-query' => TestQueryWorkflow::class,
        ]);

        $result = $worker->tick('polyglot', 'php-worker');

        $this->assertSame('query_task', $result['kind'] ?? null);
        $this->assertTrue($result['processed'] ?? false);
        $this->assertSame('completed', $result['outcome'] ?? null);
        $this->assertSame('query-task-1', $result['query_task_id'] ?? null);
        $this->assertSame('currentStage', $result['query_task']['query_name'] ?? null);
        $this->assertSame('workflow-1', $result['query_task']['workflow_id'] ?? null);
        $this->assertSame('run-1', $result['query_task']['run_id'] ?? null);
        $this->assertSame('php-worker', $result['query_task']['lease_owner'] ?? null);
        $this->assertSame(2, $result['query_task']['query_task_attempt'] ?? null);
        $this->assertSame([
            'http://server:8080/api/worker/query-tasks/poll',
            'http://server:8080/api/worker/query-tasks/query-task-1/complete',
        ], array_column($requests, 'url'));
        $this->assertSame([
            'lease_owner' => 'php-worker',
            'query_task_attempt' => 2,
            'result' => 'waiting-for-name',
            'result_envelope' => [
                'codec' => 'avro',
                'blob' => Serializer::serializeWithCodec('avro', 'waiting-for-name'),
            ],
        ], $requests[1]['body']);
    }

    public function testTickCompletesWorkflowTaskWhenNoQueryTaskIsReady(): void
    {
        $http = new HttpFactory();
        $requests = [];

        $http->fake(function (Request $request) use ($http, &$requests) {
            $requests[] = [
                'method' => strtoupper($request->method()),
                'url' => $request->url(),
                'body' => $request->data(),
            ];

            if (str_ends_with($request->url(), '/query-tasks/poll')) {
                return $http->response([
                    'task' => null,
                    'poll_status' => 'empty',
                ]);
            }

            if (str_ends_with($request->url(), '/workflow-tasks/poll')) {
                return $http->response([
                    'task' => $this->workflowTask([
                        'workflow_task_attempt' => 3,
                        'lease_owner' => 'php-worker',
                    ]),
                    'poll_status' => 'leased',
                ]);
            }

            return $http->response(['recorded' => true]);
        });

        $client = new WorkerProtocolClient($http, 'http://server:8080', 'test-token', 'default');
        $worker = new StandaloneWorkflowWorker($client, [
            'polyglot.php.simple' => StandaloneWorkflowWorkerSimpleWorkflow::class,
        ]);

        $result = $worker->tick('polyglot', 'php-worker');

        $this->assertSame('workflow_task', $result['kind'] ?? null);
        $this->assertTrue($result['processed'] ?? false);
        $this->assertSame('completed', $result['outcome'] ?? null);
        $this->assertSame([
            'http://server:8080/api/worker/query-tasks/poll',
            'http://server:8080/api/worker/workflow-tasks/poll',
            'http://server:8080/api/worker/workflow-tasks/workflow-task-1/complete',
        ], array_column($requests, 'url'));
        $this->assertSame('php-worker', $requests[2]['body']['lease_owner'] ?? null);
        $this->assertSame(3, $requests[2]['body']['workflow_task_attempt'] ?? null);
        $this->assertSame('complete_workflow', $requests[2]['body']['commands'][0]['type'] ?? null);
        $this->assertSame(
            'ready',
            Serializer::unserializeWithCodec('avro', $requests[2]['body']['commands'][0]['result'] ?? ''),
        );
    }

    public function testProcessOneWorkflowTaskDrainsReadyQueryBeforeInitialExecutionThenRecordsWait(): void
    {
        $http = new HttpFactory();
        $requests = [];

        $http->fake(function (Request $request) use ($http, &$requests) {
            $requests[] = [
                'method' => strtoupper($request->method()),
                'url' => $request->url(),
                'body' => $request->data(),
            ];

            if (str_ends_with($request->url(), '/workflow-tasks/poll')) {
                return $http->response([
                    'task' => $this->workflowTask([
                        'workflow_type' => 'polyglot.php.signal-query',
                        'workflow_class' => 'polyglot.php.signal-query',
                        'history_events' => [
                            [
                                'id' => 'event-started',
                                'sequence' => 1,
                                'event_type' => HistoryEventType::WorkflowStarted->value,
                                'payload' => [
                                    'workflow_type' => 'polyglot.php.signal-query',
                                    'workflow_class' => 'polyglot.php.signal-query',
                                    'payload_codec' => 'avro',
                                ],
                                'recorded_at' => '2026-05-17T00:00:00+00:00',
                            ],
                        ],
                    ]),
                    'poll_status' => 'leased',
                ]);
            }

            if (str_ends_with($request->url(), '/query-tasks/poll')) {
                return $http->response([
                    'task' => $this->queryTask([
                        'lease_owner' => 'php-worker',
                    ]),
                    'poll_status' => 'leased',
                ]);
            }

            return $http->response([
                'task_id' => 'workflow-task-1',
                'workflow_task_attempt' => 1,
                'outcome' => 'completed',
                'recorded' => true,
                'status' => 200,
            ]);
        });

        $client = new WorkerProtocolClient($http, 'http://server:8080', 'test-token', 'default');
        $worker = new StandaloneWorkflowWorker($client, [
            'polyglot.php.signal-query' => TestQueryWorkflow::class,
        ]);

        $result = $worker->processOneWorkflowTask('polyglot', 'php-worker');

        $this->assertSame('query_task', $result['kind'] ?? null);
        $this->assertTrue($result['processed'] ?? false);
        $this->assertSame('completed', $result['outcome'] ?? null);
        $this->assertSame('query-task-1', $result['query_task_id'] ?? null);
        $this->assertSame('workflow_task', $result['deferred_workflow_task']['kind'] ?? null);
        $this->assertSame('workflow-task-1', $result['deferred_workflow_task']['task_id'] ?? null);
        $this->assertSame('completed', $result['deferred_workflow_task']['outcome'] ?? null);
        $this->assertSame('open_signal_wait', $result['deferred_workflow_task']['commands'][0]['type'] ?? null);
        $this->assertSame([
            'http://server:8080/api/worker/workflow-tasks/poll',
            'http://server:8080/api/worker/query-tasks/poll',
            'http://server:8080/api/worker/query-tasks/query-task-1/complete',
            'http://server:8080/api/worker/workflow-tasks/workflow-task-1/complete',
        ], array_column($requests, 'url'));
        $this->assertSame(1, $requests[1]['body']['timeout_seconds'] ?? null);
        $this->assertSame('waiting-for-name', $requests[2]['body']['result'] ?? null);
    }

    public function testProcessOneWorkflowTaskDrainsReadyQueryAfterCompletion(): void
    {
        $http = new HttpFactory();
        $requests = [];
        $queryPolls = 0;

        $http->fake(function (Request $request) use ($http, &$queryPolls, &$requests) {
            $requests[] = [
                'method' => strtoupper($request->method()),
                'url' => $request->url(),
                'body' => $request->data(),
            ];

            if (str_ends_with($request->url(), '/workflow-tasks/poll')) {
                return $http->response([
                    'task' => $this->workflowTask([
                        'workflow_type' => 'polyglot.php.signal-query',
                        'workflow_class' => 'polyglot.php.signal-query',
                        'history_events' => [
                            [
                                'id' => 'event-started',
                                'sequence' => 1,
                                'event_type' => HistoryEventType::WorkflowStarted->value,
                                'payload' => [
                                    'workflow_type' => 'polyglot.php.signal-query',
                                    'workflow_class' => 'polyglot.php.signal-query',
                                    'payload_codec' => 'avro',
                                ],
                                'recorded_at' => '2026-05-17T00:00:00+00:00',
                            ],
                        ],
                    ]),
                    'poll_status' => 'leased',
                ]);
            }

            if (str_ends_with($request->url(), '/workflow-tasks/workflow-task-1/complete')) {
                return $http->response([
                    'task_id' => 'workflow-task-1',
                    'workflow_task_attempt' => 1,
                    'outcome' => 'completed',
                    'recorded' => true,
                    'status' => 200,
                ]);
            }

            if (str_ends_with($request->url(), '/query-tasks/poll')) {
                $queryPolls++;

                if ($queryPolls === 1) {
                    return $http->response([
                        'task' => null,
                        'poll_status' => 'empty',
                    ]);
                }

                return $http->response([
                    'task' => $this->queryTask([
                        'lease_owner' => 'php-worker',
                    ]),
                    'poll_status' => 'leased',
                ]);
            }

            return $http->response([
                'outcome' => 'completed',
                'status' => 200,
            ]);
        });

        $client = new WorkerProtocolClient($http, 'http://server:8080', 'test-token', 'default');
        $worker = new StandaloneWorkflowWorker($client, [
            'polyglot.php.signal-query' => TestQueryWorkflow::class,
        ]);

        $result = $worker->processOneWorkflowTask('polyglot', 'php-worker');

        $this->assertSame('query_task', $result['kind'] ?? null);
        $this->assertTrue($result['processed'] ?? false);
        $this->assertSame('completed', $result['outcome'] ?? null);
        $this->assertSame('query-task-1', $result['query_task_id'] ?? null);
        $this->assertSame('workflow_task', $result['deferred_workflow_task']['kind'] ?? null);
        $this->assertSame('workflow-task-1', $result['deferred_workflow_task']['task_id'] ?? null);
        $this->assertSame('completed', $result['deferred_workflow_task']['outcome'] ?? null);
        $this->assertSame('open_signal_wait', $result['deferred_workflow_task']['commands'][0]['type'] ?? null);
        $this->assertSame([
            'http://server:8080/api/worker/workflow-tasks/poll',
            'http://server:8080/api/worker/query-tasks/poll',
            'http://server:8080/api/worker/workflow-tasks/workflow-task-1/complete',
            'http://server:8080/api/worker/query-tasks/poll',
            'http://server:8080/api/worker/query-tasks/query-task-1/complete',
        ], array_column($requests, 'url'));
        $this->assertSame(1, $requests[1]['body']['timeout_seconds'] ?? null);
        $this->assertSame(1, $requests[3]['body']['timeout_seconds'] ?? null);
        $this->assertSame('waiting-for-name', $requests[4]['body']['result'] ?? null);
    }

    public function testProcessOneWorkflowTaskKeepsDrainingAfterCompletionUntilInitialCounterQueryArrives(): void
    {
        $http = new HttpFactory();
        $requests = [];
        $queryPolls = 0;

        $http->fake(function (Request $request) use ($http, &$queryPolls, &$requests) {
            $requests[] = [
                'method' => strtoupper($request->method()),
                'url' => $request->url(),
                'body' => $request->data(),
            ];

            if (str_ends_with($request->url(), '/workflow-tasks/poll')) {
                return $http->response([
                    'task' => $this->workflowTask([
                        'workflow_type' => 'conformance.counter.php',
                        'workflow_class' => 'conformance.counter.php',
                        'history_events' => [
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
                        ],
                    ]),
                    'poll_status' => 'leased',
                ]);
            }

            if (str_ends_with($request->url(), '/workflow-tasks/workflow-task-1/complete')) {
                return $http->response([
                    'task_id' => 'workflow-task-1',
                    'workflow_task_attempt' => 1,
                    'outcome' => 'completed',
                    'recorded' => true,
                    'status' => 200,
                ]);
            }

            if (str_ends_with($request->url(), '/query-tasks/poll')) {
                $queryPolls++;

                if ($queryPolls < 6) {
                    return $http->response([
                        'task' => null,
                        'poll_status' => 'empty',
                    ]);
                }

                return $http->response([
                    'task' => $this->queryTask([
                        'lease_owner' => 'php-worker',
                        'workflow_type' => 'conformance.counter.php',
                        'workflow_class' => 'conformance.counter.php',
                        'query_name' => 'state',
                        'history_export' => [
                            'workflow' => [
                                'workflow_type' => 'conformance.counter.php',
                                'workflow_class' => 'conformance.counter.php',
                                'last_history_sequence' => 2,
                            ],
                            'history_events' => [
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
                                    'id' => 'event-signal-wait-opened',
                                    'sequence' => 2,
                                    'type' => HistoryEventType::SignalWaitOpened->value,
                                    'payload' => [
                                        'signal_name' => 'increment',
                                        'signal_wait_id' => 'signal-wait-1',
                                        'sequence' => 1,
                                    ],
                                    'recorded_at' => '2026-05-17T00:00:01+00:00',
                                ],
                            ],
                        ],
                    ]),
                    'poll_status' => 'leased',
                ]);
            }

            return $http->response([
                'outcome' => 'completed',
                'status' => 200,
            ]);
        });

        $client = new WorkerProtocolClient($http, 'http://server:8080', 'test-token', 'default');
        $worker = new StandaloneWorkflowWorker($client, [
            'conformance.counter.php' => StandaloneWorkflowWorkerCounterWorkflow::class,
        ]);

        $result = $worker->processOneWorkflowTask('polyglot', 'php-worker');

        $this->assertSame('query_task', $result['kind'] ?? null);
        $this->assertTrue($result['processed'] ?? false);
        $this->assertSame('completed', $result['outcome'] ?? null);
        $this->assertSame('state', $result['query_task']['query_name'] ?? null);
        $this->assertSame('workflow_task', $result['deferred_workflow_task']['kind'] ?? null);
        $this->assertSame('completed', $result['deferred_workflow_task']['outcome'] ?? null);
        $this->assertSame([
            'http://server:8080/api/worker/workflow-tasks/poll',
            'http://server:8080/api/worker/query-tasks/poll',
            'http://server:8080/api/worker/workflow-tasks/workflow-task-1/complete',
            'http://server:8080/api/worker/query-tasks/poll',
            'http://server:8080/api/worker/query-tasks/poll',
            'http://server:8080/api/worker/query-tasks/poll',
            'http://server:8080/api/worker/query-tasks/poll',
            'http://server:8080/api/worker/query-tasks/poll',
            'http://server:8080/api/worker/query-tasks/query-task-1/complete',
        ], array_column($requests, 'url'));
        $this->assertSame(6, $queryPolls);
        foreach ([1, 3, 4, 5, 6, 7] as $requestIndex) {
            $this->assertSame(1, $requests[$requestIndex]['body']['timeout_seconds'] ?? null);
        }
        $this->assertSame(0, $requests[8]['body']['result'] ?? null);
        $this->assertSame(
            0,
            Serializer::unserializeWithCodec('avro', $requests[8]['body']['result_envelope']['blob'] ?? ''),
        );
    }

    public function testProcessOneWorkflowTaskAcceptsWaitingForHistoryResponseForEmptyCommands(): void
    {
        $http = new HttpFactory();
        $requests = [];

        $http->fake(function (Request $request) use ($http, &$requests) {
            $requests[] = [
                'method' => strtoupper($request->method()),
                'url' => $request->url(),
                'body' => $request->data(),
            ];

            if (str_ends_with($request->url(), '/workflow-tasks/poll')) {
                return $http->response([
                    'task' => $this->workflowTask([
                        'workflow_task_attempt' => 4,
                        'lease_owner' => 'php-worker',
                        'workflow_type' => 'polyglot.php.signal-query',
                        'workflow_class' => 'polyglot.php.signal-query',
                        'history_events' => [
                            [
                                'id' => 'event-started',
                                'sequence' => 1,
                                'event_type' => HistoryEventType::WorkflowStarted->value,
                                'payload' => [
                                    'workflow_type' => 'polyglot.php.signal-query',
                                    'workflow_class' => 'polyglot.php.signal-query',
                                    'payload_codec' => 'avro',
                                ],
                                'recorded_at' => '2026-05-17T00:00:00+00:00',
                            ],
                            [
                                'id' => 'event-signal-wait-opened',
                                'sequence' => 2,
                                'event_type' => HistoryEventType::SignalWaitOpened->value,
                                'payload' => [
                                    'signal_name' => 'name-provided',
                                    'signal_wait_id' => 'signal-wait-1',
                                    'sequence' => 1,
                                ],
                                'recorded_at' => '2026-05-17T00:00:01+00:00',
                            ],
                        ],
                    ]),
                    'poll_status' => 'leased',
                ]);
            }

            if (str_ends_with($request->url(), '/query-tasks/poll')) {
                return $http->response([
                    'task' => null,
                    'poll_status' => 'empty',
                ]);
            }

            return $http->response([
                'task_id' => 'workflow-task-1',
                'workflow_task_attempt' => 4,
                'outcome' => 'waiting_for_history',
                'recorded' => true,
                'reason' => null,
                'next_task_id' => null,
                'status' => 200,
            ]);
        });

        $client = new WorkerProtocolClient($http, 'http://server:8080', 'test-token', 'default');
        $worker = new StandaloneWorkflowWorker($client, [
            'polyglot.php.signal-query' => TestQueryWorkflow::class,
        ]);

        $result = $worker->processOneWorkflowTask('polyglot', 'php-worker');

        $this->assertSame('workflow_task', $result['kind'] ?? null);
        $this->assertTrue($result['processed'] ?? false);
        $this->assertSame('waiting_for_history', $result['outcome'] ?? null);
        $this->assertSame([], $result['commands'] ?? null);
        $this->assertSame('waiting_for_history', $result['worker_response']['outcome'] ?? null);
        $this->assertSame([
            'http://server:8080/api/worker/workflow-tasks/poll',
            'http://server:8080/api/worker/workflow-tasks/workflow-task-1/fail',
            'http://server:8080/api/worker/query-tasks/poll',
            'http://server:8080/api/worker/query-tasks/poll',
            'http://server:8080/api/worker/query-tasks/poll',
        ], array_column($requests, 'url'));
        $this->assertSame([
            'lease_owner' => 'php-worker',
            'workflow_task_attempt' => 4,
            'failure' => [
                'message' => 'Workflow task waiting for scheduled history.',
                'type' => 'WorkflowTaskWaitingForHistory',
            ],
        ], $requests[1]['body']);
        $this->assertSame(1, $requests[2]['body']['timeout_seconds'] ?? null);
        $this->assertSame(1, $requests[3]['body']['timeout_seconds'] ?? null);
        $this->assertSame(1, $requests[4]['body']['timeout_seconds'] ?? null);
    }

    public function testProcessOneWorkflowTaskDrainsReadyQueryAfterWaitingForHistory(): void
    {
        $http = new HttpFactory();
        $requests = [];

        $http->fake(function (Request $request) use ($http, &$requests) {
            $requests[] = [
                'method' => strtoupper($request->method()),
                'url' => $request->url(),
                'body' => $request->data(),
            ];

            if (str_ends_with($request->url(), '/workflow-tasks/poll')) {
                return $http->response([
                    'task' => $this->workflowTask([
                        'workflow_task_attempt' => 4,
                        'lease_owner' => 'php-worker',
                        'workflow_type' => 'polyglot.php.signal-query',
                        'workflow_class' => 'polyglot.php.signal-query',
                        'history_events' => [
                            [
                                'id' => 'event-started',
                                'sequence' => 1,
                                'event_type' => HistoryEventType::WorkflowStarted->value,
                                'payload' => [
                                    'workflow_type' => 'polyglot.php.signal-query',
                                    'workflow_class' => 'polyglot.php.signal-query',
                                    'payload_codec' => 'avro',
                                ],
                                'recorded_at' => '2026-05-17T00:00:00+00:00',
                            ],
                            [
                                'id' => 'event-signal-wait-opened',
                                'sequence' => 2,
                                'event_type' => HistoryEventType::SignalWaitOpened->value,
                                'payload' => [
                                    'signal_name' => 'name-provided',
                                    'signal_wait_id' => 'signal-wait-1',
                                    'sequence' => 1,
                                ],
                                'recorded_at' => '2026-05-17T00:00:01+00:00',
                            ],
                        ],
                    ]),
                    'poll_status' => 'leased',
                ]);
            }

            if (str_ends_with($request->url(), '/workflow-tasks/workflow-task-1/fail')) {
                return $http->response([
                    'task_id' => 'workflow-task-1',
                    'workflow_task_attempt' => 4,
                    'outcome' => 'waiting_for_history',
                    'recorded' => true,
                    'reason' => null,
                    'next_task_id' => null,
                    'status' => 200,
                ]);
            }

            if (str_ends_with($request->url(), '/query-tasks/poll')) {
                return $http->response([
                    'task' => $this->queryTask([
                        'lease_owner' => 'php-worker',
                    ]),
                    'poll_status' => 'leased',
                ]);
            }

            return $http->response([
                'outcome' => 'completed',
                'status' => 200,
            ]);
        });

        $client = new WorkerProtocolClient($http, 'http://server:8080', 'test-token', 'default');
        $worker = new StandaloneWorkflowWorker($client, [
            'polyglot.php.signal-query' => TestQueryWorkflow::class,
        ]);

        $result = $worker->processOneWorkflowTask('polyglot', 'php-worker');

        $this->assertSame('query_task', $result['kind'] ?? null);
        $this->assertTrue($result['processed'] ?? false);
        $this->assertSame('completed', $result['outcome'] ?? null);
        $this->assertSame('workflow_task', $result['deferred_workflow_task']['kind'] ?? null);
        $this->assertSame('waiting_for_history', $result['deferred_workflow_task']['outcome'] ?? null);
        $this->assertSame([], $result['deferred_workflow_task']['commands'] ?? null);
        $this->assertSame([
            'http://server:8080/api/worker/workflow-tasks/poll',
            'http://server:8080/api/worker/workflow-tasks/workflow-task-1/fail',
            'http://server:8080/api/worker/query-tasks/poll',
            'http://server:8080/api/worker/query-tasks/query-task-1/complete',
        ], array_column($requests, 'url'));
        $this->assertSame('waiting-for-name', $requests[3]['body']['result'] ?? null);
    }

    public function testWorkflowPollQueryPendingStatusImmediatelyProcessesQueryTask(): void
    {
        $http = new HttpFactory();
        $requests = [];
        $queryPolls = 0;

        $http->fake(function (Request $request) use ($http, &$queryPolls, &$requests) {
            $requests[] = [
                'method' => strtoupper($request->method()),
                'url' => $request->url(),
                'body' => $request->data(),
            ];

            if (str_ends_with($request->url(), '/query-tasks/poll')) {
                $queryPolls++;

                if ($queryPolls === 1) {
                    return $http->response([
                        'task' => null,
                        'poll_status' => 'empty',
                    ]);
                }

                return $http->response([
                    'task' => $this->queryTask([
                        'lease_owner' => 'php-worker',
                    ]),
                    'poll_status' => 'leased',
                ]);
            }

            if (str_ends_with($request->url(), '/workflow-tasks/poll')) {
                return $http->response([
                    'task' => null,
                    'poll_status' => 'query_task_pending',
                ]);
            }

            return $http->response([
                'outcome' => 'completed',
                'status' => 200,
            ]);
        });

        $client = new WorkerProtocolClient($http, 'http://server:8080', 'test-token', 'default');
        $worker = new StandaloneWorkflowWorker($client, [
            'polyglot.php.signal-query' => TestQueryWorkflow::class,
        ]);

        $result = $worker->tick('polyglot', 'php-worker');

        $this->assertSame('query_task', $result['kind'] ?? null);
        $this->assertTrue($result['processed'] ?? false);
        $this->assertSame('completed', $result['outcome'] ?? null);
        $this->assertSame('query_task_pending', $result['deferred_workflow_poll']['poll_status'] ?? null);
        $this->assertSame([
            'http://server:8080/api/worker/query-tasks/poll',
            'http://server:8080/api/worker/workflow-tasks/poll',
            'http://server:8080/api/worker/query-tasks/poll',
            'http://server:8080/api/worker/query-tasks/query-task-1/complete',
        ], array_column($requests, 'url'));
    }

    public function testWorkflowPollQueryPendingStatusRetiresTimedOutQueryPollFence(): void
    {
        $http = new HttpFactory();
        $requests = [];
        $firstPollRequestId = null;

        $http->fake(function (Request $request) use ($http, &$firstPollRequestId, &$requests) {
            $requests[] = [
                'method' => strtoupper($request->method()),
                'url' => $request->url(),
                'body' => $request->data(),
            ];

            if (str_ends_with($request->url(), '/query-tasks/poll')) {
                $pollRequestId = $request->data()['poll_request_id'] ?? null;
                $firstPollRequestId ??= is_string($pollRequestId) ? $pollRequestId : null;

                if ($pollRequestId === $firstPollRequestId) {
                    throw new ConnectionException('cURL error 28: Operation timed out');
                }

                return $http->response([
                    'task' => $this->queryTask([
                        'lease_owner' => 'php-worker',
                    ]),
                    'poll_status' => 'leased',
                ]);
            }

            if (str_ends_with($request->url(), '/workflow-tasks/poll')) {
                return $http->response([
                    'task' => null,
                    'poll_status' => 'query_task_pending',
                ]);
            }

            return $http->response([
                'outcome' => 'completed',
                'status' => 200,
            ]);
        });

        $client = new WorkerProtocolClient($http, 'http://server:8080', 'test-token', 'default');
        $worker = new StandaloneWorkflowWorker($client, [
            'polyglot.php.signal-query' => TestQueryWorkflow::class,
        ]);

        $result = $worker->tick('polyglot', 'php-worker');

        $this->assertSame('query_task', $result['kind'] ?? null);
        $this->assertTrue($result['processed'] ?? false);
        $this->assertSame('completed', $result['outcome'] ?? null);
        $this->assertSame('query_task_pending', $result['deferred_workflow_poll']['poll_status'] ?? null);
        $this->assertSame([
            'http://server:8080/api/worker/query-tasks/poll',
            'http://server:8080/api/worker/query-tasks/poll',
            'http://server:8080/api/worker/workflow-tasks/poll',
            'http://server:8080/api/worker/query-tasks/poll',
            'http://server:8080/api/worker/query-tasks/query-task-1/complete',
        ], array_column($requests, 'url'));
        $this->assertIsString($requests[0]['body']['poll_request_id'] ?? null);
        $this->assertSame(
            $requests[0]['body']['poll_request_id'] ?? null,
            $requests[1]['body']['poll_request_id'] ?? null,
        );
        $this->assertIsString($requests[3]['body']['poll_request_id'] ?? null);
        $this->assertNotSame(
            $requests[0]['body']['poll_request_id'] ?? null,
            $requests[3]['body']['poll_request_id'] ?? null,
        );
    }

    public function testWorkflowPollQueryPendingStatusKeepsDrainingUntilCounterCurrentQueryArrives(): void
    {
        $http = new HttpFactory();
        $requests = [];
        $queryPolls = 0;

        $http->fake(function (Request $request) use ($http, &$queryPolls, &$requests) {
            $requests[] = [
                'method' => strtoupper($request->method()),
                'url' => $request->url(),
                'body' => $request->data(),
            ];

            if (str_ends_with($request->url(), '/workflow-tasks/poll')) {
                return $http->response([
                    'task' => null,
                    'poll_status' => 'query_task_pending',
                ]);
            }

            if (str_ends_with($request->url(), '/query-tasks/poll')) {
                $queryPolls++;

                if ($queryPolls < 6) {
                    return $http->response([
                        'task' => null,
                        'poll_status' => 'empty',
                    ]);
                }

                return $http->response([
                    'task' => $this->queryTask([
                        'lease_owner' => 'php-worker',
                        'workflow_type' => 'conformance.counter.php',
                        'workflow_class' => 'conformance.counter.php',
                        'query_name' => 'current',
                        'history_export' => [
                            'workflow' => [
                                'workflow_type' => 'conformance.counter.php',
                                'workflow_class' => 'conformance.counter.php',
                            ],
                            'history_events' => [
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
                            ],
                        ],
                    ]),
                    'poll_status' => 'leased',
                ]);
            }

            return $http->response([
                'outcome' => 'completed',
                'status' => 200,
            ]);
        });

        $client = new WorkerProtocolClient($http, 'http://server:8080', 'test-token', 'default');
        $worker = new StandaloneWorkflowWorker($client, [
            'conformance.counter.php' => StandaloneWorkflowWorkerCounterWorkflow::class,
        ]);

        $result = $worker->processOneWorkflowTask('polyglot', 'php-worker');

        $this->assertSame('query_task', $result['kind'] ?? null);
        $this->assertTrue($result['processed'] ?? false);
        $this->assertSame('completed', $result['outcome'] ?? null);
        $this->assertSame('current', $result['query_task']['query_name'] ?? null);
        $this->assertSame('query_task_pending', $result['deferred_workflow_poll']['poll_status'] ?? null);
        $this->assertSame(6, $queryPolls);
        $this->assertSame([
            'http://server:8080/api/worker/workflow-tasks/poll',
            'http://server:8080/api/worker/query-tasks/poll',
            'http://server:8080/api/worker/query-tasks/poll',
            'http://server:8080/api/worker/query-tasks/poll',
            'http://server:8080/api/worker/query-tasks/poll',
            'http://server:8080/api/worker/query-tasks/poll',
            'http://server:8080/api/worker/query-tasks/poll',
            'http://server:8080/api/worker/query-tasks/query-task-1/complete',
        ], array_column($requests, 'url'));
        foreach ([1, 2, 3, 4, 5, 6] as $requestIndex) {
            $this->assertSame(1, $requests[$requestIndex]['body']['timeout_seconds'] ?? null);
        }
        $this->assertSame(0, $requests[7]['body']['result'] ?? null);
        $this->assertSame(
            0,
            Serializer::unserializeWithCodec('avro', $requests[7]['body']['result_envelope']['blob'] ?? ''),
        );
    }

    public function testQueryCompletionRejectedIsReportedAsFailedProcessing(): void
    {
        $http = new HttpFactory();
        $requests = [];

        $http->fake(function (Request $request) use ($http, &$requests) {
            $requests[] = [
                'method' => strtoupper($request->method()),
                'url' => $request->url(),
                'body' => $request->data(),
            ];

            if (str_ends_with($request->url(), '/query-tasks/poll')) {
                return $http->response([
                    'task' => $this->queryTask([
                        'lease_owner' => 'php-worker',
                    ]),
                    'poll_status' => 'leased',
                ]);
            }

            if (str_ends_with($request->url(), '/query-tasks/query-task-1/complete')) {
                return $http->response([
                    'outcome' => 'rejected',
                    'reason' => 'query_task_not_claimed',
                    'message' => 'Query task is no longer leased.',
                    'status' => 409,
                ], 409);
            }

            return $http->response([
                'outcome' => 'failed',
                'status' => 200,
            ]);
        });

        $client = new WorkerProtocolClient($http, 'http://server:8080', 'test-token', 'default');
        $worker = new StandaloneWorkflowWorker($client, [
            'polyglot.php.signal-query' => TestQueryWorkflow::class,
        ]);

        $result = $worker->processOneQueryTask('polyglot', 'php-worker');

        $this->assertSame('query_task', $result['kind'] ?? null);
        $this->assertTrue($result['processed'] ?? false);
        $this->assertSame('failed', $result['outcome'] ?? null);
        $this->assertSame('Query task is no longer leased.', $result['failure']['message'] ?? null);
        $this->assertSame('QueryTaskCompletionRejected', $result['failure']['type'] ?? null);
        $this->assertSame([
            'http://server:8080/api/worker/query-tasks/poll',
            'http://server:8080/api/worker/query-tasks/query-task-1/complete',
            'http://server:8080/api/worker/query-tasks/query-task-1/fail',
        ], array_column($requests, 'url'));
    }

    public function testWorkflowTaskUsesDefaultPayloadCodecWhenPollOmitsCodec(): void
    {
        $http = new HttpFactory();
        $requests = [];

        $http->fake(function (Request $request) use ($http, &$requests) {
            $requests[] = [
                'method' => strtoupper($request->method()),
                'url' => $request->url(),
                'body' => $request->data(),
            ];

            if (str_ends_with($request->url(), '/query-tasks/poll')) {
                return $http->response([
                    'task' => null,
                    'poll_status' => 'empty',
                ]);
            }

            if (str_ends_with($request->url(), '/workflow-tasks/poll')) {
                return $http->response([
                    'task' => $this->workflowTask([
                        'payload_codec' => null,
                    ]),
                    'poll_status' => 'leased',
                ]);
            }

            return $http->response([
                'outcome' => 'completed',
                'recorded' => true,
                'status' => 200,
            ]);
        });

        $client = new WorkerProtocolClient($http, 'http://server:8080', 'test-token', 'default');
        $worker = new StandaloneWorkflowWorker($client, [
            'polyglot.php.simple' => StandaloneWorkflowWorkerSimpleWorkflow::class,
        ]);

        $result = $worker->tick('polyglot', 'php-worker');

        $this->assertSame('workflow_task', $result['kind'] ?? null);
        $this->assertTrue($result['processed'] ?? false);
        $this->assertSame('completed', $result['outcome'] ?? null);
        $this->assertSame('avro', $requests[2]['body']['commands'][0]['payload_codec'] ?? null);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function queryTask(array $overrides = []): array
    {
        return array_replace_recursive([
            'query_task_id' => 'query-task-1',
            'query_task_attempt' => 1,
            'lease_owner' => 'php-worker',
            'workflow_id' => 'workflow-1',
            'run_id' => 'run-1',
            'workflow_type' => 'polyglot.php.signal-query',
            'workflow_class' => 'polyglot.php.signal-query',
            'query_name' => 'currentStage',
            'payload_codec' => 'avro',
            'query_arguments' => [
                'codec' => 'avro',
                'blob' => Serializer::serializeWithCodec('avro', []),
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
                    'workflow_type' => 'polyglot.php.signal-query',
                    'workflow_class' => 'polyglot.php.signal-query',
                    'status' => 'running',
                    'last_history_sequence' => 1,
                    'started_at' => '2026-05-17T00:00:00+00:00',
                ],
                'payloads' => [
                    'codec' => 'avro',
                    'arguments' => [
                        'available' => true,
                        'data' => Serializer::serializeWithCodec('avro', []),
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
                            'workflow_type' => 'polyglot.php.signal-query',
                            'workflow_class' => 'polyglot.php.signal-query',
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
    private function workflowTask(array $overrides = []): array
    {
        return array_replace_recursive([
            'task_id' => 'workflow-task-1',
            'workflow_task_attempt' => 1,
            'lease_owner' => 'php-worker',
            'workflow_id' => 'workflow-1',
            'run_id' => 'run-1',
            'workflow_type' => 'polyglot.php.simple',
            'workflow_class' => 'polyglot.php.simple',
            'payload_codec' => 'avro',
            'arguments' => [
                'codec' => 'avro',
                'blob' => Serializer::serializeWithCodec('avro', []),
            ],
            'history_events' => [
                [
                    'id' => 'event-started',
                    'sequence' => 1,
                    'event_type' => HistoryEventType::WorkflowStarted->value,
                    'payload' => [
                        'workflow_type' => 'polyglot.php.simple',
                        'workflow_class' => 'polyglot.php.simple',
                        'payload_codec' => 'avro',
                    ],
                    'recorded_at' => '2026-05-17T00:00:00+00:00',
                ],
            ],
            'next_history_page_token' => null,
        ], $overrides);
    }
}

final class StandaloneWorkflowWorkerSimpleWorkflow extends Workflow
{
    public function handle(): string
    {
        return 'ready';
    }
}

#[Signal('increment', [[
    'name' => 'amount',
    'type' => 'int',
]])]
final class StandaloneWorkflowWorkerCounterWorkflow extends Workflow
{
    private int $count = 0;

    public function handle(): mixed
    {
        while (true) {
            $this->count += (int) signal('increment');
        }
    }

    #[QueryMethod]
    public function state(): int
    {
        return $this->count;
    }

    #[QueryMethod]
    public function current(): int
    {
        return $this->count;
    }
}
