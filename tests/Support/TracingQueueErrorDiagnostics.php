<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\QueryException;
use Throwable;

final class TracingQueueErrorDiagnostics
{
    public static function context(Throwable $error): array
    {
        $statement = $error instanceof QueryException ? $error->getSql() : null;

        return [
            'statement' => $statement === null ? null : mb_strcut($statement, 0, 1024, 'UTF-8'),
            'statement_truncated' => $statement !== null && strlen($statement) > 1024,
            'stage_frames' => array_map(static fn (array $frame): array => [
                'file' => isset($frame['file']) ? basename($frame['file']) : null,
                'line' => $frame['line'] ?? null,
                'function' => isset($frame['function']) ? substr($frame['function'], 0, 128) : null,
            ], array_slice($error->getTrace(), 0, 8)),
        ];
    }

    public static function isFailedJobLogContention(array $error): bool
    {
        $code = $error['native_code'] ?? null;
        if (
            ($error['error_class'] ?? null) !== QueryException::class
            || ! is_int($code) || ! in_array($code & 255, [5, 6], true)
            || ($error['operation'] ?? null) !== 'insert'
            || ($error['transaction_level'] ?? null) !== 0
            || ($error['pdo_in_transaction'] ?? null) !== false
            || ($error['statement_truncated'] ?? null) !== false
            || ($error['statement'] ?? null) !== 'insert into "failed_jobs" '
                . '("uuid", "connection", "queue", "payload", "exception", "failed_at") values (?, ?, ?, ?, ?, ?)'
        ) {
            return false;
        }

        $frames = $error['stage_frames'] ?? [];
        if (! is_array($frames)) {
            return false;
        }

        return array_filter($frames, static fn (mixed $frame): bool => is_array($frame)
            && ($frame['file'] ?? null) === 'DatabaseUuidFailedJobProvider.php'
            && ($frame['function'] ?? null) === 'insert') !== []
            && array_filter($frames, static fn (mixed $frame): bool => is_array($frame)
                && ($frame['file'] ?? null) === 'WorkCommand.php'
                && ($frame['function'] ?? null) === 'logFailedJob') !== [];
    }
}
