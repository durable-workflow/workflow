<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;
use Workflow\Commands\V2ReplayConformanceCommand;
use Workflow\V2\Conformance\ReplayConformanceBookingActivity;
use Workflow\V2\Conformance\ReplayConformanceCancelActivity;
use Workflow\V2\Conformance\ReplayConformanceFailingChildWorkflow;
use Workflow\V2\Conformance\ReplayConformanceGreetingActivity;
use Workflow\V2\Conformance\ReplayConformanceVersionedActivityV2;
use Workflow\V2\Conformance\ReplayConformanceVersionedActivityV3;
use Workflow\V2\Support\QueryStateReplayer;
use Workflow\V2\Support\TypeRegistry;
use Workflow\V2\Support\WorkflowReplayer;

final class V2ReplayConformanceCommandTest extends TestCase
{
    public function testCommandEmitsPhpReplayConformanceShard(): void
    {
        $reportPath = $this->ephemeralPath('replay-conformance-out');

        $this->artisan('workflow:v2:replay-conformance', [
            '--artifact-version' => [
                'server=0.2.169',
                'cli=0.1.55',
                'workflow-php=2.0.0-alpha.172',
                'sdk-python=0.4.71',
                'waterline=2.0.0-alpha.57',
            ],
            '--artifact-source' => [
                'server=docker_image',
                'cli=official_install_script',
                'workflow-php=composer_package',
                'sdk-python=pypi_package',
                'waterline=packagist_package',
            ],
            '--output' => $reportPath,
        ])->assertSuccessful()
            ->execute();

        $report = $this->readJson($reportPath);
        $scenarios = array_column($report['scenario_results'], null, 'scenario_id');

        $this->assertSame('durable-workflow.v2.replay-conformance.result', $report['schema']);
        $this->assertSame('workflow-php-runtime-shard', $report['coverage_scope']);
        $this->assertSame('pass', $report['outcome']);
        $this->assertSame(['workflow-php'], $report['runtime_matrix']['runtimes']);
        $this->assertSame('2.0.0-alpha.172', $report['artifact_versions']['workflow-php']);
        $this->assertSame('pass', $scenarios['published_artifact_install_only']['status']);
        $this->assertSame(
            'composer_package',
            $scenarios['published_artifact_install_only']['observed_outputs']['artifact_sources']['workflow-php'],
        );
        $this->assertSame(
            'packagist_package',
            $scenarios['published_artifact_install_only']['observed_outputs']['artifact_sources']['waterline'],
        );
        $this->assertTrue(
            $scenarios['published_artifact_install_only']['observed_outputs']['published_install_tuple_proven'],
        );
        $this->assertSame('pass', $scenarios['php_completed_history_activity_replay']['status']);
        $this->assertSame('pass', $scenarios['php_worker_restart_signal_update_state']['status']);
        $this->assertSame('pass', $scenarios['php_code_divergence_refusal']['status']);
        $this->assertSame(
            'non_determinism_error',
            $scenarios['php_code_divergence_refusal']['observed_outputs']['observed_outcome']
        );
        $this->assertSame('pass', $scenarios['server_history_mutation_refusal']['status']);
        $this->assertSame(
            'bundle_invalid_or_drifted',
            $scenarios['server_history_mutation_refusal']['observed_outputs']['observed_outcome']
        );
        $this->assertSame('pass', $scenarios['malformed_history_refusal']['status']);
        $this->assertSame('pass', $scenarios['php_in_flight_signal_restart_timing']['status']);
        $this->assertSame(
            'same_next_decision_after_replay',
            $scenarios['php_in_flight_signal_restart_timing']['observed_outputs']['observed_outcome'],
        );
        $this->assertSame([], $report['findings']);
        $this->assertSame([], $report['finding_links']);
    }

    public function testCommandFailsPublishedArtifactScenarioWhenVersionsAreMissing(): void
    {
        $reportPath = $this->ephemeralPath('replay-conformance-out');

        $this->artisan('workflow:v2:replay-conformance', [
            '--artifact-version' => ['workflow-php=2.0.0-alpha.172'],
            '--artifact-source' => [
                'server=docker_image',
                'cli=official_install_script',
                'workflow-php=composer_package',
                'sdk-python=pypi_package',
                'waterline=packagist_package',
            ],
            '--output' => $reportPath,
        ])->assertFailed()
            ->execute();

        $report = $this->readJson($reportPath);
        $scenarios = array_column($report['scenario_results'], null, 'scenario_id');

        $this->assertSame('fail', $report['outcome']);
        $this->assertSame('fail', $scenarios['published_artifact_install_only']['status']);
        $this->assertContains(
            'server',
            $scenarios['published_artifact_install_only']['observed_outputs']['missing_artifacts'],
        );
        $this->assertNotEmpty($report['finding_links']['published_artifact_install_only']);
    }

    public function testCommandFailsPublishedArtifactScenarioWhenSourcesAreMissing(): void
    {
        $reportPath = $this->ephemeralPath('replay-conformance-out');

        $this->artisan('workflow:v2:replay-conformance', [
            '--artifact-version' => [
                'server=0.2.169',
                'cli=0.1.55',
                'workflow-php=2.0.0-alpha.172',
                'sdk-python=0.4.71',
                'waterline=2.0.0-alpha.57',
            ],
            '--output' => $reportPath,
        ])->assertFailed()
            ->execute();

        $report = $this->readJson($reportPath);
        $scenarios = array_column($report['scenario_results'], null, 'scenario_id');
        $observed = $scenarios['published_artifact_install_only']['observed_outputs'];

        $this->assertSame('fail', $scenarios['published_artifact_install_only']['status']);
        $this->assertSame(
            ['server', 'cli', 'workflow-php', 'sdk-python', 'waterline'],
            $observed['missing_artifact_sources'],
        );
        $this->assertFalse($observed['published_install_tuple_proven']);
    }

    public function testCommandDoesNotAutoDetectWorkflowPhpArtifactVersion(): void
    {
        $reportPath = $this->ephemeralPath('replay-conformance-out');

        $this->artisan('workflow:v2:replay-conformance', [
            '--artifact-version' => [
                'server=0.2.169',
                'cli=0.1.55',
                'sdk-python=0.4.71',
                'waterline=2.0.0-alpha.57',
            ],
            '--artifact-source' => [
                'server=docker_image',
                'cli=official_install_script',
                'workflow-php=composer_package',
                'sdk-python=pypi_package',
                'waterline=packagist_package',
            ],
            '--output' => $reportPath,
        ])->assertFailed()
            ->execute();

        $report = $this->readJson($reportPath);
        $scenarios = array_column($report['scenario_results'], null, 'scenario_id');

        $this->assertContains(
            'workflow-php',
            $scenarios['published_artifact_install_only']['observed_outputs']['missing_artifact_versions'],
        );
    }

    public function testCommandRejectsDevVersionsAndLocalArtifactSources(): void
    {
        $reportPath = $this->ephemeralPath('replay-conformance-out');

        $this->artisan('workflow:v2:replay-conformance', [
            '--artifact-version' => [
                'server=0.2.169',
                'cli=0.1.55',
                'workflow-php=dev-main',
                'sdk-python=0.4.71',
                'waterline=2.0.0-alpha.57',
            ],
            '--artifact-source' => [
                'server=workspace_repo_as_artifact_under_test',
                'cli=official_install_script',
                'workflow-php=composer_package',
                'sdk-python=pypi_package',
                'waterline=packagist_package',
            ],
            '--output' => $reportPath,
        ])->assertFailed()
            ->execute();

        $report = $this->readJson($reportPath);
        $scenarios = array_column($report['scenario_results'], null, 'scenario_id');
        $observed = $scenarios['published_artifact_install_only']['observed_outputs'];

        $this->assertSame('fail', $scenarios['published_artifact_install_only']['status']);
        $this->assertSame('dev-main', $observed['rejected_versions']['workflow-php']['version']);
        $this->assertSame('workspace_repo_as_artifact_under_test', $observed['forbidden_sources']['server']);
        $this->assertFalse($observed['published_artifacts_only']);
    }

    public function testCommandRejectsNonPackagistWaterlineArtifactSources(): void
    {
        $reportPath = $this->ephemeralPath('replay-conformance-out');

        $this->artisan('workflow:v2:replay-conformance', [
            '--artifact-version' => [
                'server=0.2.169',
                'cli=0.1.55',
                'workflow-php=2.0.0-alpha.172',
                'sdk-python=0.4.71',
                'waterline=2.0.0-alpha.57',
            ],
            '--artifact-source' => [
                'server=docker_image',
                'cli=official_install_script',
                'workflow-php=composer_package',
                'sdk-python=pypi_package',
                'waterline=github_release',
            ],
            '--output' => $reportPath,
        ])->assertFailed()
            ->execute();

        $report = $this->readJson($reportPath);
        $scenarios = array_column($report['scenario_results'], null, 'scenario_id');
        $observed = $scenarios['published_artifact_install_only']['observed_outputs'];

        $this->assertSame('fail', $scenarios['published_artifact_install_only']['status']);
        $this->assertSame('github_release', $observed['untrusted_sources']['waterline']);
        $this->assertFalse($observed['published_install_tuple_proven']);
    }

    public function testGeneratedGoldenHistoryUsesPortableReplayTypeKeys(): void
    {
        $this->assertSame(
            ReplayConformanceGreetingActivity::TYPE_KEY,
            TypeRegistry::for(ReplayConformanceGreetingActivity::class),
        );
        $this->assertSame(
            ReplayConformanceBookingActivity::TYPE_KEY,
            TypeRegistry::for(ReplayConformanceBookingActivity::class),
        );
        $this->assertSame(
            ReplayConformanceCancelActivity::TYPE_KEY,
            TypeRegistry::for(ReplayConformanceCancelActivity::class),
        );
        $this->assertSame(
            ReplayConformanceVersionedActivityV2::TYPE_KEY,
            TypeRegistry::for(ReplayConformanceVersionedActivityV2::class),
        );
        $this->assertSame(
            ReplayConformanceVersionedActivityV3::TYPE_KEY,
            TypeRegistry::for(ReplayConformanceVersionedActivityV3::class),
        );

        $expectedActivityTypes = [
            'single-activity' => [ReplayConformanceGreetingActivity::TYPE_KEY],
            'signal-activity' => [ReplayConformanceGreetingActivity::TYPE_KEY],
            'version-marker' => [ReplayConformanceVersionedActivityV3::TYPE_KEY],
            'saga-compensation' => [
                ReplayConformanceBookingActivity::TYPE_KEY,
                ReplayConformanceCancelActivity::TYPE_KEY,
            ],
        ];

        foreach ($expectedActivityTypes as $scenario => $expectedTypes) {
            $bundle = $this->historyBundle($scenario);
            $activityTypes = $this->activityTypes($bundle);

            $this->assertSame($expectedTypes, $activityTypes);

            foreach ($activityTypes as $activityType) {
                $this->assertStringNotContainsString('\\', $activityType);
            }
        }

        $sagaBundle = $this->historyBundle('saga-compensation');
        $childPayload = $this->firstPayloadOfType($sagaBundle, 'ChildRunFailed');

        $this->assertSame(
            ReplayConformanceFailingChildWorkflow::TYPE_KEY,
            $childPayload['child_workflow_type'] ?? null,
        );
        $this->assertStringNotContainsString('\\', (string) ($childPayload['child_workflow_type'] ?? ''));

        $state = (new QueryStateReplayer())->query(
            (new WorkflowReplayer())->runFromHistoryExport($sagaBundle),
            'currentState',
        );

        $this->assertSame('compensated', $state['stage'] ?? null);
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

    /**
     * @return array<string, mixed>
     */
    private function historyBundle(string $scenario): array
    {
        $method = new ReflectionMethod(V2ReplayConformanceCommand::class, 'historyBundle');
        $command = new V2ReplayConformanceCommand(new Filesystem());
        $bundle = $method->invoke($command, $scenario);

        $this->assertIsArray($bundle);

        return $bundle;
    }

    /**
     * @param array<string, mixed> $bundle
     * @return list<string>
     */
    private function activityTypes(array $bundle): array
    {
        $types = [];

        foreach ($bundle['history_events'] ?? [] as $event) {
            if (! is_array($event) || ! is_array($event['payload'] ?? null)) {
                continue;
            }

            $activityType = $event['payload']['activity_type'] ?? null;
            if (is_string($activityType)) {
                $types[] = $activityType;
            }
        }

        return $types;
    }

    /**
     * @param array<string, mixed> $bundle
     * @return array<string, mixed>
     */
    private function firstPayloadOfType(array $bundle, string $type): array
    {
        foreach ($bundle['history_events'] ?? [] as $event) {
            if (! is_array($event) || ($event['type'] ?? null) !== $type) {
                continue;
            }

            $payload = $event['payload'] ?? null;

            return is_array($payload) ? $payload : [];
        }

        return [];
    }
}
