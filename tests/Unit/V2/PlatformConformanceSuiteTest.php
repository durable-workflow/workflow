<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;
use Workflow\V2\Support\PlatformConformanceSuite;
use Workflow\V2\Support\SurfaceStabilityContract;

/**
 * Pins the platform conformance suite manifest mirrored by
 * `Workflow\V2\Support\PlatformConformanceSuite`. The authority is
 * `docs/architecture/platform-conformance-suite.md`. The standalone
 * `workflow-server` re-exports the same manifest from
 * `GET /api/cluster/info` under `platform_conformance_suite`.
 *
 * Adding a target, adding a fixture category, promoting a provisional
 * category to required, or changing a pass / fail rule is a contract
 * change. Update the spec doc, the static mirror, the per-repo
 * conformance claim docs, and bump
 * `PlatformConformanceSuite::VERSION` in the same change.
 */
final class PlatformConformanceSuiteTest extends TestCase
{
    public function testManifestAdvertisesAuthorityIdentity(): void
    {
        $manifest = PlatformConformanceSuite::manifest();

        $this->assertSame('durable-workflow.v2.platform-conformance.suite', $manifest['schema']);
        $this->assertSame(3, $manifest['version']);
        $this->assertSame(
            'https://github.com/durable-workflow/workflow/blob/v2/docs/architecture/platform-conformance-suite.md',
            $manifest['authority_doc'],
        );
        $this->assertSame(
            SurfaceStabilityContract::SCHEMA,
            $manifest['surface_stability_authority'],
            'the conformance suite is downstream of the surface stability contract',
        );
    }

    public function testResultDocumentSchemaIsAdvertised(): void
    {
        $manifest = PlatformConformanceSuite::manifest();

        $this->assertSame('durable-workflow.v2.platform-conformance.result', $manifest['result_schema']);
        $this->assertSame(1, $manifest['result_version']);
    }

    public function testConformanceLevelsCoverFullPartialProvisionalNonconforming(): void
    {
        $this->assertSame(
            ['full', 'partial', 'provisional', 'nonconforming'],
            PlatformConformanceSuite::CONFORMANCE_LEVELS,
        );
    }

    public function testTargetMatrixCoversIssueDeliverables(): void
    {
        $expected = [
            'standalone_server',
            'official_sdk',
            'worker_protocol_implementation',
            'cli_json_client',
            'waterline_contract_surface',
            'repair_actionability_surface',
            'mcp_discovery_surface',
        ];
        $this->assertSame($expected, PlatformConformanceSuite::targetNames());
    }

    public function testEveryTargetReferencesOnlyKnownSurfaceFamiliesAndCategories(): void
    {
        $manifest = PlatformConformanceSuite::manifest();

        $surfaceFamilies = array_keys(
            SurfaceStabilityContract::manifest()['surface_families'],
        );
        $categoryNames = array_keys($manifest['fixture_catalog']);

        foreach ($manifest['targets'] as $name => $target) {
            $this->assertArrayHasKey('description', $target, "$name needs description");
            $this->assertArrayHasKey('required_surface_families', $target, "$name needs required_surface_families");
            $this->assertArrayHasKey('required_fixture_categories', $target, "$name needs required_fixture_categories");

            foreach ($target['required_surface_families'] as $family) {
                $this->assertContains(
                    $family,
                    $surfaceFamilies,
                    "$name requires unknown surface family `$family`; declare it in SurfaceStabilityContract first",
                );
            }
            foreach ($target['required_fixture_categories'] as $category) {
                $this->assertContains(
                    $category,
                    $categoryNames,
                    "$name requires unknown fixture category `$category`",
                );
            }
        }
    }

    public function testFixtureCatalogCoversIssueDeliverables(): void
    {
        $expected = [
            'control_plane_request_response',
            'worker_task_lifecycle',
            'signal_query_runtime_contract',
            'history_replay_bundles',
            'failure_repair_actionability',
            'cli_json_envelopes',
            'waterline_observer_envelopes',
            'mcp_discovery_envelopes',
        ];
        $this->assertSame($expected, PlatformConformanceSuite::fixtureCategoryNames());
    }

    public function testEveryFixtureCategoryDeclaresStatusDescriptionAndAtLeastOneSource(): void
    {
        $manifest = PlatformConformanceSuite::manifest();

        $allowedStatus = [
            PlatformConformanceSuite::CATEGORY_STATUS_STABLE,
            PlatformConformanceSuite::CATEGORY_STATUS_PROVISIONAL,
        ];

        foreach ($manifest['fixture_catalog'] as $name => $category) {
            $this->assertArrayHasKey('status', $category, "$name needs status");
            $this->assertContains($category['status'], $allowedStatus, "$name has unknown status");
            $this->assertArrayHasKey('description', $category, "$name needs description");
            $this->assertArrayHasKey('sources', $category, "$name needs sources[]");
            $this->assertNotEmpty($category['sources'], "$name needs at least one source-of-truth pointer");

            foreach ($category['sources'] as $source) {
                $this->assertArrayHasKey('repository', $source, "$name source needs repository");
                $this->assertArrayHasKey('path', $source, "$name source needs path");
            }
        }
    }

    public function testEveryFixtureCategoryAuthorityDocIsACustomerResolvableUrl(): void
    {
        $manifest = PlatformConformanceSuite::manifest();

        foreach ($manifest['fixture_catalog'] as $name => $category) {
            $this->assertArrayHasKey('authority_doc', $category, "$name needs authority_doc");

            $authorityDoc = $category['authority_doc'];
            $this->assertIsString($authorityDoc, "$name authority_doc must be a string");

            foreach (preg_split('/,\s*/', $authorityDoc) as $entry) {
                $this->assertMatchesRegularExpression(
                    '#^https://#',
                    $entry,
                    "$name authority_doc entry `$entry` must be a customer-resolvable https:// URL",
                );
            }
        }
    }

    public function testMcpDiscoveryCategoryNamesCurrentReferenceSurface(): void
    {
        $manifest = PlatformConformanceSuite::manifest();
        $category = $manifest['fixture_catalog']['mcp_discovery_envelopes'];

        $this->assertSame(
            PlatformConformanceSuite::CATEGORY_STATUS_PROVISIONAL,
            $category['status'],
            'MCP conformance remains advisory until the public fixture set is promoted.',
        );
        $this->assertSame(
            'https://github.com/durable-workflow/durable-workflow.github.io/blob/main/docs/mcp-workflows.md',
            $category['authority_doc'],
        );
        $this->assertContains(
            [
                'repository' => 'sample-app',
                'path' => 'tests/Feature/McpWorkflowServerTest.php',
            ],
            $category['sources'],
            'the sample-app MCP server test is the current executable reference surface',
        );
        $this->assertContains(
            [
                'repository' => 'durable-workflow.github.io',
                'path' => 'static/platform-protocol-specs/mcp-tool-results.schema.json',
            ],
            $category['sources'],
            'the tool-result JSON Schema must remain named as a conformance source',
        );
    }

    public function testSignalQueryRuntimeContractIsRequiredForInteractiveSurfaces(): void
    {
        $manifest = PlatformConformanceSuite::manifest();
        $category = $manifest['fixture_catalog']['signal_query_runtime_contract'];

        $this->assertSame(
            PlatformConformanceSuite::CATEGORY_STATUS_STABLE,
            $category['status'],
            'signals and queries must be load-bearing, not a provisional smoke.',
        );

        foreach ([
            'standalone_server',
            'official_sdk',
            'worker_protocol_implementation',
            'cli_json_client',
            'waterline_contract_surface',
        ] as $target) {
            $this->assertContains(
                'signal_query_runtime_contract',
                $manifest['targets'][$target]['required_fixture_categories'],
                "$target must be graded against the live signals/queries contract",
            );
        }

        foreach ([
            'published_artifact_install_only',
            'python_worker_cli_and_sdk_baseline',
            'php_worker_cli_and_sdk_baseline',
            'python_worker_php_facing_and_cli_clients',
            'php_worker_python_and_cli_clients',
            'ordered_signal_delivery',
            'dedup_contract_observation',
            'signal_during_replay',
            'query_during_replay',
            'completed_run_signal_and_query',
            'unknown_signal_and_query_errors',
            'malformed_signal_and_query_payloads',
            'waterline_operator_visibility',
        ] as $scenario) {
            $this->assertContains(
                $scenario,
                $category['required_scenarios'],
                "signals/queries conformance must name scenario $scenario",
            );
        }

        $this->assertSame(
            'https://github.com/durable-workflow/durable-workflow.github.io/blob/main/docs/platform-conformance.md',
            $category['authority_doc'],
        );
        $this->assertContains(
            [
                'repository' => 'durable-workflow.github.io',
                'path' => 'static/platform-conformance/signal-query-runtime-scenarios.json',
            ],
            $category['sources'],
            'the public scenario manifest must be the consumable source for full signals/queries coverage',
        );
        $this->assertContains(
            [
                'repository' => 'waterline',
                'path' => 'CONFORMANCE.md',
            ],
            $category['sources'],
            'operator visibility must remain part of the live signals/queries contract',
        );
        $this->assertContains(
            [
                'repository' => 'workflow',
                'path' => 'src/V2/Client/ControlPlaneClient.php',
            ],
            $category['sources'],
            'the PHP SDK control-plane client must remain a conformance source for cross-language signal/query clients',
        );
    }

    public function testHistoryReplayBundlesAreFlaggedForFrozenExactMatch(): void
    {
        $manifest = PlatformConformanceSuite::manifest();
        $category = $manifest['fixture_catalog']['history_replay_bundles'];

        $rule = $manifest['pass_fail_rules']['frozen_shape_exact_match'] ?? null;
        $this->assertNotNull($rule, 'frozen_shape_exact_match rule must be declared');
        $this->assertContains(
            'history_replay_bundles',
            $rule['applies_to_categories'],
            'history_replay_bundles must be subject to exact-match because the underlying surface is frozen',
        );

        $this->assertSame(
            PlatformConformanceSuite::CATEGORY_STATUS_STABLE,
            $category['status'],
            'history replay must be load-bearing, not a provisional smoke.',
        );
        $this->assertContains(
            [
                'repository' => 'durable-workflow.github.io',
                'path' => 'static/platform-conformance/replay-runtime-scenarios.json',
            ],
            $category['sources'],
            'the public replay scenario manifest must remain a conformance source',
        );

        foreach ([
            'published_artifact_install_only',
            'python_completed_history_activity_replay',
            'python_completed_history_signal_update_replay',
            'python_completed_history_wait_condition_replay',
            'python_completed_history_version_marker_replay',
            'python_completed_history_saga_compensation_replay',
            'php_completed_history_activity_replay',
            'php_completed_history_signal_update_replay',
            'php_completed_history_wait_condition_replay',
            'php_completed_history_version_marker_replay',
            'php_completed_history_saga_compensation_replay',
            'python_worker_restart_completed_query',
            'python_worker_restart_activity_state',
            'python_worker_restart_signal_update_state',
            'python_worker_restart_wait_condition_state',
            'python_worker_restart_version_marker_state',
            'python_worker_restart_saga_compensation_state',
            'php_worker_restart_completed_query',
            'php_worker_restart_activity_state',
            'php_worker_restart_signal_update_state',
            'php_worker_restart_wait_condition_state',
            'php_worker_restart_version_marker_state',
            'php_worker_restart_saga_compensation_state',
            'python_code_divergence_refusal',
            'php_code_divergence_refusal',
            'server_history_mutation_refusal',
            'malformed_history_refusal',
            'python_in_flight_signal_restart_timing',
            'php_in_flight_signal_restart_timing',
        ] as $scenario) {
            $this->assertContains(
                $scenario,
                $category['required_scenarios'],
                "history replay conformance must name scenario $scenario",
            );
        }
    }

    public function testPassFailRulesNameTheCoreContract(): void
    {
        $manifest = PlatformConformanceSuite::manifest();
        $rules = $manifest['pass_fail_rules'];

        $this->assertArrayHasKey('guaranteed_field_equality', $rules);
        $this->assertArrayHasKey('unknown_additive_fields_tolerated', $rules);
        $this->assertArrayHasKey('frozen_shape_exact_match', $rules);
        $this->assertArrayHasKey('required_fixtures_must_pass', $rules);
        $this->assertArrayHasKey('stable_runtime_scenario_coverage', $rules);
        $this->assertArrayHasKey('provisional_categories_warn_only', $rules);
        $this->assertArrayHasKey('diagnostic_only_mismatches_pass', $rules);

        $this->assertSame(
            SurfaceStabilityContract::SCHEMA . '#field_visibility_rule',
            $rules['guaranteed_field_equality']['follows'],
            'the equality rule must defer to the surface stability contract field visibility rule',
        );
        $this->assertContains(
            'signal_query_runtime_contract',
            $rules['stable_runtime_scenario_coverage']['applies_to_categories'],
            'smoke-only signals/queries coverage must not satisfy the stable runtime category',
        );
        $this->assertContains(
            'history_replay_bundles',
            $rules['stable_runtime_scenario_coverage']['applies_to_categories'],
            'smoke-only replay coverage must not satisfy the stable runtime category',
        );
    }

    public function testHarnessContractRequiresStructuredResultDocument(): void
    {
        $manifest = PlatformConformanceSuite::manifest();
        $contract = $manifest['harness_contract'];

        $this->assertArrayHasKey('requirements', $contract);
        $this->assertArrayHasKey('emit_result_document', $contract['requirements']);
        $this->assertArrayHasKey('exit_code', $contract['requirements']);

        $this->assertContains(
            'conformance_level',
            $contract['result_document_required_fields'],
            'the result document must always declare a conformance_level',
        );
        $this->assertContains(
            'suite_version',
            $contract['result_document_required_fields'],
            'the result document must always pin the suite version it ran against',
        );
    }

    public function testReleaseGatesCoverEveryFirstPartySurface(): void
    {
        $manifest = PlatformConformanceSuite::manifest();
        $gates = $manifest['release_gates']['gates'];

        $this->assertArrayHasKey('durable-workflow/server', $gates);
        $this->assertArrayHasKey('durable-workflow/workflow', $gates);
        $this->assertArrayHasKey('durable_workflow', $gates);
        $this->assertArrayHasKey('dw', $gates);
        $this->assertArrayHasKey('waterline', $gates);

        $serverGate = $gates['durable-workflow/server'];
        $this->assertContains('standalone_server', $serverGate['required_targets']);
        $this->assertContains('worker_protocol_implementation', $serverGate['required_targets']);
        $this->assertContains('repair_actionability_surface', $serverGate['required_targets']);

        $this->assertTrue(
            $manifest['release_gates']['enforcement']['block_on_nonconforming'],
            'a nonconforming harness result must block the release',
        );
    }
}
