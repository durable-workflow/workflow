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
     * @var array<class-string, string|null>
     */
    private static array $fingerprints = [];

    /**
     * @var array<class-string, list<string>>
     */
    private static array $queryMethods = [];

    /**
     * @var array<class-string, array<string, string>>
     */
    private static array $queryTargets = [];

    /**
     * @var array<class-string, list<array{name: string, parameters: list<array{name: string, position: int, required: bool, variadic: bool, default_available: bool, default: mixed, type: ?string, allows_null: bool}>}>>
     */
    private static array $queryContracts = [];

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
            self::$queryMethods[$class] = array_keys(self::queryTargets($class));
        }

        return self::$queryMethods[$class];
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
    public static function queryContracts(string $class): array
    {
        if (! self::isWorkflowClass($class)) {
            return [];
        }

        if (! array_key_exists($class, self::$queryContracts)) {
            $reflection = new ReflectionClass($class);
            $contracts = [];

            foreach (self::queryTargets($class) as $name => $method) {
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

            self::$queryContracts[$class] = $contracts;
        }

        return self::$queryContracts[$class];
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
    public static function queryContract(string $class, string $target): ?array
    {
        $resolved = self::resolveQueryTarget($class, $target);

        if ($resolved === null) {
            return null;
        }

        foreach (self::queryContracts($class) as $contract) {
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
    public static function resolveQueryTarget(string $class, string $target): ?array
    {
        $targets = self::queryTargets($class);

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
     * @return array{
     *     queries: list<string>,
     *     query_contracts: list<array{
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
            'queries' => self::queryMethods($class),
            'query_contracts' => self::queryContracts($class),
            'signals' => self::signalNames($class),
            'signal_contracts' => self::signalContracts($class),
            'updates' => self::updateMethods($class),
            'update_contracts' => self::updateContracts($class),
        ];
    }

    /**
     * @param class-string $class
     */
    public static function fingerprint(string $class): ?string
    {
        if (! self::isWorkflowClass($class)) {
            return null;
        }

        if (! array_key_exists($class, self::$fingerprints)) {
            $sources = [];
            $seen = [];

            self::collectFingerprintSources(new ReflectionClass($class), $sources, $seen);
            ksort($sources);

            self::$fingerprints[$class] = $sources === []
                ? null
                : 'sha256:' . hash('sha256', json_encode($sources, JSON_THROW_ON_ERROR));
        }

        return self::$fingerprints[$class];
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
        return self::resolveQueryTarget($class, $method) !== null;
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
     * @return array<string, string>
     */
    private static function queryTargets(string $class): array
    {
        if (! self::isWorkflowClass($class)) {
            return [];
        }

        if (! array_key_exists($class, self::$queryTargets)) {
            $targets = [];

            foreach ((new ReflectionClass($class))->getMethods() as $method) {
                $attributes = $method->getAttributes(QueryMethod::class);

                if ($attributes === []) {
                    continue;
                }

                /** @var QueryMethod $attribute */
                $attribute = $attributes[0]->newInstance();
                $name = $attribute->name ?? $method->getName();

                if (array_key_exists($name, $targets) && $targets[$name] !== $method->getName()) {
                    throw new LogicException(sprintf(
                        'Workflow [%s] declares duplicate durable query name [%s] on methods [%s] and [%s].',
                        $class,
                        $name,
                        $targets[$name],
                        $method->getName(),
                    ));
                }

                $targets[$name] = $method->getName();
            }

            ksort($targets);

            self::$queryTargets[$class] = $targets;
        }

        return self::$queryTargets[$class];
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

    /**
     * @param array<string, array{symbol: string, kind: string, source: string}> $sources
     * @param array<string, true> $seen
     */
    private static function collectFingerprintSources(
        ReflectionClass $reflection,
        array &$sources,
        array &$seen,
    ): void {
        $name = $reflection->getName();

        if (isset($seen[$name])) {
            return;
        }

        $seen[$name] = true;

        if ($name !== Workflow::class) {
            $source = self::reflectionSource($reflection);

            if ($source !== null) {
                $sources[$name] = [
                    'symbol' => $name,
                    'kind' => $reflection->isTrait() ? 'trait' : 'class',
                    'source' => $source,
                ];
            }
        }

        foreach ($reflection->getTraits() as $trait) {
            self::collectFingerprintSources($trait, $sources, $seen);
        }

        $parent = $reflection->getParentClass();

        if ($parent instanceof ReflectionClass && is_subclass_of($parent->getName(), Workflow::class)) {
            self::collectFingerprintSources($parent, $sources, $seen);
        }
    }

    private static function reflectionSource(ReflectionClass $reflection): ?string
    {
        $file = $reflection->getFileName();

        if (! is_string($file) || $file === '') {
            return null;
        }

        $startLine = $reflection->getStartLine();
        $endLine = $reflection->getEndLine();

        if (! is_int($startLine) || ! is_int($endLine) || $startLine < 1 || $endLine < $startLine) {
            return null;
        }

        $lines = @file($file);

        if (! is_array($lines)) {
            return null;
        }

        return implode('', array_slice($lines, $startLine - 1, $endLine - $startLine + 1));
    }
}
