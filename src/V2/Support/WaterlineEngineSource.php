<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunLineageEntry;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowRunTimerEntry;
use Workflow\V2\Models\WorkflowRunWait;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimelineEntry;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Models\WorkflowUpdate;

final class WaterlineEngineSource
{
    public const ENGINE_AUTO = 'auto';

    public const ENGINE_V1 = 'v1';

    public const ENGINE_V2 = 'v2';

    public static function resolve(string|null $configured = null): string
    {
        /** @var string $resolved */
        $resolved = self::status($configured)['resolved'];

        return $resolved;
    }

    /**
     * @return array{
     *     configured: string,
     *     resolved: string,
     *     uses_v2: bool,
     *     v2_operator_surface_available: bool,
     *     status: string,
     *     severity: string,
     *     message: string,
     *     issues: list<array{
     *         config_key: string|null,
     *         model: string,
     *         connection: string|null,
     *         table: string|null,
     *         reason: string,
     *         message: string
     *     }>,
     *     required_tables: list<array{
     *         config_key: string|null,
     *         model: string,
     *         connection: string|null,
     *         table: string|null,
     *         available: bool
     *     }>
     * }
     */
    public static function status(string|null $configured = null): array
    {
        $configured = self::normalize($configured);
        $inspection = self::inspectV2OperatorSurface();
        $resolved = match ($configured) {
            self::ENGINE_V1 => self::ENGINE_V1,
            self::ENGINE_V2 => self::ENGINE_V2,
            default => $inspection['available'] ? self::ENGINE_V2 : self::ENGINE_V1,
        };
        $usesV2 = $resolved === self::ENGINE_V2 && $inspection['available'] === true;
        $summary = self::summary(
            configured: $configured,
            resolved: $resolved,
            v2OperatorSurfaceAvailable: $inspection['available'],
        );

        return [
            'configured' => $configured,
            'resolved' => $resolved,
            'uses_v2' => $usesV2,
            'v2_operator_surface_available' => $inspection['available'],
            'status' => $summary['status'],
            'severity' => $summary['severity'],
            'message' => $summary['message'],
            'issues' => $inspection['issues'],
            'required_tables' => $inspection['required_tables'],
        ];
    }

    public static function v2OperatorSurfaceAvailable(): bool
    {
        return self::inspectV2OperatorSurface()['available'];
    }

    /**
     * @return list<array{config_key: string|null, model: string}>
     */
    private static function requiredModelClasses(): array
    {
        return [
            [
                'config_key' => 'instance_model',
                'model' => ConfiguredV2Models::resolve('instance_model', WorkflowInstance::class),
            ],
            [
                'config_key' => 'run_model',
                'model' => ConfiguredV2Models::resolve('run_model', WorkflowRun::class),
            ],
            [
                'config_key' => 'history_event_model',
                'model' => ConfiguredV2Models::resolve('history_event_model', WorkflowHistoryEvent::class),
            ],
            [
                'config_key' => 'task_model',
                'model' => ConfiguredV2Models::resolve('task_model', WorkflowTask::class),
            ],
            [
                'config_key' => 'command_model',
                'model' => ConfiguredV2Models::resolve('command_model', WorkflowCommand::class),
            ],
            [
                'config_key' => 'link_model',
                'model' => ConfiguredV2Models::resolve('link_model', WorkflowLink::class),
            ],
            [
                'config_key' => 'activity_execution_model',
                'model' => ConfiguredV2Models::resolve('activity_execution_model', ActivityExecution::class),
            ],
            [
                'config_key' => 'activity_attempt_model',
                'model' => ConfiguredV2Models::resolve('activity_attempt_model', ActivityAttempt::class),
            ],
            [
                'config_key' => 'timer_model',
                'model' => ConfiguredV2Models::resolve('timer_model', WorkflowTimer::class),
            ],
            [
                'config_key' => 'failure_model',
                'model' => ConfiguredV2Models::resolve('failure_model', WorkflowFailure::class),
            ],
            [
                'config_key' => 'run_summary_model',
                'model' => ConfiguredV2Models::resolve('run_summary_model', WorkflowRunSummary::class),
            ],
            [
                'config_key' => 'run_wait_model',
                'model' => ConfiguredV2Models::resolve('run_wait_model', WorkflowRunWait::class),
            ],
            [
                'config_key' => 'run_timeline_entry_model',
                'model' => ConfiguredV2Models::resolve('run_timeline_entry_model', WorkflowTimelineEntry::class),
            ],
            [
                'config_key' => 'run_timer_entry_model',
                'model' => ConfiguredV2Models::resolve('run_timer_entry_model', WorkflowRunTimerEntry::class),
            ],
            [
                'config_key' => 'run_lineage_entry_model',
                'model' => ConfiguredV2Models::resolve('run_lineage_entry_model', WorkflowRunLineageEntry::class),
            ],
            [
                'config_key' => null,
                'model' => WorkflowSignal::class,
            ],
            [
                'config_key' => null,
                'model' => WorkflowUpdate::class,
            ],
        ];
    }

    /**
     * @return array{
     *     available: bool,
     *     issues: list<array{
     *         config_key: string|null,
     *         model: string,
     *         connection: string|null,
     *         table: string|null,
     *         reason: string,
     *         message: string
     *     }>,
     *     required_tables: list<array{
     *         config_key: string|null,
     *         model: string,
     *         connection: string|null,
     *         table: string|null,
     *         available: bool
     *     }>
     * }
     */
    private static function inspectV2OperatorSurface(): array
    {
        $requiredTables = [];
        $issues = [];

        foreach (self::requiredModelClasses() as $definition) {
            $inspection = self::inspectModel($definition);
            $requiredTables[] = [
                'config_key' => $inspection['config_key'],
                'model' => $inspection['model'],
                'connection' => $inspection['connection'],
                'table' => $inspection['table'],
                'available' => $inspection['available'],
            ];

            if ($inspection['available']) {
                continue;
            }

            $issues[] = [
                'config_key' => $inspection['config_key'],
                'model' => $inspection['model'],
                'connection' => $inspection['connection'],
                'table' => $inspection['table'],
                'reason' => $inspection['reason'],
                'message' => $inspection['message'],
            ];
        }

        return [
            'available' => $issues === [],
            'issues' => $issues,
            'required_tables' => $requiredTables,
        ];
    }

    /**
     * @param array{config_key: string|null, model: string} $definition
     * @return array{
     *     config_key: string|null,
     *     model: string,
     *     connection: string|null,
     *     table: string|null,
     *     available: bool,
     *     reason: string,
     *     message: string
     * }
     */
    private static function inspectModel(array $definition): array
    {
        $modelClass = $definition['model'];

        if (! is_a($modelClass, Model::class, true)) {
            return [
                'config_key' => $definition['config_key'],
                'model' => $modelClass,
                'connection' => null,
                'table' => null,
                'available' => false,
                'reason' => 'invalid_model_class',
                'message' => sprintf(
                    'The configured v2 model [%s] does not extend %s.',
                    $modelClass,
                    Model::class,
                ),
            ];
        }

        try {
            $model = new $modelClass();
            $table = $model->getTable();
            $connection = $model->getConnectionName();

            if (! is_string($table) || trim($table) === '') {
                return [
                    'config_key' => $definition['config_key'],
                    'model' => $modelClass,
                    'connection' => $connection,
                    'table' => null,
                    'available' => false,
                    'reason' => 'missing_table_name',
                    'message' => sprintf(
                        'The configured v2 model [%s] did not resolve a table name.',
                        $modelClass,
                    ),
                ];
            }

            $table = trim($table);
            $available = DB::connection($connection)
                ->getSchemaBuilder()
                ->hasTable($table);

            return [
                'config_key' => $definition['config_key'],
                'model' => $modelClass,
                'connection' => $connection,
                'table' => $table,
                'available' => $available,
                'reason' => $available ? 'available' : 'missing_table',
                'message' => $available
                    ? sprintf('The configured v2 table [%s] is available for model [%s].', $table, $modelClass)
                    : sprintf('The configured v2 table [%s] is missing for model [%s].', $table, $modelClass),
            ];
        } catch (Throwable $exception) {
            return [
                'config_key' => $definition['config_key'],
                'model' => $modelClass,
                'connection' => isset($connection) && is_string($connection) ? $connection : null,
                'table' => isset($table) && is_string($table) && trim($table) !== '' ? trim($table) : null,
                'available' => false,
                'reason' => 'schema_inspection_failed',
                'message' => sprintf(
                    'Waterline could not inspect the configured v2 model [%s]: %s',
                    $modelClass,
                    $exception->getMessage(),
                ),
            ];
        }
    }

    private static function normalize(string|null $configured): string
    {
        $normalized = strtolower(trim((string) $configured));

        return $normalized === '' ? self::ENGINE_AUTO : $normalized;
    }

    /**
     * @return array{status: string, severity: string, message: string}
     */
    private static function summary(
        string $configured,
        string $resolved,
        bool $v2OperatorSurfaceAvailable,
    ): array {
        if ($configured === self::ENGINE_V1) {
            return [
                'status' => 'v1_pinned',
                'severity' => 'ok',
                'message' => 'Waterline is pinned to the legacy v1 workflow tables.',
            ];
        }

        if ($resolved === self::ENGINE_V2 && $v2OperatorSurfaceAvailable) {
            return [
                'status' => $configured === self::ENGINE_V2 ? 'v2_pinned' : 'v2_auto',
                'severity' => 'ok',
                'message' => $configured === self::ENGINE_V2
                    ? 'Waterline is pinned to the v2 operator bridge and the required workflow tables are available.'
                    : 'Waterline auto-detected the v2 operator bridge because the required workflow tables are available.',
            ];
        }

        if ($configured === self::ENGINE_V2) {
            return [
                'status' => 'v2_pinned_unavailable',
                'severity' => 'error',
                'message' => 'Waterline is pinned to the v2 operator bridge, but the required workflow operator tables are missing or unreadable.',
            ];
        }

        return [
            'status' => 'auto_fallback_to_v1',
            'severity' => 'warning',
            'message' => 'Waterline engine_source=auto fell back to the legacy v1 workflow tables because the v2 operator surface is incomplete.',
        ];
    }
}
