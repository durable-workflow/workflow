<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;
use Workflow\V2\Support\PlatformConformanceSuite;
use Workflow\V2\Support\SurfaceStabilityContract;

final class PlatformConformanceSuiteTest extends TestCase
{
    private const RUST_SIGNAL_QUERY_SCENARIOS = [
        'rust_worker_rust_php_python_clients',
        'python_worker_rust_client',
        'php_worker_rust_client',
        'rust_query_error_and_immutability',
        'rust_replayed_instance_state_query_after_cold_restart',
    ];

    public function testManifestExactlyMatchesCommittedPublicAuthorityFixture(): void
    {
        $path = dirname(__DIR__, 3) . '/resources/platform-conformance-contract.json';
        $json = file_get_contents($path);

        $this->assertIsString($json);
        $this->assertSame(
            PlatformConformanceSuite::MIRROR_SHA256,
            hash('sha256', $json),
            'Changing any suite semantics requires a new reviewed authority digest.',
        );

        $authority = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame($authority, PlatformConformanceSuite::manifest());
        $this->assertSame(33, $authority['version']);
        $this->assertSame(PlatformConformanceSuite::VERSION, $authority['version']);
        $this->assertSame(PlatformConformanceSuite::SCHEMA, $authority['schema']);
        $this->assertSame(SurfaceStabilityContract::SCHEMA, $authority['surface_stability_authority']);
    }

    public function testTargetAndCategorySemanticsAreCompleteAndInternallyResolvable(): void
    {
        $manifest = PlatformConformanceSuite::manifest();
        $surfaceFamilies = array_keys(SurfaceStabilityContract::manifest()['surface_families']);
        $categories = array_keys($manifest['fixture_catalog']);

        $this->assertSame(array_keys($manifest['targets']), PlatformConformanceSuite::targetNames());
        $this->assertSame($categories, PlatformConformanceSuite::fixtureCategoryNames());

        foreach ($manifest['targets'] as $targetName => $target) {
            foreach ($target['required_surface_families'] as $surfaceFamily) {
                $this->assertContains(
                    $surfaceFamily,
                    $surfaceFamilies,
                    "{$targetName} references an unknown surface family.",
                );
            }

            foreach ($target['required_fixture_categories'] as $category) {
                $this->assertContains(
                    $category,
                    $categories,
                    "{$targetName} references an unknown fixture category.",
                );
            }
        }

        foreach ($manifest['fixture_catalog'] as $categoryName => $category) {
            $this->assertContains(
                $category['status'],
                [
                    PlatformConformanceSuite::CATEGORY_STATUS_STABLE,
                    PlatformConformanceSuite::CATEGORY_STATUS_PROVISIONAL,
                ],
                "{$categoryName} has an unknown stability status.",
            );
            $this->assertNotEmpty($category['sources'], "{$categoryName} must declare a source.");
        }
    }

    public function testPhpSdkAndEmbeddedWorkflowReleaseGatesAreIndependent(): void
    {
        $manifest = PlatformConformanceSuite::manifest();

        $this->assertSame(
            ['embedded_engine'],
            $manifest['release_gates']['gates']['durable-workflow/workflow']['required_targets'],
        );
        $this->assertSame(
            ['official_sdk', 'worker_protocol_implementation'],
            $manifest['release_gates']['gates']['durable-workflow/sdk']['required_targets'],
        );
        $this->assertSame(
            ['history_replay_bundles'],
            $manifest['targets']['embedded_engine']['required_fixture_categories'],
        );
    }

    public function testRustSignalQueryScenariosAreRequiredByStableTargetsAndPassFailRules(): void
    {
        $manifest = PlatformConformanceSuite::manifest();
        $category = $manifest['fixture_catalog']['signal_query_runtime_contract'];

        $this->assertSame(PlatformConformanceSuite::CATEGORY_STATUS_STABLE, $category['status']);

        foreach (self::RUST_SIGNAL_QUERY_SCENARIOS as $scenario) {
            $this->assertContains($scenario, $category['required_scenarios']);
        }

        foreach (['standalone_server', 'official_sdk', 'worker_protocol_implementation'] as $target) {
            $this->assertContains(
                'signal_query_runtime_contract',
                $manifest['targets'][$target]['required_fixture_categories'],
            );
        }

        $coverageRule = $manifest['pass_fail_rules']['stable_runtime_scenario_coverage'];
        $this->assertContains('signal_query_runtime_contract', $coverageRule['applies_to_categories']);
        $this->assertStringContainsString('every required scenario to pass', $coverageRule['rule']);
        $this->assertStringContainsString('runner-blocked cell is nonconforming', $coverageRule['rule']);
    }

    public function testRustSignalQueryScenarioContractsPreserveArtifactRolesAndImmutability(): void
    {
        $manifest = PlatformConformanceSuite::manifest();
        $category = $manifest['fixture_catalog']['signal_query_runtime_contract'];
        $contracts = $category['required_scenario_contracts'];
        $artifact = [
            'package' => 'durable-workflow',
            'version' => '2.0.0-beta.5',
            'source' => 'crates.io',
            'cargo_requirement' => '=2.0.0-beta.5',
        ];

        $this->assertStringContainsString('Rust SDK', $manifest['targets']['official_sdk']['description']);
        $this->assertSame(self::RUST_SIGNAL_QUERY_SCENARIOS, array_keys($contracts));

        foreach ($contracts as $contract) {
            $this->assertSame($artifact, $contract['artifact']);
        }

        $this->assertSame('sdk-rust', $contracts['rust_worker_rust_php_python_clients']['worker_runtime']);
        $this->assertSame(
            ['sdk-rust', 'sdk-php', 'sdk-python'],
            $contracts['rust_worker_rust_php_python_clients']['caller_paths'],
        );
        $this->assertSame('sdk-python', $contracts['python_worker_rust_client']['worker_runtime']);
        $this->assertSame('sdk-php', $contracts['php_worker_rust_client']['worker_runtime']);
        $this->assertSame('client', $contracts['python_worker_rust_client']['rust_role']);
        $this->assertSame('client', $contracts['php_worker_rust_client']['rust_role']);

        $snapshot = $contracts['rust_query_error_and_immutability'];
        $this->assertSame('snapshot_derived_transport_state', $snapshot['query_state_model']);
        foreach ([
            'successful_query_emits_no_workflow_commands',
            'failed_query_emits_no_workflow_commands',
            'successful_query_appends_no_history',
            'failed_query_appends_no_history',
            'failed_query_does_not_change_later_answer',
        ] as $assertion) {
            $this->assertContains($assertion, $snapshot['required_assertions']);
        }

        $replay = $contracts['rust_replayed_instance_state_query_after_cold_restart'];
        $this->assertSame('replayed_workflow_instance_state', $replay['query_state_model']);
        $this->assertSame(
            [
                'start_running_workflow',
                'query_running_state',
                'cold_stop_rust_worker',
                'start_fresh_rust_worker_process',
                'restore_state_from_durable_history',
                'complete_restored_workflow',
                'query_completed_state',
            ],
            $replay['lifecycle'],
        );
        foreach ([
            'successful_replayed_query_emits_no_workflow_commands',
            'failed_replayed_query_emits_no_workflow_commands',
            'successful_replayed_query_appends_no_history',
            'failed_replayed_query_appends_no_history',
            'failed_replayed_query_does_not_change_state_returned_by_later_query',
        ] as $assertion) {
            $this->assertContains($assertion, $replay['required_assertions']);
        }
    }

    public function testHistoricalReleaseGateCompatibilityNamesRemainDeclared(): void
    {
        $gates = PlatformConformanceSuite::manifest()['release_gates']['gates'];

        $this->assertArrayHasKey('durable-workflow/workflow', $gates);
        $this->assertArrayHasKey('durable-workflow/sdk', $gates);
        $this->assertArrayHasKey('durable_workflow', $gates);
        $this->assertSame(
            $gates['durable-workflow/sdk']['required_targets'],
            $gates['durable_workflow']['required_targets'],
        );
    }
}
