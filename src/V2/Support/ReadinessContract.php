<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

final class ReadinessContract
{
    public const VERSION = 1;

    /**
     * @return array<string, mixed>
     */
    public static function definition(): array
    {
        return [
            'version' => self::VERSION,
            'release_state' => 'v2_final_contract',
            'engine_source_modes' => [
                'auto' => [
                    'when_v2_operator_surface_available' => [
                        'resolved' => 'v2',
                        'uses_v2' => true,
                        'stats' => 'v2_operator_metrics',
                        'health' => 'v2_health_checks',
                        'instance_routes' => 'v2_instance_contract',
                    ],
                    'when_v2_operator_surface_missing' => [
                        'resolved' => 'v1',
                        'uses_v2' => false,
                        'stats' => 'legacy_stats_with_engine_source_diagnostics',
                        'health_http_status' => 503,
                        'instance_routes' => 'not_found',
                    ],
                ],
                'v1' => [
                    'resolved' => 'v1',
                    'uses_v2' => false,
                    'stats' => 'legacy_stats_with_engine_source_diagnostics',
                    'health_http_status' => 503,
                    'instance_routes' => 'not_found',
                ],
                'v2' => [
                    'when_v2_operator_surface_available' => [
                        'resolved' => 'v2',
                        'uses_v2' => true,
                        'stats' => 'v2_operator_metrics',
                        'health' => 'v2_health_checks',
                        'instance_routes' => 'v2_instance_contract',
                    ],
                    'when_v2_operator_surface_missing' => [
                        'resolved' => 'v2',
                        'uses_v2' => false,
                        'stats_http_status' => 503,
                        'health_http_status' => 503,
                        'instance_routes_http_status' => 503,
                    ],
                ],
            ],
            'surfaces' => [
                'boot_install' => [
                    'authority' => WaterlineEngineSource::class . '::status',
                    'readiness_key' => 'v2_operator_surface_available',
                    'gate' => 'Every configured v2 operator model resolves to a readable table.',
                    'unready_behavior' => 'auto resolves to v1; v2 pinned remains resolved to v2 but uses_v2=false and Waterline returns 503.',
                ],
                'dispatch' => [
                    'authority' => BackendCapabilities::class . '::snapshot',
                    'readiness_key' => 'supported',
                    'gate' => 'No database, queue, cache, codec, or structural-limit issue has error severity.',
                    'unready_behavior' => 'Task dispatch does not claim unsupported durable work; operators see backend_capabilities errors.',
                ],
                'claim' => [
                    'authority' => TaskBackendCapabilities::class . '::recordClaimFailureIfUnsupported',
                    'readiness_key' => 'last_claim_failed_at',
                    'gate' => 'The task queue connection satisfies the backend capability snapshot at claim time.',
                    'unready_behavior' => 'The task remains ready, claim failure metadata is persisted, and repair backoff is scheduled.',
                ],
                'stats' => [
                    'authority' => OperatorMetrics::class . '::snapshot',
                    'readiness_key' => 'engine_source.uses_v2',
                    'gate' => 'Waterline is actively using v2 before v2 operator_metrics are trusted.',
                    'unready_behavior' => 'Legacy stats stay available for auto/v1; v2 pinned and unavailable returns 503 with engine_source diagnostics.',
                ],
                'health' => [
                    'authority' => HealthCheck::class . '::snapshot',
                    'readiness_key' => 'engine_source.uses_v2',
                    'gate' => 'Waterline is actively using v2, then HealthCheck decides ok, warning, or error from backend and projection checks.',
                    'unready_behavior' => 'GET /waterline/api/v2/health returns 503 when Waterline is not actively using v2.',
                ],
            ],
            'backend_capabilities' => [
                'database' => [
                    'supported_drivers' => ['mysql', 'pgsql', 'sqlite', 'sqlsrv'],
                    'required_capabilities' => ['transactions', 'after_commit_callbacks', 'durable_ordering'],
                    'sqlite_note' => 'SQLite is supported with limited concurrent write safety and no row-lock capability.',
                ],
                'queue' => [
                    'queue_mode' => [
                        'requires_async_delivery' => true,
                        'sync_or_missing_queue_severity' => 'error',
                    ],
                    'poll_mode' => [
                        'requires_async_delivery' => false,
                        'sync_or_missing_queue_severity' => 'info',
                    ],
                ],
                'cache' => [
                    'required_capabilities' => ['atomic_locks'],
                ],
                'codec' => [
                    'default_for_new_v2_runs' => 'avro',
                    'legacy_php_codecs' => 'warning_for_v1_drain_or_import_reads',
                    'unknown_codec' => 'error',
                ],
                'structural_limits' => [
                    'authority' => StructuralLimits::class . '::snapshot',
                    'backend_adjustments' => ['sqs_max_delay_seconds', 'sqlite_concurrent_write_safety'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function forBackendCapabilities(): array
    {
        $contract = self::definition();
        $contract['effective_states'] = [
            'dispatch' => [
                'state' => 'evaluated_by_backend_capabilities_snapshot',
                'blocking_rule' => 'Any error-severity backend issue makes supported=false.',
            ],
            'claim' => [
                'state' => 'evaluated_per_task_queue_connection',
                'blocking_rule' => 'Unsupported per-task queue/backend capability records a claim failure and keeps the task ready.',
            ],
        ];

        return $contract;
    }

    /**
     * @return array<string, mixed>
     */
    public static function forEngineSourceStatus(
        string $configured,
        string $resolved,
        bool $usesV2,
        bool $v2OperatorSurfaceAvailable,
    ): array {
        $contract = self::definition();
        $contract['effective_states'] = [
            'boot_install' => [
                'ready' => $v2OperatorSurfaceAvailable,
                'state' => $v2OperatorSurfaceAvailable
                    ? 'v2_operator_surface_available'
                    : ($configured === WaterlineEngineSource::ENGINE_AUTO
                        ? 'auto_fallback_to_v1'
                        : 'v2_operator_surface_unavailable'),
            ],
            'stats' => [
                'ready' => $usesV2 || $configured !== WaterlineEngineSource::ENGINE_V2,
                'state' => self::statsState($configured, $usesV2),
            ],
            'health' => [
                'ready' => $usesV2,
                'state' => $usesV2 ? 'delegates_to_v2_health_check' : 'unavailable_503',
                'http_status_when_requested' => $usesV2 ? 'derived_from_health_checks' : 503,
            ],
            'instance_routes' => [
                'ready' => $usesV2,
                'state' => self::instanceRouteState($configured, $resolved, $usesV2),
            ],
        ];

        return $contract;
    }

    private static function statsState(string $configured, bool $usesV2): string
    {
        if ($usesV2) {
            return 'v2_operator_metrics';
        }

        if ($configured === WaterlineEngineSource::ENGINE_V2) {
            return 'unavailable_503';
        }

        return 'legacy_stats_with_engine_source_diagnostics';
    }

    private static function instanceRouteState(string $configured, string $resolved, bool $usesV2): string
    {
        if ($usesV2) {
            return 'v2_instance_contract';
        }

        if ($configured === WaterlineEngineSource::ENGINE_V2 && $resolved === WaterlineEngineSource::ENGINE_V2) {
            return 'unavailable_503';
        }

        return 'not_found';
    }
}
