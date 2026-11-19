<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Closure;
use ReflectionFunction;
use Throwable;

final class ConditionWaitDefinition
{
    /**
     * The condition's definition fingerprint. Version 1 hashes the closure's
     * raw source lines; version 2 hashes their code tokens without comments
     * and whitespace (see {@see SourceFingerprint}).
     */
    public static function fingerprint(Closure $condition, int $version = SourceFingerprint::CURRENT): ?string
    {
        $version = SourceFingerprint::normalizeVersion($version);
        $source = $version === SourceFingerprint::V2
            ? self::codeTokens($condition)
            : self::source($condition);

        return $source === null
            ? null
            : SourceFingerprint::prefix($version) . hash('sha256', $source);
    }

    /**
     * Whether a recorded condition fingerprint matches the condition as it is
     * now, compared by the algorithm that produced the recorded value. A
     * condition whose current fingerprint is unavailable is not a mismatch,
     * matching how replay treated a missing fingerprint before versions.
     */
    public static function matches(Closure $condition, string $recorded, ?string $current = null): bool
    {
        $version = SourceFingerprint::versionOf($recorded);

        if ($current === null || SourceFingerprint::versionOf($current) !== $version) {
            $current = self::fingerprint($condition, $version);
        }

        return $current === null || $current === $recorded;
    }

    private static function codeTokens(Closure $condition): ?string
    {
        try {
            $reflection = new ReflectionFunction($condition);
            $file = $reflection->getFileName();
            $startLine = $reflection->getStartLine();
            $endLine = $reflection->getEndLine();

            if (! is_string($file) || $file === '' || ! is_file($file) || ! is_int($startLine) || ! is_int($endLine)) {
                return null;
            }

            return SourceFingerprint::codeTokens($file, $startLine, $endLine);
        } catch (Throwable) {
            return null;
        }
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
