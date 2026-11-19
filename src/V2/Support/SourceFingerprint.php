<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use InvalidArgumentException;
use PhpToken;
use Throwable;

/**
 * Versioned source fingerprints for workflow definitions and condition waits.
 *
 * - Version 1 (`sha256:`) hashes raw source lines, comments and whitespace
 *   included. Histories recorded with it, or with no recognised prefix, keep
 *   being compared by version 1 exactly as before.
 * - Version 2 (`sha256-tokens:`) hashes the PHP token stream of the same lines
 *   without `T_COMMENT`, `T_DOC_COMMENT` and `T_WHITESPACE`, so editing only
 *   comments or formatting does not change it.
 *
 * The prefix is the version marker: a recorded value names the algorithm that
 * produced it, and comparison recomputes the current value with that algorithm.
 * Every release that has this class compares both versions. Which version new
 * runs record is `workflows.definition_fingerprint_version`
 * (`DW_DEFINITION_FINGERPRINT_VERSION`), `v1` by default: a worker from an
 * older release compares only version 1, so a fleet switches to `v2` only once
 * every worker runs a release that understands it.
 *
 * @internal
 */
final class SourceFingerprint
{
    public const V1 = 1;

    public const V2 = 2;

    public const CONFIG_KEY = 'workflows.definition_fingerprint_version';

    public const V1_PREFIX = 'sha256:';

    public const V2_PREFIX = 'sha256-tokens:';

    private const MAX_CACHED_FILES = 64;

    /**
     * @var array<string, array{hash: string, lines: list<int>, texts: list<string>}>
     */
    private static array $files = [];

    /**
     * The algorithm a recorded fingerprint was produced with. Values without
     * the version 2 prefix (version 1, absent or foreign) compare by version 1.
     */
    public static function versionOf(mixed $fingerprint): int
    {
        return is_string($fingerprint) && str_starts_with($fingerprint, self::V2_PREFIX)
            ? self::V2
            : self::V1;
    }

    public static function prefix(int $version): string
    {
        return $version === self::V2 ? self::V2_PREFIX : self::V1_PREFIX;
    }

    public static function normalizeVersion(int $version): int
    {
        return $version === self::V2 ? self::V2 : self::V1;
    }

    /**
     * The version new runs record, from `workflows.definition_fingerprint_version`
     * (`v1` or `v2`; `v1` when unset). Anything else is a configuration error.
     */
    public static function recordingVersion(): int
    {
        $configured = self::configuredVersion();

        if ($configured === null) {
            return self::V1;
        }

        $normalized = is_string($configured) ? strtolower(trim($configured)) : $configured;

        return match ($normalized) {
            '', 'v1' => self::V1,
            'v2' => self::V2,
            default => throw new InvalidArgumentException(sprintf(
                'Unsupported %s [%s]; use "v1" or "v2".',
                self::CONFIG_KEY,
                is_scalar($configured) ? (string) $configured : get_debug_type($configured),
            )),
        };
    }

    /**
     * Whether two recorded fingerprints name the same definition, judged from
     * the values alone. Values of the same version compare exactly. Values of
     * different versions were produced by different algorithms and cannot be
     * compared without the source, so the answer is null and callers decide.
     */
    public static function sameDefinition(?string $recorded, ?string $current): ?bool
    {
        if ($recorded === $current) {
            return true;
        }

        if ($recorded === null || $current === null || self::versionOf($recorded) === self::versionOf($current)) {
            return false;
        }

        return null;
    }

    /**
     * The canonical code-token text of the whole lines `$startLine` to
     * `$endLine` of `$file`: every token that starts on those lines, except
     * comments and whitespace, length-framed so token boundaries survive
     * (`+ +` and `++` stay distinct). Null when the file cannot be read or the
     * lines hold no code.
     */
    public static function codeTokens(string $file, int $startLine, int $endLine): ?string
    {
        if ($file === '' || $startLine < 1 || $endLine < $startLine) {
            return null;
        }

        $tokens = self::fileTokens($file);

        if ($tokens === null) {
            return null;
        }

        $count = count($tokens['lines']);
        $low = 0;
        $high = $count;

        // First token starting on or after $startLine.
        while ($low < $high) {
            $middle = intdiv($low + $high, 2);

            if ($tokens['lines'][$middle] < $startLine) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        $framed = '';

        for ($index = $low; $index < $count && $tokens['lines'][$index] <= $endLine; ++$index) {
            $text = $tokens['texts'][$index];
            $framed .= strlen($text) . ':' . $text;
        }

        return $framed === '' ? null : $framed;
    }

    private static function configuredVersion(): mixed
    {
        if (! function_exists('config')) {
            return null;
        }

        try {
            return config(self::CONFIG_KEY);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{hash: string, lines: list<int>, texts: list<string>}|null
     */
    private static function fileTokens(string $file): ?array
    {
        $source = @file_get_contents($file);

        if (! is_string($source)) {
            return null;
        }

        $hash = hash('sha256', $source);

        if ((self::$files[$file]['hash'] ?? null) === $hash) {
            return self::$files[$file];
        }

        try {
            $all = PhpToken::tokenize($source);
        } catch (Throwable) {
            return null;
        }

        $lines = [];
        $texts = [];

        foreach ($all as $token) {
            if ($token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                continue;
            }

            $lines[] = $token->line;
            // The open tag carries its trailing whitespace.
            $texts[] = $token->is(T_OPEN_TAG) ? rtrim($token->text) : $token->text;
        }

        // Bound process memory; a changed file replaces its own entry. The
        // path and content hash are cache keys only, never fingerprint input.
        unset(self::$files[$file]);

        if (count(self::$files) >= self::MAX_CACHED_FILES) {
            array_shift(self::$files);
        }

        return self::$files[$file] = [
            'hash' => $hash,
            'lines' => $lines,
            'texts' => $texts,
        ];
    }
}
