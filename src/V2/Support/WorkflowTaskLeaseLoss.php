<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Facades\Log;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Exceptions\WorkflowTaskLeaseLostException;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

/**
 * Workflow tasks that lose their lease without completing (#1231).
 *
 * A queue worker that kills a workflow task at its job timeout rolls back the
 * task's work. The task stays leased until its lease expires, task repair
 * returns it to ready, and the next worker repeats the same work. Without a
 * record of the lost leases a run that can never finish inside the worker's
 * timeout retried forever with no diagnosis.
 *
 * Task repair counts each lost lease on the task. The next claim records a
 * typed diagnostic. After the attention threshold, the run summary reports
 * repair_blocked_reason {@see self::CODE} with repair attention. Failing the
 * run instead is opt-in. None of this writes history or changes how a run
 * that completes its task behaves.
 */
final class WorkflowTaskLeaseLoss
{
    public const CODE = 'workflow_task_lease_lost';

    public const CONFIG_KEY = 'workflows.v2.workflow_task_lost_leases';

    public const DEFAULT_ATTENTION_THRESHOLD = 3;

    /**
     * @var array<string, bool>
     */
    private static array $trackedSchemas = [];

    public static function attentionThreshold(): int
    {
        $value = config(self::CONFIG_KEY . '.attention_threshold', self::DEFAULT_ATTENTION_THRESHOLD);

        return is_numeric($value) && (int) $value >= 1
            ? (int) $value
            : self::DEFAULT_ATTENTION_THRESHOLD;
    }

    public static function failsRun(): bool
    {
        return (bool) config(self::CONFIG_KEY . '.fail_run', false);
    }

    public static function lostLeases(WorkflowTask $task): int
    {
        return max(0, (int) ($task->getAttribute('lost_lease_count') ?? 0));
    }

    /**
     * Count one lost lease on a workflow task that task repair returns to
     * ready. A schema without the tracking columns keeps the old behavior.
     *
     * @return array<string, mixed>
     */
    public static function repairedAttributes(WorkflowTask $task): array
    {
        if ($task->task_type !== TaskType::Workflow
            || ! array_key_exists('lost_lease_count', $task->getAttributes())) {
            return [];
        }

        return [
            'lost_lease_count' => self::lostLeases($task) + 1,
            'last_lease_lost_at' => now(),
        ];
    }

    /**
     * The typed diagnostic for a claim that retries a task after lost leases,
     * or null for a task that never lost one.
     *
     * @return array{
     *     code: string,
     *     workflow_run_id: string,
     *     workflow_task_id: string,
     *     lost_leases: int,
     *     attention_threshold: int,
     *     needs_attention: bool,
     *     last_lease_lost_at: string|null,
     *     workflow_task_lease_seconds: int,
     *     claim_attempt: int
     * }|null
     */
    public static function claimDiagnostic(WorkflowTask $task): ?array
    {
        $lostLeases = self::lostLeases($task);

        if ($task->task_type !== TaskType::Workflow || $lostLeases === 0) {
            return null;
        }

        $lastLostAt = $task->getAttribute('last_lease_lost_at');

        return [
            'code' => self::CODE,
            'workflow_run_id' => (string) $task->workflow_run_id,
            'workflow_task_id' => (string) $task->id,
            'lost_leases' => $lostLeases,
            'attention_threshold' => self::attentionThreshold(),
            'needs_attention' => $lostLeases >= self::attentionThreshold(),
            'last_lease_lost_at' => $lastLostAt instanceof \DateTimeInterface
                ? $lastLostAt->format('Y-m-d\TH:i:s.up')
                : null,
            'workflow_task_lease_seconds' => WorkflowTaskLease::seconds(),
            'claim_attempt' => (int) $task->attempt_count,
        ];
    }

    /**
     * Report a claim that retries a task after lost leases.
     *
     * @param array<string, mixed> $diagnostic
     */
    public static function reportClaim(array $diagnostic): void
    {
        Log::warning(
            sprintf(
                '[Durable Workflow] Workflow task %s lost its lease %d time(s) without completing; '
                . 'its queue worker may be killing it at the job timeout. Run workers with '
                . '--timeout=0 or above your longest workflow task.',
                $diagnostic['workflow_task_id'],
                $diagnostic['lost_leases'],
            ),
            $diagnostic,
        );
    }

    /**
     * Whether a claimed workflow task must fail its run instead of executing.
     */
    public static function failsRunAt(WorkflowTask $task): bool
    {
        return self::failsRun()
            && $task->task_type === TaskType::Workflow
            && self::lostLeases($task) >= self::attentionThreshold();
    }

    public static function exceptionFor(WorkflowTask $task): WorkflowTaskLeaseLostException
    {
        return new WorkflowTaskLeaseLostException(
            (string) $task->id,
            self::lostLeases($task),
            WorkflowTaskLease::seconds(),
        );
    }

    /**
     * Whether the run's open workflow task has reached the attention threshold.
     */
    public static function runNeedsAttention(WorkflowRun $run): bool
    {
        $threshold = self::attentionThreshold();

        if ($run->relationLoaded('tasks')) {
            foreach ($run->tasks as $task) {
                if ($task instanceof WorkflowTask
                    && $task->task_type === TaskType::Workflow
                    && in_array($task->status, [TaskStatus::Ready, TaskStatus::Leased], true)
                    && self::lostLeases($task) >= $threshold) {
                    return true;
                }
            }

            return false;
        }

        if (! $run->exists) {
            return false;
        }

        if (! self::tracked()) {
            return false;
        }

        $query = ConfiguredV2Models::query('task_model', WorkflowTask::class);

        return $query
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Workflow->value)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->where('lost_lease_count', '>=', $threshold)
            ->exists();
    }

    /**
     * Whether the configured task table has the lost-lease columns. A schema
     * that has not run the migration has no lost leases to report.
     */
    public static function tracked(): bool
    {
        $model = ConfiguredV2Models::query('task_model', WorkflowTask::class)->getModel();
        $schemaKey = $model->getConnectionName() . '|' . $model->getTable();

        return self::$trackedSchemas[$schemaKey] ??= $model->getConnection()
            ->getSchemaBuilder()
            ->hasColumn($model->getTable(), 'lost_lease_count');
    }
}
