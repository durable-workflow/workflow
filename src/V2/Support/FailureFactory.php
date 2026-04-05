<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Throwable;

final class FailureFactory
{
    /**
     * @return array{
     *     exception_class: class-string<Throwable>,
     *     message: string,
     *     file: string,
     *     line: int,
     *     trace_preview: string
     * }
     */
    public static function make(Throwable $throwable): array
    {
        return [
            'exception_class' => $throwable::class,
            'message' => $throwable->getMessage(),
            'file' => $throwable->getFile(),
            'line' => $throwable->getLine(),
            'trace_preview' => self::preview($throwable),
        ];
    }

    private static function preview(Throwable $throwable): string
    {
        $lines = [];

        foreach (array_slice($throwable->getTrace(), 0, 5) as $frame) {
            $class = isset($frame['class']) && is_string($frame['class']) ? $frame['class'] : '';
            $type = isset($frame['type']) && is_string($frame['type']) ? $frame['type'] : '';
            $function = isset($frame['function']) && is_string($frame['function']) ? $frame['function'] : 'unknown';
            $file = isset($frame['file']) && is_string($frame['file']) ? $frame['file'] : 'unknown';
            $line = isset($frame['line']) ? (string) $frame['line'] : '0';

            $lines[] = sprintf('%s%s%s @ %s:%s', $class, $type, $function, $file, $line);
        }

        return implode("\n", $lines);
    }
}
