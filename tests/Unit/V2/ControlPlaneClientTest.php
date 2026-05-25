<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Workflow\V2\Client\ControlPlaneClient;
use Workflow\V2\Exceptions\ControlPlaneRequestException;

final class ControlPlaneClientTest extends TestCase
{
    public function testStartWorkflowSendsControlPlaneHeadersAndBody(): void
    {
        $http = new HttpFactory();
        $requestBody = null;
        $headers = [];

        $http->fake(function (Request $request) use ($http, &$requestBody, &$headers) {
            $requestBody = $request->data();
            $headers = [
                'authorization' => $request->hasHeader('Authorization', 'Bearer test-token'),
                'namespace' => $request->hasHeader('X-Namespace', 'default'),
                'control_plane_version' => $request->hasHeader(
                    ControlPlaneClient::CONTROL_PLANE_HEADER,
                    ControlPlaneClient::CONTROL_PLANE_VERSION,
                ),
            ];

            $this->assertSame('http://server:8080/api/workflows', (string) $request->url());

            return $http->response([
                'workflow_id' => 'counter-1',
                'run_id' => 'run-1',
                'workflow_type' => 'Counter',
            ], 201, [
                ControlPlaneClient::CONTROL_PLANE_HEADER => ControlPlaneClient::CONTROL_PLANE_VERSION,
            ]);
        });

        $client = new ControlPlaneClient($http, 'http://server:8080/', 'test-token');
        $response = $client->startWorkflow('Counter', 'counter-1', [['seed' => 1]], [
            'task_queue' => 'polyglot',
            'business_key' => 'customer-42',
            'memo' => ['suite' => 'signals-queries'],
            'search_attributes' => ['CustomerId' => '42'],
            'duplicate_policy' => 'fail',
            'priority' => 4,
        ]);

        $this->assertSame('counter-1', $response['workflow_id']);
        $this->assertTrue($headers['authorization']);
        $this->assertTrue($headers['namespace']);
        $this->assertTrue($headers['control_plane_version']);
        $this->assertSame([
            'workflow_id' => 'counter-1',
            'workflow_type' => 'Counter',
            'task_queue' => 'polyglot',
            'input' => [['seed' => 1]],
            'business_key' => 'customer-42',
            'memo' => ['suite' => 'signals-queries'],
            'search_attributes' => ['CustomerId' => '42'],
            'duplicate_policy' => 'fail',
            'priority' => 4,
        ], $requestBody);
    }

    public function testSignalWorkflowSupportsCurrentRunAndRunTargetedPaths(): void
    {
        $http = new HttpFactory();
        $requests = [];

        $http->fake(function (Request $request) use ($http, &$requests) {
            $requests[] = [
                'url' => (string) $request->url(),
                'body' => $request->data(),
            ];

            return $http->response([
                'workflow_id' => 'counter-1',
                'run_id' => 'run-1',
                'signal_name' => 'increment',
                'outcome' => 'signal_received',
            ], 202, [
                ControlPlaneClient::CONTROL_PLANE_HEADER => ControlPlaneClient::CONTROL_PLANE_VERSION,
            ]);
        });

        $client = new ControlPlaneClient($http, 'http://server:8080', 'test-token');

        $client->signalWorkflow('counter-1', 'increment', [3], ['request_id' => 'sig-1']);
        $client->signalWorkflow('counter-1', 'increment', [5], ['run_id' => 'run-1']);

        $this->assertSame('http://server:8080/api/workflows/counter-1/signal/increment', $requests[0]['url']);
        $this->assertSame([
            'input' => [3],
            'request_id' => 'sig-1',
        ], $requests[0]['body']);
        $this->assertSame('http://server:8080/api/workflows/counter-1/runs/run-1/signal/increment', $requests[1]['url']);
        $this->assertSame(['input' => [5]], $requests[1]['body']);
    }

    public function testCancelAndTerminateWorkflowSupportCurrentRunAndRunTargetedPaths(): void
    {
        $http = new HttpFactory();
        $requests = [];

        $http->fake(function (Request $request) use ($http, &$requests) {
            $requests[] = [
                'url' => (string) $request->url(),
                'body' => $request->data(),
                'tenant_b' => $request->hasHeader('X-Namespace', 'tenant-b'),
            ];

            return $http->response([
                'workflow_id' => 'counter-1',
                'run_id' => 'run-1',
                'outcome' => 'accepted',
            ], 202, [
                ControlPlaneClient::CONTROL_PLANE_HEADER => ControlPlaneClient::CONTROL_PLANE_VERSION,
            ]);
        });

        $client = new ControlPlaneClient($http, 'http://server:8080', 'test-token', namespace: 'tenant-a');
        $tenantB = $client->withNamespace('tenant-b');

        $this->assertSame('tenant-a', $client->namespace());
        $this->assertSame('tenant-b', $tenantB->namespace());

        $tenantB->cancelWorkflow('counter-1', ['reason' => 'namespace probe', 'request_id' => 'cancel-1']);
        $tenantB->terminateWorkflow('counter-1', ['run_id' => 'run-1', 'request_id' => 'term-1']);

        $this->assertSame([
            [
                'url' => 'http://server:8080/api/workflows/counter-1/cancel',
                'body' => [
                    'reason' => 'namespace probe',
                    'request_id' => 'cancel-1',
                ],
                'tenant_b' => true,
            ],
            [
                'url' => 'http://server:8080/api/workflows/counter-1/runs/run-1/terminate',
                'body' => [
                    'request_id' => 'term-1',
                ],
                'tenant_b' => true,
            ],
        ], $requests);
    }

    public function testQueryWorkflowReturnsRawServerEnvelope(): void
    {
        $http = new HttpFactory();
        $requestBody = null;

        $http->fake(function (Request $request) use ($http, &$requestBody) {
            $requestBody = $request->data();
            $this->assertSame('http://server:8080/api/workflows/counter-1/runs/run-1/query/current', (string) $request->url());

            return $http->response([
                'workflow_id' => 'counter-1',
                'run_id' => 'run-1',
                'query_name' => 'current',
                'result' => 8,
            ], 200, [
                ControlPlaneClient::CONTROL_PLANE_HEADER => ControlPlaneClient::CONTROL_PLANE_VERSION,
            ]);
        });

        $client = new ControlPlaneClient($http, 'http://server:8080', 'test-token');
        $response = $client->queryWorkflow('counter-1', 'current', [], ['run_id' => 'run-1']);

        $this->assertSame([], $requestBody);
        $this->assertSame(8, $response['result']);
    }

    public function testListWorkflowsSendsVisibilityQueryFilters(): void
    {
        $http = new HttpFactory();
        $requestQuery = [];
        $namespaceHeader = false;

        $http->fake(function (Request $request) use ($http, &$requestQuery, &$namespaceHeader) {
            $requestQuery = $request->data();
            if ($requestQuery === []) {
                parse_str(parse_url((string) $request->url(), PHP_URL_QUERY) ?: '', $requestQuery);
            }
            $namespaceHeader = $request->hasHeader('X-Namespace', 'sa-test');

            $this->assertSame('/api/workflows', parse_url((string) $request->url(), PHP_URL_PATH));

            return $http->response([
                'workflows' => [
                    [
                        'workflow_id' => 'order-php-1',
                        'search_attributes' => [
                            'customer_id' => 'cust-8',
                            'priority_tier' => 'platinum',
                        ],
                    ],
                ],
            ], 200, [
                ControlPlaneClient::CONTROL_PLANE_HEADER => ControlPlaneClient::CONTROL_PLANE_VERSION,
            ]);
        });

        $client = new ControlPlaneClient($http, 'http://server:8080', 'test-token', namespace: 'sa-test');
        $response = $client->listWorkflows([
            'query' => 'customer_id = "cust-2" OR customer_id = "cust-8"',
            'status' => 'running',
            'page_size' => 100,
        ]);

        $this->assertTrue($namespaceHeader);
        $this->assertSame('customer_id = "cust-2" OR customer_id = "cust-8"', $requestQuery['query']);
        $this->assertSame('running', $requestQuery['status']);
        $this->assertSame('100', (string) $requestQuery['page_size']);
        $this->assertSame('order-php-1', $response['workflows'][0]['workflow_id']);
    }

    public function testNamespaceLifecycleMethodsUseControlPlaneRoutes(): void
    {
        $http = new HttpFactory();
        $requests = [];

        $http->fake(function (Request $request) use ($http, &$requests) {
            $requests[] = [
                'method' => strtoupper($request->method()),
                'url' => (string) $request->url(),
                'body' => $request->data(),
                'namespace' => $request->hasHeader('X-Namespace', 'tenant-admin'),
            ];

            $headers = [
                ControlPlaneClient::CONTROL_PLANE_HEADER => ControlPlaneClient::CONTROL_PLANE_VERSION,
            ];

            return match (count($requests)) {
                1 => $http->response([
                    'namespaces' => [
                        ['name' => 'default', 'retention_days' => 30, 'status' => 'active'],
                        ['name' => 'billing', 'retention_days' => 90, 'status' => 'active'],
                    ],
                ], 200, $headers),
                2 => $http->response([
                    'name' => 'billing',
                    'description' => 'Billing workflows',
                    'retention_days' => 90,
                    'status' => 'active',
                ], 201, $headers),
                3 => $http->response([
                    'name' => 'billing',
                    'description' => 'Billing workflows and reports',
                    'retention_days' => 120,
                    'status' => 'active',
                ], 200, $headers),
                4 => $http->response([
                    'name' => 'billing',
                    'description' => 'Billing workflows and reports',
                    'retention_days' => 120,
                    'status' => 'active',
                ], 200, $headers),
                5 => $http->response([
                    'name' => 'billing',
                    'external_payload_storage' => [
                        'driver' => 's3',
                        'enabled' => true,
                        'threshold_bytes' => 2097152,
                        'config' => ['disk' => 'external-payload-objects'],
                    ],
                ], 200, $headers),
                default => $http->response([
                    'name' => 'billing',
                    'status' => 'deleted',
                    'deleted' => ['workflow_runs' => 2],
                ], 200, $headers),
            };
        });

        $client = new ControlPlaneClient($http, 'http://server:8080', 'test-token', namespace: 'tenant-admin');

        $list = $client->listNamespaces();
        $created = $client->createNamespace('billing', 'Billing workflows', 90);
        $updated = $client->updateNamespace('billing', 'Billing workflows and reports', 120);
        $described = $client->describeNamespace('billing');
        $storage = $client->setNamespaceExternalStorage(
            'billing',
            's3',
            thresholdBytes: 2097152,
            config: ['disk' => 'external-payload-objects'],
        );
        $deleted = $client->deleteNamespace('billing');

        $this->assertSame('billing', $list['namespaces'][1]['name']);
        $this->assertSame(90, $created['retention_days']);
        $this->assertSame(120, $updated['retention_days']);
        $this->assertSame('active', $described['status']);
        $this->assertSame('s3', $storage['external_payload_storage']['driver']);
        $this->assertSame('deleted', $deleted['status']);

        $this->assertSame([
            [
                'method' => 'GET',
                'url' => 'http://server:8080/api/namespaces',
                'body' => [],
                'namespace' => true,
            ],
            [
                'method' => 'POST',
                'url' => 'http://server:8080/api/namespaces',
                'body' => [
                    'name' => 'billing',
                    'description' => 'Billing workflows',
                    'retention_days' => 90,
                ],
                'namespace' => true,
            ],
            [
                'method' => 'PUT',
                'url' => 'http://server:8080/api/namespaces/billing',
                'body' => [
                    'description' => 'Billing workflows and reports',
                    'retention_days' => 120,
                ],
                'namespace' => true,
            ],
            [
                'method' => 'GET',
                'url' => 'http://server:8080/api/namespaces/billing',
                'body' => [],
                'namespace' => true,
            ],
            [
                'method' => 'PUT',
                'url' => 'http://server:8080/api/namespaces/billing/external-storage',
                'body' => [
                    'driver' => 's3',
                    'enabled' => true,
                    'threshold_bytes' => 2097152,
                    'config' => ['disk' => 'external-payload-objects'],
                ],
                'namespace' => true,
            ],
            [
                'method' => 'DELETE',
                'url' => 'http://server:8080/api/namespaces/billing',
                'body' => [],
                'namespace' => true,
            ],
        ], $requests);
    }

    public function testSearchAttributeDefinitionMethodsUseControlPlaneRoutes(): void
    {
        $http = new HttpFactory();
        $requests = [];

        $http->fake(function (Request $request) use ($http, &$requests) {
            $requests[] = [
                'method' => $request->method(),
                'url' => (string) $request->url(),
                'body' => $request->data(),
            ];

            $headers = [
                ControlPlaneClient::CONTROL_PLANE_HEADER => ControlPlaneClient::CONTROL_PLANE_VERSION,
            ];

            return match ($request->method()) {
                'GET' => $http->response([
                    'custom_attributes' => [
                        'customer_id' => 'string',
                    ],
                ], 200, $headers),
                'POST' => $http->response([
                    'name' => 'priority_tier',
                    'type' => 'keyword',
                ], 201, $headers),
                'DELETE' => $http->response([
                    'deleted' => true,
                    'name' => 'priority tier temp',
                ], 200, $headers),
                default => $http->response([], 500, $headers),
            };
        });

        $client = new ControlPlaneClient($http, 'http://server:8080', 'test-token');

        $definitions = $client->listSearchAttributes();
        $created = $client->createSearchAttribute('priority_tier', 'keyword');
        $deleted = $client->deleteSearchAttribute('priority tier temp');

        $this->assertSame('string', $definitions['custom_attributes']['customer_id']);
        $this->assertSame('priority_tier', $created['name']);
        $this->assertTrue($deleted['deleted']);
        $this->assertSame([
            [
                'method' => 'GET',
                'url' => 'http://server:8080/api/search-attributes',
                'body' => [],
            ],
            [
                'method' => 'POST',
                'url' => 'http://server:8080/api/search-attributes',
                'body' => [
                    'name' => 'priority_tier',
                    'type' => 'keyword',
                ],
            ],
            [
                'method' => 'DELETE',
                'url' => 'http://server:8080/api/search-attributes/priority%20tier%20temp',
                'body' => [],
            ],
        ], $requests);
    }

    public function testScheduleMethodsUseControlPlaneRoutes(): void
    {
        $http = new HttpFactory();
        $requests = [];

        $http->fake(function (Request $request) use ($http, &$requests) {
            $query = $request->data();
            if ($query === []) {
                parse_str(parse_url((string) $request->url(), PHP_URL_QUERY) ?: '', $query);
            }

            $requests[] = [
                'method' => $request->method(),
                'url' => (string) $request->url(),
                'path' => parse_url((string) $request->url(), PHP_URL_PATH),
                'query' => $query,
                'body' => $request->method() === 'GET' ? [] : $request->data(),
                'namespace' => $request->hasHeader('X-Namespace', 'schedule-test'),
            ];

            $headers = [
                ControlPlaneClient::CONTROL_PLANE_HEADER => ControlPlaneClient::CONTROL_PLANE_VERSION,
            ];

            return match (count($requests)) {
                1 => $http->response([
                    'schedules' => [
                        [
                            'schedule_id' => 'php-schedule',
                            'action' => ['workflow_type' => 'PhpWorkflow'],
                            'next_fire_at' => '2026-05-24T06:00:00Z',
                        ],
                    ],
                ], 200, $headers),
                2 => $http->response([
                    'schedule_id' => 'php-schedule',
                    'outcome' => 'created',
                ], 201, $headers),
                3 => $http->response([
                    'schedule_id' => 'php-schedule',
                    'status' => 'active',
                ], 200, $headers),
                4 => $http->response([
                    'schedule_id' => 'php-schedule',
                    'outcome' => 'updated',
                ], 200, $headers),
                5 => $http->response([
                    'schedule_id' => 'php-schedule',
                    'outcome' => 'paused',
                ], 200, $headers),
                6 => $http->response([
                    'schedule_id' => 'php-schedule',
                    'outcome' => 'resumed',
                ], 200, $headers),
                7 => $http->response([
                    'schedule_id' => 'php-schedule',
                    'outcome' => 'triggered',
                    'workflow_id' => 'wf-php-schedule',
                ], 200, $headers),
                8 => $http->response([
                    'schedule_id' => 'php-schedule',
                    'outcome' => 'backfill_started',
                    'fires_attempted' => 2,
                ], 200, $headers),
                9 => $http->response([
                    'schedule_id' => 'php-schedule',
                    'events' => [
                        ['sequence' => 1, 'event_type' => 'ScheduleCreated'],
                    ],
                ], 200, $headers),
                default => $http->response([
                    'schedule_id' => 'php-schedule',
                    'outcome' => 'deleted',
                ], 200, $headers),
            };
        });

        $client = new ControlPlaneClient($http, 'http://server:8080', 'test-token', namespace: 'schedule-test');

        $list = $client->listSchedules(['page_size' => 100]);
        $created = $client->createSchedule(
            'php-schedule',
            [
                'cron_expressions' => ['*/5 * * * *'],
                'timezone' => 'UTC',
            ],
            [
                'workflow_type' => 'PhpWorkflow',
                'task_queue' => 'scheduled',
                'input' => [['source' => 'php']],
            ],
            [
                'overlap_policy' => 'allow_all',
                'jitter_seconds' => 0,
                'max_runs' => 5,
                'memo' => ['client' => 'php'],
                'search_attributes' => ['ScheduleOwner' => 'php'],
                'paused' => true,
                'note' => 'created by php client',
            ],
        );
        $described = $client->describeSchedule('php-schedule');
        $updated = $client->updateSchedule('php-schedule', [
            'spec' => ['intervals' => [['every' => 'PT30S']]],
            'action' => ['workflow_type' => 'PhpWorkflowV2'],
            'overlap_policy' => 'skip',
            'jitter_seconds' => 1,
            'max_runs' => 6,
            'memo' => ['client' => 'php-v2'],
            'search_attributes' => ['ScheduleOwner' => 'php-v2'],
            'note' => 'updated by php client',
        ]);
        $paused = $client->pauseSchedule('php-schedule', 'hold');
        $resumed = $client->resumeSchedule('php-schedule', 'resume');
        $triggered = $client->triggerSchedule('php-schedule', 'allow_all');
        $backfilled = $client->backfillSchedule(
            'php-schedule',
            '2026-05-24T05:00:00Z',
            '2026-05-24T05:10:00Z',
            'allow_all',
        );
        $history = $client->getScheduleHistory('php-schedule', ['limit' => 50, 'after_sequence' => 0]);
        $deleted = $client->deleteSchedule('php-schedule');

        $this->assertSame('php-schedule', $list['schedules'][0]['schedule_id']);
        $this->assertSame('created', $created['outcome']);
        $this->assertSame('active', $described['status']);
        $this->assertSame('updated', $updated['outcome']);
        $this->assertSame('paused', $paused['outcome']);
        $this->assertSame('resumed', $resumed['outcome']);
        $this->assertSame('triggered', $triggered['outcome']);
        $this->assertSame(2, $backfilled['fires_attempted']);
        $this->assertSame('ScheduleCreated', $history['events'][0]['event_type']);
        $this->assertSame('deleted', $deleted['outcome']);

        $this->assertSame([
            [
                'method' => 'GET',
                'path' => '/api/schedules',
                'body' => [],
            ],
            [
                'method' => 'POST',
                'path' => '/api/schedules',
                'body' => [
                    'schedule_id' => 'php-schedule',
                    'spec' => [
                        'cron_expressions' => ['*/5 * * * *'],
                        'timezone' => 'UTC',
                    ],
                    'action' => [
                        'workflow_type' => 'PhpWorkflow',
                        'task_queue' => 'scheduled',
                        'input' => [['source' => 'php']],
                    ],
                    'overlap_policy' => 'allow_all',
                    'jitter_seconds' => 0,
                    'max_runs' => 5,
                    'memo' => ['client' => 'php'],
                    'search_attributes' => ['ScheduleOwner' => 'php'],
                    'paused' => true,
                    'note' => 'created by php client',
                ],
            ],
            [
                'method' => 'GET',
                'path' => '/api/schedules/php-schedule',
                'body' => [],
            ],
            [
                'method' => 'PUT',
                'path' => '/api/schedules/php-schedule',
                'body' => [
                    'spec' => ['intervals' => [['every' => 'PT30S']]],
                    'action' => ['workflow_type' => 'PhpWorkflowV2'],
                    'overlap_policy' => 'skip',
                    'jitter_seconds' => 1,
                    'max_runs' => 6,
                    'memo' => ['client' => 'php-v2'],
                    'search_attributes' => ['ScheduleOwner' => 'php-v2'],
                    'note' => 'updated by php client',
                ],
            ],
            [
                'method' => 'POST',
                'path' => '/api/schedules/php-schedule/pause',
                'body' => ['note' => 'hold'],
            ],
            [
                'method' => 'POST',
                'path' => '/api/schedules/php-schedule/resume',
                'body' => ['note' => 'resume'],
            ],
            [
                'method' => 'POST',
                'path' => '/api/schedules/php-schedule/trigger',
                'body' => ['overlap_policy' => 'allow_all'],
            ],
            [
                'method' => 'POST',
                'path' => '/api/schedules/php-schedule/backfill',
                'body' => [
                    'start_time' => '2026-05-24T05:00:00Z',
                    'end_time' => '2026-05-24T05:10:00Z',
                    'overlap_policy' => 'allow_all',
                ],
            ],
            [
                'method' => 'GET',
                'path' => '/api/schedules/php-schedule/history',
                'body' => [],
            ],
            [
                'method' => 'DELETE',
                'path' => '/api/schedules/php-schedule',
                'body' => [],
            ],
        ], array_map(static fn (array $request): array => [
            'method' => $request['method'],
            'path' => $request['path'],
            'body' => $request['body'],
        ], $requests));

        $this->assertSame('100', (string) $requests[0]['query']['page_size']);
        $this->assertSame('50', (string) $requests[8]['query']['limit']);
        $this->assertSame('0', (string) $requests[8]['query']['after_sequence']);
        $this->assertNotContains(false, array_column($requests, 'namespace'));
    }

    public function testThrowsTypedExceptionForControlPlaneErrors(): void
    {
        $http = new HttpFactory();
        $http->fake(fn () => $http->response([
            'message' => 'Signal argument validation failed.',
            'reason' => 'invalid_signal_arguments',
            'signal_name' => 'increment',
        ], 422, [
            ControlPlaneClient::CONTROL_PLANE_HEADER => ControlPlaneClient::CONTROL_PLANE_VERSION,
        ]));

        $client = new ControlPlaneClient($http, 'http://server:8080', 'test-token');

        try {
            $client->signalWorkflow('counter-1', 'increment', ['not-an-int']);
            $this->fail('Expected control-plane exception.');
        } catch (ControlPlaneRequestException $exception) {
            $this->assertSame(422, $exception->status());
            $this->assertSame('invalid_signal_arguments', $exception->reason());
            $this->assertSame('Signal argument validation failed.', $exception->getMessage());
            $this->assertSame('increment', $exception->body()['signal_name'] ?? null);
        }
    }

    public function testRejectsSuccessfulResponsesWithoutMatchingControlPlaneHeader(): void
    {
        $http = new HttpFactory();
        $http->fake(fn () => $http->response([
            'workflow_id' => 'counter-1',
            'run_id' => 'run-1',
            'workflow_type' => 'Counter',
        ], 201));

        $client = new ControlPlaneClient($http, 'http://server:8080', 'test-token');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('used control-plane version [missing]; expected [2]');

        $client->startWorkflow('Counter', 'counter-1');
    }

    public function testClusterInfoDoesNotRequireControlPlaneResponseHeader(): void
    {
        $http = new HttpFactory();
        $http->fake(function (Request $request) use ($http) {
            $this->assertSame('http://server:8080/api/cluster/info', (string) $request->url());

            return $http->response([
                'control_plane' => [
                    'version' => ControlPlaneClient::CONTROL_PLANE_VERSION,
                ],
            ], 200);
        });

        $client = new ControlPlaneClient($http, 'http://server:8080', 'test-token');
        $info = $client->clusterInfo();

        $this->assertSame(ControlPlaneClient::CONTROL_PLANE_VERSION, $info['control_plane']['version']);
    }
}
