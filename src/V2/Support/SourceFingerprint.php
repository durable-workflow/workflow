<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

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
 *
 * @internal
 */
final class SourceFingerprint
{
    public const V1 = 1;

    public const V2 = 2;

    /**
     * The version recorded for new runs.
     */
    public const CURRENT = self::V2;

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
     * Whether a value is a well-formed version 1 or version 2 fingerprint.
     */
    public static function isWellFormed(string $fingerprint): bool
    {
        return preg_match('/\A(?:sha256|sha256-tokens):[0-9a-f]{64}\z/D', $fingerprint) === 1;
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
