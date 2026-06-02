<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Str;
use Tests\TestCase;
use Workflow\V2\Client\ControlPlaneClient;

final class V2NamespaceConformanceCommandTest extends TestCase
{
    public function testCommandEmitsPhpNamespaceConformanceShard(): void
    {
        $requests = [];
        $http = $this->namespaceConformanceHttpFake('php-ns-test', 'instance_not_found', $requests);

        $this->app->instance(HttpFactory::class, $http);
        $reportPath = $this->ephemeralPath('namespace-conformance-out');

        $this->artisan('workflow:v2:namespace-conformance', [
            '--server-url' => 'http://server:8080',
            '--token' => 'test-token',
            '--run-id' => 'php-ns-test',
            '--artifact-version' => [
                'server=0.2.171',
                'cli=0.1.55',
                'workflow=2.0.0-alpha.174',
                'sdk-python=0.4.73',
                'waterline=2.0.0-alpha.57',
            ],
            '--artifact-source' => [
                'server=docker_image',
                'cli=official_install_script',
                'workflow=packagist_package',
                'sdk-python=pypi_package',
                'waterline=packagist_package',
            ],
            '--output' => $reportPath,
        ])->assertSuccessful();

        $report = $this->readJson($reportPath);
        $scenarios = array_column($report['scenario_results'], null, 'scenario_id');

        $this->assertSame('durable-workflow.v2.namespace-runtime.result', $report['schema']);
        $this->assertSame('workflow-php-namespace-shard', $report['coverage_scope']);
        $this->assertSame('non_passing', $report['outcome']);
        $this->assertSame('2.0.0-alpha.174', $report['artifact_versions']['workflow-php']);
        $this->assertSame(['workflow-php'], $report['runtime_matrix']['runtimes']);
        $this->assertSame('pass', $scenarios['published_artifact_install_only']['status']);
        $this->assertSame('pass', $scenarios['namespace_create_update_describe_and_list']['status']);
        $this->assertSame('pass', $scenarios['sdk_namespace_selection_parity']['status']);
        $this->assertSame('pass', $scenarios['php_worker_task_queue_namespace_isolation']['status']);
        $this->assertSame('pass', $scenarios['result_record_and_product_finding_routing']['status']);
        $this->assertSame('not_covered', $scenarios['nexus_explicit_cross_namespace_invocation']['status']);
        $this->assertTrue(
            $scenarios['sdk_namespace_selection_parity']['observed_outputs']['cross_namespace_lookup_denied']['not_found'],
        );
        $this->assertTrue(
            $scenarios['namespace_create_update_describe_and_list']['observed_outputs']['semantic_checks']['passed'],
        );
        $this->assertTrue(
            $scenarios['php_worker_task_queue_namespace_isolation']['observed_outputs']['cross_delivery_absent']['passed'],
        );

        $tenantAWorkerRequests = array_values(array_filter(
            $requests,
            static fn (array $request): bool => $request['path'] === '/api/worker/register'
                && $request['headers']['tenant_a'] === true,
        ));
        $tenantBWorkerRequests = array_values(array_filter(
            $requests,
            static fn (array $request): bool => $request['path'] === '/api/worker/register'
                && $request['headers']['tenant_b'] === true,
        ));

        $this->assertCount(1, $tenantAWorkerRequests);
        $this->assertCount(1, $tenantBWorkerRequests);
        $this->assertSame('iso', $tenantAWorkerRequests[0]['body']['task_queue']);
        $this->assertSame('iso', $tenantBWorkerRequests[0]['body']['task_queue']);
    }

    public function testCommandFailsNamespaceSelectionWhenProbeReturnsQueryNotFound(): void
    {
        $requests = [];
        $http = $this->namespaceConformanceHttpFake('php-ns-query-visible', 'query_not_found', $requests);

        $this->app->instance(HttpFactory::class, $http);
        $reportPath = $this->ephemeralPath('namespace-conformance-out');

        $this->artisan('workflow:v2:namespace-conformance', [
            '--server-url' => 'http://server:8080',
            '--token' => 'test-token',
            '--run-id' => 'php-ns-query-visible',
            '--artifact-version' => [
                'server=0.2.171',
                'cli=0.1.55',
                'workflow-php=2.0.0-alpha.174',
                'sdk-python=0.4.73',
                'waterline=2.0.0-alpha.57',
            ],
            '--artifact-source' => [
                'server=docker_image',
                'cli=official_install_script',
                'workflow-php=packagist_package',
                'sdk-python=pypi_package',
                'waterline=packagist_package',
            ],
            '--output' => $reportPath,
        ])->assertFailed();

        $report = $this->readJson($reportPath);
        $scenarios = array_column($report['scenario_results'], null, 'scenario_id');
        $namespaceSelection = $scenarios['sdk_namespace_selection_parity'];
        $crossNamespaceLookup = $namespaceSelection['observed_outputs']['cross_namespace_lookup_denied'];

        $this->assertSame('fail', $report['outcome']);
        $this->assertSame('fail', $namespaceSelection['status']);
        $this->assertFalse($crossNamespaceLookup['not_found']);
        $this->assertSame(404, $crossNamespaceLookup['status']);
        $this->assertSame('query_not_found', $crossNamespaceLookup['reason']);
        $this->assertNotEmpty($namespaceSelection['linked_findings']);
    }

    public function testCommandFailsNamespaceCrudScenarioWhenResponsesDoNotProveState(): void
    {
        $http = new HttpFactory();
        $pollCount = 0;

        $http->fake(function (Request $request) use ($http, &$pollCount) {
            $method = strtoupper($request->method());
            $url = (string) $request->url();
            $path = (string) parse_url($url, PHP_URL_PATH);

            $controlHeaders = [
                ControlPlaneClient::CONTROL_PLANE_HEADER => ControlPlaneClient::CONTROL_PLANE_VERSION,
            ];

            if ($method === 'POST' && $path === '/api/namespaces') {
                return $http->response([
                    'name' => 'wrong-' . (string) ($request->data()['name'] ?? 'unknown'),
                    'status' => 'active',
                    'retention_days' => 30,
                ], 201, $controlHeaders);
            }

            if ($method === 'PUT' && $path === '/api/namespaces/tenant-a') {
                return $http->response([
                    'name' => 'tenant-a',
                    'status' => 'active',
                    'description' => 'stale description',
                ], 200, $controlHeaders);
            }

            if ($method === 'GET' && $path === '/api/namespaces/shared') {
                return $http->response([
                    'name' => 'wrong-shared',
                    'status' => 'active',
                ], 200, $controlHeaders);
            }

            if ($method === 'GET' && str_starts_with($path, '/api/namespaces/')) {
                return $http->response([
                    'name' => basename($path),
                    'status' => 'active',
                ], 200, $controlHeaders);
            }

            if ($method === 'GET' && $path === '/api/namespaces') {
                return $http->response([
                    'namespaces' => [
                        ['name' => 'default'],
                        ['name' => 'tenant-a'],
                    ],
                ], 200, $controlHeaders);
            }

            if ($method === 'POST' && $path === '/api/workflows') {
                $workflowId = (string) ($request->data()['workflow_id'] ?? 'unknown');

                return $http->response([
                    'workflow_id' => $workflowId,
                    'run_id' => $workflowId . '-run',
                    'task_queue' => $request->data()['task_queue'] ?? null,
                ], 201, $controlHeaders);
            }

            if ($method === 'GET' && $path === '/api/workflows/php-ns-bad-tenant-a-workflow') {
                return $http->response([
                    'reason' => 'workflow_not_found',
                    'message' => 'Workflow not found.',
                ], 404, $controlHeaders);
            }

            if ($method === 'POST' && $path === '/api/worker/register') {
                return $http->response([
                    'registered' => true,
                    'worker_id' => $request->data()['worker_id'] ?? null,
                ], 201);
            }

            if ($method === 'POST' && $path === '/api/worker/workflow-tasks/poll') {
                $pollCount++;

                return match ($pollCount) {
                    1, 3 => $http->response([], 200),
                    2 => $http->response([
                        'task' => [
                            'task_id' => 'task-a',
                            'workflow_id' => 'php-ns-bad-tenant-a-workflow',
                            'workflow_task_attempt' => 1,
                            'lease_owner' => 'php-ns-bad-tenant-a-worker',
                            'task_queue' => 'iso',
                        ],
                    ], 200),
                    4 => $http->response([
                        'task' => [
                            'task_id' => 'task-b',
                            'workflow_id' => 'php-ns-bad-tenant-b-workflow',
                            'workflow_task_attempt' => 1,
                            'lease_owner' => 'php-ns-bad-tenant-b-worker',
                            'task_queue' => 'iso',
                        ],
                    ], 200),
                    default => $http->response([], 200),
                };
            }

            return $http->response(['message' => 'unexpected request'], 500, $controlHeaders);
        });

        $this->app->instance(HttpFactory::class, $http);
        $reportPath = $this->ephemeralPath('namespace-conformance-out');

        $this->artisan('workflow:v2:namespace-conformance', [
            '--server-url' => 'http://server:8080',
            '--token' => 'test-token',
            '--run-id' => 'php-ns-bad',
            '--artifact-version' => [
                'server=0.2.171',
                'cli=0.1.55',
                'workflow-php=2.0.0-alpha.174',
                'sdk-python=0.4.73',
                'waterline=2.0.0-alpha.57',
            ],
            '--artifact-source' => [
                'server=docker_image',
                'cli=official_install_script',
                'workflow-php=packagist_package',
                'sdk-python=pypi_package',
                'waterline=packagist_package',
            ],
            '--output' => $reportPath,
        ])->assertFailed();

        $report = $this->readJson($reportPath);
        $scenarios = array_column($report['scenario_results'], null, 'scenario_id');
        $crudScenario = $scenarios['namespace_create_update_describe_and_list'];
        $semanticChecks = $crudScenario['observed_outputs']['semantic_checks'];

        $this->assertSame('fail', $report['outcome']);
        $this->assertSame('fail', $crudScenario['status']);
        $this->assertFalse($semanticChecks['passed']);
        $this->assertFalse(
            $semanticChecks['create_responses_identify_requested_namespaces']['tenant-a']['passed'],
        );
        $this->assertFalse($semanticChecks['tenant_a_update_reflected']['description_passed']);
        $this->assertSame(['tenant-b', 'shared'], $semanticChecks['missing_from_list']);
        $this->assertNotEmpty($crudScenario['linked_findings']);
    }

    public function testCommandFailsWhenServerConnectionOptionsAreMissing(): void
    {
        $reportPath = $this->ephemeralPath('namespace-conformance-out');

        $this->artisan('workflow:v2:namespace-conformance', [
            '--artifact-version' => [
                'server=0.2.171',
                'cli=0.1.55',
                'workflow-php=2.0.0-alpha.174',
                'sdk-python=0.4.73',
                'waterline=2.0.0-alpha.57',
            ],
            '--artifact-source' => [
                'server=docker_image',
                'cli=official_install_script',
                'workflow-php=packagist_package',
                'sdk-python=pypi_package',
                'waterline=packagist_package',
            ],
            '--output' => $reportPath,
        ])->assertFailed();

        $report = $this->readJson($reportPath);
        $scenarios = array_column($report['scenario_results'], null, 'scenario_id');

        $this->assertSame('fail', $report['outcome']);
        $this->assertSame('fail', $scenarios['sdk_namespace_selection_parity']['status']);
        $this->assertSame(
            ['server-url', 'token'],
            $scenarios['sdk_namespace_selection_parity']['observed_outputs']['missing_options'],
        );
    }

    /**
     * @param list<array<string, mixed>> $requests
     */
    private function namespaceConformanceHttpFake(
        string $runId,
        string $crossNamespaceReason,
        array &$requests,
    ): HttpFactory {
        $http = new HttpFactory();
        $workflowA = sprintf('%s-tenant-a-workflow', $runId);
        $workflowB = sprintf('%s-tenant-b-workflow', $runId);
        $workerA = sprintf('%s-tenant-a-worker', $runId);
        $workerB = sprintf('%s-tenant-b-worker', $runId);

        $http->fake(function (Request $request) use (
            $http,
            &$requests,
            $workflowA,
            $workflowB,
            $workerA,
            $workerB,
            $crossNamespaceReason,
        ) {
            $method = strtoupper($request->method());
            $url = (string) $request->url();
            $path = (string) parse_url($url, PHP_URL_PATH);
            $headers = [
                'tenant_a' => $request->hasHeader('X-Namespace', 'tenant-a'),
                'tenant_b' => $request->hasHeader('X-Namespace', 'tenant-b'),
                'default' => $request->hasHeader('X-Namespace', 'default'),
            ];
            $requests[] = [
                'method' => $method,
                'path' => $path,
                'headers' => $headers,
                'body' => $request->data(),
            ];

            $controlHeaders = [
                ControlPlaneClient::CONTROL_PLANE_HEADER => ControlPlaneClient::CONTROL_PLANE_VERSION,
            ];

            if ($method === 'POST' && $path === '/api/namespaces') {
                $name = (string) ($request->data()['name'] ?? 'unknown');

                return $http->response([
                    'name' => $name,
                    'status' => 'active',
                    'retention_days' => 30,
                ], 201, $controlHeaders);
            }

            if ($method === 'PUT' && $path === '/api/namespaces/tenant-a') {
                return $http->response([
                    'name' => 'tenant-a',
                    'status' => 'active',
                    'description' => $request->data()['description'] ?? null,
                ], 200, $controlHeaders);
            }

            if ($method === 'GET' && str_starts_with($path, '/api/namespaces/')) {
                return $http->response([
                    'name' => basename($path),
                    'status' => 'active',
                ], 200, $controlHeaders);
            }

            if ($method === 'GET' && $path === '/api/namespaces') {
                return $http->response([
                    'namespaces' => [
                        ['name' => 'default'],
                        ['name' => 'tenant-a'],
                        ['name' => 'tenant-b'],
                        ['name' => 'shared'],
                    ],
                ], 200, $controlHeaders);
            }

            if ($method === 'POST' && $path === '/api/workflows') {
                $workflowId = (string) ($request->data()['workflow_id'] ?? 'unknown');

                return $http->response([
                    'workflow_id' => $workflowId,
                    'run_id' => $workflowId . '-run',
                    'task_queue' => $request->data()['task_queue'] ?? null,
                ], 201, $controlHeaders);
            }

            if ($method === 'POST' && $path === sprintf('/api/workflows/%s/query/__namespace_probe', $workflowA)) {
                return $http->response([
                    'reason' => $crossNamespaceReason,
                    'message' => $crossNamespaceReason === 'query_not_found'
                        ? 'Workflow query [__namespace_probe] is not declared.'
                        : 'Workflow not found.',
                ], 404, $controlHeaders);
            }

            if ($method === 'POST' && $path === '/api/worker/register') {
                return $http->response([
                    'registered' => true,
                    'worker_id' => $request->data()['worker_id'] ?? null,
                ], 201);
            }

            if ($method === 'POST' && $path === '/api/worker/workflow-tasks/poll') {
                $isTenantA = $request->hasHeader('X-Namespace', 'tenant-a');
                $isTenantB = $request->hasHeader('X-Namespace', 'tenant-b');
                $pollNumber = count(array_filter(
                    $requests,
                    static fn (array $entry): bool => $entry['path'] === '/api/worker/workflow-tasks/poll',
                ));

                if ($isTenantB && $pollNumber === 1) {
                    return $http->response([], 200);
                }

                if ($isTenantA && $pollNumber === 2) {
                    return $http->response([
                        'task' => [
                            'task_id' => 'task-a',
                            'workflow_id' => $workflowA,
                            'workflow_task_attempt' => 1,
                            'lease_owner' => $workerA,
                            'task_queue' => 'iso',
                        ],
                    ], 200);
                }

                if ($isTenantA && $pollNumber === 3) {
                    return $http->response([], 200);
                }

                if ($isTenantB && $pollNumber === 4) {
                    return $http->response([
                        'task' => [
                            'task_id' => 'task-b',
                            'workflow_id' => $workflowB,
                            'workflow_task_attempt' => 1,
                            'lease_owner' => $workerB,
                            'task_queue' => 'iso',
                        ],
                    ], 200);
                }
            }

            return $http->response(['message' => 'unexpected request'], 500, $controlHeaders);
        });

        return $http;
    }

    private function ephemeralPath(string $prefix): string
    {
        $path = sys_get_temp_dir() . '/' . $prefix . '-' . Str::ulid() . '.json';
        $this->beforeApplicationDestroyed(static function () use ($path): void {
            if (is_file($path)) {
                unlink($path);
            }
        });

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
