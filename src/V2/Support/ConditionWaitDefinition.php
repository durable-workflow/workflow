<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Closure;
use ReflectionFunction;
use Throwable;

final class ConditionWaitDefinition
{
    public static function fingerprint(Closure $condition): ?string
    {
        $source = self::source($condition);

        return $source === null
            ? null
            : 'sha256:' . hash('sha256', $source);
    }

    private static function source(Closure $condition): ?string
    {
        try {
            $reflection = new ReflectionFunction($condition);
            $file = $reflection->getFileName();
            $startLine = $reflection->getStartLine();
            $endLine = $reflection->getEndLine();

            if (! is_string($file) || $file === '' || ! is_file($file) || $startLine < 1 || $endLine < $startLine) {
                return null;
            }

            $lines = file($file, FILE_IGNORE_NEW_LINES);

            if (! is_array($lines)) {
                return null;
            }

            $slice = array_slice($lines, $startLine - 1, $endLine - $startLine + 1);
            $source = trim(implode("\n", $slice));

            return $source === '' ? null : $source;
        } catch (Throwable) {
            return null;
        }
    }
}
