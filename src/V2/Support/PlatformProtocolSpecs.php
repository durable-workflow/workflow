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

    public const VERSION = 14;

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
                'description' => 'OpenAPI specification for the standalone Durable Workflow server control-plane HTTP+JSON API: namespace, schedule, command, run, history, search, worker-session visibility, and system routes that the CLI, Python SDK, cloud control plane, and operator scripts call.',
                'format' => self::FORMAT_OPENAPI,
                'spec_id' => 'durable-workflow.v2.control-plane-api',
                'surface_family' => 'server_api',
                'authority_manifest' => 'control_plane',
                'owner_repo' => 'durable-workflow/server',
                'owner_symbol' => 'App\\Support\\ControlPlaneProtocol and App\\Support\\ControlPlaneRequestContract',
                'object_families' => [
                    [
                        'name' => 'control_plane_request_contract',
                        'owner_repo' => 'durable-workflow/server',
                        'schema_authority' => 'App\\Support\\ControlPlaneRequestContract::SCHEMA',
                        'version_authority' => 'App\\Support\\ControlPlaneRequestContract::VERSION',
                    ],
                    [
                        'name' => 'control_plane_response_envelope',
                        'owner_repo' => 'durable-workflow/server',
                        'schema_authority' => 'App\\Support\\ControlPlaneResponseContract::SCHEMA',
                        'version_authority' => 'App\\Support\\ControlPlaneResponseContract::VERSION',
                    ],
                    [
                        'name' => 'control_plane_operation_contract',
                        'owner_repo' => 'durable-workflow/server',
                        'schema_authority' => 'App\\Support\\ControlPlaneResponseContract::CONTRACT_SCHEMA',
                        'version_authority' => 'App\\Support\\ControlPlaneResponseContract::CONTRACT_VERSION',
                    ],
                ],
                'evolution_rule' => self::EVOLUTION_ADDITIVE_MINOR_BREAKING_MAJOR,
                'breaking_change_release' => 'major',
                'discovery_endpoint' => 'GET /api/cluster/info -> control_plane',
                'conformance_test' => 'durable-workflow/server: tests/Feature/ClusterInfoCompatibilityTest.php, tests/Feature/ControlPlaneVersionCoverageTest.php, tests/Feature/ControlPlane*ContractTest.php, and tests/Feature/WorkflowControlPlaneTest.php',
                'status' => self::STATUS_PUBLISHED,
                'spec_path' => 'static/platform-protocol-specs/control-plane-api.openapi.yaml',
            ],
            'worker_protocol_api' => [
                'description' => 'OpenAPI specification for the worker-plane HTTP+JSON API: register, poll, heartbeat, worker-session lifecycle, complete, and fail for workflow, activity, and query tasks. Query-task routes are lease-fenced request/response work and are advertised through the `query_tasks` worker capability. The companion AsyncAPI document `worker_protocol_stream` describes the long-poll and lease-renewal semantics.',
                'format' => self::FORMAT_OPENAPI,
                'spec_id' => 'durable-workflow.v2.worker-protocol-api',
                'surface_family' => 'worker_protocol',
                'authority_manifest' => 'worker_protocol',
                'owner_repo' => 'durable-workflow/server',
                'owner_symbol' => 'App\\Support\\WorkerProtocol and Workflow\\V2\\Support\\WorkerProtocolVersion',
                'object_families' => [
                    [
                        'name' => 'worker_registration_request',
                        'owner_repo' => 'durable-workflow/server',
                        'schema_authority' => 'App\\Http\\Controllers\\Api\\WorkerController::register',
                        'version_authority' => 'Workflow\\V2\\Support\\WorkerProtocolVersion::VERSION',
                    ],
                    [
                        'name' => 'worker_task_poll_request',
                        'owner_repo' => 'durable-workflow/server',
                        'schema_authority' => 'App\\Support\\WorkflowTaskPoller and App\\Support\\ActivityTaskPoller',
                        'version_authority' => 'Workflow\\V2\\Support\\WorkerProtocolVersion::VERSION',
                    ],
                    [
                        'name' => 'worker_task_result',
                        'owner_repo' => 'durable-workflow/server',
                        'schema_authority' => 'App\\Http\\Controllers\\Api\\WorkerController task completion/failure actions',
                        'version_authority' => 'Workflow\\V2\\Support\\WorkerProtocolVersion::VERSION',
                    ],
                    [
                        'name' => 'worker_query_task_poll_request',
                        'owner_repo' => 'durable-workflow/server',
                        'schema_authority' => 'App\\Http\\Controllers\\Api\\WorkerController::pollQueryTasks and App\\Support\\WorkflowQueryTaskBroker::poll',
                        'version_authority' => 'Workflow\\V2\\Support\\WorkerProtocolVersion::VERSION',
                    ],
                    [
                        'name' => 'worker_query_task_result',
                        'owner_repo' => 'durable-workflow/server',
                        'schema_authority' => 'App\\Http\\Controllers\\Api\\WorkerController::completeQueryTask/failQueryTask and App\\Support\\WorkflowQueryTaskBroker terminal outcomes',
                        'version_authority' => 'Workflow\\V2\\Support\\WorkerProtocolVersion::VERSION',
                    ],
                    [
                        'name' => 'external_task_input_contract',
                        'owner_repo' => 'durable-workflow/server',
                        'schema_authority' => 'App\\Support\\ExternalTaskInputContract::SCHEMA',
                        'version_authority' => 'App\\Support\\ExternalTaskInputContract::VERSION',
                    ],
                    [
                        'name' => 'external_task_result_contract',
                        'owner_repo' => 'durable-workflow/server',
                        'schema_authority' => 'App\\Support\\ExternalTaskResultContract::SCHEMA',
                        'version_authority' => 'App\\Support\\ExternalTaskResultContract::VERSION',
                    ],
                ],
                'evolution_rule' => self::EVOLUTION_ADDITIVE_MINOR_BREAKING_MAJOR,
                'breaking_change_release' => 'major',
                'discovery_endpoint' => 'GET /api/cluster/info -> worker_protocol',
                'conformance_test' => 'durable-workflow/server: tests/Feature/WorkerProtocolContractTest.php, tests/Feature/WorkerProtocolSuccessContractTest.php, tests/Feature/WorkerProtocolOwnershipErrorContractTest.php, tests/Feature/WorkerProtocolVersionCoverageTest.php, tests/Feature/WorkflowWorkerProtocolTest.php, tests/Feature/ActivityWorkerProtocolTest.php, and tests/Feature/WorkflowQueryTaskBrokerTest.php; durable-workflow/workflow: tests/Unit/V2/WorkerProtocolClientTest.php and tests/Unit/V2/WorkflowQueryTaskExecutorTest.php',
                'status' => self::STATUS_PUBLISHED,
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
                'object_families' => [
                    [
                        'name' => 'worker_poll_stream',
                        'owner_repo' => 'durable-workflow/server',
                        'schema_authority' => 'App\\Support\\LongPoller and App\\Support\\WorkerProtocol',
                        'version_authority' => 'Workflow\\V2\\Support\\WorkerProtocolVersion::VERSION',
                    ],
                    [
                        'name' => 'worker_task_lease',
                        'owner_repo' => 'durable-workflow/server',
                        'schema_authority' => 'App\\Support\\WorkflowTaskPoller and App\\Support\\ActivityTaskPoller',
                        'version_authority' => 'Workflow\\V2\\Support\\WorkerProtocolVersion::VERSION',
                    ],
                    [
                        'name' => 'worker_task_heartbeat',
                        'owner_repo' => 'durable-workflow/server',
                        'schema_authority' => 'App\\Http\\Controllers\\Api\\WorkerController heartbeat actions',
                        'version_authority' => 'Workflow\\V2\\Support\\WorkerProtocolVersion::VERSION',
                    ],
                ],
                'evolution_rule' => self::EVOLUTION_ADDITIVE_MINOR_BREAKING_MAJOR,
                'breaking_change_release' => 'major',
                'discovery_endpoint' => 'GET /api/cluster/info -> worker_protocol',
                'conformance_test' => 'durable-workflow/server: tests/Feature/WorkerProtocolContractTest.php, tests/Feature/WorkerProtocolSuccessContractTest.php, and tests/Feature/WorkerProtocolOwnershipErrorContractTest.php',
                'status' => self::STATUS_PUBLISHED,
                'spec_path' => 'static/platform-protocol-specs/worker-protocol-stream.asyncapi.yaml',
            ],
            'worker_sessions_runtime' => [
                'description' => 'JSON Schema for the worker-session runtime contract: the worker_protocol.server_capabilities.worker_sessions manifest, schedule_activity worker_session command options, explicit lifecycle request/result envelopes, activity task affinity snapshots, and operator visibility envelopes.',
                'format' => self::FORMAT_JSON_SCHEMA,
                'spec_id' => 'durable-workflow.v2.worker-sessions-runtime',
                'surface_family' => 'worker_protocol',
                'authority_manifest' => 'worker_protocol',
                'owner_repo' => 'durable-workflow/server',
                'owner_symbol' => 'App\\Support\\WorkerSessionRegistry and Workflow\\V2\\Support\\WorkerProtocolVersion::workerSessionSemantics',
                'object_families' => [
                    [
                        'name' => 'worker_session_runtime_contract',
                        'owner_repo' => 'durable-workflow/workflow',
                        'schema_authority' => 'Workflow\\V2\\Support\\WorkerProtocolVersion::workerSessionSemantics',
                        'version_authority' => 'Workflow\\V2\\Support\\WorkerProtocolVersion::VERSION',
                    ],
                    [
                        'name' => 'worker_session_options',
                        'owner_repo' => 'durable-workflow/workflow',
                        'schema_authority' => 'Workflow\\V2\\Support\\WorkerSessionOptions::toSnapshot',
                        'version_authority' => 'Workflow\\V2\\Support\\WorkerProtocolVersion::VERSION',
                    ],
                    [
                        'name' => 'worker_session_lifecycle',
                        'owner_repo' => 'durable-workflow/server',
                        'schema_authority' => 'App\\Http\\Controllers\\Api\\WorkerSessionController and App\\Support\\WorkerSessionRegistry',
                        'version_authority' => 'Workflow\\V2\\Support\\WorkerProtocolVersion::VERSION',
                    ],
                    [
                        'name' => 'worker_session_visibility',
                        'owner_repo' => 'durable-workflow/server',
                        'schema_authority' => 'App\\Support\\WorkerSessionRegistry::visibility',
                        'version_authority' => 'durable-workflow.v2.worker-sessions-runtime',
                    ],
                ],
                'evolution_rule' => self::EVOLUTION_ADDITIVE_MINOR_BREAKING_MAJOR,
                'breaking_change_release' => 'major',
                'discovery_endpoint' => 'GET /api/cluster/info -> worker_protocol.server_capabilities.worker_sessions',
                'conformance_test' => 'durable-workflow/server: tests/Feature/WorkerSessionProtocolTest.php and tests/Feature/ClusterInfoTest.php; durable-workflow/workflow: tests/Unit/V2/WorkerProtocolVersionTest.php and tests/Unit/V2/WorkerSessionOptionsTest.php',
                'status' => self::STATUS_PUBLISHED,
                'spec_path' => 'static/platform-protocol-specs/worker-sessions-runtime.schema.json',
            ],
            'local_activity_runtime' => [
                'description' => 'JSON Schema for the local activity runtime contract: the worker_protocol.server_capabilities.local_activities manifest, LocalActivityOptions snapshots, same-process execution markers, workflow-task lease heartbeats, cold-replay retry semantics, and operator visibility markers.',
                'format' => self::FORMAT_JSON_SCHEMA,
                'spec_id' => 'durable-workflow.v2.local-activity-runtime',
                'surface_family' => 'worker_protocol',
                'authority_manifest' => 'worker_protocol',
                'owner_repo' => 'durable-workflow/workflow',
                'owner_symbol' => 'Workflow\\V2\\Support\\LocalActivityContract and Workflow\\V2\\Support\\LocalActivityOptions',
                'object_families' => [
                    [
                        'name' => 'local_activity_runtime_contract',
                        'owner_repo' => 'durable-workflow/workflow',
                        'schema_authority' => 'Workflow\\V2\\Support\\LocalActivityContract::manifest',
                        'version_authority' => 'Workflow\\V2\\Support\\LocalActivityContract::VERSION',
                    ],
                    [
                        'name' => 'local_activity_options',
                        'owner_repo' => 'durable-workflow/workflow',
                        'schema_authority' => 'Workflow\\V2\\Support\\LocalActivityOptions::toSnapshot',
                        'version_authority' => 'Workflow\\V2\\Support\\LocalActivityContract::VERSION',
                    ],
                    [
                        'name' => 'local_activity_history_markers',
                        'owner_repo' => 'durable-workflow/workflow',
                        'schema_authority' => 'Workflow\\V2\\Support\\LocalActivityRuntime::eventPayload',
                        'version_authority' => 'durable-workflow.v2.history-event-payloads',
                    ],
                    [
                        'name' => 'local_activity_visibility',
                        'owner_repo' => 'durable-workflow/workflow',
                        'schema_authority' => 'Workflow\\V2\\Support\\RunActivityView and Workflow\\V2\\Support\\OperatorMetrics',
                        'version_authority' => 'durable-workflow.v2.local-activity-runtime',
                    ],
                ],
                'evolution_rule' => self::EVOLUTION_ADDITIVE_MINOR_BREAKING_MAJOR,
                'breaking_change_release' => 'major',
                'discovery_endpoint' => 'GET /api/cluster/info -> worker_protocol.server_capabilities.local_activities',
                'conformance_test' => 'durable-workflow/workflow: tests/Unit/V2/LocalActivitiesDocumentationTest.php, tests/Unit/V2/WorkerProtocolVersionTest.php, tests/Unit/V2/WorkflowFacadeTest.php, and tests/Feature/V2/V2LocalActivityTest.php; durable-workflow/server: tests/Feature/ClusterInfoTest.php',
                'status' => self::STATUS_PUBLISHED,
                'spec_path' => 'static/platform-protocol-specs/local-activity-runtime.schema.json',
            ],
            'history_event_payloads' => [
                'description' => 'JSON Schema set for every published `workflow_history_events` and `workflow_schedule_history_events` payload. Once a workflow writes an event, every future SDK that replays it must decode the same field set; the only allowed breaking change is a parallel primitive with a new event type name.',
                'format' => self::FORMAT_JSON_SCHEMA,
                'spec_id' => 'durable-workflow.v2.history-event-payloads',
                'surface_family' => 'history_event_wire_formats',
                'authority_manifest' => 'history_event_payload_contract',
                'owner_repo' => 'durable-workflow/workflow',
                'owner_symbol' => 'Workflow\\V2\\Models\\WorkflowHistoryEvent and Workflow\\V2\\Enums\\HistoryEventType',
                'object_families' => [
                    [
                        'name' => 'workflow_history_events',
                        'owner_repo' => 'durable-workflow/workflow',
                        'schema_authority' => 'Workflow\\V2\\Enums\\HistoryEventType',
                        'version_authority' => 'durable-workflow.v2.history-event-payloads',
                    ],
                    [
                        'name' => 'workflow_schedule_history_events',
                        'owner_repo' => 'durable-workflow/workflow',
                        'schema_authority' => 'Workflow\\V2\\Models\\WorkflowScheduleHistoryEvent',
                        'version_authority' => 'durable-workflow.v2.history-event-payloads',
                    ],
                ],
                'evolution_rule' => self::EVOLUTION_PARALLEL_PRIMITIVE_ONLY,
                'breaking_change_release' => self::EVOLUTION_PARALLEL_PRIMITIVE_ONLY,
                'conformance_test' => 'durable-workflow/workflow: tests/Unit/V2/HistoryEventWireFormatDocumentationTest.php and tests/Unit/V2/VersionMarkerWireFormatTest.php',
                'status' => self::STATUS_PUBLISHED,
                'spec_path' => 'static/platform-protocol-specs/history-event-payloads.schema.json',
                'companion_doc' => 'docs/api-stability.md frozen-event tables in `durable-workflow/workflow`',
            ],
            'history_export_bundle' => [
                'description' => 'JSON Schema for the history-export bundle that the server emits and that replay tooling consumes: ordered history events, payload references, payload codec metadata, lineage edges, and bundle manifest. The schema is defined once for the v2 release; the schema id `durable-workflow.v2.history-export` is the canonical version anchor and the only allowed breaking change is a parallel primitive (a new schema id alongside the existing one).',
                'format' => self::FORMAT_JSON_SCHEMA,
                'spec_id' => 'durable-workflow.v2.history-export-bundle',
                'surface_family' => 'history_event_wire_formats',
                'authority_manifest' => 'history_event_payload_contract',
                'owner_repo' => 'durable-workflow/workflow',
                'owner_symbol' => 'Workflow\\V2\\Support\\HistoryExport',
                'object_families' => [
                    [
                        'name' => 'history_export_bundle',
                        'owner_repo' => 'durable-workflow/workflow',
                        'schema_authority' => 'Workflow\\V2\\Support\\HistoryExport::SCHEMA',
                        'version_authority' => 'durable-workflow.v2.history-export',
                    ],
                ],
                'evolution_rule' => self::EVOLUTION_PARALLEL_PRIMITIVE_ONLY,
                'breaking_change_release' => self::EVOLUTION_PARALLEL_PRIMITIVE_ONLY,
                'conformance_test' => 'durable-workflow/workflow: tests/Unit/V2/HistoryExportTest.php',
                'status' => self::STATUS_PUBLISHED,
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
                'object_families' => [
                    [
                        'name' => 'replay_bundle',
                        'owner_repo' => 'durable-workflow/workflow',
                        'schema_authority' => 'Workflow\\V2\\Support\\WorkflowReplayer',
                        'version_authority' => 'durable-workflow.v2.replay-bundle',
                    ],
                ],
                'evolution_rule' => self::EVOLUTION_ADDITIVE_MINOR_BREAKING_MAJOR,
                'breaking_change_release' => 'major',
                'conformance_test' => 'durable-workflow/workflow: tests/Feature/V2/V2WorkflowReplayerTest.php, tests/Feature/V2/V2GoldenHistoryReplayTest.php, and durable-workflow/server: tests/Unit/ReplayVerificationContractTest.php',
                'status' => self::STATUS_PUBLISHED,
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
                'object_families' => [
                    [
                        'name' => 'waterline_read_envelope',
                        'owner_repo' => 'durable-workflow/waterline',
                        'schema_authority' => 'waterline route/controller response envelopes',
                        'version_authority' => 'durable-workflow.v2.waterline-read-api',
                    ],
                    [
                        'name' => 'waterline_operator_action_envelope',
                        'owner_repo' => 'durable-workflow/waterline',
                        'schema_authority' => 'waterline operator action controllers',
                        'version_authority' => 'durable-workflow.v2.waterline-read-api',
                    ],
                ],
                'evolution_rule' => self::EVOLUTION_ADDITIVE_MINOR_BREAKING_MAJOR,
                'breaking_change_release' => 'major',
                'conformance_test' => 'durable-workflow/waterline: tests/Feature/V2DashboardWorkflowTest.php, tests/Feature/V2DashboardWorkflowListTest.php, tests/Feature/V2HealthControllerTest.php, tests/Feature/V2SchedulesHistoryControllerTest.php, tests/Feature/V2ServicesControllerTest.php, and tests/Feature/V2HistoryExportControllerTest.php',
                'status' => self::STATUS_PUBLISHED,
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
                'object_families' => [
                    [
                        'name' => 'waterline_run_detail',
                        'owner_repo' => 'durable-workflow/waterline',
                        'schema_authority' => 'Waterline\\Http\\Controllers\\WorkflowsController',
                        'version_authority' => 'durable-workflow.v2.waterline-diagnostic-objects',
                    ],
                    [
                        'name' => 'waterline_health_diagnostics',
                        'owner_repo' => 'durable-workflow/waterline',
                        'schema_authority' => 'Waterline\\Http\\Controllers\\V2HealthController',
                        'version_authority' => 'durable-workflow.v2.waterline-diagnostic-objects',
                    ],
                    [
                        'name' => 'waterline_timeline_rows',
                        'owner_repo' => 'durable-workflow/waterline',
                        'schema_authority' => 'Waterline operator timeline projections',
                        'version_authority' => 'durable-workflow.v2.waterline-diagnostic-objects',
                    ],
                    [
                        'name' => 'waterline_lineage_edges',
                        'owner_repo' => 'durable-workflow/waterline',
                        'schema_authority' => 'Waterline operator lineage projections',
                        'version_authority' => 'durable-workflow.v2.waterline-diagnostic-objects',
                    ],
                ],
                'evolution_rule' => self::EVOLUTION_ADDITIVE_MINOR_BREAKING_MAJOR,
                'breaking_change_release' => 'major',
                'conformance_test' => 'durable-workflow/waterline: tests/Unit/Support/V2DiagnosticsExecutionContractAlignmentTest.php, tests/Feature/V2DashboardWorkflowTest.php, and tests/Feature/V2DashboardWorkflowListTest.php',
                'status' => self::STATUS_PUBLISHED,
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
                'object_families' => [
                    [
                        'name' => 'task_repair_policy',
                        'owner_repo' => 'durable-workflow/workflow',
                        'schema_authority' => 'Workflow\\V2\\Support\\TaskRepairPolicy::snapshot',
                        'version_authority' => 'durable-workflow.v2.repair-actionability-objects',
                    ],
                    [
                        'name' => 'task_repair_candidates',
                        'owner_repo' => 'durable-workflow/workflow',
                        'schema_authority' => 'Workflow\\V2\\Support\\TaskRepairCandidates::snapshot',
                        'version_authority' => 'durable-workflow.v2.repair-actionability-objects',
                    ],
                    [
                        'name' => 'operator_queue_visibility',
                        'owner_repo' => 'durable-workflow/workflow',
                        'schema_authority' => 'Workflow\\V2\\Support\\OperatorQueueVisibility',
                        'version_authority' => 'durable-workflow.v2.repair-actionability-objects',
                    ],
                    [
                        'name' => 'actionability',
                        'owner_repo' => 'durable-workflow/waterline',
                        'schema_authority' => 'Waterline\\Support\\ActionabilityContract',
                        'version_authority' => 'Waterline\\Support\\ActionabilityContract::VERSION',
                    ],
                    [
                        'name' => 'agent_root_cause',
                        'owner_repo' => 'durable-workflow/durable-workflow.github.io',
                        'schema_authority' => 'docs/agent-tooling-contract.md Root Cause And Remediation and static/platform-protocol-specs/repair-actionability-objects.schema.json',
                        'version_authority' => 'durable-workflow.v2.agent-root-cause',
                    ],
                    [
                        'name' => 'agent_remediation',
                        'owner_repo' => 'durable-workflow/durable-workflow.github.io',
                        'schema_authority' => 'docs/agent-tooling-contract.md Root Cause And Remediation and static/platform-protocol-specs/repair-actionability-objects.schema.json',
                        'version_authority' => 'durable-workflow.v2.agent-remediation',
                    ],
                    [
                        'name' => 'safe_mutation',
                        'owner_repo' => 'durable-workflow/durable-workflow.github.io',
                        'schema_authority' => 'docs/agent-tooling-contract.md MCP Tool Design and static/platform-protocol-specs/repair-actionability-objects.schema.json',
                        'version_authority' => 'durable-workflow.v2.safe-mutation',
                    ],
                ],
                'evolution_rule' => self::EVOLUTION_ADDITIVE_MINOR_BREAKING_MAJOR,
                'breaking_change_release' => 'major',
                'conformance_test' => 'durable-workflow/workflow: tests/Unit/Commands/V2RepairPassCommandTest.php, tests/Feature/V2/V2OperatorQueueVisibilityTest.php; durable-workflow/waterline: tests/Feature/V2DashboardWorkflowTest.php, tests/Feature/V2DashboardWorkflowListTest.php; durable-workflow/server: tests/Feature/TransportRepairTest.php',
                'status' => self::STATUS_PUBLISHED,
                'spec_path' => 'static/platform-protocol-specs/repair-actionability-objects.schema.json',
            ],
            'cli_json_envelopes' => [
                'description' => 'JSON Schema index for the `dw` CLI machine-readable output contract: the published output-schema manifest and the per-command JSON / JSONL envelope schemas that automation, agents, and operator scripts consume.',
                'format' => self::FORMAT_JSON_SCHEMA,
                'spec_id' => 'durable-workflow.v2.cli-json-envelopes',
                'surface_family' => 'cli_json',
                'authority_manifest' => 'cli_reference',
                'owner_repo' => 'durable-workflow/cli',
                'owner_symbol' => 'DurableWorkflow\\Cli\\Support\\OutputSchemaRegistry, schemas/output/manifest.json, and tests/Commands/OutputContractTest.php',
                'object_families' => [
                    [
                        'name' => 'cli_output_schema_manifest',
                        'owner_repo' => 'durable-workflow/cli',
                        'schema_authority' => 'DurableWorkflow\\Cli\\Support\\OutputSchemaRegistry::manifest',
                        'version_authority' => 'schemas/output/manifest.json version',
                    ],
                    [
                        'name' => 'cli_command_output_schema',
                        'owner_repo' => 'durable-workflow/cli',
                        'schema_authority' => 'schemas/output/*.schema.json via DurableWorkflow\\Cli\\Support\\OutputSchemaRegistry',
                        'version_authority' => 'schemas/output/manifest.json schema_id and version metadata',
                    ],
                ],
                'evolution_rule' => self::EVOLUTION_ADDITIVE_MINOR_BREAKING_MAJOR,
                'breaking_change_release' => 'major',
                'conformance_test' => 'durable-workflow/cli: tests/Commands/OutputContractTest.php and tests/Commands/ApplicationSmokeTest.php',
                'status' => self::STATUS_PUBLISHED,
                'spec_path' => 'static/platform-protocol-specs/cli-json-envelopes.schema.json',
            ],
            'mcp_discovery' => [
                'description' => 'JSON Schema for the Model Context Protocol discovery surface at `/mcp/*` and the `llms.txt` / `llms-2.0.txt` discovery files: tool list shape, parameter schema shape, and discovery hints.',
                'format' => self::FORMAT_JSON_SCHEMA,
                'spec_id' => 'durable-workflow.v2.mcp-discovery',
                'surface_family' => 'mcp_discovery_results',
                'authority_manifest' => 'mcp_workflows',
                'owner_repo' => 'durable-workflow/durable-workflow.github.io',
                'owner_symbol' => 'docs/mcp-workflows.md, scripts/generate-llms.js, and scripts/generate-llms-full.js',
                'object_families' => [
                    [
                        'name' => 'mcp_tool_discovery',
                        'owner_repo' => 'durable-workflow/durable-workflow.github.io',
                        'schema_authority' => 'docs/mcp-workflows.md and static/platform-protocol-specs/mcp-discovery.schema.json',
                        'version_authority' => 'durable-workflow.v2.mcp-discovery',
                    ],
                    [
                        'name' => 'llms_txt_discovery',
                        'owner_repo' => 'durable-workflow/durable-workflow.github.io',
                        'schema_authority' => 'docs/mcp-workflows.md and generated llms files',
                        'version_authority' => 'durable-workflow.v2.mcp-discovery',
                    ],
                ],
                'evolution_rule' => self::EVOLUTION_ADDITIVE_MINOR_BREAKING_MAJOR,
                'breaking_change_release' => 'major',
                'conformance_test' => 'durable-workflow/durable-workflow.github.io: scripts/check-discoverability.js and scripts/check-llms-ai-surfaces.js',
                'status' => self::STATUS_PUBLISHED,
                'spec_path' => 'static/platform-protocol-specs/mcp-discovery.schema.json',
            ],
            'mcp_tool_results' => [
                'description' => 'JSON Schema for MCP tool-result envelopes: result object shape, payload-preview limits, error envelope, and the `payload_preview_limit_bytes` semantics. Tool descriptions and discovery hints are diagnostic, not contract.',
                'format' => self::FORMAT_JSON_SCHEMA,
                'spec_id' => 'durable-workflow.v2.mcp-tool-results',
                'surface_family' => 'mcp_discovery_results',
                'authority_manifest' => 'mcp_workflows',
                'owner_repo' => 'durable-workflow/durable-workflow.github.io',
                'owner_symbol' => 'docs/mcp-workflows.md Tool Result Contract and static/platform-protocol-specs/mcp-tool-results.schema.json',
                'object_families' => [
                    [
                        'name' => 'mcp_tool_result_envelope',
                        'owner_repo' => 'durable-workflow/durable-workflow.github.io',
                        'schema_authority' => 'docs/mcp-workflows.md Tool Result Contract and static/platform-protocol-specs/mcp-tool-results.schema.json',
                        'version_authority' => 'durable-workflow.v2.mcp-tool-results',
                    ],
                    [
                        'name' => 'agent_root_cause',
                        'owner_repo' => 'durable-workflow/durable-workflow.github.io',
                        'schema_authority' => 'docs/mcp-workflows.md Failure And Remediation Taxonomy and static/platform-protocol-specs/mcp-tool-results.schema.json',
                        'version_authority' => 'durable-workflow.v2.agent-root-cause',
                    ],
                    [
                        'name' => 'agent_remediation',
                        'owner_repo' => 'durable-workflow/durable-workflow.github.io',
                        'schema_authority' => 'docs/mcp-workflows.md Failure And Remediation Taxonomy and static/platform-protocol-specs/mcp-tool-results.schema.json',
                        'version_authority' => 'durable-workflow.v2.agent-remediation',
                    ],
                    [
                        'name' => 'safe_mutation',
                        'owner_repo' => 'durable-workflow/durable-workflow.github.io',
                        'schema_authority' => 'docs/mcp-workflows.md Failure And Remediation Taxonomy and static/platform-protocol-specs/mcp-tool-results.schema.json',
                        'version_authority' => 'durable-workflow.v2.safe-mutation',
                    ],
                ],
                'evolution_rule' => self::EVOLUTION_ADDITIVE_MINOR_BREAKING_MAJOR,
                'breaking_change_release' => 'major',
                'conformance_test' => 'durable-workflow/durable-workflow.github.io: scripts/check-llms-ai-surfaces.js and scripts/check-platform-protocol-specs.js',
                'status' => self::STATUS_PUBLISHED,
                'spec_path' => 'static/platform-protocol-specs/mcp-tool-results.schema.json',
            ],
            'cluster_info_envelope' => [
                'description' => 'JSON Schema for the `GET /api/cluster/info` envelope: identity, capability, topology, coordination-health, and the nested protocol manifests (`client_compatibility`, `control_plane`, `worker_protocol`, `surface_stability_contract`, `platform_protocol_specs`, `platform_conformance_suite`, `sdk_neutrality_contract`, `auth_composition_contract`, `bridge_adapter_outcome_contract`, `replay_verification_contract`). The envelope is the discovery surface that every other catalog entry can be reached from.',
                'format' => self::FORMAT_JSON_SCHEMA,
                'spec_id' => 'durable-workflow.v2.cluster-info-envelope',
                'surface_family' => 'cluster_info_manifests',
                'authority_manifest' => 'cluster_info',
                'owner_repo' => 'durable-workflow/server',
                'owner_symbol' => 'App\\Http\\Controllers\\Api\\HealthController::clusterInfo',
                'object_families' => [
                    [
                        'name' => 'cluster_info_envelope',
                        'owner_repo' => 'durable-workflow/server',
                        'schema_authority' => 'App\\Http\\Controllers\\Api\\HealthController::clusterInfo',
                        'version_authority' => 'durable-workflow.v2.cluster-info-envelope',
                    ],
                    [
                        'name' => 'client_compatibility_manifest',
                        'owner_repo' => 'durable-workflow/server',
                        'schema_authority' => 'App\\Support\\ClientCompatibility::SCHEMA',
                        'version_authority' => 'App\\Support\\ClientCompatibility::VERSION',
                    ],
                    [
                        'name' => 'surface_stability_contract',
                        'owner_repo' => 'durable-workflow/workflow',
                        'schema_authority' => 'Workflow\\V2\\Support\\SurfaceStabilityContract::SCHEMA',
                        'version_authority' => 'Workflow\\V2\\Support\\SurfaceStabilityContract::VERSION',
                    ],
                    [
                        'name' => 'platform_protocol_specs_catalog',
                        'owner_repo' => 'durable-workflow/workflow',
                        'schema_authority' => 'Workflow\\V2\\Support\\PlatformProtocolSpecs::SCHEMA',
                        'version_authority' => 'Workflow\\V2\\Support\\PlatformProtocolSpecs::VERSION',
                    ],
                    [
                        'name' => 'platform_conformance_suite_manifest',
                        'owner_repo' => 'durable-workflow/workflow',
                        'schema_authority' => 'Workflow\\V2\\Support\\PlatformConformanceSuite::SCHEMA',
                        'version_authority' => 'Workflow\\V2\\Support\\PlatformConformanceSuite::VERSION',
                    ],
                    [
                        'name' => 'sdk_neutrality_contract',
                        'owner_repo' => 'durable-workflow/workflow',
                        'schema_authority' => 'Workflow\\V2\\Support\\SdkNeutralityContract::SCHEMA',
                        'version_authority' => 'Workflow\\V2\\Support\\SdkNeutralityContract::VERSION',
                    ],
                ],
                'evolution_rule' => self::EVOLUTION_ADDITIVE_MINOR_BREAKING_MAJOR,
                'breaking_change_release' => 'major',
                'discovery_endpoint' => 'GET /api/cluster/info',
                'conformance_test' => 'durable-workflow/server: tests/Feature/ClusterInfoCompatibilityTest.php',
                'status' => self::STATUS_PUBLISHED,
                'spec_path' => 'static/platform-protocol-specs/cluster-info-envelope.schema.json',
            ],
            'invocable_carrier_execution' => [
                'description' => 'JSON Schema for the invocable HTTP carrier execution surface: the activity-only HTTP carrier contract, external task input and result envelopes, the external execution surface boundary, and the handler-mapping config contract that routes activity tasks to carrier endpoints. These wire-protocol terms define what an external activity handler must implement to receive and complete tasks through the invocable carrier.',
                'format' => self::FORMAT_JSON_SCHEMA,
                'spec_id' => 'durable-workflow.v2.invocable-carrier-execution',
                'surface_family' => 'worker_protocol',
                'authority_manifest' => 'worker_protocol',
                'owner_repo' => 'durable-workflow/server',
                'owner_symbol' => 'App\\Support\\InvocableCarrierContract, App\\Support\\ExternalTaskInputContract, App\\Support\\ExternalTaskResultContract, App\\Support\\ExternalExecutionSurfaceContract, and App\\Support\\ExternalExecutorConfigContract',
                'object_families' => [
                    [
                        'name' => 'invocable_carrier_contract',
                        'owner_repo' => 'durable-workflow/server',
                        'schema_authority' => 'App\\Support\\InvocableCarrierContract::SCHEMA',
                        'version_authority' => 'App\\Support\\InvocableCarrierContract::VERSION',
                    ],
                    [
                        'name' => 'external_execution_surface_contract',
                        'owner_repo' => 'durable-workflow/server',
                        'schema_authority' => 'App\\Support\\ExternalExecutionSurfaceContract::SCHEMA',
                        'version_authority' => 'App\\Support\\ExternalExecutionSurfaceContract::VERSION',
                    ],
                    [
                        'name' => 'external_executor_config_contract',
                        'owner_repo' => 'durable-workflow/server',
                        'schema_authority' => 'App\\Support\\ExternalExecutorConfigContract::CONTRACT_SCHEMA',
                        'version_authority' => 'App\\Support\\ExternalExecutorConfigContract::CONTRACT_VERSION',
                    ],
                ],
                'evolution_rule' => self::EVOLUTION_ADDITIVE_MINOR_BREAKING_MAJOR,
                'breaking_change_release' => 'major',
                'discovery_endpoint' => 'GET /api/cluster/info -> worker_protocol.invocable_carrier_contract',
                'conformance_test' => 'durable-workflow/server: tests/Unit/InvocableCarrierContractTest.php, tests/Unit/ExternalTaskInputContractTest.php, tests/Unit/ExternalTaskResultContractTest.php, and tests/Unit/ExternalExecutionSurfaceContractTest.php',
                'status' => self::STATUS_IN_PROGRESS,
                'spec_path' => 'static/platform-protocol-specs/invocable-carrier-execution.schema.json',
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
                'catalog_aligned_with_surface_families' => 'Every entry\'s `surface_family` exists in `SurfaceStabilityContract::surfaceFamilies()`. Every `surface_family` that owns a public machine-facing surface has at least one catalog entry.',
                'owner_repo_known' => 'Every entry\'s `owner_repo` is one of the known fleet repositories.',
                'format_known' => 'Every entry\'s `format` is one of `openapi`, `json_schema`, or `asyncapi`.',
                'docs_authority_aligned' => 'docs/platform-protocol-specs.md (in durable-workflow.github.io) lists every entry in this manifest with the same format, owner, object-family authority, status, and breaking-change rule.',
                'json_mirror_aligned' => 'static/platform-protocol-specs.json (in durable-workflow.github.io) is byte-equivalent to this PHP manifest at the time of the release.',
                'object_family_authority_declared' => 'Every entry declares a non-empty `object_families` list. Each object family names the owning repo, schema authority, and version authority. Published spec files carry matching `x-durable-workflow-object-families` metadata so the file and catalog cannot drift. Embedded public agent-tooling schema ids must have matching object-family rows.',
                'spec_path_published_when_status_published' => 'When `status` is `published`, the file at `spec_path` exists in `durable-workflow/durable-workflow.github.io`, is referenced from the matching authority doc page, parses as the format declared by the catalog entry (JSON Schema 2020-12 / OpenAPI 3.1 / AsyncAPI 2.6+), and the document\'s `$id` (or OpenAPI `info.title` / AsyncAPI `id`) matches the catalog `spec_id`.',
                'breaking_change_release_consistent_with_evolution_rule' => 'Every entry\'s `breaking_change_release` is one of `major`, `parallel_primitive_only`, or `experimental_any_release`, and matches the value required by its `evolution_rule`: `additive_minor_breaking_major` -> `major`, `parallel_primitive_only` -> `parallel_primitive_only`, `experimental_any_release` -> `experimental_any_release`. Letting these diverge would let a frozen wire format claim a major-version break, contradicting its rule.',
                'deliverable_specs_published' => 'Every entry in the platform protocol-spec deliverable surface set is marked `published` and has a parseable spec document.',
            ],
            'enforcement' => [
                'machine' => 'docs site CI runs scripts/check-platform-protocol-specs.js, which loads static/platform-protocol-specs.json (a copy of this manifest), walks docs/platform-protocol-specs.md, and verifies every spec entry against the surface_stability_contract families.',
                'human' => 'Release reviewers tick the platform-protocol-specs check on every release PR before tagging.',
            ],
        ];
    }
}
