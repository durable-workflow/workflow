<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

/**
 * Published runtime contract for the task-queue priority and fairness
 * dispatch surface.
 *
 * Workflow and activity start contracts both expose three dispatch-shaping
 * fields — `priority`, `fairness_key`, and `fairness_weight` — that travel
 * over the wire alongside `connection` and `queue`. Persistence pins the
 * values onto every workflow run and workflow task row so dispatch can
 * order ready work by urgency and rebalance fairly across workload classes
 * without a server-version handshake.
 *
 * This contract is the machine-readable mirror of
 * `docs/architecture/task-queue-priority-fairness.md`. It enumerates the
 * field vocabulary, accepted ranges, defaults, inheritance rule, dispatch
 * semantics, persistence columns, and the operator observability route so
 * that polyglot SDK authors, server implementations, and operator tooling
 * can validate themselves against a single source of truth instead of
 * reading prose.
 *
 * Adding a field, changing an accepted range, changing the default, or
 * changing the dispatch semantics is a contract change. Bump
 * {@see self::VERSION} and align the architecture doc, the
 * `task-queues/{queue}/priority-fairness` observability response shape,
 * and any per-package stability documents in the same change. Removing a
 * field is a major change.
 *
 * @api Stable class surface consumed by the standalone workflow-server,
 *      which re-exports the manifest from `GET /api/cluster/info` under
 *      `worker_protocol.server_capabilities.task_queue_priority_fairness`.
 *      The class name, namespace, public constants, and public static
 *      method signatures are covered by the workflow package's semver
 *      guarantee. See docs/api-stability.md.
 */
final class TaskQueuePriorityFairnessContract
{
    public const SCHEMA = 'durable-workflow.v2.task-queue-priority-fairness.contract';

    public const VERSION = 1;

    /**
     * Authority document for the human-readable contract.
     */
    public const AUTHORITY_DOC = 'docs/architecture/task-queue-priority-fairness.md';

    /**
     * Operator observability route, relative to the configured webhooks
     * route prefix (default `webhooks`).
     */
    public const OBSERVABILITY_ROUTE = '{webhooks_route}/task-queues/{queue}/priority-fairness';

    /**
     * @return array<string, mixed>
     */
    public static function manifest(): array
    {
        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'authority_doc' => self::AUTHORITY_DOC,
            'feature' => 'task_queue_priority_fairness',
            'fields' => self::fields(),
            'inheritance' => self::inheritance(),
            'persistence' => self::persistence(),
            'dispatch' => self::dispatch(),
            'observability' => self::observability(),
            'authoring_apis' => self::authoringApis(),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function fields(): array
    {
        return [
            'priority' => [
                'type' => 'integer',
                'min' => TaskPriority::MIN,
                'min_user' => TaskPriority::MIN_USER,
                'max' => TaskPriority::MAX,
                'default' => TaskPriority::DEFAULT,
                'lower_is_more_urgent' => true,
                'description' => 'Dispatch priority. Lower numbers run first within a queue. Priority 0 is reserved for high-urgency control-plane work; user code should typically use values in the min_user..max range.',
            ],
            'fairness_key' => [
                'type' => 'string',
                'nullable' => true,
                'max_length' => TaskFairnessKey::MAX_LENGTH,
                'pattern' => '^[A-Za-z0-9._:-]{1,' . TaskFairnessKey::MAX_LENGTH . '}$',
                'normalization' => 'trim_then_lowercase',
                'default' => null,
                'default_class_label' => TaskFairnessKey::DEFAULT_CLASS,
                'description' => 'Workload-class identifier used to rebalance dispatch across distinct classes within a priority tier. Tasks with no key share the default_class_label so unmarked tenants are not crowded out by a single keyed class.',
            ],
            'fairness_weight' => [
                'type' => 'integer',
                'min' => 1,
                'max' => 1000,
                'default' => 1,
                'description' => 'Relative scheduling weight for the fairness class. A class with weight 3 receives a proportionally larger share of dispatch attention than a class with weight 1 within the same priority tier.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function inheritance(): array
    {
        return [
            'workflow_task' => 'inherits_priority_and_fairness_from_parent_run',
            'activity_task' => 'inherits_from_run_unless_activity_options_overrides',
            'activity_options_override_fields' => ['priority', 'fairness_key', 'fairness_weight'],
            'override_resolver' => 'Workflow\\V2\\Support\\TaskSchedulingFields',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function persistence(): array
    {
        return [
            'columns_per_table' => [
                'workflow_runs' => ['priority', 'fairness_key', 'fairness_weight'],
                'workflow_tasks' => ['priority', 'fairness_key', 'fairness_weight'],
            ],
            'indexes' => [
                [
                    'purpose' => 'priority_ordered_dispatch',
                    'columns' => ['queue', 'status', 'priority', 'available_at'],
                ],
                [
                    'purpose' => 'observability_by_class',
                    'columns' => ['queue', 'status', 'fairness_key'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function dispatch(): array
    {
        return [
            'poll_order' => 'priority_asc_then_available_at_asc_then_id',
            'fairness_reorder_scope' => 'within_priority_tier_only',
            'fairness_algorithm' => 'deficit_round_robin_by_recent_dispatch_score_over_weight',
            'fairness_state_half_life_seconds' => 30,
            'workflow_and_activity_buckets_isolated' => true,
            'fairness_state_contract' => 'Workflow\\V2\\Support\\TaskFairnessState',
            'fairness_scheduler' => 'Workflow\\V2\\Support\\TaskFairnessScheduler',
            'urgency_wins_over_fairness' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function observability(): array
    {
        return [
            'route' => self::OBSERVABILITY_ROUTE,
            'method' => 'GET',
            'separates_workflow_and_activity' => true,
            'response_shape' => [
                'queue' => 'string',
                'workflow_task' => 'priority_fairness_surface',
                'activity_task' => 'priority_fairness_surface',
            ],
            'priority_fairness_surface_shape' => [
                'ready_tasks' => 'integer',
                'priority_tiers' => 'list<{priority:integer,count:integer,classes:list<{fairness_key:string|null,count:integer,fairness_weight:integer}>}>',
                'recent_dispatch' => 'list<{fairness_key:string|null,score:number}>',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function authoringApis(): array
    {
        return [
            'start_options' => 'Workflow\\V2\\StartOptions',
            'activity_options' => 'Workflow\\V2\\Support\\ActivityOptions',
            'priority_normalizer' => 'Workflow\\V2\\Support\\TaskPriority',
            'fairness_key_normalizer' => 'Workflow\\V2\\Support\\TaskFairnessKey',
        ];
    }
}
