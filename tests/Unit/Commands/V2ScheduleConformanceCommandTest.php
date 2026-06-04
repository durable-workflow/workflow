<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Str;
use Tests\TestCase;
use Workflow\V2\Client\ControlPlaneClient;

final class V2ScheduleConformanceCommandTest extends TestCase
{
    public function testCommandEmitsPhpScheduleConformanceShard(): void
    {
        $requests = [];
        $http = $this->scheduleConformanceHttpFake('php-schedule-surface', $requests);

        $this->app->instance(HttpFactory::class, $http);
        $reportPath = $this->ephemeralPath('schedule-conformance-out');

        $this->artisan('workflow:v2:schedule-conformance', [
            '--server-url' => 'http://server:8080',
            '--token' => 'test-token',
            '--namespace' => 'schedule-test',
            '--schedule-id' => 'php-schedule-surface',
            '--task-queue' => 'scheduled',
            '--workflow-type' => 'ScheduleProbe',
            '--run-id' => 'php-schedule-surface',
            '--artifact-version' => [
                'server=0.2.262',
                'cli=0.1.75',
                'workflow=2.0.0-alpha.193',
                'sdk-python=0.4.84',
                'waterline=2.0.0-alpha.80',
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

        $this->assertSame('durable-workflow.v2.schedule-runtime.result', $report['schema']);
        $this->assertSame('workflow-php-schedule-shard', $report['coverage_scope']);
        $this->assertSame('non_passing', $report['outcome']);
        $this->assertSame('2.0.0-alpha.193', $report['artifact_versions']['workflow-php']);
        $this->assertSame(['workflow-php'], $report['runtime_matrix']['runtimes']);
        $this->assertSame('php_schedule_surface', $report['runtime_matrix']['surface_cells'][0]['cell']);
        $this->assertSame('pass', $scenarios['published_artifact_install_only']['status']);
        $this->assertSame('pass', $scenarios['php_schedule_surface_create_or_observe']['status']);
        $this->assertSame('pass', $scenarios['php_schedule_surface_list_or_describe']['status']);
        $this->assertSame('pass', $scenarios['php_schedule_surface_claimed_controls']['status']);
        $this->assertSame('pass', $scenarios['php_schedule_surface_state_parity']['status']);
        $this->assertSame('pass', $scenarios['result_record_and_product_finding_routing']['status']);
        $this->assertSame(
            'php-schedule-surface',
            $report['php_schedule_surface']['create_or_observe']['schedule_id'],
        );
        $this->assertSame(
            '*/5 * * * *',
            $scenarios['php_schedule_surface_state_parity']['observed_outputs']['semantic_checks']['describe']['cadence'],
        );
        $this->assertSame(
            '2026-06-03T00:00:00Z',
            $scenarios['php_schedule_surface_state_parity']['observed_outputs']['semantic_checks']['describe']['last_fire_at'],
        );
        $this->assertSame(
            ['pause', 'resume', 'trigger', 'delete'],
            $report['php_schedule_surface']['claimed_controls']['claimed_controls'],
        );
        $this->assertSame(
            'covered_by_full_schedules_harness',
            $report['php_schedule_surface']['state_parity']['cli_state_agreement'],
        );

        $paths = array_column($requests, 'path');
        $this->assertContains('/api/schedules', $paths);
        $this->assertContains('/api/schedules/php-schedule-surface/pause', $paths);
        $this->assertContains('/api/schedules/php-schedule-surface/resume', $paths);
        $this->assertContains('/api/schedules/php-schedule-surface/trigger', $paths);
    }

    public function testCommandFailsWhenListAndDescribeDoNotProveRequestedSchedule(): void
    {
        $http = new HttpFactory();

        $http->fake(function (Request $request) use ($http) {
            $method = strtoupper($request->method());
            $path = (string) parse_url((string) $request->url(), PHP_URL_PATH);
            $headers = [
                ControlPlaneClient::CONTROL_PLANE_HEADER => ControlPlaneClient::CONTROL_PLANE_VERSION,
            ];

            if ($method === 'POST' && $path === '/api/schedules') {
                return $http->response([
                    'schedule_id' => 'php-schedule-surface',
                    'status' => 'active',
                ], 201, $headers);
            }

            if ($method === 'GET' && $path === '/api/schedules/php-schedule-surface') {
                return $http->response([
                    'schedule_id' => 'wrong-schedule',
                    'spec' => ['cron_expressions' => ['0 9 * * 1-5']],
                    'status' => 'active',
                ], 200, $headers);
            }

            if ($method === 'GET' && $path === '/api/schedules') {
                return $http->response([
                    'schedules' => [
                        [
                            'schedule_id' => 'other-schedule',
                            'spec' => ['cron_expressions' => ['*/5 * * * *']],
                            'status' => 'active',
                        ],
                    ],
                ], 200, $headers);
            }

            if ($method === 'POST' && str_ends_with($path, '/pause')) {
                return $http->response(['schedule_id' => 'php-schedule-surface'], 200, $headers);
            }

            if ($method === 'POST' && str_ends_with($path, '/resume')) {
                return $http->response(['schedule_id' => 'php-schedule-surface'], 200, $headers);
            }

            if ($method === 'POST' && str_ends_with($path, '/trigger')) {
                return $http->response(['schedule_id' => 'php-schedule-surface'], 200, $headers);
            }

            if ($method === 'DELETE' && $path === '/api/schedules/php-schedule-surface') {
                return $http->response(['schedule_id' => 'php-schedule-surface', 'outcome' => 'deleted'], 200, $headers);
            }

            return $http->response([], 500, $headers);
        });

        $this->app->instance(HttpFactory::class, $http);
        $reportPath = $this->ephemeralPath('schedule-conformance-out');

        $this->artisan('workflow:v2:schedule-conformance', [
            '--server-url' => 'http://server:8080',
            '--schedule-id' => 'php-schedule-surface',
            '--artifact-version' => [
                'server=0.2.262',
                'cli=0.1.75',
                'workflow-php=2.0.0-alpha.193',
                'sdk-python=0.4.84',
                'waterline=2.0.0-alpha.80',
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
        $this->assertSame('fail', $scenarios['php_schedule_surface_create_or_observe']['status']);
        $this->assertSame('fail', $scenarios['php_schedule_surface_list_or_describe']['status']);
        $this->assertSame('fail', $scenarios['php_schedule_surface_state_parity']['status']);
        $this->assertNotEmpty($scenarios['php_schedule_surface_state_parity']['linked_findings']);
    }

    public function testCommandFailsWhenReturnedScheduleOmitsCadenceEvidence(): void
    {
        $http = new HttpFactory();

        $http->fake(function (Request $request) use ($http) {
            $method = strtoupper($request->method());
            $path = (string) parse_url((string) $request->url(), PHP_URL_PATH);
            $headers = [
                ControlPlaneClient::CONTROL_PLANE_HEADER => ControlPlaneClient::CONTROL_PLANE_VERSION,
            ];
            $recordWithoutCadence = [
                'schedule_id' => 'php-schedule-surface',
                'status' => 'active',
                'paused' => false,
                'last_fired_at' => '2026-06-03T00:00:00Z',
                'next_fire_at' => '2026-06-03T00:05:00Z',
            ];

            if ($method === 'POST' && $path === '/api/schedules') {
                return $http->response(['schedule_id' => 'php-schedule-surface', 'outcome' => 'created'], 201, $headers);
            }

            if ($method === 'GET' && $path === '/api/schedules/php-schedule-surface') {
                return $http->response($recordWithoutCadence, 200, $headers);
            }

            if ($method === 'GET' && $path === '/api/schedules') {
                return $http->response(['schedules' => [$recordWithoutCadence]], 200, $headers);
            }

            if ($method === 'POST' && str_ends_with($path, '/pause')) {
                return $http->response(['schedule_id' => 'php-schedule-surface', 'outcome' => 'paused'], 200, $headers);
            }

            if ($method === 'POST' && str_ends_with($path, '/resume')) {
                return $http->response(['schedule_id' => 'php-schedule-surface', 'outcome' => 'resumed'], 200, $headers);
            }

            if ($method === 'POST' && str_ends_with($path, '/trigger')) {
                return $http->response(['schedule_id' => 'php-schedule-surface', 'outcome' => 'triggered'], 200, $headers);
            }

            if ($method === 'DELETE' && $path === '/api/schedules/php-schedule-surface') {
                return $http->response(['schedule_id' => 'php-schedule-surface', 'outcome' => 'deleted'], 200, $headers);
            }

            return $http->response([], 500, $headers);
        });

        $this->app->instance(HttpFactory::class, $http);
        $reportPath = $this->ephemeralPath('schedule-conformance-out');

        $this->artisan('workflow:v2:schedule-conformance', [
            '--server-url' => 'http://server:8080',
            '--schedule-id' => 'php-schedule-surface',
            '--artifact-version' => [
                'server=0.2.262',
                'cli=0.1.75',
                'workflow-php=2.0.0-alpha.193',
                'sdk-python=0.4.84',
                'waterline=2.0.0-alpha.80',
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

        $this->assertSame('fail', $scenarios['php_schedule_surface_create_or_observe']['status']);
        $this->assertSame('fail', $scenarios['php_schedule_surface_list_or_describe']['status']);
        $this->assertSame('fail', $scenarios['php_schedule_surface_state_parity']['status']);
        $this->assertContains(
            'describe_after_create did not include schedule cadence/spec',
            $scenarios['php_schedule_surface_create_or_observe']['observed_outputs']['semantic_checks']['failures'],
        );
        $this->assertContains(
            'list did not include schedule cadence/spec',
            $scenarios['php_schedule_surface_state_parity']['observed_outputs']['semantic_checks']['failures'],
        );
    }

    public function testCommandFailsWhenClaimedControlsDoNotProveRequestedState(): void
    {
        $requests = [];
        $http = new HttpFactory();

        $http->fake(function (Request $request) use ($http, &$requests) {
            $method = strtoupper($request->method());
            $path = (string) parse_url((string) $request->url(), PHP_URL_PATH);
            $headers = [
                ControlPlaneClient::CONTROL_PLANE_HEADER => ControlPlaneClient::CONTROL_PLANE_VERSION,
            ];
            $active = [
                'schedule_id' => 'php-schedule-surface',
                'spec' => ['cron_expressions' => ['*/5 * * * *']],
                'status' => 'active',
                'paused' => false,
                'next_fire_at' => '2026-06-03T00:05:00Z',
            ];
            $paused = array_merge($active, ['status' => 'paused', 'paused' => true]);
            $missingResumeState = [
                'schedule_id' => 'php-schedule-surface',
                'spec' => ['cron_expressions' => ['*/5 * * * *']],
                'next_fire_at' => '2026-06-03T00:05:00Z',
            ];

            $requests[] = ['method' => $method, 'path' => $path];

            if ($method === 'POST' && $path === '/api/schedules') {
                return $http->response(['schedule_id' => 'php-schedule-surface', 'outcome' => 'created'], 201, $headers);
            }

            if ($method === 'GET' && $path === '/api/schedules/php-schedule-surface') {
                $pauseSeen = array_filter(
                    $requests,
                    static fn (array $entry): bool => $entry['path'] === '/api/schedules/php-schedule-surface/pause',
                );
                $resumeSeen = array_filter(
                    $requests,
                    static fn (array $entry): bool => $entry['path'] === '/api/schedules/php-schedule-surface/resume',
                );

                if ($pauseSeen !== [] && $resumeSeen === []) {
                    return $http->response($paused, 200, $headers);
                }

                if ($resumeSeen !== []) {
                    return $http->response($missingResumeState, 200, $headers);
                }

                return $http->response($active, 200, $headers);
            }

            if ($method === 'GET' && $path === '/api/schedules') {
                $deleteSeen = array_filter(
                    $requests,
                    static fn (array $entry): bool => $entry['path'] === '/api/schedules/php-schedule-surface'
                        && $entry['method'] === 'DELETE',
                );

                return $http->response(['schedules' => $deleteSeen === [] ? [$active] : []], 200, $headers);
            }

            if ($method === 'POST' && str_ends_with($path, '/pause')) {
                return $http->response(['schedule_id' => 'php-schedule-surface', 'outcome' => 'paused'], 200, $headers);
            }

            if ($method === 'POST' && str_ends_with($path, '/resume')) {
                return $http->response(['schedule_id' => 'php-schedule-surface', 'outcome' => 'resumed'], 200, $headers);
            }

            if ($method === 'POST' && str_ends_with($path, '/trigger')) {
                return $http->response(['schedule_id' => 'other-schedule', 'outcome' => 'triggered'], 200, $headers);
            }

            if ($method === 'DELETE' && $path === '/api/schedules/php-schedule-surface') {
                return $http->response(['schedule_id' => 'php-schedule-surface', 'outcome' => 'deleted'], 200, $headers);
            }

            return $http->response([], 500, $headers);
        });

        $this->app->instance(HttpFactory::class, $http);
        $reportPath = $this->ephemeralPath('schedule-conformance-out');

        $this->artisan('workflow:v2:schedule-conformance', [
            '--server-url' => 'http://server:8080',
            '--schedule-id' => 'php-schedule-surface',
            '--artifact-version' => [
                'server=0.2.262',
                'cli=0.1.75',
                'workflow-php=2.0.0-alpha.193',
                'sdk-python=0.4.84',
                'waterline=2.0.0-alpha.80',
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
        $controlChecks = $scenarios['php_schedule_surface_claimed_controls']['observed_outputs']['semantic_checks'];

        $this->assertSame('fail', $scenarios['php_schedule_surface_claimed_controls']['status']);
        $this->assertContains('resume did not expose active schedule state', $controlChecks['failures']);
        $this->assertContains('trigger response schedule_id did not match requested schedule', $controlChecks['failures']);
    }

    public function testCommandRejectsMissingPublishedArtifactInputs(): void
    {
        $requests = [];
        $this->app->instance(HttpFactory::class, $this->scheduleConformanceHttpFake('php-schedule-surface', $requests));
        $reportPath = $this->ephemeralPath('schedule-conformance-out');

        $this->artisan('workflow:v2:schedule-conformance', [
            '--server-url' => 'http://server:8080',
            '--schedule-id' => 'php-schedule-surface',
            '--artifact-version' => [
                'workflow=dev-main',
            ],
            '--artifact-source' => [
                'workflow=path_checkout',
            ],
            '--output' => $reportPath,
        ])->assertFailed();

        $report = $this->readJson($reportPath);
        $scenarios = array_column($report['scenario_results'], null, 'scenario_id');
        $artifactScenario = $scenarios['published_artifact_install_only'];

        $this->assertSame('fail', $artifactScenario['status']);
        $this->assertContains('server', $artifactScenario['observed_outputs']['missing_artifact_versions']);
        $this->assertSame('dev_or_branch_version', $artifactScenario['observed_outputs']['rejected_versions']['workflow-php']);
        $this->assertSame('path_checkout', $artifactScenario['observed_outputs']['forbidden_sources']['workflow-php']);
    }

    /**
     * @param list<array<string, mixed>> $requests
     */
    private function scheduleConformanceHttpFake(string $scheduleId, array &$requests): HttpFactory
    {
        $http = new HttpFactory();

        $http->fake(function (Request $request) use ($http, $scheduleId, &$requests) {
            $method = strtoupper($request->method());
            $path = (string) parse_url((string) $request->url(), PHP_URL_PATH);
            $body = $request->data();
            $headers = [
                ControlPlaneClient::CONTROL_PLANE_HEADER => ControlPlaneClient::CONTROL_PLANE_VERSION,
            ];

            $requests[] = [
                'method' => $method,
                'path' => $path,
                'body' => $body,
                'namespace' => $request->hasHeader('X-Namespace', 'schedule-test'),
            ];

            $active = [
                'schedule_id' => $scheduleId,
                'spec' => ['cron_expressions' => ['*/5 * * * *'], 'timezone' => 'UTC'],
                'status' => 'active',
                'last_fired_at' => '2026-06-03T00:00:00Z',
                'next_fire_at' => '2026-06-03T00:05:00Z',
            ];
            $paused = array_merge($active, ['status' => 'paused', 'paused' => true]);

            if ($method === 'POST' && $path === '/api/schedules') {
                return $http->response(array_merge($active, ['outcome' => 'created']), 201, $headers);
            }

            if ($method === 'GET' && $path === '/api/schedules/' . $scheduleId) {
                $pauseSeen = array_filter(
                    $requests,
                    static fn (array $entry): bool => $entry['path'] === '/api/schedules/' . $scheduleId . '/pause',
                );
                $resumeSeen = array_filter(
                    $requests,
                    static fn (array $entry): bool => $entry['path'] === '/api/schedules/' . $scheduleId . '/resume',
                );

                return $http->response($pauseSeen !== [] && $resumeSeen === [] ? $paused : $active, 200, $headers);
            }

            if ($method === 'GET' && $path === '/api/schedules') {
                $deleteSeen = array_filter(
                    $requests,
                    static fn (array $entry): bool => $entry['path'] === '/api/schedules/' . $scheduleId
                        && $entry['method'] === 'DELETE',
                );

                return $http->response([
                    'schedules' => $deleteSeen === [] ? [$active] : [],
                ], 200, $headers);
            }

            if ($method === 'POST' && $path === '/api/schedules/' . $scheduleId . '/pause') {
                return $http->response(['schedule_id' => $scheduleId, 'outcome' => 'paused'], 200, $headers);
            }

            if ($method === 'POST' && $path === '/api/schedules/' . $scheduleId . '/resume') {
                return $http->response(['schedule_id' => $scheduleId, 'outcome' => 'resumed'], 200, $headers);
            }

            if ($method === 'POST' && $path === '/api/schedules/' . $scheduleId . '/trigger') {
                return $http->response([
                    'schedule_id' => $scheduleId,
                    'outcome' => 'triggered',
                    'workflow_id' => 'scheduled-workflow-run',
                ], 200, $headers);
            }

            if ($method === 'DELETE' && $path === '/api/schedules/' . $scheduleId) {
                return $http->response(['schedule_id' => $scheduleId, 'outcome' => 'deleted'], 200, $headers);
            }

            return $http->response([], 500, $headers);
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
