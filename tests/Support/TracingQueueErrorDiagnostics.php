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
}
