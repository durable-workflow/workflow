<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Resolves configurable Eloquent model classes referenced by v2 runtime and
 * server components so a host application can swap a model implementation
 * without forking the package.
 *
 * @api Stable class surface consumed by the standalone workflow-server.
 *      The public static method signatures on this class are covered by
 *      the workflow package's semver guarantee. See docs/api-stability.md.
 */
final class ConfiguredV2Models
{
    /**
     * @param class-string<Model> $default
     * @return class-string<Model>
     */
    public static function resolve(string $configKey, string $default): string
    {
        $configured = config('workflows.v2.' . $configKey, $default);

        return is_string($configured) && is_a($configured, $default, true)
            ? $configured
            : $default;
    }

    /**
     * @param class-string<Model> $default
     * @return Builder<Model>
     */
    public static function query(string $configKey, string $default): Builder
    {
        /** @var class-string<Model> $model */
        $model = self::resolve($configKey, $default);

        return $model::query();
    }
}
