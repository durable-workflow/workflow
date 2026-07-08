<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Str;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Client\ControlPlaneClient;
use Workflow\V2\Conformance\SearchAttributesConformanceWorkflow;
use Workflow\V2\Enums\HistoryEventType;

final class V2SearchAttributesConformanceCommandTest extends TestCase
{
    public function testCommandReportsEveryRequiredScenarioWhenServerOptionsAreMissing(): void
    {
        $reportPath = $this->ephemeralPath('search-attributes-conformance-out');

        $this->artisan('workflow:v2:search-attributes-conformance', [
            '--run-id' => 'php-sa-missing',
            '--artifact-version' => [
                'server=0.2.594',
                'cli=0.1.86',
                'workflow-php=2.0.0-alpha.250',
                'sdk-python=0.4.95',
                'waterline=2.0.0-alpha.121',
            ],
            '--artifact-source' => [
                'server=published_docker_image',
                'cli=official_install_script',
                'workflow-php=composer_release',
                'sdk-python=pypi_release',
                'waterline=published_waterline_release',
            ],
            '--output' => $reportPath,
        ])->assertFailed();

        $report = $this->readJson($reportPath);
        $scenarios = array_column($report['scenario_results'], null, 'scenario_id');

        $this->assertSame('durable-workflow.v2.search-attribute-runtime.result', $report['schema']);
        $this->assertSame('workflow-php-search-attribute-shard', $report['coverage_scope']);
        $this->assertFalse($report['runner_blocked']);
        $this->assertSame('non_passing_with_root_cause_finding', $report['outcome']);
        $this->assertCount(17, $scenarios);
        $this->assertSame('pass', $scenarios['published_artifact_install_only']['status']);
        $this->assertSame('fail', $scenarios['php_worker_start_and_upsert_visibility']['status']);
        $this->assertSame(
            ['server-url', 'token'],
            $scenarios['php_worker_start_and_upsert_visibility']['observed_outputs']['missing_options'],
        );
        $this->assertSame('not_covered', $scenarios['python_worker_start_and_upsert_visibility']['status']);
        $this->assertSame('not_covered', $scenarios['waterline_operator_visibility']['status']);
        $this->assertSame('float', $report['topology']['schema_keys']['discount_ratio']);
        $this->assertSame('value_float', $report['topology']['workflow_storage_fields']['discount_ratio']);
        $this->assertArrayHasKey('namespace_isolation', $scenarios);
        $this->assertNotEmpty($scenarios['namespace_isolation']['linked_findings']);

        $encoded = json_encode($report, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('value_' . 'double', $encoded);
        $discountRatioTypeKey = '"' . 'discount_ratio' . '":';
        $doubleTypeValue = '"' . 'double' . '"';
        $this->assertStringNotContainsString($discountRatioTypeKey . $doubleTypeValue, $encoded);
    }

    public function testCommandEmitsPhpRuntimeMirrorEvidence(): void
    {
        $requests = [];
        $http = $this->searchAttributeHttpFake('php-sa-live', $requests);

        $this->app->instance(HttpFactory::class, $http);
        $reportPath = $this->ephemeralPath('search-attributes-conformance-out');

        $this->artisan('workflow:v2:search-attributes-conformance', [
            '--server-url' => 'http://server:8080',
            '--token' => 'test-token',
            '--run-id' => 'php-sa-live',
            '--artifact-version' => [
                'server=0.2.594',
                'cli=0.1.86',
                'workflow-php=2.0.0-alpha.250',
                'sdk-python=0.4.95',
                'waterline=2.0.0-alpha.121',
            ],
            '--artifact-source' => [
                'server=published_docker_image',
                'cli=official_install_script',
                'workflow-php=composer_release',
                'sdk-python=pypi_release',
                'waterline=published_waterline_release',
            ],
            '--output' => $reportPath,
        ])->assertSuccessful();

        $report = $this->readJson($reportPath);
        $scenarios = array_column($report['scenario_results'], null, 'scenario_id');
        $phpScenario = $scenarios['php_worker_start_and_upsert_visibility'];
        $outputs = $phpScenario['observed_outputs'];

        $this->assertSame('non_passing', $report['outcome']);
        $this->assertSame('pass', $phpScenario['status']);
        $this->assertSame('workflow-php', $outputs['worker_runtime']);
        $this->assertTrue($outputs['visibility_query_match']);
        $this->assertTrue($outputs['namespace_isolation']['peer_value_hidden_from_primary_namespace']);
        $this->assertTrue($outputs['namespace_isolation']['peer_value_visible_in_peer_namespace']);
        $this->assertSame('float', $outputs['type_contract']['logical_schema_keys']['discount_ratio']);
        $this->assertSame('value_float', $outputs['type_contract']['storage_fields']['discount_ratio']);
        $this->assertSame('value_float', $outputs['type_contract']['discount_ratio_storage_field']);
        $this->assertSame('float', $outputs['wire_value_context']['wire_values']['discount_ratio']['type']);
        $this->assertArrayHasKey('value_float', $outputs['wire_value_context']['wire_values']['discount_ratio']);
        $doubleValueField = 'value_' . 'double';
        $discountRatioValues = $outputs['wire_value_context']['wire_values']['discount_ratio'];
        $this->assertArrayNotHasKey($doubleValueField, $discountRatioValues);
        $this->assertSame('not_covered', $scenarios['php_to_python_codec_round_trip']['status']);
        $this->assertCount(17, $scenarios);

        $searchAttributeRequests = array_values(array_filter(
            $requests,
            static fn (array $request): bool => $request['method'] === 'POST'
                && $request['path'] === '/api/search-attributes',
        ));
        $completeRequests = array_values(array_filter(
            $requests,
            static fn (array $request): bool => $request['method'] === 'POST'
                && str_ends_with($request['path'], '/complete'),
        ));

        $this->assertCount(14, $searchAttributeRequests);
        $this->assertCount(1, $completeRequests);
        $this->assertContains('upsert_search_attributes', array_column($completeRequests[0]['body']['commands'], 'type'));
        $this->assertContains('complete_workflow', array_column($completeRequests[0]['body']['commands'], 'type'));
    }

    /**
     * @param list<array<string, mixed>> $requests
     */
    private function searchAttributeHttpFake(string $runId, array &$requests): HttpFactory
    {
        $http = new HttpFactory();
        $suffix = 'phpsalive';
        $workflowId = "{$runId}-php-search-attributes";
        $workflowRunId = "{$workflowId}-run";
        $peerWorkflowId = "{$runId}-peer-search-attributes";
        $peerRunId = "{$peerWorkflowId}-run";
        $workerId = "{$runId}-php-search-attribute-worker";
        $attributeKeys = [
            'customer_id' => "customer_id_{$suffix}",
            'order_total_cents' => "order_cents_{$suffix}",
            'discount_ratio' => "discount_ratio_{$suffix}",
            'priority_tier' => "priority_tier_{$suffix}",
            'is_vip' => "is_vip_{$suffix}",
            'created_at' => "created_at_{$suffix}",
            'tags' => "tags_{$suffix}",
        ];
        $upsertAttributes = [
            $attributeKeys['order_total_cents'] => 7350,
            $attributeKeys['discount_ratio'] => 0.2,
            $attributeKeys['priority_tier'] => 'platinum',
            $attributeKeys['is_vip'] => false,
            $attributeKeys['tags'] => ['php', 'mirror', 'upserted'],
        ];

        $http->fake(function (Request $request) use (
            $http,
            &$requests,
            $workflowId,
            $workflowRunId,
            $peerWorkflowId,
            $peerRunId,
            $workerId,
            $attributeKeys,
            $upsertAttributes,
        ) {
            $method = strtoupper($request->method());
            $url = (string) $request->url();
            $path = (string) parse_url($url, PHP_URL_PATH);
            $query = $request->data();
            if ($method === 'GET') {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            }
            $headers = [
                'default' => $request->hasHeader('X-Namespace', 'default'),
                'peer' => $request->hasHeader('X-Namespace', 'default-search-attributes-peer'),
            ];
            $requests[] = [
                'method' => $method,
                'path' => $path,
                'query' => $query,
                'headers' => $headers,
                'body' => $request->data(),
            ];

            $controlHeaders = [
                ControlPlaneClient::CONTROL_PLANE_HEADER => ControlPlaneClient::CONTROL_PLANE_VERSION,
            ];

            if ($method === 'POST' && $path === '/api/namespaces') {
                return $http->response([
                    'name' => $request->data()['name'] ?? null,
                    'status' => 'active',
                ], 201, $controlHeaders);
            }

            if ($method === 'POST' && $path === '/api/search-attributes') {
                return $http->response([
                    'name' => $request->data()['name'] ?? null,
                    'type' => $request->data()['type'] ?? null,
                ], 201, $controlHeaders);
            }

            if ($method === 'POST' && $path === '/api/worker/register') {
                return $http->response([
                    'registered' => true,
                    'worker_id' => $request->data()['worker_id'] ?? null,
                ], 201);
            }

            if ($method === 'POST' && $path === '/api/workflows') {
                $id = (string) ($request->data()['workflow_id'] ?? '');

                return $http->response([
                    'workflow_id' => $id,
                    'run_id' => $id === $peerWorkflowId ? $peerRunId : $workflowRunId,
                    'task_queue' => $request->data()['task_queue'] ?? null,
                ], 201, $controlHeaders);
            }

            if ($method === 'POST' && $path === '/api/worker/workflow-tasks/poll') {
                return $http->response([
                    'task' => [
                        'task_id' => 'search-attribute-task-1',
                        'workflow_task_attempt' => 1,
                        'lease_owner' => $workerId,
                        'workflow_id' => $workflowId,
                        'run_id' => $workflowRunId,
                        'workflow_type' => SearchAttributesConformanceWorkflow::TYPE_KEY,
                        'workflow_class' => SearchAttributesConformanceWorkflow::TYPE_KEY,
                        'payload_codec' => 'avro',
                        'arguments' => [
                            'codec' => 'avro',
                            'blob' => Serializer::serializeWithCodec('avro', [$upsertAttributes]),
                        ],
                        'history_events' => [
                            [
                                'id' => 'event-started',
                                'sequence' => 1,
                                'event_type' => HistoryEventType::WorkflowStarted->value,
                                'payload' => [
                                    'workflow_type' => SearchAttributesConformanceWorkflow::TYPE_KEY,
                                    'workflow_class' => SearchAttributesConformanceWorkflow::TYPE_KEY,
                                    'payload_codec' => 'avro',
                                ],
                                'recorded_at' => '2026-07-08T12:00:00+00:00',
                            ],
                        ],
                    ],
                    'poll_status' => 'leased',
                ], 200);
            }

            if ($method === 'POST' && $path === '/api/worker/workflow-tasks/search-attribute-task-1/complete') {
                return $http->response([
                    'outcome' => 'completed',
                    'recorded' => true,
                ], 200);
            }

            if ($method === 'GET' && $path === "/api/workflows/{$workflowId}/runs/{$workflowRunId}") {
                return $http->response([
                    'workflow_id' => $workflowId,
                    'run_id' => $workflowRunId,
                    'search_attributes' => [
                        $attributeKeys['customer_id'] => 'cust-php-alpha',
                        $attributeKeys['priority_tier'] => 'platinum',
                    ],
                ], 200, $controlHeaders);
            }

            if ($method === 'GET' && $path === '/api/workflows') {
                $queryText = (string) ($query['query'] ?? '');
                if (str_contains($queryText, 'cust-php-peer')) {
                    return $http->response([
                        'workflows' => $headers['peer'] ? [
                            ['workflow_id' => $peerWorkflowId, 'run_id' => $peerRunId],
                        ] : [],
                        'workflow_count' => $headers['peer'] ? 1 : 0,
                    ], 200, $controlHeaders);
                }

                return $http->response([
                    'workflows' => [
                        ['workflow_id' => $workflowId, 'run_id' => $workflowRunId],
                    ],
                    'workflow_count' => 1,
                ], 200, $controlHeaders);
            }

            return $http->response(['message' => 'unexpected request', 'path' => $path], 500, $controlHeaders);
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
