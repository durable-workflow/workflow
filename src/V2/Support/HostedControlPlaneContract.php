<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

/**
 * Canonical, machine-readable mirror of the hosted control-plane contract
 * documented in `docs/architecture/hosted-control-plane.md`.
 *
 * This contract freezes the semantic ladder from embedded package to
 * standalone server to managed cloud. Hosted control planes and standalone
 * servers that expose managed-cloud semantics re-export this manifest under
 * `hosted_control_plane_contract` in their discovery surface.
 *
 * Adding a tenant hierarchy level, endpoint class, quota refusal reason,
 * provider admin action, connectivity mode, or audit/export boundary is a
 * contract change. Bump VERSION and align the doc page in the same change.
 * Removing or renaming any published value is a major change.
 *
 * @api Stable class surface consumed by standalone or hosted control planes
 * that need a package-owned managed-cloud readiness manifest.
 */
final class HostedControlPlaneContract
{
    public const SCHEMA = 'durable-workflow.v2.hosted-control-plane.contract';

    public const VERSION = 1;

    public const AUTHORITY_DOC = 'https://github.com/durable-workflow/workflow/blob/v2/docs/architecture/hosted-control-plane.md';

    public const PROTOCOL_HEADER = 'X-Durable-Workflow-Hosted-Control-Plane-Version';

    public const TENANT_HIERARCHY = [
        'organization',
        'project',
        'environment',
        'namespace',
    ];

    public const ENDPOINT_CLASSES = [
        'hosted_control_plane',
        'runtime_namespace_endpoint',
        'runtime_worker_endpoint',
    ];

    public const CONNECTIVITY_MODES = [
        'direct_runtime',
        'customer_private_network',
        'provider_managed_workers',
        'cloud_relay',
    ];

    public const QUOTA_REFUSAL_REASONS = [
        'quota_exceeded',
        'metering_unavailable',
        'fair_share_throttled',
        'tenant_suspended',
        'residency_blocked',
    ];

    public const PROVIDER_ADMIN_ACTIONS = [
        'provision_namespace',
        'attach_runtime_target',
        'move_namespace_target',
        'suspend_principal',
        'resume_principal',
        'rotate_machine_identity',
        'change_quota_budget',
        'export_audit_log',
        'support_access_session',
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
            'protocol_header' => self::PROTOCOL_HEADER,
            'deployment_ladder' => self::deploymentLadder(),
            'tenant_hierarchy' => self::tenantHierarchy(),
            'identity_boundary' => self::identityBoundary(),
            'quota_metering_fairness' => self::quotaMeteringFairness(),
            'region_residency_dr' => self::regionResidencyDr(),
            'worker_connectivity_modes' => self::workerConnectivityModes(),
            'endpoint_routing_rules' => self::endpointRoutingRules(),
            'provider_admin_actions' => self::providerAdminActions(),
            'audit_export_boundaries' => self::auditExportBoundaries(),
            'api_lifecycle' => self::apiLifecycle(),
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function tenantHierarchyLevels(): array
    {
        return self::TENANT_HIERARCHY;
    }

    /**
     * @return array<int, string>
     */
    public static function endpointClasses(): array
    {
        return self::ENDPOINT_CLASSES;
    }

    /**
     * @return array<int, string>
     */
    public static function connectivityModes(): array
    {
        return self::CONNECTIVITY_MODES;
    }

    /**
     * @return array<int, string>
     */
    public static function quotaRefusalReasons(): array
    {
        return self::QUOTA_REFUSAL_REASONS;
    }

    /**
     * @return array<int, string>
     */
    public static function providerAdminActionNames(): array
    {
        return self::PROVIDER_ADMIN_ACTIONS;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function deploymentLadder(): array
    {
        return [
            'embedded_package' => [
                'owner' => 'application_host',
                'contract_boundary' => 'php_package_laravel_queue_application_auth',
                'runtime_authority' => true,
                'adds_semantics' => [
                    'workflow_authoring',
                    'local_queue_execution',
                ],
            ],
            'standalone_server' => [
                'owner' => 'runtime_operator',
                'contract_boundary' => 'http_control_plane_worker_protocol_namespace_auth_cluster_info',
                'runtime_authority' => true,
                'adds_semantics' => [
                    'namespace_endpoints',
                    'protocol_headers',
                    'role_topology',
                    'machine_readable_discovery',
                ],
            ],
            'managed_cloud' => [
                'owner' => 'hosted_control_plane_provider_and_runtime_operator',
                'contract_boundary' => 'tenant_hierarchy_runtime_target_inventory_quota_metering_audit_support',
                'runtime_authority' => false,
                'adds_semantics' => [
                    'hosted_identity',
                    'quota_metering',
                    'runtime_target_inventory',
                    'placement',
                    'provider_admin_actions',
                    'hosted_audit',
                ],
                'must_not_change' => [
                    'history_event_wire_formats',
                    'worker_protocol',
                    'lease_rules',
                    'repair_semantics',
                    'workflow_command_outcomes',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function tenantHierarchy(): array
    {
        return [
            'levels' => self::TENANT_HIERARCHY,
            'namespace_role' => 'runtime_execution_boundary',
            'placement_rule' => 'one_namespace_belongs_to_one_runtime_target_at_a_time',
            'runtime_target_identity' => [
                'runtime_target_id',
                'runtime_target_base_url',
                'region',
                'residency_profile',
                'dr_tier',
                'active_placement',
            ],
            'guarantees' => [
                'organization_project_environment_are_hosted_dimensions_not_history_fields',
                'runtime_commands_require_explicit_namespace_identity',
                'copying_runtime_target_inventory_does_not_move_workflow_state',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function identityBoundary(): array
    {
        return [
            'identity_classes' => [
                'hosted_user',
                'service_account',
                'worker_credential',
                'runtime_target_credential',
                'provider_support_actor',
            ],
            'runtime_attribution_fields' => [
                'actor_type',
                'actor_id_or_redacted_subject',
                'capability',
                'target_namespace',
                'target_resource',
                'hosted_audit_id_or_request_fingerprint',
                'command_outcome',
            ],
            'guarantees' => [
                'runtime_credentials_are_role_scoped',
                'provider_support_access_is_disabled_by_default_time_bounded_scoped_and_audited',
                'hosted_identity_may_deny_before_runtime_and_runtime_may_still_deny_after_hosted_allow',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function quotaMeteringFairness(): array
    {
        return [
            'budget_names' => [
                'namespace_command_rate',
                'namespace_worker_poll_rate',
                'task_queue_active_leases',
                'history_export_bytes',
                'storage_bytes',
                'retention_days',
            ],
            'refusal_reasons' => self::QUOTA_REFUSAL_REASONS,
            'refusal_required_fields' => [
                'reason',
                'budget',
                'scope',
                'retry_allowed',
            ],
            'guarantees' => [
                'quota_refusals_are_admission_results_not_history_rewrites',
                'metering_is_not_source_of_truth_for_history_leases_schedules_or_task_completion',
                'fairness_controls_must_preserve_execution_and_matching_guarantees',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function regionResidencyDr(): array
    {
        return [
            'placement_fields' => [
                'runtime_target_id',
                'runtime_target_base_url',
                'region',
                'residency_profile',
                'dr_tier',
                'active_placement',
            ],
            'active_writer_rule' => 'one_active_runtime_target_per_namespace_for_durable_writes',
            'residency_refusal_reason' => 'residency_blocked',
            'deferred_guarantees' => [
                'active_active_multi_region_execution',
                'automatic_cross_region_runtime_failover',
                'rpo_zero_cross_region_replication',
                'region_pinned_task_queues',
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function workerConnectivityModes(): array
    {
        return [
            'direct_runtime' => [
                'self_serve_status' => 'stable',
                'worker_traffic_path' => 'worker_to_runtime_target_base_url',
                'protocol' => 'worker_protocol',
            ],
            'customer_private_network' => [
                'self_serve_status' => 'support_led',
                'worker_traffic_path' => 'worker_to_private_runtime_target_address',
                'protocol' => 'worker_protocol',
            ],
            'provider_managed_workers' => [
                'self_serve_status' => 'support_led',
                'worker_traffic_path' => 'provider_worker_to_runtime_target',
                'protocol' => 'worker_protocol',
            ],
            'cloud_relay' => [
                'self_serve_status' => 'support_led',
                'worker_traffic_path' => 'worker_traffic_relayed_to_runtime_target',
                'protocol' => 'worker_protocol',
                'not_a_correctness_layer' => true,
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function endpointRoutingRules(): array
    {
        return [
            'hosted_control_plane' => [
                'purpose' => 'organization_project_environment_runtime_target_iam_quota_audit_support',
                'namespace_required' => 'only_for_namespace_management_actions',
                'owns_worker_traffic' => false,
            ],
            'runtime_namespace_endpoint' => [
                'purpose' => 'workflow_commands_schedules_repair_visibility_history_export',
                'namespace_required' => 'always',
                'owns_worker_traffic' => false,
            ],
            'runtime_worker_endpoint' => [
                'purpose' => 'worker_register_poll_heartbeat_complete_fail',
                'namespace_required' => 'always',
                'owns_worker_traffic' => true,
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function providerAdminActions(): array
    {
        $actions = [];
        foreach (self::PROVIDER_ADMIN_ACTIONS as $action) {
            $actions[$action] = [
                'audit_required' => true,
                'runtime_history_edit_allowed' => false,
            ];
        }

        $actions['support_access_session']['support_access'] = [
            'default' => 'disabled',
            'scope' => 'named_resources',
            'time_bounded' => true,
        ];

        $actions['rotate_machine_identity']['rotation_rule'] = 'additive_overlap_before_revoke';

        return $actions;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function auditExportBoundaries(): array
    {
        return [
            'hosted_audit_log' => [
                'authority' => 'hosted_control_plane',
                'contains' => [
                    'tenant_hierarchy_changes',
                    'hosted_iam_decisions',
                    'quota_decisions',
                    'provider_admin_actions',
                    'support_sessions',
                    'runtime_target_inventory_changes',
                ],
            ],
            'runtime_history_export' => [
                'authority' => 'runtime_target',
                'contains' => [
                    'workflow_history_events',
                    'command_outcomes',
                    'task_and_activity_facts',
                    'schedules',
                    'memos',
                    'search_attributes',
                    'payload_metadata',
                    'repair_evidence',
                ],
            ],
            'support_bundle' => [
                'authority' => 'hosted_control_plane_and_runtime_target',
                'redacted_by_default' => true,
                'contains' => [
                    'selected_hosted_audit_ids',
                    'runtime_export_references',
                    'diagnostic_summaries',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function apiLifecycle(): array
    {
        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'protocol_header' => self::PROTOCOL_HEADER,
            'additive_minor_changes' => [
                'new_optional_fields',
                'new_routes',
                'new_budget_names',
                'new_provider_admin_actions',
                'new_connectivity_modes',
            ],
            'major_changes' => [
                'remove_or_rename_endpoint_class',
                'remove_or_rename_tenant_hierarchy_level',
                'remove_or_rename_quota_refusal_reason',
                'remove_or_rename_provider_admin_action',
                'remove_or_rename_connectivity_mode',
            ],
            'runtime_protocol_authorities' => [
                'control_plane',
                'worker_protocol',
                SurfaceStabilityContract::SCHEMA,
            ],
            'unknown_field_policy' => 'unknown_required_fields_fail_closed_unknown_diagnostic_fields_are_ignored',
        ];
    }
}
