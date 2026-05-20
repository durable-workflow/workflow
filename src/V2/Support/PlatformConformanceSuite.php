<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

/**
 * Canonical, machine-readable mirror of the platform conformance suite
 * specification. The human-readable authority is the public docs-site
 * platform conformance page. This class exists so server endpoints, CI
 * gates, and third-party harnesses can validate themselves against one
 * source of truth.
 *
 * The suite is downstream of `SurfaceStabilityContract` (the platform
 * compatibility authority): every target row's `required_surface_families`
 * entry must name a key that the surface stability contract publishes.
 *
 * Adding a target, adding a required surface, adding a fixture category,
 * promoting a provisional category to required, or changing a pass / fail
 * rule is a contract change. Bump VERSION and align the doc page in the
 * same change. Removing a target or a required fixture category is a
 * major change.
 *
 * @api Stable class surface consumed by the standalone workflow-server,
 * which re-exports the manifest from `GET /api/cluster/info` under the
 * `platform_conformance_suite` key.
 */
final class PlatformConformanceSuite
{
    public const SCHEMA = 'durable-workflow.v2.platform-conformance.suite';

    public const VERSION = 7;

    public const RESULT_SCHEMA = 'durable-workflow.v2.platform-conformance.result';

    public const RESULT_VERSION = 1;

    public const AUTHORITY_DOC = 'docs/platform-conformance.md';

    public const AUTHORITY_URL = 'https://durable-workflow.github.io/docs/2.0/platform-conformance';

    public const CATEGORY_STATUS_STABLE = 'stable';

    public const CATEGORY_STATUS_PROVISIONAL = 'provisional';

    public const CONFORMANCE_LEVEL_FULL = 'full';

    public const CONFORMANCE_LEVEL_PARTIAL = 'partial';

    public const CONFORMANCE_LEVEL_PROVISIONAL = 'provisional';

    public const CONFORMANCE_LEVEL_NONCONFORMING = 'nonconforming';

    public const CONFORMANCE_LEVELS = [
        self::CONFORMANCE_LEVEL_FULL,
        self::CONFORMANCE_LEVEL_PARTIAL,
        self::CONFORMANCE_LEVEL_PROVISIONAL,
        self::CONFORMANCE_LEVEL_NONCONFORMING,
    ];

    /**
     * @return array<string, mixed>
     */
    public static function manifest(): array
    {
        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'authority_doc' => self::AUTHORITY_DOC,
            'authority_url' => self::AUTHORITY_URL,
            'surface_stability_authority' => SurfaceStabilityContract::SCHEMA,
            'result_schema' => self::RESULT_SCHEMA,
            'result_version' => self::RESULT_VERSION,
            'conformance_levels' => self::CONFORMANCE_LEVELS,
            'targets' => self::targets(),
            'fixture_catalog' => self::fixtureCatalog(),
            'pass_fail_rules' => self::passFailRules(),
            'harness_contract' => self::harnessContract(),
            'release_gates' => self::releaseGates(),
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function targetNames(): array
    {
        return array_keys(self::targets());
    }

    /**
     * @return array<int, string>
     */
    public static function fixtureCategoryNames(): array
    {
        return array_keys(self::fixtureCatalog());
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function targets(): array
    {
        return [
            'standalone_server' => [
                'description' => 'Implementations of the standalone Durable Workflow server: the HTTP control plane plus the worker plane plus the cluster_info manifests.',
                'required_surface_families' => [
                    'server_api',
                    'worker_protocol',
                    'cluster_info_manifests',
                ],
                'required_fixture_categories' => [
                    'control_plane_request_response',
                    'signal_query_runtime_contract',
                    'namespace_runtime_contract',
                    'child_workflow_runtime_contract',
                    'worker_task_lifecycle',
                    'failure_repair_actionability',
                ],
            ],
            'official_sdk' => [
                'description' => 'First-party SDK distributed by the project (PHP workflow package, Python SDK, future first-party SDKs). Must drive the worker plane, replay frozen history, and round-trip the control plane.',
                'required_surface_families' => [
                    'official_sdks',
                    'worker_protocol',
                    'history_event_wire_formats',
                ],
                'required_fixture_categories' => [
                    'control_plane_request_response',
                    'signal_query_runtime_contract',
                    'namespace_runtime_contract',
                    'child_workflow_runtime_contract',
                    'worker_task_lifecycle',
                    'history_replay_bundles',
                ],
            ],
            'worker_protocol_implementation' => [
                'description' => 'Third-party or alternate-language implementation of the worker plane and replay surface only. Does not have to host the server or implement the CLI JSON envelopes.',
                'required_surface_families' => [
                    'worker_protocol',
                    'history_event_wire_formats',
                ],
                'required_fixture_categories' => [
                    'worker_task_lifecycle',
                    'signal_query_runtime_contract',
                    'namespace_runtime_contract',
                    'child_workflow_runtime_contract',
                    'history_replay_bundles',
                ],
            ],
            'cli_json_client' => [
                'description' => 'Implementation that emits the `--output=json` and `--output=jsonl` envelopes that automation, agents, and operator scripts depend on. Includes the official `dw` CLI and any alternate operator shell that claims drop-in JSON parity.',
                'required_surface_families' => [
                    'cli_json',
                ],
                'required_fixture_categories' => [
                    'control_plane_request_response',
                    'signal_query_runtime_contract',
                    'namespace_runtime_contract',
                    'child_workflow_runtime_contract',
                    'cli_json_envelopes',
                ],
            ],
            'waterline_contract_surface' => [
                'description' => 'Implementation of the Waterline observability HTTP API and operator dashboard JSON shapes. Includes the first-party Waterline UI and any alternate observer that claims compatibility.',
                'required_surface_families' => [
                    'waterline_api',
                ],
                'required_fixture_categories' => [
                    'signal_query_runtime_contract',
                    'namespace_runtime_contract',
                    'waterline_observer_envelopes',
                ],
            ],
            'repair_actionability_surface' => [
                'description' => 'Implementation that emits or consumes the failure / repair / actionability objects on stuck tasks, deterministic failure, and replay-mismatch surfaces.',
                'required_surface_families' => [
                    'worker_protocol',
                    'server_api',
                ],
                'required_fixture_categories' => [
                    'failure_repair_actionability',
                ],
            ],
            'mcp_discovery_surface' => [
                'description' => 'Implementation of the Model Context Protocol surfaces (`/mcp/*`) and the `llms.txt` / `llms-2.0.txt` discovery files used by AI clients.',
                'required_surface_families' => [
                    'mcp_discovery_results',
                ],
                'required_fixture_categories' => [
                    'mcp_discovery_envelopes',
                ],
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function fixtureCatalog(): array
    {
        return [
            'control_plane_request_response' => [
                'status' => self::CATEGORY_STATUS_STABLE,
                'description' => 'Frozen request bodies and response shapes for control-plane operations: workflow.start, signal, query, update, cancel, task-history page, namespace storage driver, storage round-trip diagnostic.',
                'sources' => [
                    [
                        'repository' => 'cli',
                        'path' => 'tests/fixtures/control-plane/',
                    ],
                    [
                        'repository' => 'sdk-python',
                        'path' => 'tests/fixtures/control-plane/',
                    ],
                ],
                'authority_doc' => 'https://github.com/durable-workflow/durable-workflow.github.io/blob/main/docs/polyglot/cli-python-parity.md',
            ],
            'worker_task_lifecycle' => [
                'status' => self::CATEGORY_STATUS_STABLE,
                'description' => 'Task input envelopes (poll → claim → run) and task result envelopes (complete, fail, cancel, heartbeat) used by every conforming worker.',
                'sources' => [
                    [
                        'repository' => 'cli',
                        'path' => 'tests/fixtures/external-task/',
                    ],
                    [
                        'repository' => 'cli',
                        'path' => 'tests/fixtures/external-task-input/',
                    ],
                    [
                        'repository' => 'sdk-python',
                        'path' => 'tests/fixtures/external-task-input/',
                    ],
                    [
                        'repository' => 'sdk-python',
                        'path' => 'tests/fixtures/external-task-result/',
                    ],
                ],
                'authority_doc' => 'https://github.com/durable-workflow/server/blob/main/docs/contracts/external-task-input.md, https://github.com/durable-workflow/server/blob/main/docs/contracts/external-task-result.md',
            ],
            'signal_query_runtime_contract' => [
                'status' => self::CATEGORY_STATUS_STABLE,
                'description' => 'Live published-artifact scenarios for signal delivery and query consistency across PHP and Python workers, CLI and SDK clients, replay timing, terminal runs, malformed payloads, and operator visibility.',
                'sources' => [
                    [
                        'repository' => 'durable-workflow.github.io',
                        'path' => 'static/platform-conformance/signal-query-runtime-scenarios.json',
                    ],
                ],
                'authority_doc' => self::AUTHORITY_URL,
                'required_scenarios' => [
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
                ],
            ],
            'history_replay_bundles' => [
                'status' => self::CATEGORY_STATUS_STABLE,
                'description' => 'Deterministic replay coverage for frozen history bundles, worker restart replay, adversarial refusal, and in-flight signal timing across the official PHP and Python runtimes.',
                'sources' => [
                    [
                        'repository' => 'durable-workflow.github.io',
                        'path' => 'static/platform-conformance/replay-runtime-scenarios.json',
                    ],
                    [
                        'repository' => 'workflow',
                        'path' => 'tests/Fixtures/V2/GoldenHistory/',
                    ],
                    [
                        'repository' => 'sdk-python',
                        'path' => 'tests/fixtures/golden_history/',
                    ],
                ],
                'authority_doc' => 'https://durable-workflow.github.io/docs/2.0/platform-conformance, https://github.com/durable-workflow/server/blob/main/docs/contracts/replay-verification.md, https://github.com/durable-workflow/workflow/blob/v2/docs/api-stability.md',
                'required_scenarios' => self::replayRequiredScenarios(),
            ],
            'namespace_runtime_contract' => [
                'status' => self::CATEGORY_STATUS_STABLE,
                'description' => 'Live published-artifact scenarios for Temporal-parity namespace isolation, lifecycle cleanup, CLI and SDK namespace selection, PHP worker routing, Waterline visibility, Nexus opt-in crossing, and search-attribute value query isolation.',
                'sources' => [
                    [
                        'repository' => 'durable-workflow.github.io',
                        'path' => 'static/platform-conformance/namespace-runtime-scenarios.json',
                    ],
                ],
                'authority_doc' => self::AUTHORITY_URL,
                'required_scenarios' => self::namespaceRequiredScenarios(),
            ],
            'child_workflow_runtime_contract' => [
                'status' => self::CATEGORY_STATUS_STABLE,
                'description' => 'Live published-artifact scenarios for child workflow orchestration across PHP and Python workers, cross-language parent/child execution, failure and cancellation propagation, replay after worker restart, concurrent fan-out, and namespace behavior.',
                'sources' => [
                    [
                        'repository' => 'durable-workflow.github.io',
                        'path' => 'static/platform-conformance/child-workflow-runtime-scenarios.json',
                    ],
                ],
                'authority_doc' => self::AUTHORITY_URL,
                'required_scenarios' => self::childWorkflowRequiredScenarios(),
            ],
            'failure_repair_actionability' => [
                'status' => self::CATEGORY_STATUS_STABLE,
                'description' => 'Failure objects and repair / actionability shapes for stuck tasks, deterministic failure, and replay-mismatch surfaces.',
                'sources' => [
                    [
                        'repository' => 'server',
                        'path' => 'docs/contracts/external-task-result.md',
                    ],
                    [
                        'repository' => 'server',
                        'path' => 'docs/contracts/replay-verification.md',
                    ],
                ],
                'authority_doc' => 'https://github.com/durable-workflow/server/blob/main/docs/contracts/external-task-result.md',
            ],
            'cli_json_envelopes' => [
                'status' => self::CATEGORY_STATUS_STABLE,
                'description' => 'The `--output=json` and `--output=jsonl` envelopes that automation depends on. Diagnostic-only fields are listed in the schema and excluded from the contract diff.',
                'sources' => [
                    [
                        'repository' => 'cli',
                        'path' => 'tests/fixtures/control-plane/',
                    ],
                    [
                        'repository' => 'cli',
                        'path' => 'schemas/',
                    ],
                ],
                'authority_doc' => 'https://github.com/durable-workflow/durable-workflow.github.io/blob/main/docs/polyglot/cli-reference.md',
            ],
            'waterline_observer_envelopes' => [
                'status' => self::CATEGORY_STATUS_PROVISIONAL,
                'description' => 'The `/waterline/api/v2/*` shapes and operator dashboard JSON envelopes. Promoted to required when the Waterline contract slice lands its public fixture set.',
                'sources' => [
                    [
                        'repository' => 'waterline',
                        'path' => 'tests/fixtures/observer/ (planned)',
                    ],
                ],
                'authority_doc' => 'https://github.com/durable-workflow/waterline/blob/v2/CONFORMANCE.md',
            ],
            'mcp_discovery_envelopes' => [
                'status' => self::CATEGORY_STATUS_PROVISIONAL,
                'description' => 'MCP `tools/list`, `tools/call`, tool-result, and `llms-2.0.txt` discovery envelopes. Promoted to required when the MCP tool surface stabilizes its public fixture set.',
                'sources' => [
                    [
                        'repository' => 'durable-workflow.github.io',
                        'path' => 'docs/mcp-workflows.md',
                    ],
                    [
                        'repository' => 'durable-workflow.github.io',
                        'path' => 'static/platform-protocol-specs/mcp-tool-results.schema.json',
                    ],
                    [
                        'repository' => 'sample-app',
                        'path' => 'tests/Feature/McpWorkflowServerTest.php',
                    ],
                ],
                'authority_doc' => 'https://github.com/durable-workflow/durable-workflow.github.io/blob/main/docs/mcp-workflows.md',
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function passFailRules(): array
    {
        return [
            'guaranteed_field_equality' => [
                'rule' => 'Every field marked guaranteed in the fixture\'s schema must be present, type-correct, and value-equal in the implementation\'s response. Diagnostic-only fields are ignored.',
                'follows' => SurfaceStabilityContract::SCHEMA . '#field_visibility_rule',
            ],
            'unknown_additive_fields_tolerated' => [
                'rule' => 'An implementation that emits extra fields not present in the fixture passes if and only if those fields are documented diagnostic-only or the fixture is on a stability level that allows additive evolution.',
            ],
            'frozen_shape_exact_match' => [
                'rule' => 'Fixtures backed by a frozen surface family must match exactly. There is no diagnostic-only allowance for frozen shapes; a frozen-shape mismatch is always a fail.',
                'applies_to_categories' => ['history_replay_bundles'],
            ],
            'required_fixtures_must_pass' => [
                'rule' => 'A release that claims a target must pass every required fixture category for that target. One failed required fixture means the release does not conform for that target.',
            ],
            'stable_runtime_scenario_coverage' => [
                'rule' => 'A stable runtime fixture category must report every required scenario it declares with one of the statuses published by its runtime scenario manifest: pass, fail, unsupported, not_covered, or runner_blocked. Full conformance requires every required scenario to pass. A smoke-only subset, omitted scenario, unsupported public surface, uncovered cell, or runner-blocked cell is nonconforming and must link the owning finding.',
                'applies_to_categories' => [
                    'signal_query_runtime_contract',
                    'history_replay_bundles',
                    'namespace_runtime_contract',
                    'child_workflow_runtime_contract',
                ],
            ],
            'provisional_categories_warn_only' => [
                'rule' => 'A failed fixture in a provisional category emits a warning in the harness output and does not block the release. The category becomes load-bearing when promoted to stable in a later suite version.',
            ],
            'diagnostic_only_mismatches_pass' => [
                'rule' => 'If only diagnostic-only fields differ, the harness records the difference in its diagnostic_diff output and the fixture passes.',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private static function replayRequiredScenarios(): array
    {
        return [
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
        ];
    }

    /**
     * @return list<string>
     */
    private static function namespaceRequiredScenarios(): array
    {
        return [
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
        ];
    }

    /**
     * @return list<string>
     */
    private static function childWorkflowRequiredScenarios(): array
    {
        return [
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
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function harnessContract(): array
    {
        return [
            'description' => 'The conformance harness is the executable that consumes the catalog and emits a result document. It is intentionally language-neutral.',
            'requirements' => [
                'load_manifest' => 'Loads the suite manifest from the implementation\'s `surface_stability_contract` and `platform_conformance_suite` cluster_info entries, or from a vendored copy of the static mirror.',
                'load_fixtures' => 'Loads each declared fixture from its source-of-truth path declared in the fixture catalog. Does not fork or vendor fixtures.',
                'drive_implementation' => 'Drives the implementation through the fixture\'s documented operation (HTTP request, worker poll, replay invocation, CLI invocation, MCP discovery call).',
                'compare_under_rules' => 'Compares the response against the fixture under the pass / fail rules above.',
                'emit_result_document' => 'Emits one harness result document per run, schema `' . self::RESULT_SCHEMA . '` version ' . self::RESULT_VERSION . '. The document carries the suite version, the implementation identity, the per-fixture pass / fail / diagnostic diff, and the overall conformance level.',
                'exit_code' => 'Exits non-zero if and only if the conformance level is `nonconforming`.',
            ],
            'result_document_required_fields' => [
                'schema',
                'version',
                'suite_version',
                'implementation' => '{ name, version, claimed_targets[] }',
                'per_fixture_results' => '{ category, fixture_id, status: pass|fail|warn|skipped, diagnostic_diff }',
                'conformance_level',
                'generated_at',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function releaseGates(): array
    {
        return [
            'description' => 'A release that publishes a compatibility claim must produce a signed harness result document before tag.',
            'gates' => [
                'durable-workflow/server' => [
                    'required_targets' => [
                        'standalone_server',
                        'worker_protocol_implementation',
                        'repair_actionability_surface',
                    ],
                    'artifact' => 'GitHub release attaches the harness result document.',
                ],
                'durable-workflow/workflow' => [
                    'required_targets' => [
                        'official_sdk',
                        'worker_protocol_implementation',
                    ],
                    'artifact' => 'GitHub release attaches the harness result document.',
                ],
                'durable_workflow' => [
                    'required_targets' => [
                        'official_sdk',
                        'worker_protocol_implementation',
                    ],
                    'artifact' => 'GitHub release attaches the harness result document.',
                ],
                'dw' => [
                    'required_targets' => [
                        'cli_json_client',
                    ],
                    'artifact' => 'GitHub release attaches the harness result document.',
                ],
                'waterline' => [
                    'required_targets' => [
                        'waterline_contract_surface',
                    ],
                    'artifact' => 'GitHub release attaches the harness result document.',
                ],
            ],
            'enforcement' => [
                'machine' => 'docs site CI runs scripts/check-compatibility-authority.js (extended to walk the platform_conformance_suite manifest) and a per-repo CI job named `platform-conformance` runs the harness against the local build.',
                'human' => 'Release reviewers confirm the harness result is attached, the conformance level is `full` or `provisional`, and the suite version matches the build under test before tagging.',
                'block_on_nonconforming' => true,
            ],
        ];
    }
}
