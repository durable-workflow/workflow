<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use LogicException;
use ReflectionClass;
use Throwable;
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

    /**
     * @param class-string<Throwable> $class
     */
    public static function typeForThrowable(string $class): ?string
    {
        $configuredType = self::configuredTypeForClass($class);

        if ($configuredType !== null) {
            return $configuredType;
        }

        $reflection = new ReflectionClass($class);
        $attributes = $reflection->getAttributes(Type::class);

        return $attributes === []
            ? null
            : $attributes[0]->newInstance()->key;
    }

    /**
     * @return class-string<Throwable>|null
     */
    public static function resolveThrowableClass(string $storedClass, ?string $exceptionType): ?string
    {
        $resolution = self::resolveThrowableClassWithSource($storedClass, $exceptionType);

        return $resolution['class'] ?? null;
    }

    /**
     * @return array{class: class-string<Throwable>, source: 'exception_type'|'class_alias'|'recorded_class'}|null
     */
    public static function resolveThrowableClassWithSource(string $storedClass, ?string $exceptionType): ?array
    {
        if (is_string($exceptionType) && $exceptionType !== '') {
            $configuredClass = self::configuredClassForType($exceptionType, 'exceptions', Throwable::class);

            if ($configuredClass !== null) {
                return [
                    'class' => $configuredClass,
                    'source' => 'exception_type',
                ];
            }
        }

        $aliasedClass = self::configuredThrowableClassAlias($storedClass);

        if ($aliasedClass !== null) {
            return [
                'class' => $aliasedClass,
                'source' => 'class_alias',
            ];
        }

        if (self::isValidClass($storedClass, Throwable::class)) {
            return [
                'class' => $storedClass,
                'source' => 'recorded_class',
            ];
        }

        return null;
    }

    private static function configuredTypeForClass(string $class): ?string
    {
        $configKey = match (true) {
            is_subclass_of($class, Workflow::class) => 'workflows',
            is_subclass_of($class, Activity::class) => 'activities',
            is_subclass_of($class, Throwable::class) => 'exceptions',
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
        // Fetch the entire type map as an array and look up the key directly
        // so that dotted durable type keys like "tests.external-greeting-workflow"
        // are matched as flat array keys instead of being interpreted as nested
        // config paths by Laravel's dot-notation config helper.
        $types = config("workflows.v2.types.{$configKey}");

        if (! is_array($types)) {
            return null;
        }

        $mappedClass = $types[$type] ?? null;

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

    /**
     * @return class-string<Throwable>|null
     */
    private static function configuredThrowableClassAlias(string $storedClass): ?string
    {
        /** @var array<string, class-string>|null $aliases */
        $aliases = config('workflows.v2.types.exception_class_aliases');

        if (! is_array($aliases)) {
            return null;
        }

        $mappedClass = $aliases[$storedClass] ?? null;

        if (! is_string($mappedClass) || $mappedClass === '') {
            return null;
        }

        if (! self::isValidClass($mappedClass, Throwable::class)) {
            throw new LogicException(sprintf(
                'Configured exception class alias [%s] points to [%s], which is not a loadable %s.',
                $storedClass,
                $mappedClass,
                Throwable::class,
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
