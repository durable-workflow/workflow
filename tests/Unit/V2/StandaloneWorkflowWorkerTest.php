<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Tests\Fixtures\V2\TestQueryWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Support\HistoryExport;
use Workflow\V2\Worker\StandaloneWorkflowWorker;
use Workflow\V2\Worker\WorkerProtocolClient;
use Workflow\V2\Workflow;

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
