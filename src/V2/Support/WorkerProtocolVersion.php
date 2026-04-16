<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

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
    public const VERSION = '1.0';

    /**
     * Default page size for paginated history responses.
     */
    public const DEFAULT_HISTORY_PAGE_SIZE = 200;

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
        return [
            'poll',
            'claim',
            'claimStatus',
            'complete',
            'fail',
            'status',
            'heartbeat',
        ];
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
        return [
            'complete_workflow',
            'fail_workflow',
            'continue_as_new',
        ];
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
        ];
    }
}
