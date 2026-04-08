<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class() extends Migration {
    public function up(): void
    {
        DB::table('activity_executions')
            ->select([
                'id',
                'workflow_run_id',
                'status',
                'attempt_count',
                'current_attempt_id',
                'started_at',
                'closed_at',
                'last_heartbeat_at',
                'created_at',
                'updated_at',
            ])
            ->orderBy('id')
            ->chunk(100, function ($executions): void {
                foreach ($executions as $execution) {
                    $attemptNumber = $this->attemptNumber($execution);

                    if ($attemptNumber === null) {
                        continue;
                    }

                    $task = $this->matchingTask($execution);
                    $attempt = $this->existingAttempt($execution, $attemptNumber);
                    $attemptId = $attempt->id ?? $this->attemptId($execution);

                    if ($attempt === null) {
                        $status = $this->attemptStatus($execution, $task);
                        $startedAt = $execution->started_at ?? $execution->created_at ?? now();
                        $closedAt = $status === 'running'
                            ? null
                            : ($execution->closed_at ?? $execution->updated_at ?? $startedAt);
                        $updatedAt = $closedAt ?? $execution->last_heartbeat_at ?? $startedAt;

                        DB::table('activity_attempts')->insert([
                            'id' => $attemptId,
                            'workflow_run_id' => $execution->workflow_run_id,
                            'activity_execution_id' => $execution->id,
                            'workflow_task_id' => $task->id ?? null,
                            'attempt_number' => $attemptNumber,
                            'status' => $status,
                            'lease_owner' => $status === 'running' ? ($task->lease_owner ?? null) : null,
                            'started_at' => $startedAt,
                            'last_heartbeat_at' => $execution->last_heartbeat_at,
                            'lease_expires_at' => $status === 'running' ? ($task->lease_expires_at ?? null) : null,
                            'closed_at' => $closedAt,
                            'created_at' => $execution->created_at ?? $startedAt,
                            'updated_at' => $updatedAt,
                        ]);
                    }

                    if ($execution->current_attempt_id !== $attemptId) {
                        DB::table('activity_executions')
                            ->where('id', $execution->id)
                            ->update([
                                'current_attempt_id' => $attemptId,
                                'attempt_count' => max(
                                    is_numeric($execution->attempt_count ?? null)
                                        ? (int) $execution->attempt_count
                                        : 0,
                                    $attemptNumber,
                                ),
                            ]);
                    } elseif ((int) ($execution->attempt_count ?? 0) < $attemptNumber) {
                        DB::table('activity_executions')
                            ->where('id', $execution->id)
                            ->update([
                                'attempt_count' => $attemptNumber,
                            ]);
                    }

                    if (
                        $task !== null
                        && is_numeric($task->attempt_count ?? null)
                        && (int) $task->attempt_count < $attemptNumber
                    ) {
                        DB::table('workflow_tasks')
                            ->where('id', $task->id)
                            ->update([
                                'attempt_count' => $attemptNumber,
                            ]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Backfill-only migration.
    }

    private function existingAttempt(object $execution, int $attemptNumber): ?object
    {
        $currentAttemptId = $this->stringValue($execution->current_attempt_id);

        if ($currentAttemptId !== null) {
            $attempt = DB::table('activity_attempts')
                ->where('id', $currentAttemptId)
                ->first();

            if ($attempt !== null) {
                return $attempt;
            }
        }

        return DB::table('activity_attempts')
            ->where('activity_execution_id', $execution->id)
            ->where('attempt_number', $attemptNumber)
            ->first();
    }

    private function matchingTask(object $execution): ?object
    {
        if (! in_array($execution->status, ['pending', 'running'], true)) {
            return null;
        }

        return DB::table('workflow_tasks')
            ->select(['id', 'status', 'lease_owner', 'lease_expires_at', 'attempt_count'])
            ->where('workflow_run_id', $execution->workflow_run_id)
            ->where('task_type', 'activity')
            ->whereIn('status', ['leased', 'ready'])
            ->where('payload->activity_execution_id', $execution->id)
            ->orderByRaw("case when status = 'leased' then 0 else 1 end")
            ->orderByDesc('attempt_count')
            ->orderByDesc('created_at')
            ->first();
    }

    private function attemptStatus(object $execution, ?object $task): string
    {
        return match ($execution->status) {
            'running' => 'running',
            'completed' => 'completed',
            'failed' => 'failed',
            'cancelled' => 'cancelled',
            'pending' => $task?->status === 'leased' ? 'running' : 'expired',
            default => 'expired',
        };
    }

    private function attemptNumber(object $execution): ?int
    {
        $attemptCount = is_numeric($execution->attempt_count ?? null)
            ? (int) $execution->attempt_count
            : 0;

        if ($attemptCount > 0) {
            return $attemptCount;
        }

        if ($this->stringValue($execution->current_attempt_id) !== null) {
            return 1;
        }

        if (in_array($execution->status, ['running', 'completed', 'failed', 'cancelled'], true)) {
            return 1;
        }

        return ($execution->started_at !== null || $execution->closed_at !== null) ? 1 : null;
    }

    private function attemptId(object $execution): string
    {
        return $this->stringValue($execution->current_attempt_id) ?? (string) Str::ulid();
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : null;
    }
};
