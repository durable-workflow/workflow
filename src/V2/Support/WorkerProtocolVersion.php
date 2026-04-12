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
     * Full summary of the protocol for capability negotiation or diagnostics.
     *
     * @return array{
     *     version: string,
     *     workflow_task_verbs: list<string>,
     *     activity_task_verbs: list<string>,
     *     non_terminal_command_types: list<string>,
     *     terminal_command_types: list<string>,
     *     history_pagination: array{default_page_size: int, max_page_size: int},
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
        ];
    }
}
