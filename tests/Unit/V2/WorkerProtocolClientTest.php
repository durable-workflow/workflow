<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Support\WorkerProtocolVersion;
use Workflow\V2\Worker\WorkerProtocolClient;
use Workflow\V2\Worker\WorkflowQueryTaskExecutor;

final class WorkerProtocolClientTest extends TestCase
{
    public function testRegisterWorkerSendsWorkerProtocolHeadersAndBody(): void
    {
        $http = new HttpFactory();
        $requestBody = null;
        $headers = [
            'authorization' => false,
            'namespace' => false,
            'protocol_version' => false,
        ];

        $http->fake(function (Request $request) use ($http, &$requestBody, &$headers) {
            $requestBody = $request->data();
            $headers = [
                'authorization' => $request->hasHeader('Authorization', 'Bearer test-token'),
                'namespace' => $request->hasHeader('X-Namespace', 'default'),
                'protocol_version' => $request->hasHeader(
                    'X-Durable-Workflow-Protocol-Version',
                    WorkerProtocolVersion::VERSION,
                ),
            ];

            return $http->response(['ok' => true, 'heartbeat_interval_seconds' => 60], 201);
        });

        $client = new WorkerProtocolClient($http, 'http://server:8080/', 'test-token', 'default');
        $response = $client->registerWorker(
            workerId: 'php-worker',
            taskQueue: 'polyglot',
            supportedWorkflowTypes: ['demo.workflow'],
            supportedActivityTypes: ['demo.activity'],
            buildId: 'build-a',
            maxConcurrentWorkflowTasks: 2,
            maxConcurrentActivityTasks: 3,
            workflowDefinitionFingerprints: ['demo.workflow' => 'sha256:abc'],
            capabilities: [WorkflowQueryTaskExecutor::CAPABILITY],
        );

        $this->assertSame(['ok' => true, 'heartbeat_interval_seconds' => 60], $response);
        $this->assertTrue($headers['authorization']);
        $this->assertTrue($headers['namespace']);
        $this->assertTrue($headers['protocol_version']);
        $this->assertSame([
            'worker_id' => 'php-worker',
            'task_queue' => 'polyglot',
            'runtime' => 'php',
            'sdk_version' => WorkerProtocolClient::DEFAULT_SDK_VERSION,
            'supported_workflow_types' => ['demo.workflow'],
            'supported_activity_types' => ['demo.activity'],
            'workflow_definition_fingerprints' => ['demo.workflow' => 'sha256:abc'],
            'capabilities' => ['query_tasks'],
            'build_id' => 'build-a',
            'max_concurrent_workflow_tasks' => 2,
            'max_concurrent_activity_tasks' => 3,
        ], $requestBody);
    }

    public function testStandaloneQueryPollAndCompleteUseCachedLease(): void
    {
        $http = new HttpFactory();
        $requests = [];

        $http->fake(function (Request $request) use ($http, &$requests) {
            $requests[] = [
                'method' => strtoupper($request->method()),
                'url' => $request->url(),
                'body' => $request->data(),
            ];

            if (str_ends_with($request->url(), '/poll')) {
                return $http->response([
                    'task' => [
                        'query_task_id' => 'query-task-1',
                        'query_task_attempt' => 2,
                        'lease_owner' => 'php-worker',
                        'query_name' => 'currentStage',
                    ],
                ]);
            }

            return $http->response(['recorded' => true]);
        });

        $client = new WorkerProtocolClient($http, 'http://server:8080', 'test-token', 'default');
        $tasks = $client->pollQueryTasks(queue: 'polyglot', workerId: 'php-worker');
        $complete = $client->completeQueryTask('query-task-1', 'waiting', [
            'codec' => 'avro',
            'blob' => 'encoded-result',
        ]);

        $this->assertCount(1, $tasks);
        $this->assertSame('query-task-1', $tasks[0]['query_task_id']);
        $this->assertSame(['recorded' => true], $complete);
        $this->assertSame([
            [
                'method' => 'POST',
                'url' => 'http://server:8080/api/worker/query-tasks/poll',
                'body' => ['worker_id' => 'php-worker', 'task_queue' => 'polyglot'],
            ],
            [
                'method' => 'POST',
                'url' => 'http://server:8080/api/worker/query-tasks/query-task-1/complete',
                'body' => [
                    'lease_owner' => 'php-worker',
                    'query_task_attempt' => 2,
                    'result' => 'waiting',
                    'result_envelope' => [
                        'codec' => 'avro',
                        'blob' => 'encoded-result',
                    ],
                ],
            ],
        ], $requests);
    }

    public function testStandaloneQueryFailureUsesCachedLease(): void
    {
        $http = new HttpFactory();
        $requests = [];

        $http->fake(function (Request $request) use ($http, &$requests) {
            $requests[] = [
                'method' => strtoupper($request->method()),
                'url' => $request->url(),
                'body' => $request->data(),
            ];

            if (str_ends_with($request->url(), '/poll')) {
                return $http->response([
                    'task' => [
                        'query_task_id' => 'query-task-1',
                        'query_task_attempt' => 3,
                        'lease_owner' => 'php-worker',
                    ],
                ]);
            }

            return $http->response(['recorded' => true]);
        });

        $client = new WorkerProtocolClient($http, 'http://server:8080', 'test-token', 'default');
        $client->pollQueryTasks(queue: 'polyglot', workerId: 'php-worker');
        $failure = $client->failQueryTask(
            'query-task-1',
            'No query handler.',
            reason: 'rejected_unknown_query',
            failureType: 'QueryNotFound',
            validationErrors: [
                'query' => ['Query validation failed.'],
            ],
        );

        $this->assertSame(['recorded' => true], $failure);
        $this->assertSame([
            [
                'method' => 'POST',
                'url' => 'http://server:8080/api/worker/query-tasks/poll',
                'body' => ['worker_id' => 'php-worker', 'task_queue' => 'polyglot'],
            ],
            [
                'method' => 'POST',
                'url' => 'http://server:8080/api/worker/query-tasks/query-task-1/fail',
                'body' => [
                    'lease_owner' => 'php-worker',
                    'query_task_attempt' => 3,
                    'failure' => [
                        'message' => 'No query handler.',
                        'reason' => 'rejected_unknown_query',
                        'type' => 'QueryNotFound',
                        'validation_errors' => [
                            'query' => ['Query validation failed.'],
                        ],
                    ],
                ],
            ],
        ], $requests);
    }

    public function testPollWorkflowTasksUsesStandaloneWorkerApiByDefault(): void
    {
        $http = new HttpFactory();
        $requestBody = null;
        $requestMethod = null;
        $requestUrl = null;

        $http->fake(function (Request $request) use ($http, &$requestBody, &$requestMethod, &$requestUrl) {
            $requestBody = $request->data();
            $requestMethod = strtoupper($request->method());
            $requestUrl = $request->url();

            return $http->response([
                'task' => [
                    'task_id' => 'task-1',
                    'workflow_run_id' => 'run-1',
                    'workflow_task_attempt' => 1,
                    'lease_owner' => 'php-worker',
                    'next_history_page_token' => 'seq:1',
                ],
                'poll_status' => 'leased',
            ]);
        });

        $client = new WorkerProtocolClient($http, 'http://server:8080', 'test-token', 'default');
        $tasks = $client->pollWorkflowTasks(queue: 'polyglot', timeoutSeconds: 120, workerId: 'php-worker');

        $this->assertSame('POST', $requestMethod);
        $this->assertSame('http://server:8080/api/worker/workflow-tasks/poll', $requestUrl);
        $this->assertCount(1, $tasks);
        $this->assertSame('task-1', $tasks[0]['task_id']);
        $this->assertSame([
            'worker_id' => 'php-worker',
            'task_queue' => 'polyglot',
        ], $requestBody);
    }

    public function testEmbeddedBridgeModePollsWebhookTaskOpportunityList(): void
    {
        $http = new HttpFactory();
        $requestBody = null;
        $requestMethod = null;
        $requestUrl = null;

        $http->fake(function (Request $request) use ($http, &$requestBody, &$requestMethod, &$requestUrl) {
            $requestBody = $request->data();
            $requestMethod = strtoupper($request->method());
            $requestUrl = $request->url();

            return $http->response([
                'tasks' => [
                    [
                        'task_id' => 'task-1',
                        'workflow_run_id' => 'run-1',
                    ],
                ],
            ]);
        });

        $client = new WorkerProtocolClient(
            $http,
            'http://server:8080',
            'test-token',
            'default',
            bridgePath: '/webhooks',
            embeddedBridgeMode: true,
        );
        $tasks = $client->pollWorkflowTasks(queue: 'polyglot', limit: 10, timeoutSeconds: 120);

        $this->assertSame('GET', $requestMethod);
        $this->assertStringStartsWith('http://server:8080/webhooks/workflow-tasks/poll', (string) $requestUrl);
        $this->assertCount(1, $tasks);
        $this->assertSame('task-1', $tasks[0]['task_id']);
        $this->assertSame([
            'limit' => 10,
            'timeout_seconds' => WorkerProtocolVersion::MAX_LONG_POLL_TIMEOUT,
            'queue' => 'polyglot',
        ], $requestBody);
    }

    public function testStandaloneWorkflowClaimReturnsCachedLeaseWithoutWebhookRequest(): void
    {
        $http = new HttpFactory();
        $requestUrls = [];

        $http->fake(function (Request $request) use ($http, &$requestUrls) {
            $requestUrls[] = $request->url();

            return $http->response([
                'task' => [
                    'task_id' => 'task-1',
                    'workflow_task_attempt' => 1,
                    'lease_owner' => 'php-worker',
                ],
            ]);
        });

        $client = new WorkerProtocolClient($http, 'http://server:8080', 'test-token', 'default');
        $client->pollWorkflowTasks(queue: 'polyglot', workerId: 'php-worker');
        $claim = $client->claimWorkflowTask('task-1', 'php-worker');

        $this->assertSame(['http://server:8080/api/worker/workflow-tasks/poll'], $requestUrls);
        $this->assertIsArray($claim);
        $this->assertSame('task-1', $claim['task_id']);
        $this->assertSame(1, $claim['workflow_task_attempt']);
    }

    public function testStandaloneWorkflowHistoryAndCompleteUseCachedLease(): void
    {
        $http = new HttpFactory();
        $requests = [];

        $http->fake(function (Request $request) use ($http, &$requests) {
            $requests[] = [
                'method' => strtoupper($request->method()),
                'url' => $request->url(),
                'body' => $request->data(),
            ];

            if (str_ends_with($request->url(), '/poll')) {
                return $http->response([
                    'task' => [
                        'task_id' => 'task-1',
                        'workflow_task_attempt' => 3,
                        'lease_owner' => 'php-worker',
                        'next_history_page_token' => 'seq:10',
                    ],
                ]);
            }

            if (str_ends_with($request->url(), '/history')) {
                return $http->response(['history_events' => [], 'next_history_page_token' => null]);
            }

            return $http->response(['recorded' => true]);
        });

        $client = new WorkerProtocolClient($http, 'http://server:8080', 'test-token', 'default');
        $client->pollWorkflowTasks(queue: 'polyglot', workerId: 'php-worker');
        $history = $client->workflowTaskHistory('task-1');
        $complete = $client->completeWorkflowTask('task-1', [['type' => 'complete_workflow']]);

        $this->assertSame(['history_events' => [], 'next_history_page_token' => null], $history);
        $this->assertSame(['recorded' => true], $complete);
        $this->assertSame([
            [
                'method' => 'POST',
                'url' => 'http://server:8080/api/worker/workflow-tasks/poll',
                'body' => ['worker_id' => 'php-worker', 'task_queue' => 'polyglot'],
            ],
            [
                'method' => 'POST',
                'url' => 'http://server:8080/api/worker/workflow-tasks/task-1/history',
                'body' => [
                    'lease_owner' => 'php-worker',
                    'workflow_task_attempt' => 3,
                    'next_history_page_token' => 'seq:10',
                ],
            ],
            [
                'method' => 'POST',
                'url' => 'http://server:8080/api/worker/workflow-tasks/task-1/complete',
                'body' => [
                    'lease_owner' => 'php-worker',
                    'workflow_task_attempt' => 3,
                    'commands' => [['type' => 'complete_workflow']],
                ],
            ],
        ], $requests);
    }

    public function testClaimWorkflowTaskReturnsNullForNotClaimableRace(): void
    {
        $http = new HttpFactory();

        $client = new WorkerProtocolClient($http, 'http://server:8080', 'test-token', 'default');

        $this->assertNull($client->claimWorkflowTask('task-1', 'php-worker'));
    }

    public function testStandaloneActivityClaimReturnsCachedLeaseWithoutWebhookRequest(): void
    {
        $http = new HttpFactory();
        $requestUrls = [];

        $http->fake(function (Request $request) use ($http, &$requestUrls) {
            $requestUrls[] = $request->url();

            return $http->response([
                'task' => [
                    'task_id' => 'activity-task-1',
                    'activity_attempt_id' => 'attempt-1',
                    'lease_owner' => 'php-worker',
                ],
            ]);
        });

        $client = new WorkerProtocolClient($http, 'http://server:8080', 'test-token', 'default');
        $client->pollActivityTasks(queue: 'polyglot', workerId: 'php-worker');
        $claim = $client->claimActivityTask('activity-task-1', 'php-worker');

        $this->assertSame(['http://server:8080/api/worker/activity-tasks/poll'], $requestUrls);
        $this->assertIsArray($claim);
        $this->assertSame('attempt-1', $claim['activity_attempt_id']);
    }

    public function testCompleteActivityAttemptAcceptsScalarResults(): void
    {
        $http = new HttpFactory();
        $requestBodies = [];
        $requestUrls = [];

        $http->fake(function (Request $request) use ($http, &$requestBodies, &$requestUrls) {
            $requestBodies[] = $request->data();
            $requestUrls[] = $request->url();

            return $http->response(['recorded' => true]);
        });

        $client = new WorkerProtocolClient($http, 'http://server:8080', 'test-token', 'default');
        $results = [42, 3.25, true, false];

        foreach ($results as $index => $result) {
            $response = $client->completeActivityAttempt(
                'attempt-'.($index + 1),
                $result,
                taskId: 'activity-task-'.($index + 1),
                leaseOwner: 'php-worker',
            );

            $this->assertSame(['recorded' => true], $response);
        }

        $this->assertSame([
            'http://server:8080/api/worker/activity-tasks/activity-task-1/complete',
            'http://server:8080/api/worker/activity-tasks/activity-task-2/complete',
            'http://server:8080/api/worker/activity-tasks/activity-task-3/complete',
            'http://server:8080/api/worker/activity-tasks/activity-task-4/complete',
        ], $requestUrls);
        $this->assertSame([
            ['activity_attempt_id' => 'attempt-1', 'lease_owner' => 'php-worker', 'result' => 42],
            ['activity_attempt_id' => 'attempt-2', 'lease_owner' => 'php-worker', 'result' => 3.25],
            ['activity_attempt_id' => 'attempt-3', 'lease_owner' => 'php-worker', 'result' => true],
            ['activity_attempt_id' => 'attempt-4', 'lease_owner' => 'php-worker', 'result' => false],
        ], $requestBodies);
    }

    public function testPollWorkflowTasksTreatsHttpTimeoutAsEmptyPoll(): void
    {
        $http = new HttpFactory();
        $http->fake(fn (Request $request) => throw new ConnectionException(
            'cURL error 28: Operation timed out after 35001 milliseconds',
        ));

        $client = new WorkerProtocolClient($http, 'http://server:8080', 'test-token', 'default');

        $this->assertSame(
            [],
            $client->pollWorkflowTasks(queue: 'polyglot', timeoutSeconds: 30, workerId: 'php-worker'),
        );
    }

    public function testPollActivityTasksRethrowsNonTimeoutConnectionErrors(): void
    {
        $http = new HttpFactory();
        $http->fake(fn (Request $request) => throw new ConnectionException(
            'cURL error 7: Failed to connect to server port 8080',
        ));

        $client = new WorkerProtocolClient($http, 'http://server:8080', 'test-token', 'default');

        $this->expectException(ConnectionException::class);

        $client->pollActivityTasks(queue: 'polyglot', timeoutSeconds: 30, workerId: 'php-worker');
    }
}
