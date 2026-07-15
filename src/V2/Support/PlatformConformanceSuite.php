<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use JsonException;
use RuntimeException;

/**
 * Canonical, machine-readable mirror of the public platform conformance suite.
 *
 * The complete authority document ships with the package so server endpoints,
 * release gates, and third-party harnesses all consume the same target,
 * category, and pass/fail semantics as the public docs site.
 *
 * @api Stable class surface consumed by the standalone workflow-server,
 * which re-exports the manifest from `GET /api/cluster/info` under the
 * `platform_conformance_suite` key.
 */
final class PlatformConformanceSuite
{
    public const SCHEMA = 'durable-workflow.v2.platform-conformance.suite';

    public const VERSION = 30;

    public const MIRROR_SHA256 = '1807509b4a56463c37998e91e433ff7cf79c49c9eb9722d6f36fefb38ac615a0';

    public const RESULT_SCHEMA = 'durable-workflow.v2.platform-conformance.result';

    public const RESULT_VERSION = 1;

    public const AUTHORITY_DOC = 'docs/platform-conformance.md';

    public const AUTHORITY_URL = 'https://durable-workflow.github.io/docs/2.0/platform-conformance';

    public const CATEGORY_STATUS_STABLE = 'stable';

    public const CATEGORY_STATUS_PROVISIONAL = 'provisional';

    public const CONFORMANCE_LEVEL_FULL = 'full';

    public const CONFORMANCE_LEVEL_PARTIAL = 'partial';

    public const CONFORMANCE_LEVEL_PROVISIONAL = 'provisional';

    public const CONFORMANCE_LEVEL_NONCONFORMING = 'nonconforming';

    public const CONFORMANCE_LEVELS = [
        self::CONFORMANCE_LEVEL_FULL,
        self::CONFORMANCE_LEVEL_PARTIAL,
        self::CONFORMANCE_LEVEL_PROVISIONAL,
        self::CONFORMANCE_LEVEL_NONCONFORMING,
    ];

    /**
     * @var array<string, mixed>|null
     */
    private static ?array $manifest = null;

    /**
     * @return array<string, mixed>
     */
    public static function manifest(): array
    {
        if (self::$manifest !== null) {
            return self::$manifest;
        }

        $path = dirname(__DIR__, 3) . '/resources/platform-conformance-contract.json';
        $json = file_get_contents($path);

        if ($json === false) {
            throw new RuntimeException("Platform conformance suite mirror is missing at {$path}.");
        }

        if (hash('sha256', $json) !== self::MIRROR_SHA256) {
            throw new RuntimeException(
                'Platform conformance suite mirror digest does not match the packaged authority.'
            );
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Platform conformance suite mirror is not valid JSON.', 0, $exception);
        }

        if (! is_array(
            $decoded
        ) || ($decoded['schema'] ?? null) !== self::SCHEMA || ($decoded['version'] ?? null) !== self::VERSION) {
            throw new RuntimeException('Platform conformance suite mirror identity does not match the class contract.');
        }

        self::$manifest = $decoded;

        return self::$manifest;
    }

    /**
     * @return array<int, string>
     */
    public static function targetNames(): array
    {
        return array_keys(self::manifest()['targets']);
    }

    /**
     * @return array<int, string>
     */
    public static function fixtureCategoryNames(): array
    {
        return array_keys(self::manifest()['fixture_catalog']);
    }
}
