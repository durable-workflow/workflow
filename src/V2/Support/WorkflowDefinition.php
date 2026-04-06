<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use ReflectionClass;
use Workflow\QueryMethod;
use Workflow\UpdateMethod;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Workflow;

final class WorkflowDefinition
{
    /**
     * @var array<class-string, list<string>>
     */
    private static array $queryMethods = [];

    /**
     * @var array<class-string, list<string>>
     */
    private static array $signalNames = [];

    /**
     * @var array<class-string, list<string>>
     */
    private static array $updateMethods = [];

    /**
     * @param class-string $class
     * @return list<string>
     */
    public static function queryMethods(string $class): array
    {
        if (! self::isWorkflowClass($class)) {
            return [];
        }

        if (! array_key_exists($class, self::$queryMethods)) {
            self::$queryMethods[$class] = self::methodNamesWithAttribute($class, QueryMethod::class);
        }

        return self::$queryMethods[$class];
    }

    /**
     * @param class-string $class
     * @return list<string>
     */
    public static function signalNames(string $class): array
    {
        if (! self::isWorkflowClass($class)) {
            return [];
        }

        if (! array_key_exists($class, self::$signalNames)) {
            $signals = [];

            foreach ((new ReflectionClass($class))->getAttributes(Signal::class) as $attribute) {
                $signals[] = $attribute->newInstance()->name;
            }

            $signals = array_values(array_unique($signals));
            sort($signals);

            self::$signalNames[$class] = $signals;
        }

        return self::$signalNames[$class];
    }

    /**
     * @param class-string $class
     * @return list<string>
     */
    public static function updateMethods(string $class): array
    {
        if (! self::isWorkflowClass($class)) {
            return [];
        }

        if (! array_key_exists($class, self::$updateMethods)) {
            self::$updateMethods[$class] = self::methodNamesWithAttribute($class, UpdateMethod::class);
        }

        return self::$updateMethods[$class];
    }

    /**
     * @param class-string $class
     */
    public static function hasQueryMethod(string $class, string $method): bool
    {
        return in_array($method, self::queryMethods($class), true);
    }

    /**
     * @param class-string $class
     */
    public static function hasSignal(string $class, string $name): bool
    {
        return in_array($name, self::signalNames($class), true);
    }

    /**
     * @param class-string $class
     */
    public static function hasUpdateMethod(string $class, string $method): bool
    {
        return in_array($method, self::updateMethods($class), true);
    }

    /**
     * @param class-string $class
     * @return list<string>
     */
    private static function methodNamesWithAttribute(string $class, string $attributeClass): array
    {
        $methods = [];

        foreach ((new ReflectionClass($class))->getMethods() as $method) {
            if ($method->getAttributes($attributeClass) === []) {
                continue;
            }

            $methods[] = $method->getName();
        }

        sort($methods);

        return $methods;
    }

    /**
     * @param class-string $class
     */
    private static function isWorkflowClass(string $class): bool
    {
        return class_exists($class) && is_subclass_of($class, Workflow::class);
    }
}
