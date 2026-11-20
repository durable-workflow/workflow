<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Exceptions\WorkflowTaskLeaseLostException;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;

/**
 * Workflow tasks that lose their lease without completing.
 *
 * A worker that is killed, times out or loses its connection while running a
 * workflow task rolls back the task's work. The task stays leased until its
 * lease expires, task repair returns it to ready, and the next worker repeats
 * the same work. Without a record of the lost leases a task that can never
 * complete retried forever with no diagnosis.
 *
 * Task repair counts each lost lease on the task. The next claim logs a typed
 * diagnostic. At the attention threshold the run summary and describe carry
 * the additive `task_attention` field. Repair-blocked reasons and repair
 * actions are unchanged. Failing the run instead is opt-in. None of this
 * writes history or changes how a run whose task completes behaves.
 */
final class WorkflowTaskLeaseLoss
{
    public const CODE = 'workflow_task_lease_lost';

    public const CONFIG_KEY = 'workflows.v2.workflow_task_lost_leases';

    public const DEFAULT_ATTENTION_THRESHOLD = 3;

    /**
     * A failing threshold of one would fail a run on its first lost lease.
     */
    public const MINIMUM_FAILING_THRESHOLD = 2;

    /**
     * Seconds a cached schema answer is trusted before the columns are looked
     * up again, so a process notices a schema rollback.
     */
    private const SCHEMA_RECHECK_SECONDS = 60;

    /**
     * @var array<string, array{tracked: bool, checked_at: int}>
     */
    private static array $schemas = [];

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

    /**
     * Refuse, at boot, a run-failing configuration that would fail a run on
     * too few lost leases.
     */
    public static function validateConfiguration(): void
    {
        if (! self::failsRun()) {
            return;
        }

        $value = config(self::CONFIG_KEY . '.attention_threshold', self::DEFAULT_ATTENTION_THRESHOLD);

        if (! is_numeric($value) || (int) $value < self::MINIMUM_FAILING_THRESHOLD) {
            throw new InvalidArgumentException(sprintf(
                '%s.attention_threshold must be at least %d when %s.fail_run is enabled.',
                self::CONFIG_KEY,
                self::MINIMUM_FAILING_THRESHOLD,
                self::CONFIG_KEY,
            ));
        }
    }

    public static function lostLeases(WorkflowTask $task): int
    {
        return max(0, (int) ($task->getAttribute('lost_lease_count') ?? 0));
    }

    /**
     * Count one lost lease on a workflow task that task repair returns to
     * ready. A task row without the tracking columns keeps the old behavior.
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

        return [
            'code' => self::CODE,
            'workflow_run_id' => (string) $task->workflow_run_id,
            'workflow_task_id' => (string) $task->id,
            'lost_leases' => $lostLeases,
            'attention_threshold' => self::attentionThreshold(),
            'needs_attention' => $lostLeases >= self::attentionThreshold(),
            'last_lease_lost_at' => self::timestamp($task->getAttribute('last_lease_lost_at')),
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
                '[Durable Workflow] Workflow task %s lost its lease %d time(s) without completing '
                . '(its worker was killed, timed out, or lost its connection); retrying it.',
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
            && self::lostLeases($task) >= max(self::MINIMUM_FAILING_THRESHOLD, self::attentionThreshold());
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
     * The run's additive `task_attention` value: its open workflow task's
     * consecutive lost leases once they reach the threshold, otherwise null.
     *
     * @return array{reason: string, consecutive: int, threshold: int, workflow_task_id: string, last_lease_lost_at: string|null}|null
     */
    public static function taskAttention(WorkflowRun $run, ?WorkflowTask $openWorkflowTask = null): ?array
    {
        if ($run->status->isTerminal()) {
            return null;
        }

        $threshold = self::attentionThreshold();
        $task = null;

        if ($openWorkflowTask instanceof WorkflowTask
            && $openWorkflowTask->task_type === TaskType::Workflow
            && in_array($openWorkflowTask->status, [TaskStatus::Ready, TaskStatus::Leased], true)) {
            // A bounded projection already holds the run's open workflow task.
            $task = self::lostLeases($openWorkflowTask) >= $threshold ? $openWorkflowTask : null;
        } elseif ($run->relationLoaded('tasks')) {
            foreach ($run->tasks as $candidate) {
                if ($candidate instanceof WorkflowTask
                    && $candidate->task_type === TaskType::Workflow
                    && in_array($candidate->status, [TaskStatus::Ready, TaskStatus::Leased], true)
                    && self::lostLeases($candidate) >= $threshold
                    && ($task === null || self::lostLeases($candidate) > self::lostLeases($task))) {
                    $task = $candidate;
                }
            }
        } elseif ($run->exists && self::tracked()) {
            $task = self::untrackedOnMissingColumn(
                self::taskModel(),
                static fn (): ?WorkflowTask => ConfiguredV2Models::query('task_model', WorkflowTask::class)
                    ->where('workflow_run_id', $run->id)
                    ->where('task_type', TaskType::Workflow->value)
                    ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
                    ->where('lost_lease_count', '>=', $threshold)
                    ->orderByDesc('lost_lease_count')
                    ->first(),
            );
        }

        if (! $task instanceof WorkflowTask) {
            return null;
        }

        // SQLite reads an unknown double-quoted column name as a string, so a
        // removed column can match instead of failing. A row without the
        // column means the schema was rolled back.
        if (! array_key_exists('lost_lease_count', $task->getAttributes())) {
            self::$schemas = [];

            return null;
        }

        return [
            'reason' => self::CODE,
            'consecutive' => self::lostLeases($task),
            'threshold' => $threshold,
            'workflow_task_id' => (string) $task->id,
            'last_lease_lost_at' => self::timestamp($task->getAttribute('last_lease_lost_at')),
        ];
    }

    /**
     * The summary attributes that carry `task_attention`, or none when the
     * summary table has no such column.
     *
     * @return array<string, mixed>
     */
    public static function summaryAttributes(WorkflowRun $run, ?WorkflowTask $openWorkflowTask = null): array
    {
        if (! self::summaryTracked()) {
            return [];
        }

        return [
            'task_attention' => self::taskAttention($run, $openWorkflowTask),
        ];
    }

    /**
     * Whether the configured task table has the lost-lease columns.
     */
    public static function tracked(bool $fresh = false): bool
    {
        return self::hasColumn(self::taskModel(), 'lost_lease_count', $fresh);
    }

    /**
     * Whether the configured run-summary table has `task_attention`.
     */
    public static function summaryTracked(bool $fresh = false): bool
    {
        return self::hasColumn(self::summaryModel(), 'task_attention', $fresh);
    }

    /**
     * Run a query that reads the lost-lease columns. If a schema rollback
     * removed them, degrade to untracked instead of failing. Inside a
     * transaction the query runs in its own savepoint, so the failed
     * statement cannot abort the caller's transaction.
     *
     * @template T
     * @param callable(): T $query
     * @return T|null
     */
    public static function untrackedOnMissingColumn(Model $model, callable $query): mixed
    {
        $connection = $model->getConnection();

        try {
            return $connection->transactionLevel() > 0
                ? $connection->transaction(static fn () => $query())
                : $query();
        } catch (QueryException $exception) {
            if (! self::missingColumn($exception)) {
                throw $exception;
            }

            self::$schemas = [];

            return null;
        }
    }

    /**
     * Write a run summary whose values may carry task_attention.
     *
     * A schema rollback can drop the column while the cached check still
     * reports it. On that error the cache is forgotten and $write runs again
     * without task_attention. A failed statement aborts a caller's open
     * PostgreSQL transaction, so there the error is rethrown instead and the
     * caller's retry sees the current schema.
     *
     * @template T
     * @param array<string, mixed> $values
     * @param callable(array<string, mixed>): T $write
     * @return T
     */
    public static function writeSummary(array $values, callable $write): mixed
    {
        if (! array_key_exists('task_attention', $values)) {
            return $write($values);
        }

        try {
            return $write($values);
        } catch (QueryException $exception) {
            if (! self::missingColumn($exception)) {
                throw $exception;
            }

            self::$schemas = [];
            $connection = self::summaryModel()->getConnection();

            if ($connection->transactionLevel() > 0 && $connection->getDriverName() === 'pgsql') {
                throw $exception;
            }

            unset($values['task_attention']);

            return $write($values);
        }
    }

    private static function hasColumn(Model $model, string $column, bool $fresh = false): bool
    {
        $key = $model->getConnectionName() . '|' . $model->getTable() . '|' . $column;
        $cached = self::$schemas[$key] ?? null;
        // The framework clock, so a frozen test clock holds the window.
        $now = Date::now()->getTimestamp();

        if (! $fresh && $cached !== null) {
            $elapsed = $now - $cached['checked_at'];

            if ($elapsed >= 0 && $elapsed < self::SCHEMA_RECHECK_SECONDS) {
                return $cached['tracked'];
            }
        }

        $tracked = $model->getConnection()
            ->getSchemaBuilder()
            ->hasColumn($model->getTable(), $column);
        self::$schemas[$key] = [
            'tracked' => $tracked,
            'checked_at' => $now,
        ];

        return $tracked;
    }

    private static function missingColumn(QueryException $exception): bool
    {
        $message = $exception->getMessage();

        return in_array((string) $exception->getCode(), ['42S22', '42703'], true)
            || str_contains($message, 'no such column')
            || str_contains($message, 'has no column named')
            || str_contains($message, 'Unknown column')
            || str_contains($message, 'does not exist');
    }

    private static function taskModel(): Model
    {
        return ConfiguredV2Models::query('task_model', WorkflowTask::class)->getModel();
    }

    private static function summaryModel(): Model
    {
        return ConfiguredV2Models::query('run_summary_model', WorkflowRunSummary::class)->getModel();
    }

    private static function timestamp(mixed $value): ?string
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d\TH:i:s.up') : null;
    }
}
