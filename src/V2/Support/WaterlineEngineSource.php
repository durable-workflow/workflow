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
        return match (self::normalize($configured)) {
            self::ENGINE_V1 => self::ENGINE_V1,
            self::ENGINE_V2 => self::ENGINE_V2,
            default => self::v2OperatorSurfaceAvailable() ? self::ENGINE_V2 : self::ENGINE_V1,
        };
    }

    public static function v2OperatorSurfaceAvailable(): bool
    {
        foreach (self::requiredModelClasses() as $modelClass) {
            if (! self::tableExistsForModel($modelClass)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<class-string<Model>>
     */
    private static function requiredModelClasses(): array
    {
        return [
            ConfiguredV2Models::resolve('instance_model', WorkflowInstance::class),
            ConfiguredV2Models::resolve('run_model', WorkflowRun::class),
            ConfiguredV2Models::resolve('history_event_model', WorkflowHistoryEvent::class),
            ConfiguredV2Models::resolve('task_model', WorkflowTask::class),
            ConfiguredV2Models::resolve('command_model', WorkflowCommand::class),
            ConfiguredV2Models::resolve('link_model', WorkflowLink::class),
            ConfiguredV2Models::resolve('activity_execution_model', ActivityExecution::class),
            ConfiguredV2Models::resolve('activity_attempt_model', ActivityAttempt::class),
            ConfiguredV2Models::resolve('timer_model', WorkflowTimer::class),
            ConfiguredV2Models::resolve('failure_model', WorkflowFailure::class),
            ConfiguredV2Models::resolve('run_summary_model', WorkflowRunSummary::class),
            ConfiguredV2Models::resolve('run_wait_model', WorkflowRunWait::class),
            ConfiguredV2Models::resolve('run_timeline_entry_model', WorkflowTimelineEntry::class),
            ConfiguredV2Models::resolve('run_timer_entry_model', WorkflowRunTimerEntry::class),
            ConfiguredV2Models::resolve('run_lineage_entry_model', WorkflowRunLineageEntry::class),
            WorkflowSignal::class,
            WorkflowUpdate::class,
        ];
    }

    /**
     * @param class-string<Model> $modelClass
     */
    private static function tableExistsForModel(string $modelClass): bool
    {
        if (! is_a($modelClass, Model::class, true)) {
            return false;
        }

        try {
            $model = new $modelClass();
            $table = $model->getTable();

            if (! is_string($table) || trim($table) === '') {
                return false;
            }

            return DB::connection($model->getConnectionName())
                ->getSchemaBuilder()
                ->hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }

    private static function normalize(string|null $configured): string
    {
        $normalized = strtolower(trim((string) $configured));

        return $normalized === '' ? self::ENGINE_AUTO : $normalized;
    }
}
