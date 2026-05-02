<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

/**
 * Canonical, machine-readable mirror of the platform conformance suite
 * specification. The human-readable authority is
 * `docs/architecture/platform-conformance-suite.md`. This class exists
 * so server endpoints, CI gates, and third-party harnesses can validate
 * themselves against one source of truth.
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

    public const VERSION = 1;

    public const RESULT_SCHEMA = 'durable-workflow.v2.platform-conformance.result';

    public const RESULT_VERSION = 1;

    public const AUTHORITY_DOC = 'docs/architecture/platform-conformance-suite.md';

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
                    'cli_json_envelopes',
                ],
            ],
            'waterline_contract_surface' => [
                'description' => 'Implementation of the Waterline observability HTTP API and operator dashboard JSON shapes. Includes the first-party Waterline UI and any alternate observer that claims compatibility.',
                'required_surface_families' => [
                    'waterline_api',
                ],
                'required_fixture_categories' => [
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
                'authority_doc' => 'durable-workflow.github.io/docs/polyglot/cli-python-parity.md',
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
                'authority_doc' => 'server/docs/contracts/external-task-input.md, server/docs/contracts/external-task-result.md',
            ],
            'history_replay_bundles' => [
                'status' => self::CATEGORY_STATUS_STABLE,
                'description' => 'Frozen history event bundles. A conforming SDK must replay each bundle and reproduce the documented final command sequence.',
                'sources' => [
                    [
                        'repository' => 'workflow',
                        'path' => 'tests/Fixtures/V2/GoldenHistory/',
                    ],
                    [
                        'repository' => 'sdk-python',
                        'path' => 'tests/fixtures/golden_history/',
                    ],
                ],
                'authority_doc' => 'workflow/docs/api-stability.md (frozen history-event wire formats)',
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
                'authority_doc' => 'server/docs/contracts/external-task-result.md',
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
                'authority_doc' => 'durable-workflow.github.io/docs/polyglot/cli-reference.md',
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
                'authority_doc' => 'waterline/CONFORMANCE.md',
            ],
            'mcp_discovery_envelopes' => [
                'status' => self::CATEGORY_STATUS_PROVISIONAL,
                'description' => 'MCP `tools/list`, `tools/call`, and `llms-2.0.txt` discovery envelopes. Promoted to required when the MCP tool surface stabilizes its public fixture set.',
                'sources' => [
                    [
                        'repository' => 'workflow',
                        'path' => 'tests/Fixtures/Mcp/ (planned)',
                    ],
                    [
                        'repository' => 'server',
                        'path' => 'tests/Fixtures/Mcp/ (planned)',
                    ],
                ],
                'authority_doc' => 'durable-workflow.github.io/docs/polyglot/ (mcp surface, planned)',
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
            'provisional_categories_warn_only' => [
                'rule' => 'A failed fixture in a provisional category emits a warning in the harness output and does not block the release. The category becomes load-bearing when promoted to stable in a later suite version.',
            ],
            'diagnostic_only_mismatches_pass' => [
                'rule' => 'If only diagnostic-only fields differ, the harness records the difference in its diagnostic_diff output and the fixture passes.',
            ],
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
