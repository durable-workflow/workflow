<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Throwable;

/** @internal Queue observation only; never task or lease authority. */
final class TaskTraceCarrier
{
    private const SCHEMA = 'durable-workflow.queue-trace';

    /**
     * @return array<string, mixed>|null
     */
    public static function capture(string $taskId): ?array
    {
        if (! self::validTaskId($taskId)) {
            return null;
        }
        $context = TraceCarrier::capture();
        if ($context === null) {
            return null;
        }

        $envelope = [
            'schema' => self::SCHEMA,
            'version' => 1,
            'task_id' => $taskId,
            'context' => $context,
        ];
        try {
            return strlen(json_encode($envelope, JSON_THROW_ON_ERROR)) <= 4096 ? $envelope : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{schema: string, version: int, carrier: array<string, string>}|null
     */
    public static function fromJob(mixed $value, string $taskId): ?array
    {
        if (! self::validTaskId($taskId) || ! is_array($value) || ($value['schema'] ?? null) !== self::SCHEMA
            || ($value['version'] ?? null) !== 1 || ($value['task_id'] ?? null) !== $taskId
            || array_diff(array_keys($value), ['schema', 'version', 'task_id', 'context']) !== []
            || ! is_array($value['context'] ?? null) || ! is_array($value['context']['carrier'] ?? null)
            || array_diff(array_keys($value['context']['carrier']), ['traceparent', 'tracestate']) !== []) {
            return null;
        }
        $context = TraceCarrier::normalize($value['context']);
        if ($context === null) {
            return null;
        }

        try {
            return strlen(json_encode($value, JSON_THROW_ON_ERROR)) <= 4096 ? $context : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function validTaskId(string $taskId): bool
    {
        return $taskId !== '' && strlen($taskId) <= 512 && ! preg_match('/[\x00-\x1f\x7f]/', $taskId);
    }
}
