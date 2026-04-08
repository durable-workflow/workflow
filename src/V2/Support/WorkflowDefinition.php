<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use LogicException;
use ReflectionClass;
use ReflectionMethod;
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
     * @var array<class-string, list<array{
     *     name: string,
     *     parameters: list<array{
     *         name: string,
     *         position: int,
     *         required: bool,
     *         variadic: bool,
     *         default_available: bool,
     *         default: mixed,
     *         type: ?string,
     *         allows_null: bool
     *     }>
     * }>>
     */
    private static array $signalContracts = [];

    /**
     * @var array<class-string, list<string>>
     */
    private static array $updateMethods = [];

    /**
     * @var array<class-string, array<string, string>>
     */
    private static array $updateTargets = [];

    /**
     * @var array<class-string, list<array{name: string, parameters: list<array{name: string, position: int, required: bool, variadic: bool, default_available: bool, default: mixed, type: ?string, allows_null: bool}>}>>
     */
    private static array $updateContracts = [];

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
     * @return array{
     *     signals: list<string>,
     *     signal_contracts: list<array{
     *         name: string,
     *         parameters: list<array{
     *             name: string,
     *             position: int,
     *             required: bool,
     *             variadic: bool,
     *             default_available: bool,
     *             default: mixed,
     *             type: ?string,
     *             allows_null: bool
     *         }>
     *     }>,
     *     updates: list<string>,
     *     update_contracts: list<array{
     *         name: string,
     *         parameters: list<array{
     *             name: string,
     *             position: int,
     *             required: bool,
     *             variadic: bool,
     *             default_available: bool,
     *             default: mixed,
     *             type: ?string,
     *             allows_null: bool
     *         }>
     *     }>
     * }
     */
    public static function commandContract(string $class): array
    {
        return [
            'signals' => self::signalNames($class),
            'signal_contracts' => self::signalContracts($class),
            'updates' => self::updateMethods($class),
            'update_contracts' => self::updateContracts($class),
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
            $seenSignals = [];

            foreach ((new ReflectionClass($class))->getAttributes(Signal::class) as $attribute) {
                /** @var Signal $definition */
                $definition = $attribute->newInstance();

                if (array_key_exists($definition->name, $seenSignals)) {
                    throw new LogicException(sprintf(
                        'Workflow [%s] declares duplicate durable signal name [%s].',
                        $class,
                        $definition->name,
                    ));
                }

                $signals[] = $definition->name;
                $seenSignals[$definition->name] = true;
            }

            sort($signals);

            self::$signalNames[$class] = $signals;
        }

        return self::$signalNames[$class];
    }

    /**
     * @param class-string $class
     * @return list<array{
     *     name: string,
     *     parameters: list<array{
     *         name: string,
     *         position: int,
     *         required: bool,
     *         variadic: bool,
     *         default_available: bool,
     *         default: mixed,
     *         type: ?string,
     *         allows_null: bool
     *     }>
     * }>
     */
    public static function signalContracts(string $class): array
    {
        if (! self::isWorkflowClass($class)) {
            return [];
        }

        if (! array_key_exists($class, self::$signalContracts)) {
            $contracts = [];
            $seenSignals = [];

            foreach ((new ReflectionClass($class))->getAttributes(Signal::class) as $attribute) {
                /** @var Signal $definition */
                $definition = $attribute->newInstance();

                if (array_key_exists($definition->name, $seenSignals)) {
                    throw new LogicException(sprintf(
                        'Workflow [%s] declares duplicate durable signal name [%s].',
                        $class,
                        $definition->name,
                    ));
                }

                if ($definition->parameters !== []) {
                    $contracts[] = [
                        'name' => $definition->name,
                        'parameters' => $definition->parameters,
                    ];
                }
                $seenSignals[$definition->name] = true;
            }

            usort($contracts, static fn (array $left, array $right): int => $left['name'] <=> $right['name']);

            self::$signalContracts[$class] = $contracts;
        }

        return self::$signalContracts[$class];
    }

    /**
     * @param class-string $class
     * @return array{
     *     name: string,
     *     parameters: list<array{
     *         name: string,
     *         position: int,
     *         required: bool,
     *         variadic: bool,
     *         default_available: bool,
     *         default: mixed,
     *         type: ?string,
     *         allows_null: bool
     *     }>
     * }|null
     */
    public static function signalContract(string $class, string $target): ?array
    {
        foreach (self::signalContracts($class) as $contract) {
            if ($contract['name'] === $target) {
                return $contract;
            }
        }

        return null;
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
     * @return list<array{
     *     name: string,
     *     parameters: list<array{
     *         name: string,
     *         position: int,
     *         required: bool,
     *         variadic: bool,
     *         default_available: bool,
     *         default: mixed,
     *         type: ?string,
     *         allows_null: bool
     *     }>
     * }>
     */
    public static function updateContracts(string $class): array
    {
        if (! self::isWorkflowClass($class)) {
            return [];
        }

        if (! array_key_exists($class, self::$updateContracts)) {
            $reflection = new ReflectionClass($class);
            $contracts = [];

            foreach (self::updateTargets($class) as $name => $method) {
                /** @var ReflectionMethod $reflectionMethod */
                $reflectionMethod = $reflection->getMethod($method);
                $parameters = [];
                $position = 0;

                foreach ($reflectionMethod->getParameters() as $parameter) {
                    if (self::isContainerInjected($parameter)) {
                        continue;
                    }

                    $parameters[] = [
                        'name' => $parameter->getName(),
                        'position' => $position,
                        'required' => ! $parameter->isDefaultValueAvailable() && ! $parameter->isVariadic(),
                        'variadic' => $parameter->isVariadic(),
                        'default_available' => $parameter->isDefaultValueAvailable(),
                        'default' => $parameter->isDefaultValueAvailable()
                            ? $parameter->getDefaultValue()
                            : null,
                        'type' => self::typeString($parameter),
                        'allows_null' => $parameter->getType()?->allowsNull() ?? true,
                    ];
                    $position++;
                }

                $contracts[] = [
                    'name' => $name,
                    'parameters' => $parameters,
                ];
            }

            self::$updateContracts[$class] = $contracts;
        }

        return self::$updateContracts[$class];
    }

    /**
     * @param class-string $class
     * @return array{
     *     name: string,
     *     parameters: list<array{
     *         name: string,
     *         position: int,
     *         required: bool,
     *         variadic: bool,
     *         default_available: bool,
     *         default: mixed,
     *         type: ?string,
     *         allows_null: bool
     *     }>
     * }|null
     */
    public static function updateContract(string $class, string $target): ?array
    {
        $resolved = self::resolveUpdateTarget($class, $target);

        if ($resolved === null) {
            return null;
        }

        foreach (self::updateContracts($class) as $contract) {
            if ($contract['name'] === $resolved['name']) {
                return $contract;
            }
        }

        return null;
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

    private static function isContainerInjected(\ReflectionParameter $parameter): bool
    {
        $type = $parameter->getType();

        return $type instanceof \ReflectionNamedType
            && ! $type->isBuiltin();
    }

    private static function typeString(\ReflectionParameter $parameter): ?string
    {
        $type = $parameter->getType();

        return $type === null
            ? null
            : (string) $type;
    }

    /**
     * @param class-string $class
     */
    private static function isWorkflowClass(string $class): bool
    {
        return class_exists($class) && is_subclass_of($class, Workflow::class);
    }
}
