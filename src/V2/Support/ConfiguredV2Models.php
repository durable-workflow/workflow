<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

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
