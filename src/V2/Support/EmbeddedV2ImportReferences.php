<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\CommandStatus;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\SignalStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Enums\UpdateStatus;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowRun;

/** @internal Validates every copied identifier that can resolve a durable row. */
final class EmbeddedV2ImportReferences
{
    /**
     * @param array<string, mixed> $bundle
     * @param array<string, mixed> $workflow
     * @return list<array{rule: string, message: string}>
     */
    public static function errors(array $bundle, array $workflow, bool $lock = false): array
    {
        $runId = self::string($workflow['run_id'] ?? null);
        $instanceId = self::string($workflow['instance_id'] ?? null);
        $namespace = self::string($workflow['namespace'] ?? null);
        if ($runId === null || $instanceId === null) {
            return [];
        }

        $commands = self::ids($bundle['commands'] ?? null, static fn (array $row): bool =>
            CommandType::tryFrom(self::string($row['type'] ?? null) ?? '') !== null
            && CommandStatus::tryFrom(self::string($row['status'] ?? null) ?? '') !== null);
        $signals = self::ids($bundle['signals'] ?? null, static fn (array $row): bool =>
            SignalStatus::tryFrom(self::string($row['status'] ?? null) ?? '') !== null);
        $updates = self::ids($bundle['updates'] ?? null, static fn (array $row): bool =>
            UpdateStatus::tryFrom(self::string($row['status'] ?? null) ?? '') !== null);
        $tasks = self::ids($bundle['tasks'] ?? null, static fn (array $row): bool =>
            TaskType::tryFrom(self::string($row['type'] ?? null) ?? '') !== null
            && TaskStatus::tryFrom(self::string($row['status'] ?? null) ?? '') !== null);
        $activities = self::ids($bundle['activities'] ?? null, static fn (array $row): bool =>
            ActivityStatus::tryFrom(self::string($row['status'] ?? null) ?? '') !== null);
        $timers = self::ids($bundle['timers'] ?? null, static fn (array $row): bool =>
            TimerStatus::tryFrom(self::string($row['status'] ?? null) ?? '') !== null);
        $failures = self::ids($bundle['failures'] ?? null, static fn (array $row): bool => true);
        [$attempts, $attemptsByActivity, $attemptErrors] = self::attempts($bundle, $activities, $tasks);
        $errors = $attemptErrors;

        foreach ([
            'commands' => $bundle['commands'] ?? null,
            'signals' => $bundle['signals'] ?? null,
            'updates' => $bundle['updates'] ?? null,
        ] as $section => $rows) {
            foreach (self::rows($rows) as $index => $row) {
                if (! self::importableTargetRow($section, $row)) {
                    continue;
                }
                $resolved = self::string($row['resolved_run_id'] ?? null);
                if ($resolved !== null && $resolved !== $runId) {
                    self::add(
                        $errors,
                        'references.resolved_run_invalid',
                        sprintf('%s.%d.resolved_run_id must identify the imported run.', $section, $index),
                    );
                }
                $requested = self::string($row['requested_run_id'] ?? null);
                if ($requested !== null && $requested !== $runId
                    && ! self::requestedRunAllowed($requested, $instanceId, $namespace, $lock)) {
                    self::add(
                        $errors,
                        'references.requested_run_invalid',
                        sprintf(
                            '%s.%d.requested_run_id must identify this run or another run of this workflow in the target namespace.',
                            $section,
                            $index,
                        ),
                    );
                }
            }
        }

        $archiveCommand = self::string($workflow['archive_command_id'] ?? null);
        if ($archiveCommand !== null && ! isset($commands[$archiveCommand])) {
            self::add(
                $errors,
                'references.archive_command_invalid',
                'workflow.archive_command_id must identify a command imported from this bundle.',
            );
        }

        foreach (self::rows($bundle['history_events'] ?? null) as $index => $event) {
            if (HistoryEventType::tryFrom(self::string($event['type'] ?? null) ?? '') === null) {
                continue;
            }
            $taskId = self::string($event['workflow_task_id'] ?? null);
            if ($taskId !== null && ! isset($tasks[$taskId])) {
                self::add(
                    $errors,
                    'references.history_task_invalid',
                    sprintf('history_events.%d.workflow_task_id must identify a task imported from this bundle.', $index),
                );
            }
            $commandId = self::string($event['workflow_command_id'] ?? null);
            if ($commandId !== null && ! isset($commands[$commandId])
                && ! self::movedMessageCommandHistory(
                    $event,
                    $commandId,
                    $runId,
                    $instanceId,
                    $namespace,
                    $lock,
                )) {
                self::add(
                    $errors,
                    'references.history_command_invalid',
                    sprintf(
                        'history_events.%d.workflow_command_id must identify a bundle command or a recorded instance-message handoff.',
                        $index,
                    ),
                );
            }
        }

        foreach (self::rows($bundle['activities'] ?? null) as $index => $activity) {
            $activityId = self::string($activity['id'] ?? null);
            if ($activityId === null || ! isset($activities[$activityId])) {
                continue;
            }
            $currentAttempt = self::string($activity['current_attempt_id'] ?? null);
            if ($currentAttempt !== null && ! isset($attemptsByActivity[$activityId][$currentAttempt])) {
                self::add(
                    $errors,
                    'references.activity_current_attempt_invalid',
                    sprintf(
                        'activities.%d.current_attempt_id must identify an imported attempt of that activity.',
                        $index,
                    ),
                );
            }
        }

        $taskTargets = [
            'activity_execution_id' => $activities,
            'activity_attempt_id' => $attempts,
            'timer_id' => $timers,
            'workflow_signal_id' => $signals,
            'workflow_update_id' => $updates,
            'workflow_command_id' => $commands,
        ];
        $resumeTargets = [
            'activity_execution' => $activities,
            'activity_attempt' => $attempts,
            'timer' => $timers,
            'workflow_signal' => $signals,
            'workflow_update' => $updates,
            'workflow_command' => $commands,
            'workflow_task' => $tasks,
        ];
        foreach (self::rows($bundle['tasks'] ?? null) as $index => $task) {
            if (! self::importableTask($task)) {
                continue;
            }
            $payload = is_array($task['payload'] ?? null) ? $task['payload'] : [];
            foreach ($taskTargets as $field => $owned) {
                $reference = self::string($payload[$field] ?? null);
                if ($reference !== null && ! isset($owned[$reference])) {
                    self::add(
                        $errors,
                        'references.task_payload_invalid',
                        sprintf('tasks.%d.payload.%s must identify a row imported from this bundle.', $index, $field),
                    );
                }
            }
            $resumeKind = self::string($payload['resume_source_kind'] ?? null);
            $resumeId = self::string($payload['resume_source_id'] ?? null);
            if ($resumeId !== null && isset($resumeTargets[$resumeKind])
                && ! isset($resumeTargets[$resumeKind][$resumeId])) {
                self::add(
                    $errors,
                    'references.task_payload_invalid',
                    sprintf(
                        'tasks.%d.payload.resume_source_id must identify a bundle row of kind %s.',
                        $index,
                        $resumeKind,
                    ),
                );
            }
        }

        foreach (self::rows($bundle['updates'] ?? null) as $index => $update) {
            if (UpdateStatus::tryFrom(self::string($update['status'] ?? null) ?? '') === null) {
                continue;
            }
            $failureId = self::string($update['failure_id'] ?? null);
            if ($failureId === null) {
                continue;
            }
            $query = WorkflowFailure::query()->whereKey($failureId);
            if ($lock) {
                $query->lockForUpdate();
            }
            if ($query->exists()) {
                self::add(
                    $errors,
                    'references.update_failure_invalid',
                    sprintf('updates.%d.failure_id is already owned by another stored failure.', $index),
                );
            } elseif (isset($failures[$failureId])) {
                // The failure will be created later in this same transaction.
            }
        }

        foreach (self::rows($bundle['failures'] ?? null) as $index => $failure) {
            if (self::string($failure['source_kind'] ?? null) !== 'activity_execution') {
                continue;
            }
            $sourceId = self::string($failure['source_id'] ?? null);
            if ($sourceId === null || ! isset($activities[$sourceId])) {
                self::add(
                    $errors,
                    'references.failure_activity_invalid',
                    sprintf('failures.%d.source_id must identify an activity imported from this bundle.', $index),
                );
            }
        }

        return $errors;
    }

    /**
     * @param array<string, true> $activities
     * @param array<string, true> $tasks
     * @return array{array<string, true>, array<string, array<string, true>>, list<array{rule: string, message: string}>}
     */
    private static function attempts(array $bundle, array $activities, array $tasks): array
    {
        $attempts = [];
        $byActivity = [];
        $errors = [];
        foreach (self::rows($bundle['activities'] ?? null) as $activityIndex => $activity) {
            $activityId = self::string($activity['id'] ?? null);
            foreach (self::rows($activity['attempts'] ?? null) as $attemptIndex => $attempt) {
                if (ActivityAttemptStatus::tryFrom(self::string($attempt['status'] ?? null) ?? '') === null) {
                    continue;
                }
                if ($activityId === null || ! isset($activities[$activityId])) {
                    self::add(
                        $errors,
                        'references.activity_attempt_without_activity',
                        sprintf(
                            'activities.%d.attempts.%d cannot be imported when its activity row is not importable.',
                            $activityIndex,
                            $attemptIndex,
                        ),
                    );
                    continue;
                }
                $attemptId = self::string($attempt['id'] ?? null);
                if ($attemptId !== null) {
                    $attempts[$attemptId] = true;
                    $byActivity[$activityId][$attemptId] = true;
                }
                $taskId = self::string($attempt['workflow_task_id'] ?? null)
                    ?? self::string($attempt['task_id'] ?? null);
                if ($taskId !== null && ! isset($tasks[$taskId])) {
                    self::add(
                        $errors,
                        'references.activity_attempt_task_invalid',
                        sprintf(
                            'activities.%d.attempts.%d.workflow_task_id must identify a task imported from this bundle.',
                            $activityIndex,
                            $attemptIndex,
                        ),
                    );
                }
            }
        }

        return [$attempts, $byActivity, $errors];
    }

    /** @param array<string, mixed> $row */
    private static function importableTargetRow(string $section, array $row): bool
    {
        return match ($section) {
            'commands' => CommandType::tryFrom(self::string($row['type'] ?? null) ?? '') !== null
                && CommandStatus::tryFrom(self::string($row['status'] ?? null) ?? '') !== null,
            'signals' => SignalStatus::tryFrom(self::string($row['status'] ?? null) ?? '') !== null,
            'updates' => UpdateStatus::tryFrom(self::string($row['status'] ?? null) ?? '') !== null,
            default => false,
        };
    }

    /** @param array<string, mixed> $row */
    private static function importableTask(array $row): bool
    {
        return TaskType::tryFrom(self::string($row['type'] ?? null) ?? '') !== null
            && TaskStatus::tryFrom(self::string($row['status'] ?? null) ?? '') !== null;
    }

    private static function requestedRunAllowed(
        string $runId,
        string $instanceId,
        ?string $namespace,
        bool $lock,
    ): bool {
        $query = WorkflowRun::query();
        if ($lock) {
            $query->lockForUpdate();
        }
        $run = $query->find($runId, ['id', 'workflow_instance_id', 'namespace']);

        return $run instanceof WorkflowRun
            && $run->workflow_instance_id === $instanceId
            && $run->namespace === $namespace;
    }

    /** @param array<string, mixed> $event */
    private static function movedMessageCommandHistory(
        array $event,
        string $commandId,
        string $runId,
        string $instanceId,
        ?string $namespace,
        bool $lock,
    ): bool {
        $type = self::string($event['type'] ?? null);
        $expectedCommandType = match ($type) {
            HistoryEventType::SignalReceived->value => CommandType::Signal->value,
            HistoryEventType::UpdateAccepted->value => CommandType::Update->value,
            default => null,
        };
        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
        $snapshot = is_array($payload['command'] ?? null) ? $payload['command'] : [];

        if ($expectedCommandType === null
            || self::string($payload['workflow_command_id'] ?? null) !== $commandId
            || self::string($payload['workflow_run_id'] ?? null) !== $runId
            || self::string($snapshot['id'] ?? null) !== $commandId
            || self::string($snapshot['type'] ?? null) !== $expectedCommandType
            || self::string($snapshot['target_scope'] ?? null) !== 'instance') {
            return false;
        }

        $commands = WorkflowCommand::query()->whereKey($commandId);
        if ($lock) {
            $commands->lockForUpdate();
        }
        $command = $commands->first(['id', 'workflow_instance_id', 'workflow_run_id', 'command_type', 'target_scope']);
        if (! $command instanceof WorkflowCommand) {
            return true;
        }
        $runs = WorkflowRun::query()->whereKey($command->workflow_run_id);
        if ($lock) {
            $runs->lockForUpdate();
        }
        $commandRun = $runs->first(['id', 'namespace']);

        return $command->workflow_instance_id === $instanceId
            && $command->command_type->value === $expectedCommandType
            && $command->target_scope === 'instance'
            && $commandRun instanceof WorkflowRun
            && $commandRun->namespace === $namespace;
    }

    /**
     * @param mixed $value
     * @param callable(array<string, mixed>): bool $importable
     * @return array<string, true>
     */
    private static function ids(mixed $value, callable $importable): array
    {
        $ids = [];
        foreach (self::rows($value) as $row) {
            $id = self::string($row['id'] ?? null);
            if ($id !== null && $importable($row)) {
                $ids[$id] = true;
            }
        }

        return $ids;
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_array(...))) : [];
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @param list<array{rule: string, message: string}> $errors */
    private static function add(array &$errors, string $rule, string $message): void
    {
        $errors[] = [
            'rule' => $rule,
            'message' => $message,
        ];
    }
}
