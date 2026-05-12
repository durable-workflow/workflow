<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\Serializers\CodecRegistry;

/**
 * Frozen versioned contract for the external workflow-worker protocol.
 *
 * This class defines the canonical verb set, history pagination parameters,
 * and protocol version that external workers (including the standalone server)
 * must align to. Bump the version when the verb set, request/response shapes,
 * or pagination contract changes in a backwards-incompatible way.
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
    public const VERSION = '1.3';

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
     */
    public const MIN_LONG_POLL_TIMEOUT = 1;

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
     * Non-terminal command types that an external worker may return
     * from a workflow task completion.
     *
     * @return list<string>
     */
    public static function nonTerminalCommandTypes(): array
    {
        return [
            'schedule_activity',
            'start_timer',
            'start_child_workflow',
            'complete_update',
            'fail_update',
            'record_side_effect',
            'record_version_marker',
            'upsert_search_attributes',
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
     *     non_terminal_command_types: list<string>,
     *     terminal_command_types: list<string>,
     *     history_pagination: array{default_page_size: int, max_page_size: int},
     *     history_compression: array{supported_encodings: list<string>, compression_threshold: int},
     *     long_poll: array{default_timeout_seconds: int, min_timeout_seconds: int, max_timeout_seconds: int},
     *     local_activities: array<string, mixed>,
     *     worker_session_verbs: list<string>,
     *     sticky_execution: array<string, mixed>,
     *     worker_sessions: array<string, mixed>,
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
            'non_terminal_command_types' => self::nonTerminalCommandTypes(),
            'terminal_command_types' => self::terminalCommandTypes(),
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
            'payload_codecs_universal' => CodecRegistry::universal(),
            'payload_codecs_engine_specific' => CodecRegistry::engineSpecific(),
            'unsupported_payload_codec_reason' => self::REASON_UNSUPPORTED_PAYLOAD_CODEC,
            'invocable_carrier' => self::invocableCarrierSemantics(),
            'task_queue_priority_fairness' => self::taskQueuePriorityFairnessSemantics(),
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
            'minimum_protocol_version' => self::VERSION,
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
                'minimum_protocol_version' => self::VERSION,
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
            'failure_detection' => [
                'lease_expiry',
                'registered_worker_heartbeat_staleness',
            ],
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
            'routing' => [
                'queue',
                'connection',
                'requirements',
            ],
            'visibility' => [
                'active',
                'closed',
                'expired',
                'failed',
                'orphaned',
            ],
            'statuses' => [
                'active',
                'closed',
                'expired',
                'failed',
                'orphaned',
            ],
            'terminal_statuses' => [
                'closed',
            ],
            'terminal_conditions' => [
                'explicit_close',
                'ttl_expired',
                'allow_reacquire_after_failure_false',
            ],
            'authoring_guidance' => [
                'use_for_process_local_state_gpu_memory_or_filesystem_affinity',
                'prefer_ordinary_queued_activities_for_independent_steps',
                'prefer_one_larger_activity_for_atomic_side_effects',
            ],
        ];
    }
}
