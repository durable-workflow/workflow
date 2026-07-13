<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use JsonException;
use RuntimeException;

/**
 * Loader for the consumer-facing platform protocol-spec catalog.
 *
 * The packaged JSON document is also published at
 * https://durable-workflow.github.io/platform-protocol-specs.json. Each
 * catalog entry exposes a stable protocol id and a directly consumable HTTPS
 * OpenAPI, AsyncAPI, or JSON Schema reference. Repository-local implementation
 * symbols and test provenance are deliberately not part of this runtime
 * manifest.
 *
 * The standalone workflow server re-exports the manifest from
 * `GET /api/cluster/info` under the `platform_protocol_specs` key.
 *
 * Adding, removing, or changing a spec entry is a contract change. Bump
 * VERSION and MIRROR_SHA256, then align the packaged JSON document, the public
 * JSON mirror, its published spec artifacts, and the 2.0 consumer guide.
 *
 * @api Stable class surface consumed by the standalone workflow server.
 */
final class PlatformProtocolSpecs
{
    public const SCHEMA = 'durable-workflow.v2.platform-protocol-specs.catalog';

    public const VERSION = 15;

    public const PACKAGE_CONTRACT_PATH = 'resources/platform-protocol-specs.json';

    public const MIRROR_SHA256 = 'bf5474fd46d9a570352133fff88197afcb9b75d312b773572b01f81b4591d335';

    public const CATALOG_URL = 'https://durable-workflow.github.io/platform-protocol-specs.json';

    public const AUTHORITY_URL = 'https://durable-workflow.github.io/docs/2.0/platform-protocol-specs';

    public const FORMAT_OPENAPI = 'openapi';

    public const FORMAT_JSON_SCHEMA = 'json_schema';

    public const FORMAT_ASYNCAPI = 'asyncapi';

    public const FORMATS = [
        self::FORMAT_OPENAPI,
        self::FORMAT_JSON_SCHEMA,
        self::FORMAT_ASYNCAPI,
    ];

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_PLANNED = 'planned';

    public const STATUSES = [
        self::STATUS_PUBLISHED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_PLANNED,
    ];

    public const EVOLUTION_ADDITIVE_MINOR_BREAKING_MAJOR = 'additive_minor_breaking_major';

    public const EVOLUTION_PARALLEL_PRIMITIVE_ONLY = 'parallel_primitive_only';

    public const EVOLUTION_EXPERIMENTAL_ANY_RELEASE = 'experimental_any_release';

    public const OWNER_REPOS = [
        'durable-workflow/workflow',
        'durable-workflow/server',
        'durable-workflow/waterline',
        'durable-workflow/durable-workflow.github.io',
        'durable-workflow/cli',
        'durable-workflow/sdk-python',
    ];

    /** @var array<string, mixed>|null */
    private static ?array $manifest = null;

    /**
     * @return array<string, mixed>
     */
    public static function manifest(): array
    {
        if (self::$manifest !== null) {
            return self::$manifest;
        }

        $path = dirname(__DIR__, 3) . '/' . self::PACKAGE_CONTRACT_PATH;
        $json = file_get_contents($path);

        if ($json === false) {
            throw new RuntimeException("Platform protocol-spec catalog is missing at {$path}.");
        }

        if (hash('sha256', $json) !== self::MIRROR_SHA256) {
            throw new RuntimeException('Platform protocol-spec catalog digest does not match the packaged authority.');
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Platform protocol-spec catalog is not valid JSON.', 0, $exception);
        }

        if (
            !is_array($decoded)
            || ($decoded['schema'] ?? null) !== self::SCHEMA
            || ($decoded['version'] ?? null) !== self::VERSION
            || ($decoded['catalog_url'] ?? null) !== self::CATALOG_URL
            || ($decoded['authority_url'] ?? null) !== self::AUTHORITY_URL
        ) {
            throw new RuntimeException('Platform protocol-spec catalog identity does not match the class contract.');
        }

        self::$manifest = $decoded;

        return self::$manifest;
    }

    /**
     * @return array<int, string>
     */
    public static function formatValues(): array
    {
        return self::FORMATS;
    }

    /**
     * @return array<int, string>
     */
    public static function statusValues(): array
    {
        return self::STATUSES;
    }

    /**
     * @return array<int, string>
     */
    public static function ownerRepoValues(): array
    {
        return self::OWNER_REPOS;
    }

    /**
     * @return array<int, string>
     */
    public static function specNames(): array
    {
        return array_keys(self::manifest()['specs']);
    }
}
