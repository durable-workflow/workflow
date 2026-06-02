<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;
use Workflow\V2\Support\PlatformConformanceSuite;
use Workflow\V2\Support\SurfaceStabilityContract;

/**
 * Pins the platform conformance suite manifest mirrored by
 * `Workflow\V2\Support\PlatformConformanceSuite`. The authority is the
 * public docs-site platform conformance page. The standalone
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
        $this->assertSame(18, $manifest['version']);
        $this->assertSame('docs/platform-conformance.md', $manifest['authority_doc']);
        $this->assertSame(
            'https://durable-workflow.github.io/docs/2.0/platform-conformance',
            $manifest['authority_url'],
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
            'prerelease_release_candidate',
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
            'search_attribute_runtime_contract',
            'history_replay_bundles',
            'namespace_runtime_contract',
            'child_workflow_runtime_contract',
            'worker_versioning_runtime_contract',
            'saga_runtime_contract',
            'migration_runtime_contract',
            'skew_refusal_matrix_contract',
            'prerelease_readiness_contract',
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

    public function testMigrationAndPrereleaseReadinessContractsArePublished(): void
    {
        $manifest = PlatformConformanceSuite::manifest();

        $this->assertSame(
            17,
            $manifest['version'],
            'the workflow mirror must stay aligned with the currently published platform conformance contract',
        );
        $this->assertArrayHasKey('migration_runtime_contract', $manifest['fixture_catalog']);
        $this->assertArrayHasKey('skew_refusal_matrix_contract', $manifest['fixture_catalog']);
        $this->assertArrayHasKey('prerelease_readiness_contract', $manifest['fixture_catalog']);
        $this->assertContains(
            'migration_runtime_contract',
            $manifest['pass_fail_rules']['stable_runtime_scenario_coverage']['applies_to_categories'],
            'migration conformance is load-bearing once the public scenario manifest is published',
        );
        $this->assertContains(
            'skew_refusal_matrix_contract',
            $manifest['pass_fail_rules']['stable_runtime_scenario_coverage']['applies_to_categories'],
            'skew refusal matrix conformance must stay non-passing until the full published-artifact matrix exists',
        );
        $this->assertContains(
            'prerelease_readiness_contract',
            $manifest['pass_fail_rules']['stable_runtime_scenario_coverage']['applies_to_categories'],
            'prerelease readiness must stay non-passing until published-artifact product evidence exists',
        );

        foreach ([
            'standalone_server',
            'official_sdk',
            'worker_protocol_implementation',
            'cli_json_client',
            'waterline_contract_surface',
        ] as $target) {
            $this->assertNotContains(
                'prerelease_readiness_contract',
                $manifest['targets'][$target]['required_fixture_categories'],
                "$target is an implementation target; prerelease readiness is claimed only by the release-candidate aggregate target",
            );
            $this->assertContains(
                'skew_refusal_matrix_contract',
                $manifest['targets'][$target]['required_fixture_categories'],
                "$target must be graded against the published-artifact skew-refusal matrix",
            );
        }

        $this->assertSame(
            ['skew_refusal_matrix_contract', 'prerelease_readiness_contract'],
            $manifest['targets']['prerelease_release_candidate']['required_fixture_categories'],
        );
    }

    public function testMigrationRuntimeContractNamesUpgradeSafetySurface(): void
    {
        $manifest = PlatformConformanceSuite::manifest();
        $category = $manifest['fixture_catalog']['migration_runtime_contract'];

        $this->assertSame(
            PlatformConformanceSuite::CATEGORY_STATUS_STABLE,
            $category['status'],
            'v1 to v2 migration safety must be load-bearing, not a fresh-install smoke.',
        );

        foreach ([
            'standalone_server',
            'official_sdk',
            'worker_protocol_implementation',
            'cli_json_client',
            'waterline_contract_surface',
        ] as $target) {
            $this->assertContains(
                'migration_runtime_contract',
                $manifest['targets'][$target]['required_fixture_categories'],
                "$target must be graded against the live migration contract",
            );
        }

        $this->assertSame(
            [
                [
                    'repository' => 'durable-workflow.github.io',
                    'path' => 'static/platform-conformance/migration-runtime-scenarios.json',
                ],
            ],
            $category['sources'],
            'the public migration scenario manifest must be the consumable source for upgrade-safety coverage',
        );
        $this->assertSame(
            'https://durable-workflow.github.io/docs/2.0/platform-conformance',
            $category['authority_doc'],
            'the migration contract must point at the public platform conformance authority doc',
        );

        foreach ([
            'published_artifact_install_only',
            'latest_supported_v1_state_setup',
            'documented_migration_steps_execute',
            'completed_history_preservation_and_replay',
            'in_flight_workflow_progress_preserved',
            'mid_activity_retry_preserved',
            'schedule_cross_upgrade_cadence_preserved',
            'worker_registration_projection_preserved',
            'waterline_operator_visibility_preserved',
            'cli_access_to_preupgrade_state',
            'new_v2_workflow_start_after_upgrade',
            'rollback_contract_verified',
            'version_skew_refusal',
        ] as $scenario) {
            $this->assertContains(
                $scenario,
                $category['required_scenarios'],
                "migration conformance must name scenario $scenario",
            );
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

    public function testSkewRefusalMatrixContractNamesFullRuntimeSurface(): void
    {
        $manifest = PlatformConformanceSuite::manifest();
        $category = $manifest['fixture_catalog']['skew_refusal_matrix_contract'];

        $this->assertSame(
            PlatformConformanceSuite::CATEGORY_STATUS_STABLE,
            $category['status'],
            'skew refusal must be load-bearing, not a protocol-manifest smoke.',
        );
        $this->assertSame(
            [
                [
                    'repository' => 'durable-workflow.github.io',
                    'path' => 'static/platform-conformance/skew-refusal-matrix-scenarios.json',
                ],
            ],
            $category['sources'],
            'the public skew scenario manifest must be the consumable source for the full matrix',
        );

        foreach ([
            'published_artifact_install_only',
            'cli_version_pair_matrix',
            'sdk_python_version_pair_matrix',
            'workflow_worker_version_pair_matrix',
            'waterline_version_pair_matrix',
            'future_version_boundary_matrix',
            'request_response_capture_for_skewed_operations',
            'focused_finding_routing',
        ] as $scenario) {
            $this->assertContains(
                $scenario,
                $category['required_scenarios'],
                "skew conformance must name scenario $scenario",
            );
        }

        $this->assertContains(
            'skew_refusal_matrix_contract',
            $manifest['pass_fail_rules']['stable_runtime_scenario_coverage']['applies_to_categories'],
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
            'https://durable-workflow.github.io/docs/2.0/platform-conformance',
            $category['authority_doc'],
        );
        $this->assertSame(
            [
                [
                    'repository' => 'durable-workflow.github.io',
                    'path' => 'static/platform-conformance/signal-query-runtime-scenarios.json',
                ],
            ],
            $category['sources'],
            'the public scenario manifest must be the consumable source for full signals/queries coverage',
        );
    }

    public function testSearchAttributeRuntimeContractNamesFullParitySurface(): void
    {
        $manifest = PlatformConformanceSuite::manifest();
        $category = $manifest['fixture_catalog']['search_attribute_runtime_contract'];

        $this->assertSame(
            PlatformConformanceSuite::CATEGORY_STATUS_STABLE,
            $category['status'],
            'search attributes must be load-bearing, not a Python/server smoke.',
        );

        foreach ([
            'standalone_server',
            'official_sdk',
            'worker_protocol_implementation',
            'cli_json_client',
            'waterline_contract_surface',
        ] as $target) {
            $this->assertContains(
                'search_attribute_runtime_contract',
                $manifest['targets'][$target]['required_fixture_categories'],
                "$target must be graded against the live search-attribute contract",
            );
        }

        foreach ([
            'published_artifact_install_only',
            'schema_definition_and_reserved_name_refusal',
            'python_worker_start_and_upsert_visibility',
            'php_worker_start_and_upsert_visibility',
            'cli_query_and_error_surface',
            'waterline_operator_visibility',
            'python_to_php_codec_round_trip',
            'php_to_python_codec_round_trip',
            'equality_range_bool_query_behavior',
            'or_not_query_grammar',
            'keyword_list_membership',
            'type_safety_wrong_literal',
            'undefined_key_rejection',
            'indexing_latency_distribution',
            'load_and_bounded_latency',
            'namespace_isolation',
            'query_injection_hardening',
        ] as $scenario) {
            $this->assertContains(
                $scenario,
                $category['required_scenarios'],
                "search-attribute conformance must name scenario $scenario",
            );
        }

        $this->assertSame(
            [
                [
                    'repository' => 'durable-workflow.github.io',
                    'path' => 'static/platform-conformance/search-attribute-runtime-scenarios.json',
                    'public_url' => 'https://durable-workflow.com/platform-conformance/search-attribute-runtime-scenarios.json',
                ],
            ],
            $category['sources'],
            'the public search-attribute scenario manifest must be the consumable source for full parity coverage',
        );

        $this->assertContains(
            'search_attribute_runtime_contract',
            $manifest['pass_fail_rules']['stable_runtime_scenario_coverage']['applies_to_categories'],
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

    public function testNamespaceRuntimeContractNamesFullTemporalParitySurface(): void
    {
        $manifest = PlatformConformanceSuite::manifest();
        $category = $manifest['fixture_catalog']['namespace_runtime_contract'];

        $this->assertSame(
            PlatformConformanceSuite::CATEGORY_STATUS_STABLE,
            $category['status'],
            'namespace conformance must be load-bearing, not a smoke subset.',
        );

        foreach ([
            'standalone_server',
            'official_sdk',
            'worker_protocol_implementation',
            'cli_json_client',
            'waterline_contract_surface',
        ] as $target) {
            $this->assertContains(
                'namespace_runtime_contract',
                $manifest['targets'][$target]['required_fixture_categories'],
                "$target must be graded against the live namespace runtime contract",
            );
        }

        foreach ([
            'published_artifact_install_only',
            'namespace_create_update_describe_and_list',
            'workflow_cross_namespace_visibility_isolation',
            'workflow_cross_namespace_mutation_isolation',
            'php_worker_task_queue_namespace_isolation',
            'cli_namespace_context_and_default_scope',
            'sdk_namespace_selection_parity',
            'search_attribute_schema_and_value_query_isolation',
            'schedule_namespace_isolation',
            'namespace_lifecycle_cleanup_and_recreate',
            'waterline_operator_namespace_visibility',
            'nexus_explicit_cross_namespace_invocation',
            'reserved_namespace_name_refusal',
            'result_record_and_product_finding_routing',
        ] as $scenario) {
            $this->assertContains(
                $scenario,
                $category['required_scenarios'],
                "namespace conformance must name scenario $scenario",
            );
        }

        $this->assertSame(
            [
                [
                    'repository' => 'durable-workflow.github.io',
                    'path' => 'static/platform-conformance/namespace-runtime-scenarios.json',
                ],
            ],
            $category['sources'],
            'the public namespace scenario manifest must be the consumable source for full namespace coverage',
        );
    }

    public function testChildWorkflowRuntimeContractIsRequiredForParentChildSurfaces(): void
    {
        $manifest = PlatformConformanceSuite::manifest();
        $category = $manifest['fixture_catalog']['child_workflow_runtime_contract'];

        $this->assertSame(
            PlatformConformanceSuite::CATEGORY_STATUS_STABLE,
            $category['status'],
            'child workflow parity must be load-bearing, not a single-runtime smoke.',
        );

        foreach ([
            'standalone_server',
            'official_sdk',
            'worker_protocol_implementation',
            'cli_json_client',
        ] as $target) {
            $this->assertContains(
                'child_workflow_runtime_contract',
                $manifest['targets'][$target]['required_fixture_categories'],
                "$target must be graded against the live child workflow contract",
            );
        }

        $this->assertSame(
            [
                [
                    'repository' => 'durable-workflow.github.io',
                    'path' => 'static/platform-conformance/child-workflow-runtime-scenarios.json',
                ],
            ],
            $category['sources'],
            'the public child workflow scenario manifest must be the consumable source for full child workflow coverage',
        );

        foreach ([
            'published_artifact_install_only',
            'python_parent_python_child_baseline',
            'php_parent_php_child_baseline',
            'php_parent_python_child_cross_language',
            'python_parent_php_child_cross_language',
            'child_failure_round_trip_matrix',
            'parent_cancellation_propagates_to_child',
            'direct_child_cancellation_observed_by_parent',
            'worker_restart_replay_preserves_child_outcome',
            'concurrent_child_fan_out',
            'child_workflow_namespace_contract',
        ] as $scenario) {
            $this->assertContains(
                $scenario,
                $category['required_scenarios'],
                "child workflow conformance must name scenario $scenario",
            );
        }
    }

    public function testWorkerVersioningRuntimeContractNamesSafeDeploySurface(): void
    {
        $manifest = PlatformConformanceSuite::manifest();
        $category = $manifest['fixture_catalog']['worker_versioning_runtime_contract'];

        $this->assertSame(
            PlatformConformanceSuite::CATEGORY_STATUS_STABLE,
            $category['status'],
            'worker versioning must be load-bearing, not advisory release notes.',
        );

        foreach ([
            'standalone_server',
            'official_sdk',
            'worker_protocol_implementation',
            'cli_json_client',
            'waterline_contract_surface',
        ] as $target) {
            $this->assertContains(
                'worker_versioning_runtime_contract',
                $manifest['targets'][$target]['required_fixture_categories'],
                "$target must be graded against the live worker-versioning contract",
            );
        }

        $this->assertSame(
            [
                [
                    'repository' => 'durable-workflow.github.io',
                    'path' => 'static/platform-conformance/worker-versioning-runtime-scenarios.json',
                ],
            ],
            $category['sources'],
            'the public worker-versioning scenario manifest must be the consumable source for safe-deploy coverage',
        );

        foreach ([
            'published_artifact_install_only',
            'worker_registration_build_ids',
            'operator_rollout_visibility',
            'drain_resume_operator_controls',
            'pin_on_start',
            'replay_only_by_compatible_workers',
            'new_starts_to_promoted_version',
            'replay_across_cache_eviction',
            'no_compatible_worker_behavior',
            'operator_visibility_surfaces',
            'cross_language_php_python_pinning',
            'adversarial_no_version_bump',
            'history_api_version_pin',
        ] as $scenario) {
            $this->assertContains(
                $scenario,
                $category['required_scenarios'],
                "worker-versioning conformance must name scenario $scenario",
            );
        }
    }

    public function testSagaRuntimeContractNamesCompensationParitySurface(): void
    {
        $manifest = PlatformConformanceSuite::manifest();
        $category = $manifest['fixture_catalog']['saga_runtime_contract'];

        $this->assertSame(
            PlatformConformanceSuite::CATEGORY_STATUS_STABLE,
            $category['status'],
            'saga compensation must be load-bearing, not a happy-path smoke.',
        );

        foreach ([
            'standalone_server',
            'official_sdk',
            'worker_protocol_implementation',
            'cli_json_client',
            'waterline_contract_surface',
        ] as $target) {
            $this->assertContains(
                'saga_runtime_contract',
                $manifest['targets'][$target]['required_fixture_categories'],
                "$target must be graded against the live saga compensation contract",
            );
        }

        $this->assertSame(
            [
                [
                    'repository' => 'durable-workflow.github.io',
                    'path' => 'static/platform-conformance/saga-runtime-scenarios.json',
                ],
            ],
            $category['sources'],
            'the public saga scenario manifest must be the consumable source for full compensation coverage',
        );

        foreach ([
            'published_artifact_install_only',
            'forward_success_path',
            'failure_at_d_reverse_compensation',
            'failure_at_c_reverse_compensation',
            'failure_at_a_no_compensation',
            'compensation_retry_idempotence',
            'compensation_failure_visibility',
            'mid_compensation_worker_restart',
            'php_workflow_python_compensation',
            'python_workflow_php_compensation',
            'typed_compensation_error_round_trip',
            'operator_visible_mid_compensation_status',
        ] as $scenario) {
            $this->assertContains(
                $scenario,
                $category['required_scenarios'],
                "saga conformance must name scenario $scenario",
            );
        }
    }

    public function testPrereleaseReadinessContractNamesReleaseCandidateAuditSurface(): void
    {
        $manifest = PlatformConformanceSuite::manifest();
        $category = $manifest['fixture_catalog']['prerelease_readiness_contract'];

        $this->assertSame(
            PlatformConformanceSuite::CATEGORY_STATUS_STABLE,
            $category['status'],
            'prerelease readiness must be load-bearing, not coverage-only evidence.',
        );
        $this->assertSame(
            [
                [
                    'repository' => 'durable-workflow.github.io',
                    'path' => 'static/platform-conformance/prerelease-readiness-scenarios.json',
                ],
            ],
            $category['sources'],
            'the public prerelease readiness scenario manifest must be the consumable source for release-candidate evidence',
        );

        foreach ([
            'published_artifact_release_set',
            'workflow_feature_completeness_verdict',
            'workflow_migration_readiness_verdict',
            'workflow_public_api_stability_verdict',
            'workflow_documentation_and_config_verdict',
            'quickstart_local_server_hosted_completion',
            'quickstart_laravel_branch_completion',
            'waterline_feature_completeness_verdict',
            'waterline_migration_and_config_verdict',
            'waterline_public_api_and_docs_verdict',
            'ecosystem_compatibility_verdict',
            'focused_finding_routing',
        ] as $scenario) {
            $this->assertContains(
                $scenario,
                $category['required_scenarios'],
                "prerelease readiness conformance must name scenario $scenario",
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
        $this->assertContains(
            'namespace_runtime_contract',
            $rules['stable_runtime_scenario_coverage']['applies_to_categories'],
            'smoke-only namespace coverage must not satisfy the stable runtime category',
        );
        $this->assertContains(
            'child_workflow_runtime_contract',
            $rules['stable_runtime_scenario_coverage']['applies_to_categories'],
            'smoke-only child workflow coverage must not satisfy the stable runtime category',
        );
        $this->assertContains(
            'worker_versioning_runtime_contract',
            $rules['stable_runtime_scenario_coverage']['applies_to_categories'],
            'safe-deploy worker-versioning coverage must not satisfy the suite with smoke-only evidence',
        );
        $this->assertContains(
            'saga_runtime_contract',
            $rules['stable_runtime_scenario_coverage']['applies_to_categories'],
            'saga compensation coverage must not satisfy the suite with smoke-only evidence',
        );
        $this->assertContains(
            'migration_runtime_contract',
            $rules['stable_runtime_scenario_coverage']['applies_to_categories'],
            'migration coverage must not satisfy the suite with fresh-install-only evidence',
        );
        $this->assertContains(
            'prerelease_readiness_contract',
            $rules['stable_runtime_scenario_coverage']['applies_to_categories'],
            'runner-only prerelease readiness evidence must not satisfy the suite',
        );
        foreach (['pass', 'fail', 'unsupported', 'not_covered', 'runner_blocked'] as $status) {
            $this->assertStringContainsString(
                $status,
                $rules['stable_runtime_scenario_coverage']['rule'],
                "runtime scenario status `$status` must stay part of the versioned status taxonomy",
            );
        }
        $this->assertStringContainsString(
            'statuses published by its runtime scenario manifest',
            $rules['stable_runtime_scenario_coverage']['rule'],
            'the suite rule must delegate the status set to the published scenario manifests',
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
        $this->assertArrayHasKey('durable-workflow/2.0-release-candidate', $gates);

        $serverGate = $gates['durable-workflow/server'];
        $this->assertContains('standalone_server', $serverGate['required_targets']);
        $this->assertContains('worker_protocol_implementation', $serverGate['required_targets']);
        $this->assertContains('repair_actionability_surface', $serverGate['required_targets']);

        $releaseCandidateGate = $gates['durable-workflow/2.0-release-candidate'];
        $this->assertContains('prerelease_release_candidate', $releaseCandidateGate['required_targets']);

        $this->assertTrue(
            $manifest['release_gates']['enforcement']['block_on_nonconforming'],
            'a nonconforming harness result must block the release',
        );
    }
}
