<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

final class RoleTopologySnapshot
{
    public const SCHEMA = 'durable-workflow.v2.role-topology';

    public const VERSION = 4;

    private const DEFAULT_EMBEDDED_SHAPE = 'embedded';

    private const SERVER_DEFAULT_SHAPE = 'standalone_server';

    private const SUPPORTED_SHAPES = ['embedded', 'standalone_server', 'split_control_execution'];

    private const ROLE_VOCABULARY = [
        'api_ingress',
        'control_plane',
        'matching',
        'history_projection',
        'scheduler',
        'execution_plane',
    ];

    private const DEFAULT_PROCESS_CLASS_BY_SHAPE = [
        'embedded' => 'application_process',
        'standalone_server' => 'server_http_node',
        'split_control_execution' => 'control_plane_node',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function current(): array
    {
        $shapeAssignments = self::shapeAssignments();
        $currentNode = self::currentNode($shapeAssignments);

        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'supported_shapes' => self::SUPPORTED_SHAPES,
            'role_vocabulary' => self::ROLE_VOCABULARY,
            'current_shape' => $currentNode['shape'],
            'current_process_class' => $currentNode['process_class'],
            'current_roles' => $currentNode['roles'],
            'execution_mode' => self::executionMode(),
            'matching_role' => MatchingRoleSnapshot::current(),
            'role_catalog' => self::roleCatalog($currentNode['roles']),
            'shape_assignments' => $shapeAssignments,
            'authority_boundaries' => self::authorityBoundaries(),
            'authority_surfaces' => self::authoritySurfaces(),
            'failure_domains' => self::failureDomains(),
            'scaling_boundaries' => self::scalingBoundaries(),
            'supported_topologies' => self::supportedTopologies(),
            'migration_path' => self::migrationPath(),
        ];
    }

    /**
     * @param  array<string, array{process_classes: list<array{name: string, roles: list<string>}>}>|null  $shapeAssignments
     * @return array{shape: string, process_class: string, roles: list<string>}
     */
    public static function currentNode(?array $shapeAssignments = null): array
    {
        $shapeAssignments ??= self::shapeAssignments();
        $shape = self::currentShape();
        $processClass = self::currentProcessClass($shape, $shapeAssignments);

        return [
            'shape' => $shape,
            'process_class' => $processClass,
            'roles' => self::rolesForProcessClass($shape, $processClass, $shapeAssignments),
        ];
    }

    private static function executionMode(): string
    {
        $serverMode = config('server.mode');

        if (is_string($serverMode) && $serverMode !== '') {
            return $serverMode === 'embedded'
                ? 'local_queue_worker'
                : 'remote_worker_protocol';
        }

        return config('workflows.v2.task_dispatch_mode') === 'poll'
            ? 'remote_worker_protocol'
            : 'local_queue_worker';
    }

    /**
     * @return array<string, array{
     *     plane: string,
     *     hosted_by_current_node: bool,
     *     runs_user_code: bool,
     *     accepts_external_http: bool,
     *     steady_state_interface: string
     * }>
     */
    private static function roleCatalog(array $currentRoles): array
    {
        return [
            'api_ingress' => [
                'plane' => 'control',
                'hosted_by_current_node' => in_array('api_ingress', $currentRoles, true),
                'runs_user_code' => false,
                'accepts_external_http' => true,
                'steady_state_interface' => 'external_http',
            ],
            'control_plane' => [
                'plane' => 'control',
                'hosted_by_current_node' => in_array('control_plane', $currentRoles, true),
                'runs_user_code' => false,
                'accepts_external_http' => true,
                'steady_state_interface' => 'control_plane_contract',
            ],
            'matching' => [
                'plane' => 'control',
                'hosted_by_current_node' => in_array('matching', $currentRoles, true),
                'runs_user_code' => false,
                'accepts_external_http' => true,
                'steady_state_interface' => 'worker_poll_and_repair',
            ],
            'history_projection' => [
                'plane' => 'control',
                'hosted_by_current_node' => in_array('history_projection', $currentRoles, true),
                'runs_user_code' => false,
                'accepts_external_http' => false,
                'steady_state_interface' => 'projection_writer',
            ],
            'scheduler' => [
                'plane' => 'control',
                'hosted_by_current_node' => in_array('scheduler', $currentRoles, true),
                'runs_user_code' => false,
                'accepts_external_http' => false,
                'steady_state_interface' => 'schedule_runner',
            ],
            'execution_plane' => [
                'plane' => 'execution',
                'hosted_by_current_node' => in_array('execution_plane', $currentRoles, true),
                'runs_user_code' => true,
                'accepts_external_http' => false,
                'steady_state_interface' => 'worker_protocol',
            ],
        ];
    }

    private static function currentShape(): string
    {
        $shape = config('server.topology.shape');

        if (is_string($shape) && in_array($shape, self::SUPPORTED_SHAPES, true)) {
            return $shape;
        }

        return self::DEFAULT_EMBEDDED_SHAPE;
    }

    /**
     * @param  array<string, array{process_classes: list<array{name: string, roles: list<string>}>}>  $shapeAssignments
     */
    private static function currentProcessClass(string $shape, array $shapeAssignments): string
    {
        $processClass = config('server.topology.process_class');

        if (is_string($processClass) && $processClass !== '') {
            $roles = self::rolesForProcessClass($shape, $processClass, $shapeAssignments);

            if ($roles !== []) {
                return $processClass;
            }
        }

        return self::DEFAULT_PROCESS_CLASS_BY_SHAPE[$shape]
            ?? self::DEFAULT_PROCESS_CLASS_BY_SHAPE[self::SERVER_DEFAULT_SHAPE];
    }

    /**
     * @param  array<string, array{process_classes: list<array{name: string, roles: list<string>}>}>  $shapeAssignments
     * @return list<string>
     */
    private static function rolesForProcessClass(string $shape, string $processClass, array $shapeAssignments): array
    {
        $shapeConfig = $shapeAssignments[$shape] ?? null;

        if (! is_array($shapeConfig)) {
            return [];
        }

        foreach ($shapeConfig['process_classes'] as $class) {
            if (($class['name'] ?? null) !== $processClass) {
                continue;
            }

            return array_values(array_filter(
                $class['roles'] ?? [],
                static fn (mixed $role): bool => is_string($role) && $role !== '',
            ));
        }

        return [];
    }

    /**
     * @return array<string, array{
     *     process_classes: list<array{name: string, roles: list<string>}>
     * }>
     */
    private static function shapeAssignments(): array
    {
        return [
            'embedded' => [
                'process_classes' => [
                    [
                        'name' => 'application_process',
                        'roles' => [
                            'control_plane',
                            'matching',
                            'history_projection',
                            'scheduler',
                            'execution_plane',
                        ],
                    ],
                ],
            ],
            'standalone_server' => [
                'process_classes' => [
                    [
                        'name' => 'server_http_node',
                        'roles' => ['api_ingress', 'control_plane', 'matching', 'history_projection'],
                    ],
                    [
                        'name' => 'scheduler_node',
                        'roles' => ['scheduler'],
                    ],
                    [
                        'name' => 'worker_node',
                        'roles' => ['execution_plane'],
                    ],
                ],
            ],
            'split_control_execution' => [
                'process_classes' => [
                    [
                        'name' => 'ingress_node',
                        'roles' => ['api_ingress'],
                    ],
                    [
                        'name' => 'control_plane_node',
                        'roles' => ['api_ingress', 'control_plane', 'history_projection'],
                    ],
                    [
                        'name' => 'scheduler_node',
                        'roles' => ['scheduler'],
                    ],
                    [
                        'name' => 'matching_node',
                        'roles' => ['matching'],
                    ],
                    [
                        'name' => 'execution_node',
                        'roles' => ['execution_plane'],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, array{writes: list<string>}>
     */
    private static function authorityBoundaries(): array
    {
        return [
            'control_plane' => [
                'writes' => ['workflow_instances', 'workflow_runs.status', 'workflow_tasks.lifecycle'],
            ],
            'execution_plane' => [
                'writes' => ['workflow_tasks.outcomes', 'activity_attempts', 'worker_compatibility_heartbeats'],
            ],
            'matching' => [
                'writes' => ['workflow_tasks.leases', 'activity_tasks.leases'],
            ],
            'history_projection' => [
                'writes' => ['history_events', 'workflow_run_summaries', 'workflow_history_exports'],
            ],
            'scheduler' => [
                'writes' => ['workflow_schedules.fire_state', 'workflow_starts.scheduled'],
            ],
            'api_ingress' => [
                'writes' => ['worker_registrations'],
            ],
        ];
    }

    /**
     * @return array<string, array{mutations: array<string, array{owning_roles: list<string>, read_roles: list<string>}>}>
     */
    private static function authoritySurfaces(): array
    {
        return [
            'workflow_instances' => [
                'mutations' => [
                    'status_transitions' => [
                        'owning_roles' => ['control_plane'],
                        'read_roles' => ['history_projection', 'api_ingress'],
                    ],
                ],
            ],
            'workflow_runs' => [
                'mutations' => [
                    'status_transitions' => [
                        'owning_roles' => ['control_plane'],
                        'read_roles' => ['history_projection', 'api_ingress'],
                    ],
                ],
            ],
            'workflow_tasks' => [
                'mutations' => [
                    'create_retire' => [
                        'owning_roles' => ['control_plane', 'history_projection'],
                        'read_roles' => ['matching', 'execution_plane'],
                    ],
                    'lease_claim_release' => [
                        'owning_roles' => ['matching'],
                        'read_roles' => ['execution_plane', 'control_plane'],
                    ],
                ],
            ],
            'activity_executions' => [
                'mutations' => [
                    'create' => [
                        'owning_roles' => ['control_plane'],
                        'read_roles' => ['history_projection'],
                    ],
                    'outcomes' => [
                        'owning_roles' => ['execution_plane'],
                        'read_roles' => ['history_projection'],
                    ],
                ],
            ],
            'activity_attempts' => [
                'mutations' => [
                    'create' => [
                        'owning_roles' => ['control_plane'],
                        'read_roles' => ['history_projection'],
                    ],
                    'outcomes' => [
                        'owning_roles' => ['execution_plane'],
                        'read_roles' => ['history_projection'],
                    ],
                ],
            ],
            'history_events' => [
                'mutations' => [
                    'record' => [
                        'owning_roles' => ['history_projection'],
                        'read_roles' => ['control_plane', 'execution_plane', 'api_ingress'],
                    ],
                ],
            ],
            'run_summaries' => [
                'mutations' => [
                    'project' => [
                        'owning_roles' => ['history_projection'],
                        'read_roles' => ['control_plane', 'matching', 'api_ingress'],
                    ],
                ],
            ],
            'workflow_schedules' => [
                'mutations' => [
                    'crud' => [
                        'owning_roles' => ['control_plane'],
                        'read_roles' => ['api_ingress'],
                    ],
                    'fire' => [
                        'owning_roles' => ['scheduler'],
                        'read_roles' => ['api_ingress'],
                    ],
                ],
            ],
            'worker_compatibility_heartbeats' => [
                'mutations' => [
                    'heartbeat' => [
                        'owning_roles' => ['execution_plane'],
                        'read_roles' => ['matching', 'history_projection', 'api_ingress'],
                    ],
                ],
            ],
            'worker_registrations' => [
                'mutations' => [
                    'register_heartbeat' => [
                        'owning_roles' => ['api_ingress'],
                        'read_roles' => ['matching', 'control_plane', 'history_projection'],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, array{
     *     effect: string,
     *     operator_signal: string
     * }>
     */
    private static function failureDomains(): array
    {
        return [
            'control_plane_down' => [
                'effect' => 'workers_continue_claimed_tasks_only_until_lease_expiry',
                'operator_signal' => 'operator_commands_fail_fast',
            ],
            'execution_plane_down' => [
                'effect' => 'ready_tasks_accumulate_without_loss',
                'operator_signal' => 'operators_see_ready_depth_growth',
            ],
            'matching_down' => [
                'effect' => 'claim_falls_back_to_direct_ready_task_discovery',
                'operator_signal' => 'ready_depth_rises_while_claim_rate_falls',
            ],
            'history_projection_down' => [
                'effect' => 'projection_reads_may_stale_while_durable_writes_continue',
                'operator_signal' => 'projection_lag_seconds_may_increase',
            ],
            'scheduler_down' => [
                'effect' => 'scheduled_workflows_stop_firing_and_record_missed_runs',
                'operator_signal' => 'operators_see_missed_schedule_state',
            ],
            'api_ingress_down' => [
                'effect' => 'external_http_traffic_stops_at_the_edge',
                'operator_signal' => 'embedded_in_process_calls_may_continue',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function scalingBoundaries(): array
    {
        return [
            'api_ingress' => 'incoming_http_request_rate',
            'control_plane' => 'operator_commands_and_run_lifecycle_transitions',
            'matching' => 'ready_task_rate_and_poller_count',
            'history_projection' => 'durable_event_rate',
            'scheduler' => 'active_schedule_count',
            'execution_plane' => 'workflow_and_activity_task_rate',
        ];
    }

    /**
     * @return array<string, array{
     *     execution_mode: string,
     *     process_classes: array<string, array{roles: list<string>}>
     * }>
     */
    private static function supportedTopologies(): array
    {
        return [
            'embedded' => [
                'execution_mode' => 'local_queue_worker',
                'process_classes' => [
                    'application_process' => [
                        'roles' => [
                            'control_plane',
                            'matching',
                            'history_projection',
                            'scheduler',
                            'execution_plane',
                        ],
                    ],
                ],
            ],
            'standalone_server' => [
                'execution_mode' => 'remote_worker_protocol',
                'process_classes' => [
                    'server_http_node' => [
                        'roles' => ['api_ingress', 'control_plane', 'matching', 'history_projection'],
                    ],
                    'scheduler_node' => [
                        'roles' => ['scheduler'],
                    ],
                    'worker_node' => [
                        'roles' => ['execution_plane'],
                    ],
                ],
            ],
            'split_control_execution' => [
                'execution_mode' => 'remote_worker_protocol',
                'process_classes' => [
                    'ingress_node' => [
                        'roles' => ['api_ingress'],
                    ],
                    'control_plane_node' => [
                        'roles' => ['api_ingress', 'control_plane', 'history_projection'],
                    ],
                    'scheduler_node' => [
                        'roles' => ['scheduler'],
                    ],
                    'matching_node' => [
                        'roles' => ['matching'],
                    ],
                    'execution_node' => [
                        'roles' => ['execution_plane'],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return list<array{
     *     step: string,
     *     result: string
     * }>
     */
    private static function migrationPath(): array
    {
        return [
            [
                'step' => 'audit_role_boundaries',
                'result' => 'tooling flags cross-role writes before runtime shape changes',
            ],
            [
                'step' => 'expose_role_bindings',
                'result' => 'container seams allow out-of-process adapters without patching the package',
            ],
            [
                'step' => 'introduce_dedicated_matching_shape',
                'result' => 'matching can run as its own process class without changing the claim contract',
            ],
            [
                'step' => 'split_history_projection',
                'result' => 'history and projections can move out of process without introducing a second writer',
            ],
            [
                'step' => 'split_scheduler',
                'result' => 'schedule firing can move behind leader election while single-replica deployments stay legal',
            ],
            [
                'step' => 'optional_execution_partitioning',
                'result' => 'workers can partition by namespace, connection, queue, and compatibility',
            ],
        ];
    }
}
