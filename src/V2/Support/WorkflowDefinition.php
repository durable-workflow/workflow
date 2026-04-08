<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use LogicException;
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
     * @var array<class-string, array<string, string>>
     */
    private static array $updateTargets = [];

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
     * @return array{signals: list<string>, updates: list<string>}
     */
    public static function commandContract(string $class): array
    {
        return [
            'signals' => self::signalNames($class),
            'updates' => self::updateMethods($class),
        ];
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
            self::$updateMethods[$class] = array_keys(self::updateTargets($class));
        }

        return self::$updateMethods[$class];
    }

    /**
     * @param class-string $class
     * @return array{name: string, method: string}|null
     */
    public static function resolveUpdateTarget(string $class, string $target): ?array
    {
        $targets = self::updateTargets($class);

        if (array_key_exists($target, $targets)) {
            return [
                'name' => $target,
                'method' => $targets[$target],
            ];
        }

        foreach ($targets as $name => $method) {
            if ($method === $target) {
                return [
                    'name' => $name,
                    'method' => $method,
                ];
            }
        }

        return null;
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
        return self::resolveUpdateTarget($class, $method) !== null;
    }

    /**
     * @param class-string $class
     * @return array<string, string>
     */
    private static function updateTargets(string $class): array
    {
        if (! self::isWorkflowClass($class)) {
            return [];
        }

        if (! array_key_exists($class, self::$updateTargets)) {
            $targets = [];

            foreach ((new ReflectionClass($class))->getMethods() as $method) {
                $attributes = $method->getAttributes(UpdateMethod::class);

                if ($attributes === []) {
                    continue;
                }

                /** @var UpdateMethod $attribute */
                $attribute = $attributes[0]->newInstance();
                $name = $attribute->name ?? $method->getName();

                if (array_key_exists($name, $targets) && $targets[$name] !== $method->getName()) {
                    throw new LogicException(sprintf(
                        'Workflow [%s] declares duplicate durable update name [%s] on methods [%s] and [%s].',
                        $class,
                        $name,
                        $targets[$name],
                        $method->getName(),
                    ));
                }

                $targets[$name] = $method->getName();
            }

            ksort($targets);

            self::$updateTargets[$class] = $targets;
        }

        return self::$updateTargets[$class];
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
