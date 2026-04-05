<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use ReflectionClass;

final class DefaultPropertyCache
{
    /**
     * @var array<class-string, array<string, mixed>>
     */
    private static array $cache = [];

    /**
     * @param class-string $class
     * @return array<string, mixed>
     */
    public static function for(string $class): array
    {
        if (! isset(self::$cache[$class])) {
            self::$cache[$class] = (new ReflectionClass($class))->getDefaultProperties();
        }

        return self::$cache[$class];
    }
}
