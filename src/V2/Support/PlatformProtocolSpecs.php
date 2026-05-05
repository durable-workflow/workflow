<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

/**
 * Canonical, machine-readable mirror of the platform-wide normative
 * protocol-spec catalog published at
 * https://durable-workflow.github.io/docs/2.0/platform-protocol-specs.
 *
 * The doc page is the human-readable authority. This class is the same
 * catalog in machine-readable form so that server endpoints, CI gates,
 * SDK builds, agents, and third-party tooling can validate themselves
 * against one source of truth instead of reading prose or
 * reverse-engineering fixtures.
 *
 * The companion `Workflow\V2\Support\SurfaceStabilityContract` says
 * *which* surfaces are public and *how* they may change. This catalog
 * says *where* the normative spec for each surface lives, *which
 * format* (OpenAPI / JSON Schema / AsyncAPI) it uses, *which
 * repository* owns it, and *which conformance test* pins it against
 * drift.
 *
 * Adding a spec entry, changing its format, owner, breaking-change
 * rule, or status, or removing an entry is a contract change. Bump
 * VERSION and align the doc page, the static JSON mirror in
 * `durable-workflow.github.io/static/platform-protocol-specs.json`, and
 * the per-package stability docs in the same change. Removing a spec
 * entry is a major change. The `release_check` block enumerates the
 * gates a release must pass before publish.
 *
 * @api Stable class surface consumed by the standalone workflow-server,
 * which re-exports the manifest from `GET /api/cluster/info` under the
 * `platform_protocol_specs` key.
 */
final class PlatformProtocolSpecs
{
    public const SCHEMA = 'durable-workflow.v2.platform-protocol-specs.catalog';

    public const VERSION = 1;

    public const AUTHORITY_URL = 'https://durable-workflow.github.io/docs/2.0/platform-protocol-specs';

    public const FORMAT_OPENAPI = 'openapi';

    public const FORMAT_JSON_SCHEMA = 'json_schema';

    public const FORMAT_ASYNCAPI = 'asyncapi';

    public const FORMATS = [
        self::FORMAT_OPENAPI,
        self::FORMAT_JSON_SCHEMA,
        self::FORMAT_ASYNCAPI,
    ];

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_PLANNED = 'planned';

    public const STATUSES = [
        self::STATUS_PUBLISHED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_PLANNED,
    ];

    public const EVOLUTION_ADDITIVE_MINOR_BREAKING_MAJOR = 'additive_minor_breaking_major';

    public const EVOLUTION_PARALLEL_PRIMITIVE_ONLY = 'parallel_primitive_only';

    public const EVOLUTION_EXPERIMENTAL_ANY_RELEASE = 'experimental_any_release';

    public const OWNER_REPOS = [
        'durable-workflow/workflow',
        'durable-workflow/server',
        'durable-workflow/waterline',
        'durable-workflow/durable-workflow.github.io',
        'durable-workflow/cli',
        'durable-workflow/sdk-python',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function manifest(): array
    {
        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'authority_url' => self::AUTHORITY_URL,
            'formats' => self::formats(),
            'owner_repos' => self::OWNER_REPOS,
            'status_levels' => self::statusLevels(),
            'evolution_rules' => self::evolutionRules(),
            'specs' => self::specs(),
            'release_check' => self::releaseCheck(),
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function formatValues(): array
    {
        return self::FORMATS;
    }

    /**
     * @return array<int, string>
     */
    public static function statusValues(): array
    {
        return self::STATUSES;
    }

    /**
     * @return array<int, string>
     */
    public static function ownerRepoValues(): array
    {
        return self::OWNER_REPOS;
    }

    /**
     * @return array<int, string>
     */
    public static function specNames(): array
    {
        return array_keys(self::specs());
    }

    /**
     * @return array<string, array<string, string>>
     */
    private static function formats(): array
    {
        return [
            self::FORMAT_OPENAPI => [
                'meaning' => 'OpenAPI 3.1 specification document. Used for HTTP+JSON request/response surfaces where method, path, status code, and body shape are part of the contract.',
                'file_extensions' => 'yaml or json',
            ],
            self::FORMAT_JSON_SCHEMA => [
                'meaning' => 'JSON Schema (Draft 2020-12) document for a single object family or a small set of related object families. Used for response bodies, persisted record shapes, event payloads, and tool-result envelopes that are produced or consumed across protocol boundaries.',
                'file_extensions' => 'json',
            ],
            self::FORMAT_ASYNCAPI => [
                'meaning' => 'AsyncAPI 2.6+ specification document. Used for event-stream and long-poll semantics where ordering, delivery, ack, and channel topology are part of the contract.',
                'file_extensions' => 'yaml or json',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function statusLevels(): array
    {
        return [
            self::STATUS_PUBLISHED => 'A normative machine-readable spec exists at the listed `spec_path`, is pinned by `conformance_test`, and is referenced from public docs. SDK authors and tooling must validate against this spec.',
            self::STATUS_IN_PROGRESS => 'The owner repo has begun publishing the spec; coverage is partial. Fields and routes that are listed are normative; routes not yet listed are still governed by the `authority_manifest` and the per-package stability document. CI enforces alignment between what the spec covers and what the manifest advertises.',
            self::STATUS_PLANNED => 'The spec is planned but not yet published. The `authority_manifest` and the per-package stability document remain the normative source until the spec lands. This status exists so the catalog enumerates the full spec set even before every spec is written.',
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function evolutionRules(): array
    {
        return [
            self::EVOLUTION_ADDITIVE_MINOR_BREAKING_MAJOR => [
                'meaning' => 'Additive changes (new endpoints, new optional fields, new enum cases that consumers may safely ignore) advance the spec in a minor version. Removals, renames, type narrowings, or semantic changes require a major version, ship behind a parallel route or field where feasible, and are announced one minor in advance per `SurfaceStabilityContract::releaseRules`.',
                'applies_to_formats' => [self::FORMAT_OPENAPI, self::FORMAT_ASYNCAPI, self::FORMAT_JSON_SCHEMA],
            ],
            self::EVOLUTION_PARALLEL_PRIMITIVE_ONLY => [
                'meaning' => 'The spec describes a frozen wire format. The only allowed breaking change is to introduce a parallel primitive (a new event type, command type, or schema id) alongside the existing one. The original shape stays decodable indefinitely.',
                'applies_to_formats' => [self::FORMAT_JSON_SCHEMA],
            ],
            self::EVOLUTION_EXPERIMENTAL_ANY_RELEASE => [
                'meaning' => 'Spec entries marked experimental may change in any release. Consumers must opt in by reading the experimental flag on the spec.',
                'applies_to_formats' => [self::FORMAT_OPENAPI, self::FORMAT_ASYNCAPI, self::FORMAT_JSON_SCHEMA],
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function specs(): array
    {
        return [
            'control_plane_api' => [
                'description' => 'OpenAPI specification for the standalone Durable Workflow server control-plane HTTP+JSON API: namespace, schedule, command, run, history, search, and system routes that the CLI, Python SDK, cloud control plane, and operator scripts call.',
                'format' => self::FORMAT_OPENAPI,
                'spec_id' => 'durable-workflow.v2.control-plane-api',
                'surface_family' => 'server_api',
                'authority_manifest' => 'control_plane',
                'owner_repo' => 'durable-workflow/server',
                'owner_symbol' => 'App\\Support\\ControlPlaneProtocol and App\\Support\\ControlPlaneRequestContract',
                'evolution_rule' => self::EVOLUTION_ADDITIVE_MINOR_BREAKING_MAJOR,
                'breaking_change_release' => 'major',
                'discovery_endpoint' => 'GET /api/cluster/info -> control_plane',
                'conformance_test' => 'durable-workflow/server: tests/Feature/ClusterInfoCompatibilityTest.php and tests/Feature/Api/* per-route contract tests',
                'status' => self::STATUS_IN_PROGRESS,
                'spec_path' => 'static/platform-protocol-specs/control-plane-api.openapi.yaml',
            ],
            'worker_protocol_api' => [
                'description' => 'OpenAPI specification for the worker-plane HTTP+JSON API: register, poll, heartbeat, complete, and fail for workflow and activity tasks. The companion AsyncAPI document `worker_protocol_stream` describes the long-poll and lease-renewal semantics.',
                'format' => self::FORMAT_OPENAPI,
                'spec_id' => 'durable-workflow.v2.worker-protocol-api',
                'surface_family' => 'worker_protocol',
                'authority_manifest' => 'worker_protocol',
                'owner_repo' => 'durable-workflow/server',
                'owner_symbol' => 'App\\Support\\WorkerProtocol and Workflow\\V2\\Support\\WorkerProtocolVersion',
                'evolution_rule' => self::EVOLUTION_ADDITIVE_MINOR_BREAKING_MAJOR,
                'breaking_change_release' => 'major',
                'discovery_endpoint' => 'GET /api/cluster/info -> worker_protocol',
                'conformance_test' => 'durable-workflow/server: tests/Feature/Api/Worker* contract tests',
                'status' => self::STATUS_IN_PROGRESS,
                'spec_path' => 'static/platform-protocol-specs/worker-protocol-api.openapi.yaml',
            ],
            'worker_protocol_stream' => [
                'description' => 'AsyncAPI specification for the worker poll/heartbeat/complete/fail event-stream semantics: long-poll cancellation, lease renewal, queue routing precedence, build-id rollout drains, and graceful disconnect.',
                'format' => self::FORMAT_ASYNCAPI,
                'spec_id' => 'durable-workflow.v2.worker-protocol-stream',
                'surface_family' => 'worker_protocol',
                'authority_manifest' => 'worker_protocol',
                'owner_repo' => 'durable-workflow/server',
                'owner_symbol' => 'App\\Support\\WorkerProtocol and Workflow\\V2\\Support\\WorkerCompatibilityFleet',
                'evolution_rule' => self::EVOLUTION_ADDITIVE_MINOR_BREAKING_MAJOR,
                'breaking_change_release' => 'major',
                'discovery_endpoint' => 'GET /api/cluster/info -> worker_protocol',
                'conformance_test' => 'durable-workflow/server: tests/Feature/Api/WorkerLongPollTest.php and tests/Feature/Api/WorkerHeartbeatTest.php',
                'status' => self::STATUS_PLANNED,
                'spec_path' => 'static/platform-protocol-specs/worker-protocol-stream.asyncapi.yaml',
            ],
            'history_event_payloads' => [
                'description' => 'JSON Schema set for every published `workflow_history_events` and `workflow_schedule_history_events` payload. Once a workflow writes an event, every future SDK that replays it must decode the same field set; the only allowed breaking change is a parallel primitive with a new event type name.',
                'format' => self::FORMAT_JSON_SCHEMA,
                'spec_id' => 'durable-workflow.v2.history-event-payloads',
                'surface_family' => 'history_event_wire_formats',
                'authority_manifest' => 'history_event_payload_contract',
                'owner_repo' => 'durable-workflow/workflow',
                'owner_symbol' => 'Workflow\\V2\\Models\\WorkflowHistoryEvent and Workflow\\V2\\Enums\\HistoryEventType',
                'evolution_rule' => self::EVOLUTION_PARALLEL_PRIMITIVE_ONLY,
                'breaking_change_release' => self::EVOLUTION_PARALLEL_PRIMITIVE_ONLY,
                'conformance_test' => 'durable-workflow/workflow: tests/Unit/V2/HistoryEventWireFormatDocumentationTest.php and tests/Unit/V2/VersionMarkerWireFormatTest.php',
                'status' => self::STATUS_IN_PROGRESS,
                'spec_path' => 'static/platform-protocol-specs/history-event-payloads.schema.json',
                'companion_doc' => 'docs/api-stability.md frozen-event tables in `durable-workflow/workflow`',
            ],
            'history_export_bundle' => [
                'description' => 'JSON Schema for the history-export bundle that the server emits and that replay tooling consumes: ordered history events, payload references, payload codec metadata, lineage edges, and bundle manifest.',
                'format' => self::FORMAT_JSON_SCHEMA,
                'spec_id' => 'durable-workflow.v2.history-export-bundle',
                'surface_family' => 'history_event_wire_formats',
                'authority_manifest' => 'history_event_payload_contract',
                'owner_repo' => 'durable-workflow/workflow',
                'owner_symbol' => 'Workflow\\V2\\Support\\HistoryExport',
                'evolution_rule' => self::EVOLUTION_ADDITIVE_MINOR_BREAKING_MAJOR,
                'breaking_change_release' => 'major',
                'conformance_test' => 'durable-workflow/workflow: tests/Unit/V2/HistoryExportTest.php',
                'status' => self::STATUS_PLANNED,
                'spec_path' => 'static/platform-protocol-specs/history-export-bundle.schema.json',
            ],
            'replay_bundle' => [
                'description' => 'JSON Schema for the deterministic replay bundle: workflow definition fingerprint, decoded history, side-effect projections, and replay-verification metadata. Consumed by replay tooling, debugging UIs, and the replay-verification contract.',
                'format' => self::FORMAT_JSON_SCHEMA,
                'spec_id' => 'durable-workflow.v2.replay-bundle',
                'surface_family' => 'history_event_wire_formats',
                'authority_manifest' => 'replay_verification_contract',
                'owner_repo' => 'durable-workflow/workflow',
                'owner_symbol' => 'Workflow\\V2\\Support\\WorkflowReplayer and Workflow\\V2\\Support\\ReplayState',
                'evolution_rule' => self::EVOLUTION_ADDITIVE_MINOR_BREAKING_MAJOR,
                'breaking_change_release' => 'major',
                'conformance_test' => 'durable-workflow/workflow: tests/Unit/V2/WorkflowReplayer*Test.php and durable-workflow/server: tests/Feature/ReplayVerificationContractTest.php',
                'status' => self::STATUS_PLANNED,
                'spec_path' => 'static/platform-protocol-specs/replay-bundle.schema.json',
            ],
            'waterline_read_api' => [
                'description' => 'OpenAPI specification for the Waterline observability read API at `/waterline/api/v2/*`: workflow lookup, run timeline, signal/update views, schedule audit, and dashboard JSON shapes.',
                'format' => self::FORMAT_OPENAPI,
                'spec_id' => 'durable-workflow.v2.waterline-read-api',
                'surface_family' => 'waterline_api',
                'authority_manifest' => 'waterline_operator_api',
                'owner_repo' => 'durable-workflow/waterline',
                'owner_symbol' => 'waterline `routes/api.php` and the Waterline OperatorReadController',
                'evolution_rule' => self::EVOLUTION_ADDITIVE_MINOR_BREAKING_MAJOR,
                'breaking_change_release' => 'major',
                'conformance_test' => 'durable-workflow/waterline: tests/Feature/OperatorReadApiTest.php and contract tests under tests/Feature/Api/',
                'status' => self::STATUS_PLANNED,
                'spec_path' => 'static/platform-protocol-specs/waterline-read-api.openapi.yaml',
            ],
            'waterline_diagnostic_objects' => [
                'description' => 'JSON Schema for the diagnostic object families Waterline emits: timeline rows, lineage edges, operator hints, queue depth snapshots, and engine-source attribution. These are the diagnostic shapes that operator dashboards and external observability adapters parse.',
                'format' => self::FORMAT_JSON_SCHEMA,
                'spec_id' => 'durable-workflow.v2.waterline-diagnostic-objects',
                'surface_family' => 'waterline_api',
                'authority_manifest' => 'waterline_operator_api',
                'owner_repo' => 'durable-workflow/waterline',
                'owner_symbol' => 'waterline DiagnosticObjectContract and OperatorReadProjections',
                'evolution_rule' => self::EVOLUTION_ADDITIVE_MINOR_BREAKING_MAJOR,
                'breaking_change_release' => 'major',
                'conformance_test' => 'durable-workflow/waterline: tests/Feature/DiagnosticObjectContractTest.php',
                'status' => self::STATUS_PLANNED,
                'spec_path' => 'static/platform-protocol-specs/waterline-diagnostic-objects.schema.json',
            ],
            'repair_actionability_objects' => [
                'description' => 'JSON Schema for the repair and actionability object families: task-repair candidates, repair policies, actionability hints, operator-queue visibility entries, and the structured failure shapes that drive operator-led recovery.',
                'format' => self::FORMAT_JSON_SCHEMA,
                'spec_id' => 'durable-workflow.v2.repair-actionability-objects',
                'surface_family' => 'server_api',
                'authority_manifest' => 'control_plane',
                'owner_repo' => 'durable-workflow/workflow',
                'owner_symbol' => 'Workflow\\V2\\Support\\TaskRepairCandidates, Workflow\\V2\\Support\\TaskRepairPolicy, and Workflow\\V2\\Support\\OperatorQueueVisibility',
                'evolution_rule' => self::EVOLUTION_ADDITIVE_MINOR_BREAKING_MAJOR,
                'breaking_change_release' => 'major',
                'conformance_test' => 'durable-workflow/workflow: tests/Unit/V2/TaskRepair*Test.php and durable-workflow/server: tests/Feature/Api/RepairControllerTest.php',
                'status' => self::STATUS_IN_PROGRESS,
                'spec_path' => 'static/platform-protocol-specs/repair-actionability-objects.schema.json',
            ],
            'mcp_discovery' => [
                'description' => 'JSON Schema for the Model Context Protocol discovery surface at `/mcp/*` and the `llms.txt` / `llms-2.0.txt` discovery files: tool list shape, parameter schema shape, and discovery hints.',
                'format' => self::FORMAT_JSON_SCHEMA,
                'spec_id' => 'durable-workflow.v2.mcp-discovery',
                'surface_family' => 'mcp_discovery_results',
                'authority_manifest' => 'mcp_workflows',
                'owner_repo' => 'durable-workflow/server',
                'owner_symbol' => 'App\\Mcp\\Discovery and the MCP route group in routes/api.php',
                'evolution_rule' => self::EVOLUTION_ADDITIVE_MINOR_BREAKING_MAJOR,
                'breaking_change_release' => 'major',
                'conformance_test' => 'durable-workflow/server: tests/Feature/Mcp/DiscoveryContractTest.php',
                'status' => self::STATUS_PLANNED,
                'spec_path' => 'static/platform-protocol-specs/mcp-discovery.schema.json',
            ],
            'mcp_tool_results' => [
                'description' => 'JSON Schema for MCP tool-result envelopes: result object shape, payload-preview limits, error envelope, and the `payload_preview_limit_bytes` semantics. Tool descriptions and discovery hints are diagnostic, not contract.',
                'format' => self::FORMAT_JSON_SCHEMA,
                'spec_id' => 'durable-workflow.v2.mcp-tool-results',
                'surface_family' => 'mcp_discovery_results',
                'authority_manifest' => 'mcp_workflows',
                'owner_repo' => 'durable-workflow/server',
                'owner_symbol' => 'App\\Mcp\\ToolResultEnvelope',
                'evolution_rule' => self::EVOLUTION_ADDITIVE_MINOR_BREAKING_MAJOR,
                'breaking_change_release' => 'major',
                'conformance_test' => 'durable-workflow/server: tests/Feature/Mcp/ToolResultEnvelopeTest.php',
                'status' => self::STATUS_PLANNED,
                'spec_path' => 'static/platform-protocol-specs/mcp-tool-results.schema.json',
            ],
            'cluster_info_envelope' => [
                'description' => 'JSON Schema for the `GET /api/cluster/info` envelope: identity, capability, topology, coordination-health, and the nested protocol manifests (`client_compatibility`, `control_plane`, `worker_protocol`, `surface_stability_contract`, `platform_protocol_specs`, `auth_composition_contract`, `bridge_adapter_outcome_contract`, `replay_verification_contract`).',
                'format' => self::FORMAT_JSON_SCHEMA,
                'spec_id' => 'durable-workflow.v2.cluster-info-envelope',
                'surface_family' => 'cluster_info_manifests',
                'authority_manifest' => 'cluster_info',
                'owner_repo' => 'durable-workflow/server',
                'owner_symbol' => 'App\\Http\\Controllers\\Api\\HealthController::clusterInfo',
                'evolution_rule' => self::EVOLUTION_ADDITIVE_MINOR_BREAKING_MAJOR,
                'breaking_change_release' => 'major',
                'conformance_test' => 'durable-workflow/server: tests/Feature/ClusterInfoCompatibilityTest.php',
                'status' => self::STATUS_IN_PROGRESS,
                'spec_path' => 'static/platform-protocol-specs/cluster-info-envelope.schema.json',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function releaseCheck(): array
    {
        return [
            'description' => 'Release reviewers must confirm the platform-protocol-specs catalog check before publish.',
            'gates' => [
                'catalog_aligned_with_surface_families' => 'Every entry`s `surface_family` exists in `SurfaceStabilityContract::surfaceFamilies()`. Every `surface_family` that owns a public machine-facing surface has at least one catalog entry.',
                'owner_repo_known' => 'Every entry`s `owner_repo` is one of the known fleet repositories.',
                'format_known' => 'Every entry`s `format` is one of `openapi`, `json_schema`, or `asyncapi`.',
                'docs_authority_aligned' => 'docs/platform-protocol-specs.md (in durable-workflow.github.io) lists every entry in this manifest with the same format, owner, status, and breaking-change rule.',
                'json_mirror_aligned' => 'static/platform-protocol-specs.json (in durable-workflow.github.io) is byte-equivalent to this PHP manifest at the time of the release.',
                'spec_path_published_when_status_published' => 'When `status` is `published`, the file at `spec_path` exists in `durable-workflow/durable-workflow.github.io` and is referenced from the matching authority doc page.',
            ],
            'enforcement' => [
                'machine' => 'docs site CI runs scripts/check-platform-protocol-specs.js, which loads static/platform-protocol-specs.json (a copy of this manifest), walks docs/platform-protocol-specs.md, and verifies every spec entry against the surface_stability_contract families.',
                'human' => 'Release reviewers tick the platform-protocol-specs check on every release PR before tagging.',
            ],
        ];
    }
}
