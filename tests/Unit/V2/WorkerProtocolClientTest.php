<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
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
        $this->assertIsArray($requestBody['process_metrics'] ?? null);
        $this->assertIsInt($requestBody['process_metrics']['process_id'] ?? null);
        $this->assertIsString($requestBody['process_metrics']['process_started_at'] ?? null);
        $this->assertIsInt($requestBody['process_metrics']['process_uptime_seconds'] ?? null);
        unset($requestBody['process_metrics']);
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

    public function testStandaloneWorkflowWorkerRegistrationAdvertisesQueryTaskCapabilityByDefault(): void
    {
        $http = new HttpFactory();
        $requestBody = null;

        $http->fake(function (Request $request) use ($http, &$requestBody) {
            $requestBody = $request->data();

            return $http->response(['registered' => true], 201);
        });

        $client = new WorkerProtocolClient($http, 'http://server:8080', 'test-token', 'default');
        $client->registerWorker(
            workerId: 'php-worker',
            taskQueue: 'polyglot',
            supportedWorkflowTypes: ['polyglot.php.signal-query'],
        );

        $this->assertSame(['query_tasks'], $requestBody['capabilities'] ?? null);
    }

    public function testStandaloneWorkflowWorkerCanAdvertiseCommandContracts(): void
    {
        $http = new HttpFactory();
        $requestBody = null;

        $http->fake(function (Request $request) use ($http, &$requestBody) {
            $requestBody = $request->data();

            return $http->response(['registered' => true], 201);
        });

        $contracts = [
            'polyglot.php.signal-query' => [
                'queries' => ['current'],
                'query_contracts' => [['name' => 'current', 'parameters' => []]],
                'signals' => ['increment'],
                'signal_contracts' => [[
                    'name' => 'increment',
                    'parameters' => [[
                        'name' => 'amount',
                        'position' => 0,
                        'required' => true,
                        'variadic' => false,
                        'default_available' => false,
                        'default' => null,
                        'type' => 'int',
                        'allows_null' => false,
                    ]],
                ]],
                'updates' => [],
                'update_contracts' => [],
                'entry_method' => 'handle',
                'entry_mode' => 'canonical',
                'entry_declaring_class' => 'ConformanceCounterWorkflow',
            ],
        ];

        $client = new WorkerProtocolClient($http, 'http://server:8080', 'test-token', 'default');
        $client->registerWorker(
            workerId: 'php-worker',
            taskQueue: 'polyglot',
            supportedWorkflowTypes: ['polyglot.php.signal-query'],
            workflowCommandContracts: $contracts,
        );

        $this->assertSame($contracts, $requestBody['workflow_command_contracts'] ?? null);
    }

    public function testWorkerClientCanSelectNamespaceForSameQueueIsolation(): void
    {
        $http = new HttpFactory();
        $requests = [];

        $http->fake(function (Request $request) use ($http, &$requests) {
            $requests[] = [
                'namespace_a' => $request->hasHeader('X-Namespace', 'tenant-a'),
                'namespace_b' => $request->hasHeader('X-Namespace', 'tenant-b'),
                'body' => $request->data(),
            ];

            return $http->response(['registered' => true], 201);
        });

        $defaultClient = new WorkerProtocolClient($http, 'http://server:8080', 'test-token', 'default');
        $tenantA = $defaultClient->withNamespace('tenant-a');
        $tenantB = $defaultClient->withNamespace('tenant-b');

        $this->assertSame('default', $defaultClient->namespace());
        $this->assertSame('tenant-a', $tenantA->namespace());
        $this->assertSame('tenant-b', $tenantB->namespace());

        $tenantA->registerWorker(workerId: 'php-worker-a', taskQueue: 'iso');
        $tenantB->registerWorker(workerId: 'php-worker-b', taskQueue: 'iso');

        unset($requests[0]['body']['process_metrics'], $requests[1]['body']['process_metrics']);

        $this->assertSame([
            [
                'namespace_a' => true,
                'namespace_b' => false,
                'body' => [
                    'worker_id' => 'php-worker-a',
                    'task_queue' => 'iso',
                    'runtime' => 'php',
                    'sdk_version' => WorkerProtocolClient::DEFAULT_SDK_VERSION,
                    'supported_workflow_types' => [],
                    'supported_activity_types' => [],
                ],
            ],
            [
                'namespace_a' => false,
                'namespace_b' => true,
                'body' => [
                    'worker_id' => 'php-worker-b',
                    'task_queue' => 'iso',
                    'runtime' => 'php',
                    'sdk_version' => WorkerProtocolClient::DEFAULT_SDK_VERSION,
                    'supported_workflow_types' => [],
                    'supported_activity_types' => [],
                ],
            ],
        ], $requests);
    }

    public function testActivityOnlyWorkerRegistrationDoesNotAdvertiseQueryTasksByDefault(): void
    {
        $http = new HttpFactory();
        $requestBody = null;

        $http->fake(function (Request $request) use ($http, &$requestBody) {
            $requestBody = $request->data();

            return $http->response(['registered' => true], 201);
        });

        $client = new WorkerProtocolClient($http, 'http://server:8080', 'test-token', 'default');
        $client->registerWorker(
            workerId: 'php-activity-worker',
            taskQueue: 'polyglot',
            supportedActivityTypes: ['polyglot.php.activity'],
        );

        $this->assertArrayNotHasKey('capabilities', $requestBody);
    }

    public function testExplicitWorkerCapabilitiesOverrideQueryTaskDefault(): void
    {
        $http = new HttpFactory();
        $requestBody = null;

        $http->fake(function (Request $request) use ($http, &$requestBody) {
            $requestBody = $request->data();

            return $http->response(['registered' => true], 201);
        });

        $client = new WorkerProtocolClient($http, 'http://server:8080', 'test-token', 'default');
        $client->registerWorker(
            workerId: 'php-worker',
            taskQueue: 'polyglot',
            supportedWorkflowTypes: ['polyglot.php.signal-query'],
            capabilities: [],
        );

        $this->assertSame([], $requestBody['capabilities'] ?? null);
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
        $tasks = $client->pollQueryTasks(
            queue: 'polyglot',
            workerId: 'php-worker',
            pollRequestId: 'query-poll-request-1',
        );
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
                'body' => [
                    'worker_id' => 'php-worker',
                    'task_queue' => 'polyglot',
                    'poll_request_id' => 'query-poll-request-1',
                ],
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
        $client->pollQueryTasks(
            queue: 'polyglot',
            workerId: 'php-worker',
            pollRequestId: 'query-poll-request-2',
        );
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
                'body' => [
                    'worker_id' => 'php-worker',
                    'task_queue' => 'polyglot',
                    'poll_request_id' => 'query-poll-request-2',
                ],
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

    public function testStandaloneQueryPollRetriesTimeoutWithSamePollRequestId(): void
    {
        $http = new HttpFactory();
        $requests = [];

        $http->fake(function (Request $request) use ($http, &$requests) {
            $requests[] = $request->data();

            if (count($requests) === 1) {
                throw new ConnectionException('cURL error 28: Operation timed out');
            }

            return $http->response([
                'task' => [
                    'query_task_id' => 'query-task-retry',
                    'query_task_attempt' => 1,
                    'lease_owner' => 'php-worker',
                ],
            ]);
        });

        $client = new WorkerProtocolClient($http, 'http://server:8080', 'test-token', 'default');
        $tasks = $client->pollQueryTasks(queue: 'polyglot', workerId: 'php-worker');

        $this->assertCount(1, $tasks);
        $this->assertSame('query-task-retry', $tasks[0]['query_task_id']);
        $this->assertCount(2, $requests);
        $this->assertSame('php-worker', $requests[0]['worker_id']);
        $this->assertSame('polyglot', $requests[0]['task_queue']);
        $this->assertIsString($requests[0]['poll_request_id']);
        $this->assertNotSame('', $requests[0]['poll_request_id']);
        $this->assertSame($requests[0]['poll_request_id'], $requests[1]['poll_request_id']);
    }

    public function testStandaloneQueryPollRecoversTimedOutGeneratedPollRequestOnNextCall(): void
    {
        $http = new HttpFactory();
        $requests = [];

        $http->fake(function (Request $request) use ($http, &$requests) {
            $requests[] = $request->data();

            if (count($requests) <= 2) {
                throw new ConnectionException('cURL error 28: Operation timed out');
            }

            return $http->response([
                'task' => [
                    'query_task_id' => 'query-task-recovered',
                    'query_task_attempt' => 1,
                    'lease_owner' => 'php-worker',
                ],
            ]);
        });

        $client = new WorkerProtocolClient($http, 'http://server:8080', 'test-token', 'default');

        $this->assertSame([], $client->pollQueryTasks(queue: 'polyglot', workerId: 'php-worker'));

        $tasks = $client->pollQueryTasks(queue: 'polyglot', workerId: 'php-worker');

        $this->assertCount(1, $tasks);
        $this->assertSame('query-task-recovered', $tasks[0]['query_task_id']);
        $this->assertCount(3, $requests);
        $this->assertIsString($requests[0]['poll_request_id']);
        $this->assertNotSame('', $requests[0]['poll_request_id']);
        $this->assertSame($requests[0]['poll_request_id'], $requests[1]['poll_request_id']);
        $this->assertSame($requests[0]['poll_request_id'], $requests[2]['poll_request_id']);
    }

    public function testStandaloneQueryLongPollRequestTimeoutHonorsCallerTimeout(): void
    {
        $client = new WorkerProtocolClient(new HttpFactory(), 'http://server:8080', 'test-token', 'default');
        $timeout = new ReflectionMethod($client, 'longPollRequestTimeoutSeconds');
        $timeout->setAccessible(true);

        $this->assertSame(6, $timeout->invoke($client, 1));
        $this->assertSame(35, $timeout->invoke($client, WorkerProtocolVersion::DEFAULT_LONG_POLL_TIMEOUT));
        $this->assertSame(65, $timeout->invoke($client, WorkerProtocolVersion::MAX_LONG_POLL_TIMEOUT + 30));
    }

    public function testStandaloneWorkerTaskLongPollRequestTimeoutHonorsCallerTimeout(): void
    {
        $client = new WorkerProtocolClient(new HttpFactory(), 'http://server:8080', 'test-token', 'default');
        $timeout = new ReflectionMethod($client, 'workerTaskLongPollRequestTimeoutSeconds');
        $timeout->setAccessible(true);

        $this->assertSame(6, $timeout->invoke($client, 1));
        $this->assertSame(35, $timeout->invoke($client, WorkerProtocolVersion::DEFAULT_LONG_POLL_TIMEOUT));
        $this->assertSame(65, $timeout->invoke($client, WorkerProtocolVersion::MAX_LONG_POLL_TIMEOUT + 30));
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
            'timeout_seconds' => WorkerProtocolVersion::MAX_LONG_POLL_TIMEOUT,
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
                'body' => [
                    'worker_id' => 'php-worker',
                    'task_queue' => 'polyglot',
                    'timeout_seconds' => WorkerProtocolVersion::DEFAULT_LONG_POLL_TIMEOUT,
                ],
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

    public function testStandaloneWorkflowCompleteWithNoCommandsAcknowledgesWaitingForHistory(): void
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
                        'task_id' => 'task-waiting',
                        'workflow_task_attempt' => 4,
                        'lease_owner' => 'php-worker',
                    ],
                ]);
            }

            return $http->response([
                'task_id' => 'task-waiting',
                'workflow_task_attempt' => 4,
                'outcome' => 'waiting_for_history',
                'recorded' => true,
                'reason' => null,
                'next_task_id' => null,
            ]);
        });

        $client = new WorkerProtocolClient($http, 'http://server:8080', 'test-token', 'default');
        $client->pollWorkflowTasks(queue: 'polyglot', workerId: 'php-worker');
        $complete = $client->completeWorkflowTask('task-waiting', []);

        $this->assertSame('waiting_for_history', $complete['outcome'] ?? null);
        $this->assertSame([
            [
                'method' => 'POST',
                'url' => 'http://server:8080/api/worker/workflow-tasks/poll',
                'body' => [
                    'worker_id' => 'php-worker',
                    'task_queue' => 'polyglot',
                    'timeout_seconds' => WorkerProtocolVersion::DEFAULT_LONG_POLL_TIMEOUT,
                ],
            ],
            [
                'method' => 'POST',
                'url' => 'http://server:8080/api/worker/workflow-tasks/task-waiting/fail',
                'body' => [
                    'lease_owner' => 'php-worker',
                    'workflow_task_attempt' => 4,
                    'failure' => [
                        'message' => 'Workflow task waiting for scheduled history.',
                        'type' => 'WorkflowTaskWaitingForHistory',
                    ],
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
        $requestBodies = [];

        $http->fake(function (Request $request) use ($http, &$requestBodies, &$requestUrls) {
            $requestBodies[] = $request->data();
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
        $this->assertSame([[
            'worker_id' => 'php-worker',
            'task_queue' => 'polyglot',
            'timeout_seconds' => WorkerProtocolVersion::DEFAULT_LONG_POLL_TIMEOUT,
        ]], $requestBodies);
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
