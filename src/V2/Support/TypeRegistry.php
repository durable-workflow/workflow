<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use LogicException;
use ReflectionClass;
use Workflow\V2\Activity;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

final class TypeRegistry
{
    /**
     * @var array<class-string, string>
     */
    private static array $cache = [];

    /**
     * @param class-string $class
     */
    public static function for(string $class): string
    {
        $configuredType = self::configuredTypeForClass($class);

        if ($configuredType !== null) {
            return $configuredType;
        }

        if (! isset(self::$cache[$class])) {
            $reflection = new ReflectionClass($class);
            $attributes = $reflection->getAttributes(Type::class);

            self::$cache[$class] = $attributes === []
                ? $class
                : $attributes[0]->newInstance()->key;
        }

        return self::$cache[$class];
    }

    public static function resolveWorkflowClass(string $storedClass, ?string $workflowType): string
    {
        return self::resolveClass($storedClass, $workflowType, Workflow::class, 'workflows', 'workflow');
    }

    public static function resolveActivityClass(string $storedClass, ?string $activityType): string
    {
        return self::resolveClass($storedClass, $activityType, Activity::class, 'activities', 'activity');
    }

    private static function configuredTypeForClass(string $class): ?string
    {
        $configKey = match (true) {
            is_subclass_of($class, Workflow::class) => 'workflows',
            is_subclass_of($class, Activity::class) => 'activities',
            default => null,
        };

        if ($configKey === null) {
            return null;
        }

        /** @var array<string, class-string>|null $types */
        $types = config("workflows.v2.types.{$configKey}");

        if (! is_array($types)) {
            return null;
        }

        foreach ($types as $type => $mappedClass) {
            if ($mappedClass === $class) {
                return $type;
            }
        }

        return null;
    }

    private static function configuredClassForType(string $type, string $configKey, string $expectedBaseClass): ?string
    {
        $mappedClass = config("workflows.v2.types.{$configKey}.{$type}");

        if (! is_string($mappedClass)) {
            return null;
        }

        if (! self::isValidClass($mappedClass, $expectedBaseClass)) {
            throw new LogicException(sprintf(
                'Configured durable type [%s] points to [%s], which is not a loadable %s.',
                $type,
                $mappedClass,
                $expectedBaseClass,
            ));
        }

        return $mappedClass;
    }

    private static function resolveClass(
        string $storedClass,
        ?string $type,
        string $expectedBaseClass,
        string $configKey,
        string $label,
    ): string {
        if (self::isValidClass($storedClass, $expectedBaseClass)) {
            return $storedClass;
        }

        if (is_string($type) && $type !== '') {
            $configuredClass = self::configuredClassForType($type, $configKey, $expectedBaseClass);

            if ($configuredClass !== null) {
                return $configuredClass;
            }
        }

        throw new LogicException(sprintf(
            'Unable to resolve %s class [%s] for durable type [%s]. Register it under [workflows.v2.types.%s.%s] or restore the original class.',
            $label,
            $storedClass,
            $type ?? 'unknown',
            $configKey,
            $type ?? '{type}',
        ));
    }

    private static function isValidClass(string $class, string $expectedBaseClass): bool
    {
        return class_exists($class) && is_subclass_of($class, $expectedBaseClass);
    }
}
