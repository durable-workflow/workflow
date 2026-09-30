<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\Serializers\CodecRegistry;
use Workflow\V2\Models\WorkflowMemo;
use Workflow\V2\Models\WorkflowSearchAttribute;

/**
 * Frozen versioned contract for the external workflow-worker protocol.
 *
 * This class defines the canonical verb set, command shapes, history
 * pagination parameters, and protocol version that external workers
 * (including the standalone server) must align to.
 *
 * @api Stable class surface consumed by the standalone workflow-server.
 *      The public static method signatures and constant names on this class
 *      are covered by the workflow package's semver guarantee. See
 *      docs/api-stability.md.
 */
final class WorkerProtocolVersion
{
    /**
     * Current protocol version.
     *
     * Follows semver-style numbering. Bump the major when a change is
     * backwards-incompatible (new required fields, removed verbs, changed
     * pagination semantics). Bump the minor for additive changes (new
     * optional fields, new non-terminal command types).
     */
    public const VERSION = '1.19';

    /**
     * Worker registration capability for server-routed workflow query
     * tasks. Workers that advertise this capability may receive query work
     * through the query task poll/complete/fail endpoints.
     */
    public const CAPABILITY_QUERY_TASKS = 'query_tasks';

    /**
     * Worker registration capability for portable service-mode message
     * streams. The capability and its workflow-task completion metadata are
     * version-gated together so an older worker cannot partially opt in.
     */
    public const CAPABILITY_MESSAGE_STREAMS = 'message_streams';

    /**
     * Worker registration capability for lossless portable memo upserts.
     * Memo command entries use the standard Avro payload envelope and are
     * rejected before execution when this capability is unavailable.
     */
    public const CAPABILITY_MEMO_UPSERTS = 'memo_upserts';

    /**
     * Worker registration capability for canonical typed search-attribute
     * command identity and replay metadata.
     */
    public const CAPABILITY_TYPED_SEARCH_ATTRIBUTES = 'typed_search_attributes';

    /**
     * Worker registration capability for explicit authored condition-wait
     * occurrence identity across commands, history, and replay.
     */
    public const CAPABILITY_CONDITION_WAIT_OCCURRENCE_IDENTITY = 'condition_wait_occurrence_identity';

    /**
     * Worker can execute and durably report local activities in workflow tasks.
     */
    public const CAPABILITY_LOCAL_ACTIVITIES = 'local_activities';

    /**
     * Worker can manage typed worker-session lifecycle and affinity options.
     */
    public const CAPABILITY_WORKER_SESSIONS = 'worker_sessions';

    /**
     * Worker owns a bounded exact-identity sticky workflow cache.
     */
    public const CAPABILITY_STICKY_EXECUTION = 'sticky_execution';

    /**
     * Worker can emit durable selection-group metadata and replay persisted
     * winner and loser-lifecycle markers.
     */
    public const CAPABILITY_DURABLE_SELECTION = 'durable_selection';

    /**
     * Stable fail-closed reason a worker or server must return when it
     * receives an input task whose payload codec is not in the universal
     * advertised codec set or its declared engine-specific opt-in. The
     * task must not be reinterpreted through another codec or silently
     * dropped.
     */
    public const REASON_UNSUPPORTED_PAYLOAD_CODEC = 'unsupported_payload_codec';

    /**
     * Default page size for paginated history responses.
     *
     * Aligned with the standalone server's WORKFLOW_SERVER_HISTORY_PAGE_SIZE_DEFAULT
     * default (server.worker_protocol.history_page_size_default in config/server.php).
     * The server is the authority on pagination size — this constant is the
     * contract default that external workers and SDK authors should assume
     * when the server has not advertised a value via server_capabilities.
     */
    public const DEFAULT_HISTORY_PAGE_SIZE = 500;

    /**
     * Maximum allowed page size for paginated history responses.
     */
    public const MAX_HISTORY_PAGE_SIZE = 1000;

    /**
     * Supported content encodings for compressed history payloads.
     *
     * When a caller requests compression via Accept-Encoding, the bridge
     * or server may return the history_events array as a base64-encoded
     * compressed blob under the 'history_events_compressed' key, with
     * 'history_events_encoding' indicating the algorithm used.
     *
     * @var list<string>
     */
    public const SUPPORTED_HISTORY_ENCODINGS = ['gzip', 'deflate'];

    /**
     * Minimum history event count before compression is worthwhile.
     *
     * Below this threshold the overhead of encode/decode exceeds the
     * transfer savings, so the bridge should return uncompressed events.
     */
    public const COMPRESSION_THRESHOLD = 50;

    /**
     * Default long-poll timeout in seconds.
     *
     * When a poll request includes a long-poll timeout, the bridge or
     * server holds the connection open for up to this duration waiting
     * for a matching task to become ready, rather than returning an
     * empty result immediately.
     */
    public const DEFAULT_LONG_POLL_TIMEOUT = 30;

    /**
     * Maximum long-poll timeout in seconds.
     */
    public const MAX_LONG_POLL_TIMEOUT = 60;

    /**
     * Minimum long-poll timeout in seconds.
     *
     * A zero-second timeout is an immediate probe: the server must return a
     * currently claimable task or an empty response without holding the
     * connection open. Standalone workers use this during startup fairness
     * drains so public queries do not block heartbeat or workflow progress.
     */
    public const MIN_LONG_POLL_TIMEOUT = 0;

    public const PORTABLE_WORKER_AFFINITY_MINIMUM_PROTOCOL_VERSION = '1.18';

    /**
     * Protocol version that makes ordered local-activity attempt reports
     * mandatory on workflow-task completion.
     */
    public const LOCAL_ACTIVITY_ATTEMPT_REPORTS_MINIMUM_PROTOCOL_VERSION = '1.19';

    public const DURABLE_SELECTION_MINIMUM_PROTOCOL_VERSION = '1.19';

    private const QUERY_TASKS_MINIMUM_PROTOCOL_VERSION = '1.8';

    private const UPSERT_SEARCH_ATTRIBUTES_MINIMUM_PROTOCOL_VERSION = '1.8';

    private const TYPED_SEARCH_ATTRIBUTES_MINIMUM_PROTOCOL_VERSION = '1.16';

    private const CONDITION_WAIT_MINIMUM_PROTOCOL_VERSION = '1.9';

    private const CONDITION_WAIT_OCCURRENCE_IDENTITY_MINIMUM_PROTOCOL_VERSION = '1.17';

    private const UPSERT_MEMO_MINIMUM_PROTOCOL_VERSION = '1.14';

    private const WORKER_SESSIONS_MINIMUM_PROTOCOL_VERSION = '1.8';

    private const FAIL_WORKFLOW_EXCEPTION_MINIMUM_PROTOCOL_VERSION = '1.10';

    private const SERVICE_OPERATION_COMMAND_MINIMUM_PROTOCOL_VERSION = '1.13';

    private const MESSAGE_STREAMS_MINIMUM_PROTOCOL_VERSION = '1.15';

    private const MESSAGE_STREAM_COMPLETION_FIELDS = ['message_stream_cursors', 'message_stream_waits'];

    /**
     * Workflow task bridge verbs — the canonical set of operations an
     * external workflow worker may invoke.
     *
     * @return list<string>
     */
    public static function workflowTaskVerbs(): array
    {
        return [
            'poll',
            'claim',
            'claimStatus',
            'historyPayload',
            'historyPayloadPaginated',
            'execute',
            'complete',
            'fail',
            'heartbeat',
        ];
    }

    /**
     * Activity task bridge verbs — the canonical set of operations an
     * external activity worker may invoke.
     *
     * @return list<string>
     */
    public static function activityTaskVerbs(): array
    {
        return ['poll', 'claim', 'claimStatus', 'complete', 'fail', 'status', 'heartbeat'];
    }

    /**
     * Query task verbs exposed by the standalone worker protocol.
     *
     * Query tasks are server-routed, lease-fenced request/response work
     * items. They replay committed history in a worker process, return a
     * query result or typed failure to the waiting caller, and never append
     * workflow history.
     *
     * @return list<string>
     */
    public static function queryTaskVerbs(): array
    {
        return ['poll', 'complete', 'fail'];
    }

    /**
     * Worker registration capabilities with protocol-defined semantics.
     *
     * @return list<string>
     */
    public static function workerCapabilities(): array
    {
        return self::workerCapabilitiesForVersion(self::VERSION);
    }

    /**
     * Protocol-defined worker capabilities available at a negotiated version.
     *
     * @return list<string>
     */
    public static function workerCapabilitiesForVersion(string $protocolVersion): array
    {
        $capabilities = [];

        if (self::supportsFeatureVersion($protocolVersion, self::QUERY_TASKS_MINIMUM_PROTOCOL_VERSION)) {
            $capabilities[] = self::CAPABILITY_QUERY_TASKS;
        }

        if (self::supportsFeatureVersion($protocolVersion, self::UPSERT_MEMO_MINIMUM_PROTOCOL_VERSION)) {
            $capabilities[] = self::CAPABILITY_MEMO_UPSERTS;
        }

        if (self::supportsMessageStreams($protocolVersion)) {
            $capabilities[] = self::CAPABILITY_MESSAGE_STREAMS;
        }

        if (self::supportsTypedSearchAttributes($protocolVersion)) {
            $capabilities[] = self::CAPABILITY_TYPED_SEARCH_ATTRIBUTES;
        }

        if (self::supportsConditionWaitOccurrenceIdentity($protocolVersion)) {
            $capabilities[] = self::CAPABILITY_CONDITION_WAIT_OCCURRENCE_IDENTITY;
        }

        if (self::supportsFeatureVersion($protocolVersion, self::PORTABLE_WORKER_AFFINITY_MINIMUM_PROTOCOL_VERSION)) {
            $capabilities[] = self::CAPABILITY_LOCAL_ACTIVITIES;
            $capabilities[] = self::CAPABILITY_WORKER_SESSIONS;
            $capabilities[] = self::CAPABILITY_STICKY_EXECUTION;
        }

        if (self::supportsFeatureVersion($protocolVersion, self::DURABLE_SELECTION_MINIMUM_PROTOCOL_VERSION)) {
            $capabilities[] = self::CAPABILITY_DURABLE_SELECTION;
        }

        return $capabilities;
    }

    public static function supportsMessageStreams(string $protocolVersion): bool
    {
        return self::supportsFeatureVersion($protocolVersion, self::MESSAGE_STREAMS_MINIMUM_PROTOCOL_VERSION);
    }

    public static function supportsTypedSearchAttributes(string $protocolVersion): bool
    {
        return self::supportsFeatureVersion($protocolVersion, self::TYPED_SEARCH_ATTRIBUTES_MINIMUM_PROTOCOL_VERSION);
    }

    public static function supportsConditionWaitOccurrenceIdentity(string $protocolVersion): bool
    {
        return self::supportsFeatureVersion(
            $protocolVersion,
            self::CONDITION_WAIT_OCCURRENCE_IDENTITY_MINIMUM_PROTOCOL_VERSION,
        );
    }

    public static function supportsLocalActivities(string $protocolVersion): bool
    {
        return self::supportsFeatureVersion(
            $protocolVersion,
            self::PORTABLE_WORKER_AFFINITY_MINIMUM_PROTOCOL_VERSION,
        );
    }

    public static function supportsLocalActivityAttemptReports(string $protocolVersion): bool
    {
        return self::supportsFeatureVersion(
            $protocolVersion,
            self::LOCAL_ACTIVITY_ATTEMPT_REPORTS_MINIMUM_PROTOCOL_VERSION,
        );
    }

    /**
     * Version-gated workflow-task completion fields a worker may submit.
     *
     * @return list<string>
     */
    public static function messageStreamCompletionFieldsForVersion(string $protocolVersion): array
    {
        return self::supportsMessageStreams($protocolVersion)
            ? self::MESSAGE_STREAM_COMPLETION_FIELDS
            : [];
    }

    /**
     * Non-terminal command types that an external worker may return
     * from a workflow task completion.
     *
     * @return list<string>
     */
    public static function nonTerminalCommandTypes(): array
    {
        return [
            'cancel_selection_operation',
            'schedule_activity',
            'start_timer',
            'start_child_workflow',
            'start_service_operation',
            'complete_update',
            'fail_update',
            'record_side_effect',
            'record_local_activity',
            'record_version_marker',
            'upsert_memo',
            'upsert_search_attributes',
            'open_condition_wait',
            'open_signal_wait',
        ];
    }

    /**
     * Terminal command types that an external worker may return
     * from a workflow task completion. At most one terminal command
     * is allowed per completion.
     *
     * @return list<string>
     */
    public static function terminalCommandTypes(): array
    {
        return ['complete_workflow', 'fail_workflow', 'continue_as_new'];
    }

    /**
     * Supported content encodings for history payload compression.
     *
     * @return list<string>
     */
    public static function supportedHistoryEncodings(): array
    {
        return self::SUPPORTED_HISTORY_ENCODINGS;
    }

    /**
     * Long-poll semantics for the poll verbs.
     *
     * When an external worker's poll request includes a timeout_seconds
     * parameter, the bridge or server holds the connection open for up to
     * that duration (clamped to [MIN, MAX]) waiting for a matching task.
     *
     * - If a task becomes ready during the wait, it is returned immediately.
     * - If the timeout expires with no task, the response is an empty list.
     * - Heartbeat-style keepalive is not required; HTTP-level timeouts
     *   should be set above MAX_LONG_POLL_TIMEOUT to avoid premature drops.
     * - The client should retry immediately on an empty long-poll response
     *   unless shutting down.
     *
     * @return array{
     *     default_timeout_seconds: int,
     *     min_timeout_seconds: int,
     *     max_timeout_seconds: int,
     * }
     */
    public static function longPollSemantics(): array
    {
        return [
            'default_timeout_seconds' => self::DEFAULT_LONG_POLL_TIMEOUT,
            'min_timeout_seconds' => self::MIN_LONG_POLL_TIMEOUT,
            'max_timeout_seconds' => self::MAX_LONG_POLL_TIMEOUT,
        ];
    }

    /**
     * Clamp a caller-supplied long-poll timeout to the valid range.
     */
    public static function clampLongPollTimeout(int $timeoutSeconds): int
    {
        return max(self::MIN_LONG_POLL_TIMEOUT, min($timeoutSeconds, self::MAX_LONG_POLL_TIMEOUT));
    }

    /**
     * Local-activity semantics advertised to workers and operators.
     *
     * The server does not receive a schedule command for a local activity:
     * the SDK/runtime that owns workflow replay executes it inside the
     * workflow task and records normal activity history with the local marker.
     *
     * @return array<string, mixed>
     */
    public static function localActivitySemantics(): array
    {
        return LocalActivityContract::manifest();
    }

    /**
     * Published task-queue priority and fairness wire contract.
     *
     * @return array<string, mixed>
     */
    public static function taskQueuePriorityFairnessSemantics(): array
    {
        return TaskQueuePriorityFairnessContract::manifest();
    }

    /**
     * Full summary of the protocol for capability negotiation or diagnostics.
     *
     * @return array{
     *     version: string,
     *     workflow_task_verbs: list<string>,
     *     activity_task_verbs: list<string>,
     *     query_task_verbs: list<string>,
     *     worker_capabilities: list<string>,
     *     workflow_task_command_payload_envelope_fields: array<string, list<string>>,
     *     non_terminal_command_types: list<string>,
     *     terminal_command_types: list<string>,
     *     workflow_history_budget: array<string, mixed>,
     *     history_pagination: array{default_page_size: int, max_page_size: int},
     *     history_compression: array{supported_encodings: list<string>, compression_threshold: int},
     *     long_poll: array{default_timeout_seconds: int, min_timeout_seconds: int, max_timeout_seconds: int},
     *     local_activities: array<string, mixed>,
     *     worker_session_verbs: list<string>,
     *     sticky_execution: array<string, mixed>,
     *     worker_sessions: array<string, mixed>,
     *     query_tasks: array<string, mixed>,
     *     upsert_memo_command: array<string, mixed>,
     *     upsert_search_attributes_command: array<string, mixed>,
     *     condition_wait_command: array<string, mixed>,
     *     service_operation_command: array<string, mixed>,
     *     message_streams: array<string, mixed>,
     *     fail_workflow_command: array<string, mixed>,
     *     invocable_carrier: array<string, mixed>,
     *     task_queue_priority_fairness: array<string, mixed>,
     * }
     */
    public static function describe(): array
    {
        return [
            'version' => self::VERSION,
            'workflow_task_verbs' => self::workflowTaskVerbs(),
            'activity_task_verbs' => self::activityTaskVerbs(),
            'query_task_verbs' => self::queryTaskVerbs(),
            'worker_capabilities' => self::workerCapabilities(),
            'workflow_task_command_payload_envelope_fields' => WorkflowCommandNormalizer::payloadEnvelopeFields(),
            'non_terminal_command_types' => self::nonTerminalCommandTypes(),
            'terminal_command_types' => self::terminalCommandTypes(),
            'workflow_history_budget' => WorkerHistoryPayloadContract::manifest(),
            'history_pagination' => [
                'default_page_size' => self::DEFAULT_HISTORY_PAGE_SIZE,
                'max_page_size' => self::MAX_HISTORY_PAGE_SIZE,
            ],
            'history_compression' => [
                'supported_encodings' => self::supportedHistoryEncodings(),
                'compression_threshold' => self::COMPRESSION_THRESHOLD,
            ],
            'long_poll' => self::longPollSemantics(),
            'local_activities' => self::localActivitySemantics(),
            'worker_session_verbs' => self::workerSessionVerbs(),
            'sticky_execution' => StickyExecution::describe(),
            'worker_sessions' => self::workerSessionSemantics(),
            'query_tasks' => self::queryTaskSemantics(),
            'upsert_memo_command' => self::upsertMemoCommandShape(),
            'upsert_search_attributes_command' => self::upsertSearchAttributesCommandShape(),
            'condition_wait_command' => self::conditionWaitCommandShape(),
            'durable_selection' => self::durableSelectionSemantics(),
            'service_operation_command' => self::serviceOperationCommandShape(),
            'message_streams' => self::messageStreamSemantics(),
            'fail_workflow_command' => self::failWorkflowCommandShape(),
            'payload_codecs_universal' => CodecRegistry::universal(),
            'unsupported_payload_codec_reason' => self::REASON_UNSUPPORTED_PAYLOAD_CODEC,
            'invocable_carrier' => self::invocableCarrierSemantics(),
            'task_queue_priority_fairness' => self::taskQueuePriorityFairnessSemantics(),
        ];
    }

    /**
     * Published workflow-task command and history contract for memo upserts.
     *
     * @return array<string, mixed>
     */
    public static function upsertMemoCommandShape(): array
    {
        return [
            'type' => 'upsert_memo',
            'category' => 'non_terminal_command',
            'worker_capability' => self::CAPABILITY_MEMO_UPSERTS,
            'minimum_protocol_version' => self::UPSERT_MEMO_MINIMUM_PROTOCOL_VERSION,
            'required_fields' => ['type', 'entries'],
            'optional_fields' => [],
            'entries' => [
                'shape' => 'payload-envelope<avro-map<string, Value|null>>',
                'payload_envelope_field' => true,
                'codec' => MemoPayload::CODEC,
                'value_schema' => 'durable_workflow.protocol.Value',
                'key_pattern' => MemoPayload::KEY_PATTERN,
                'null_value' => 'delete_key',
                'max_keys_per_run' => WorkflowMemo::MAX_MEMOS_PER_RUN,
                'max_value_size_bytes' => WorkflowMemo::MAX_VALUE_SIZE_BYTES,
                'max_total_size_bytes' => WorkflowMemo::MAX_TOTAL_SIZE_BYTES,
                'size_measurement' => 'avro_single_object_bytes',
                'runtime_configured_total_limit' => 'workflows.v2.structural_limits.memo_size_bytes',
            ],
            'merge' => [
                'base' => 'memo visible immediately before the command sequence',
                'operation' => 'replace named keys; remove keys whose value is null; preserve all other keys',
                'canonical_order' => 'lexicographic_key_order',
            ],
            'history' => [
                'event_type' => 'MemoUpserted',
                'required_fields' => ['sequence', 'entries', 'merged'],
                'replay_identity' => ['sequence', 'entries'],
                'entries_shape' => 'payload-envelope<avro-map<string, Value|null>>',
                'merged_shape' => 'payload-envelope<avro-map<string, Value>>',
                'merged_is_projection' => true,
            ],
            'idempotency' => [
                'fence' => ['workflow_task_id', 'workflow_task_attempt'],
                'duplicate_completion' => 'does_not_append_or_apply',
                'redelivery' => 'replay_consumes_matching_history_sequence',
            ],
            'continue_as_new' => 'merged memo is inherited before commands on the continued run',
            'external_payloads' => [
                'entries_field_authority' => WorkflowCommandNormalizer::class . '::payloadEnvelopeFields',
                'envelopes_and_references' => 'standard_avro_payload_envelope',
                'resolution_owner' => 'runtime',
                'sdk_storage_drivers' => false,
            ],
            'published_artifact_conformance' => [
                'worker_languages' => ['php', 'python', 'rust'],
                'runtime_targets' => ['standalone_server', 'managed_cloud'],
                'required_observations' => [
                    'capability_discovered',
                    'memo_update_completed',
                    'waiting_describe_merged',
                    'completed_describe_merged',
                    'replay_does_not_duplicate',
                ],
            ],
        ];
    }

    /**
     * Published workflow-task command shape for search-attribute upserts.
     *
     * @return array<string, mixed>
     */
    public static function upsertSearchAttributesCommandShape(): array
    {
        return [
            'type' => 'upsert_search_attributes',
            'category' => 'non_terminal_command',
            'minimum_protocol_version' => self::UPSERT_SEARCH_ATTRIBUTES_MINIMUM_PROTOCOL_VERSION,
            'required_fields' => ['type', 'attributes'],
            'optional_fields' => ['attribute_types'],
            'attributes' => [
                'shape' => 'map<string, scalar|list<string>|null>',
                'key' => 'search_attribute_key',
                'value_types' => ['string', 'int', 'float', 'bool', 'datetime-string', 'list<string>', 'null'],
                'null_value' => 'delete_attribute',
                'list_values' => [
                    'shape' => 'list<string>',
                    'search_attribute_type' => WorkflowSearchAttribute::TYPE_KEYWORD_LIST,
                    'max_entry_length' => WorkflowSearchAttribute::MAX_KEYWORD_LENGTH,
                ],
            ],
            'attribute_types' => [
                'shape' => 'map<string, search_attribute_type>',
                'required' => false,
                'minimum_protocol_version' => self::TYPED_SEARCH_ATTRIBUTES_MINIMUM_PROTOCOL_VERSION,
                'valid_values' => WorkflowSearchAttribute::VALID_TYPES,
                'invalid_values' => 'reject_command',
                'omitted_values' => 'infer from the attribute value; absence in old history is unknown identity, not a typed match',
            ],
            'history' => [
                'event_type' => 'SearchAttributesUpserted',
                'replay_identity' => ['sequence', 'attributes', 'attribute_types'],
                'legacy_missing_attribute_types' => 'compare values only and preserve unknown typed identity',
            ],
        ];
    }

    /**
     * Published workflow-task command and history contract for condition waits.
     *
     * @return array<string, mixed>
     */
    public static function conditionWaitCommandShape(): array
    {
        return [
            'type' => 'open_condition_wait',
            'category' => 'non_terminal_command',
            'minimum_protocol_version' => self::CONDITION_WAIT_MINIMUM_PROTOCOL_VERSION,
            'required_fields' => ['type'],
            'optional_fields' => [
                'condition_key',
                'condition_definition_fingerprint',
                'condition_wait_occurrence_id',
                'timeout_seconds',
            ],
            'condition_wait_occurrence_id' => [
                'shape' => 'non-empty string',
                'required' => false,
                'minimum_protocol_version' => self::CONDITION_WAIT_OCCURRENCE_IDENTITY_MINIMUM_PROTOCOL_VERSION,
                'worker_capability' => self::CAPABILITY_CONDITION_WAIT_OCCURRENCE_IDENTITY,
                'meaning' => 'explicit deterministic identity of one authored wait occurrence',
            ],
            'history' => [
                'lifecycle_event_types' => [
                    'ConditionWaitOpened',
                    'ConditionWaitSatisfied',
                    'ConditionWaitTimedOut',
                ],
                'timeout_event_types' => ['TimerScheduled', 'TimerCancelled', 'TimerFired'],
                'timeout_event_selector' => [
                    'timer_kind' => 'condition_timeout',
                ],
                'occurrence_field' => 'condition_wait_occurrence_id',
                'propagation' => 'exact_value_on_every_event_for_one_wait_lifecycle',
                'replay_identity' => [
                    'sequence',
                    'condition_wait_occurrence_id',
                    'condition_key',
                    'condition_definition_fingerprint',
                    'timeout_seconds',
                ],
                'physical_reevaluations' => 'reuse_open_occurrence_identity',
                'adjacent_authored_waits' => 'distinct_occurrence_identity',
                'legacy_missing_occurrence_id' => 'legacy_condition_wait_without_occurrence_identity',
            ],
            'version_gate' => [
                'workers_below_minimum_must_not_advertise_capability' => true,
                'workers_below_minimum_must_not_submit_occurrence_id' => true,
                'capable_workers_must_submit_occurrence_id' => true,
                'servers_below_minimum_must_reject_before_execution' => true,
                'rejection_status' => 400,
                'rejection_reason' => 'unsupported_protocol_version',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function durableSelectionSemantics(): array
    {
        return [
            'minimum_protocol_version' => self::DURABLE_SELECTION_MINIMUM_PROTOCOL_VERSION,
            'worker_capability' => self::CAPABILITY_DURABLE_SELECTION,
            'group_mode' => 'select',
            'group_id_prefix' => 'select-calls',
            'eligible_command_types' => [
                'schedule_activity',
                'start_child_workflow',
                'start_timer',
                'open_signal_wait',
                'open_condition_wait',
            ],
            'command_metadata_fields' => [
                'parallel_group_mode',
                'selection_member_key',
                'selection_member_index',
                'selection_member_base_sequence',
                'selection_member_size',
                'selection_member_kind',
            ],
            'selection_key_domain' => 'non_empty_string_or_non_negative_integer',
            'winner_history_event' => 'SelectionResolved',
            'cancellation_history_event' => 'SelectionOperationCancelled',
            'cancellation_command' => 'cancel_selection_operation',
            'cancellation_request_result' => 'void',
            'cancellation_outcome_authority' => 'SelectionOperationCancelled',
            'cancellation_member_authority' => 'durable_scheduled_or_open_history',
            'terminal_before_cancellation' => 'preserve_terminal_result_without_cancellation_marker',
            'cancellation_replay_without_marker' => 'advance_only_after_committed_noop_boundary',
            'winner_identity_fields' => [
                'selection_group_id',
                'member_key',
                'member_index',
                'member_base_sequence',
                'member_size',
                'operation_kind',
                'operation_identity',
                'outcome',
                'resolution_event_id',
                'resolution_event_type',
            ],
            'winner_commit' => 'first_eligible_resolution_under_parent_run_lock',
            'replay' => 'consume_recorded_winner_independent_of_later_history_order',
            'non_winners' => ['continue', 'await', 'cancel'],
            'implicit_cancellation' => false,
            'version_gate' => [
                'workers_below_minimum_must_not_advertise_capability' => true,
                'servers_below_minimum_must_reject_selection_metadata' => true,
                'rejection_reason' => 'unsupported_protocol_version',
            ],
        ];
    }

    /**
     * Published workflow-task command shape for durable Nexus service calls.
     *
     * @return array<string, mixed>
     */
    public static function serviceOperationCommandShape(): array
    {
        return [
            'type' => 'start_service_operation',
            'category' => 'non_terminal_command',
            'minimum_protocol_version' => self::SERVICE_OPERATION_COMMAND_MINIMUM_PROTOCOL_VERSION,
            'required_fields' => ['type', 'endpoint_name', 'service_name', 'operation_name', 'request_payload'],
            'optional_fields' => [
                'payload_codec',
                'namespace',
                'caller_namespace',
                'service_call_id',
                'idempotency_key',
                'mode_override',
                'wait_for',
                'wait_timeout_seconds',
                'target_workflow_instance_id',
                'target_workflow_run_id',
                'connection',
                'queue',
                'business_key',
                'labels',
                'memo',
                'search_attributes',
                'duplicate_start_policy',
                'metadata',
                'request_payload_reference',
                'principal_subject',
                'principal_method',
                'principal_roles',
                'principal_tenant',
                'principal_claims',
            ],
            'request_payload' => [
                'shape' => 'serialized payload string or payload envelope',
                'envelope_fields' => ['codec', 'blob', 'external_storage'],
                'codec_field' => 'payload_codec',
            ],
            'mode_override' => [
                'shape' => 'string',
                'valid_values' => ['sync', 'async'],
                'required' => false,
            ],
            'wait_for' => [
                'shape' => 'string',
                'valid_values' => ['accepted', 'completed'],
                'required' => false,
            ],
            'wait_timeout_seconds' => [
                'shape' => 'non-negative integer',
                'required' => false,
            ],
            'metadata' => [
                'shape' => 'array<string, mixed>',
                'reserved_conformance_keys' => [
                    'caller_sdk_language',
                    'service_sdk_language',
                    'artifact_tuple',
                    'published_artifact_worker_execution',
                ],
            ],
            'service_call_result_events' => [
                'ServiceCallStarted',
                'ServiceCallCompleted',
                'ServiceCallFailed',
                'ServiceCallCancelled',
            ],
        ];
    }

    /**
     * Portable service-mode message-stream completion contract.
     *
     * @return array<string, mixed>
     */
    public static function messageStreamSemantics(): array
    {
        return [
            'feature' => self::CAPABILITY_MESSAGE_STREAMS,
            'minimum_protocol_version' => self::MESSAGE_STREAMS_MINIMUM_PROTOCOL_VERSION,
            'worker_capability' => self::CAPABILITY_MESSAGE_STREAMS,
            'registration_field' => 'capabilities',
            'completion_fields' => [
                'message_stream_cursors' => [
                    'required' => false,
                    'shape' => 'list<message_stream_cursor_advance>',
                    'max_items' => 100,
                    'item' => [
                        'additional_fields_allowed' => false,
                        'required_fields' => ['stream_name', 'through_position'],
                        'stream_name' => [
                            'shape' => 'string',
                            'pattern' => '^[A-Za-z0-9._:-]{1,128}$',
                        ],
                        'through_position' => [
                            'shape' => 'integer',
                            'minimum' => 0,
                            'meaning' => 'highest_contiguous_consumed_position',
                        ],
                    ],
                ],
                'message_stream_waits' => [
                    'required' => false,
                    'shape' => 'list<message_stream_wait>',
                    'max_items' => 100,
                    'item' => [
                        'additional_fields_allowed' => false,
                        'required_fields' => ['stream_name', 'after_position'],
                        'stream_name' => [
                            'shape' => 'string',
                            'pattern' => '^[A-Za-z0-9._:-]{1,128}$',
                        ],
                        'after_position' => [
                            'shape' => 'integer',
                            'minimum' => 0,
                            'meaning' => 'wait_for_the_first_message_after_position',
                        ],
                    ],
                ],
            ],
            'version_gate' => [
                'workers_below_minimum_must_not_advertise_capability' => true,
                'workers_below_minimum_must_not_submit_completion_fields' => true,
                'rejection_status' => 409,
                'rejection_reason' => 'message_streams_unavailable',
            ],
        ];
    }

    /**
     * Published workflow-task terminal failure command shape.
     *
     * @return array<string, mixed>
     */
    public static function failWorkflowCommandShape(): array
    {
        return [
            'type' => 'fail_workflow',
            'category' => 'terminal_command',
            'required_fields' => ['type', 'message'],
            'optional_fields' => ['exception_class', 'exception_type', 'exception', 'non_retryable'],
            'field_minimum_protocol_versions' => [
                'exception' => self::FAIL_WORKFLOW_EXCEPTION_MINIMUM_PROTOCOL_VERSION,
            ],
            'message' => [
                'shape' => 'non-empty string',
                'meaning' => 'terminal workflow failure summary',
            ],
            'exception_class' => [
                'shape' => 'string',
                'required' => false,
                'meaning' => 'SDK exception class name when available',
            ],
            'exception_type' => [
                'shape' => 'string',
                'required' => false,
                'meaning' => 'stable typed failure classification when available',
            ],
            'exception' => [
                'shape' => 'array<string, mixed>',
                'required' => false,
                'minimum_protocol_version' => self::FAIL_WORKFLOW_EXCEPTION_MINIMUM_PROTOCOL_VERSION,
                'meaning' => 'structured terminal workflow failure payload preserved from the worker',
            ],
            'non_retryable' => [
                'shape' => 'bool',
                'required' => false,
                'meaning' => 'marks the terminal workflow failure as non-retryable',
            ],
        ];
    }

    /**
     * Published server-routed workflow query task contract.
     *
     * @return array<string, mixed>
     */
    public static function queryTaskSemantics(): array
    {
        return [
            'feature' => self::CAPABILITY_QUERY_TASKS,
            'minimum_protocol_version' => self::QUERY_TASKS_MINIMUM_PROTOCOL_VERSION,
            'worker_capability' => self::CAPABILITY_QUERY_TASKS,
            'verbs' => self::queryTaskVerbs(),
            'path_prefix' => '/api/worker/query-tasks',
            'endpoints' => [
                'poll' => [
                    'method' => 'POST',
                    'path' => '/api/worker/query-tasks/poll',
                    'request_fields' => ['worker_id', 'task_queue', 'poll_request_id', 'timeout_seconds'],
                    'response_fields' => ['task', 'poll_status'],
                ],
                'complete' => [
                    'method' => 'POST',
                    'path' => '/api/worker/query-tasks/{query_task_id}/complete',
                    'request_fields' => ['lease_owner', 'query_task_attempt', 'result', 'result_envelope'],
                ],
                'fail' => [
                    'method' => 'POST',
                    'path' => '/api/worker/query-tasks/{query_task_id}/fail',
                    'request_fields' => ['lease_owner', 'query_task_attempt', 'failure'],
                ],
            ],
            'poll' => [
                'leases_on_return' => true,
                'long_poll' => self::longPollSemantics(),
                'poll_request_idempotency' => true,
                'empty_response_poll_status' => 'empty',
                'workflow_task_pending_poll_status' => 'workflow_task_pending',
                'poll_statuses' => [
                    'leased',
                    'empty',
                    'workflow_task_pending',
                    'stale_worker_registration',
                    'unavailable',
                ],
                'requires_registered_worker' => true,
                'requires_worker_capability' => self::CAPABILITY_QUERY_TASKS,
            ],
            'task_fields' => [
                'query_task_id',
                'query_task_attempt',
                'lease_owner',
                'workflow_id',
                'run_id',
                'task_queue',
                'workflow_type',
                'workflow_class',
                'query_name',
                'query_arguments',
                'payload_codec',
                'history_export',
                'history_events',
            ],
            'completion' => [
                'requires_lease_owner' => true,
                'requires_query_task_attempt' => true,
                'result_envelope_fields' => ['codec', 'blob', 'external_storage'],
                'terminal_for_query_task' => true,
            ],
            'failure' => [
                'requires_lease_owner' => true,
                'requires_query_task_attempt' => true,
                'failure_fields' => ['message', 'reason', 'type', 'stack_trace', 'validation_errors'],
                'known_reasons' => ['rejected_unknown_query', 'invalid_query_arguments', 'query_rejected'],
                'terminal_for_query_task' => true,
            ],
            'durability' => [
                'history_event_appended' => false,
                'workflow_command_created' => false,
                'result_resolves_waiting_query_request' => true,
                'query_replay_must_suppress_commands' => true,
            ],
        ];
    }

    /**
     * Published invocable HTTP carrier wire-protocol contract.
     *
     * Surfaces the stable terms that activity-grade external handlers must
     * implement: the carrier type, HTTP method, request and response content
     * types, envelope schema identifiers, failure vocabulary, and the
     * cluster-info discovery path under which the full carrier contract
     * manifest is published.
     *
     * @return array<string, mixed>
     */
    public static function invocableCarrierSemantics(): array
    {
        return [
            'feature' => 'invocable_http_carrier',
            'contract_version' => '1.0',
            'scope' => [ExternalTaskInput::KIND_ACTIVITY_TASK],
            'explicit_non_goals' => [
                'workflow_task_execution',
                'workflow_replay',
                'history_mutation',
                'generic_webhook_ingress',
            ],
            'request' => [
                'method' => 'POST',
                'content_type' => 'application/vnd.durable-workflow.external-task-input+json',
                'body_schema' => ExternalTaskInput::SCHEMA,
                'body_schema_version' => ExternalTaskInput::VERSION,
                'idempotency_key_source' => 'task.idempotency_key',
            ],
            'response' => [
                'success_status' => 200,
                'content_type' => InvocableHttpAdapter::RESULT_MEDIA_TYPE,
                'body_schema' => InvocableActivityHandler::RESULT_SCHEMA,
                'body_schema_version' => InvocableActivityHandler::RESULT_VERSION,
            ],
            'failure_kinds' => [
                'application',
                'timeout',
                'cancellation',
                'malformed_output',
                'handler_crash',
                'decode_failure',
                'unsupported_payload',
            ],
            'failure_classifications' => [
                'application_error',
                'timeout',
                'cancelled',
                'deadline_exceeded',
                'handler_crash',
                'decode_failure',
                'malformed_output',
                'unsupported_payload_codec',
                'unsupported_payload_reference',
            ],
            'cluster_info_path' => 'worker_protocol.invocable_carrier_contract',
        ];
    }

    /**
     * Worker-session lifecycle verbs exposed by the worker protocol.
     *
     * @return list<string>
     */
    public static function workerSessionVerbs(): array
    {
        return ['create', 'heartbeat', 'close'];
    }

    /**
     * Published worker-session runtime contract.
     *
     * @return array<string, mixed>
     */
    public static function workerSessionSemantics(): array
    {
        return [
            'feature' => 'worker_sessions',
            'contract_version' => '1.0',
            'minimum_protocol_version' => self::WORKER_SESSIONS_MINIMUM_PROTOCOL_VERSION,
            'command_field' => 'worker_session',
            'activity_options_field' => 'worker_session',
            'verbs' => self::workerSessionVerbs(),
            'lifecycle' => [
                'creation' => 'lazy_create_on_first_admitted_activity_or_explicit_worker_create',
                'renewal' => 'activity_heartbeat_or_explicit_session_heartbeat',
                'close' => 'explicit_holder_close',
                'lease_expiry' => 'session_expires_when_lease_is_not_renewed',
                'ttl_expiry' => 'absolute_session_ttl_is_terminal_for_that_session_id',
            ],
            'ownership' => 'single_worker_lease_owner',
            'lease' => [
                'scope' => 'namespace_session_id',
                'owner' => 'registered_worker_id',
                'activity_attempt_leases_remain_independent' => true,
            ],
            'admission' => [
                'queue_routing_first' => true,
                'requires_registered_worker' => true,
                'requires_capabilities' => true,
                'create_if_missing_default' => true,
                'allow_reacquire_after_failure_default' => true,
            ],
            'rollout_safety' => [
                'minimum_protocol_version' => self::WORKER_SESSIONS_MINIMUM_PROTOCOL_VERSION,
                'mixed_server_rollout_fenced_by_protocol_version' => true,
                'servers_below_minimum_must_reject_worker_session_commands' => true,
                'servers_below_minimum_must_not_claim_worker_session_activity_tasks' => true,
            ],
            'limits' => [
                'max_concurrent_worker_sessions' => 'worker_registration',
                'max_concurrent_activities' => 'session',
            ],
            'default_max_concurrent_activities' => 1,
            'renewal' => [
                'activity_heartbeat_renews_session' => true,
                'explicit_session_heartbeat' => true,
            ],
            'failure_detection' => ['lease_expiry', 'registered_worker_heartbeat_staleness'],
            'holder_loss' => [
                'in_flight_activities_keep_at_least_once_attempt_semantics' => true,
                'replacement_worker_must_reacquire_session' => true,
                'process_local_state_must_be_rebuilt_after_reacquire' => true,
            ],
            'cancellation' => [
                'workflow_cancellation_observed_through_activity_heartbeat' => true,
                'session_lease_does_not_override_activity_cancel_requested' => true,
                'planned_shutdown_should_close_sessions' => true,
            ],
            'routing' => ['queue', 'connection', 'requirements'],
            'visibility' => ['active', 'closed', 'expired', 'failed', 'orphaned'],
            'statuses' => ['active', 'closed', 'expired', 'failed', 'orphaned'],
            'terminal_statuses' => ['closed'],
            'terminal_conditions' => ['explicit_close', 'ttl_expired', 'allow_reacquire_after_failure_false'],
            'authoring_guidance' => [
                'use_for_process_local_state_gpu_memory_or_filesystem_affinity',
                'prefer_ordinary_queued_activities_for_independent_steps',
                'prefer_one_larger_activity_for_atomic_side_effects',
            ],
        ];
    }

    private static function supportsFeatureVersion(string $candidate, string $minimum): bool
    {
        if (
            preg_match('/\A(\d+)\.(\d+)\z/D', $candidate, $candidateParts) !== 1
            || preg_match('/\A(\d+)\.(\d+)\z/D', $minimum, $minimumParts) !== 1
        ) {
            return false;
        }

        return (int) $candidateParts[1] === (int) $minimumParts[1]
            && (int) $candidateParts[2] >= (int) $minimumParts[2];
    }
}
