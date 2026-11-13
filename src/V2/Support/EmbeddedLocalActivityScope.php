<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Closure;
use Throwable;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Models\WorkflowTask;

/** @internal The in-process queue job owns this hosting claim across local phases. */
final class EmbeddedLocalActivityScope
{
    /** @var array<string, array{attempt: int, history_sequence: int|null, resolutions: array<string, array{invocation: DeferredEntityReferenceResolution, arguments: array, error: Throwable|null}>}> */
    private static array $claims = [];

    /**
     * @template T
     * @param Closure(): T $operation
     * @return T
     */
    public static function forClaim(string $taskId, int $attempt, Closure $operation): mixed
    {
        $previous = self::$claims[$taskId] ?? null;
        unset(self::$claims[$taskId]);
        if (ConfiguredV2Models::connection()->transactionLevel() === 0) {
            self::$claims[$taskId] = ['attempt' => $attempt, 'history_sequence' => null, 'resolutions' => []];
        }
        try {
            return $operation();
        } finally {
            if ($previous === null) {
                unset(self::$claims[$taskId]);
            } else {
                self::$claims[$taskId] = $previous;
            }
        }
    }

    public static function owns(WorkflowTask $task): bool
    {
        $claim = self::$claims[$task->id] ?? null;
        return $claim !== null && $claim['attempt'] === (int) $task->attempt_count
            && $task->status === TaskStatus::Leased && $task->lease_owner === $task->id
            && $task->lease_expires_at !== null && now()->lt($task->lease_expires_at);
    }

    public static function contains(string $taskId): bool
    {
        return isset(self::$claims[$taskId]);
    }

    public static function historySequenceAtTaskStart(WorkflowTask $task, int $sequence): int
    {
        if (! self::owns($task)) {
            return $sequence;
        }
        self::$claims[$task->id]['history_sequence'] ??= $sequence;
        return self::$claims[$task->id]['history_sequence'];
    }

    public static function remember(DeferredEntityReferenceResolution $invocation, array $arguments, ?Throwable $error): void
    {
        $claim = self::$claims[$invocation->taskId] ?? null;
        if ($claim !== null && $claim['attempt'] === $invocation->taskAttempt) {
            self::$claims[$invocation->taskId]['resolutions'][$invocation->executionId] = [
                'invocation' => $invocation,
                'arguments' => $arguments,
                'error' => $error,
            ];
        }
    }

    /** @return array{invocation: DeferredEntityReferenceResolution, arguments: array, error: Throwable|null}|null */
    public static function resolution(WorkflowTask $task, string $executionId): ?array
    {
        return self::owns($task) ? self::$claims[$task->id]['resolutions'][$executionId] ?? null : null;
    }

    public static function forget(WorkflowTask $task, string $executionId): void
    {
        unset(self::$claims[$task->id]['resolutions'][$executionId]);
    }
}
