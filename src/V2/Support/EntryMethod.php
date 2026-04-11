<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use LogicException;
use ReflectionClass;
use ReflectionMethod;
use Workflow\V2\Activity;
use Workflow\V2\Workflow;

final class EntryMethod
{
    public static function forWorkflow(object|string $workflow): ReflectionMethod
    {
        return self::describe($workflow, Workflow::class, 'workflow')['method'];
    }

    public static function forActivity(object|string $activity): ReflectionMethod
    {
        return self::describe($activity, Activity::class, 'activity')['method'];
    }

    /**
     * @return array{
     *     method: ReflectionMethod,
     *     name: 'handle'|'execute',
     *     mode: 'canonical'|'compatibility',
     *     declared_on: class-string
     * }
     */
    public static function describeWorkflow(object|string $workflow): array
    {
        return self::describe($workflow, Workflow::class, 'workflow');
    }

    /**
     * @return array{
     *     method: ReflectionMethod,
     *     name: 'handle'|'execute',
     *     mode: 'canonical'|'compatibility',
     *     declared_on: class-string
     * }
     */
    public static function describeActivity(object|string $activity): array
    {
        return self::describe($activity, Activity::class, 'activity');
    }

    /**
     * @return array{
     *     method: ReflectionMethod,
     *     name: 'handle'|'execute',
     *     mode: 'canonical'|'compatibility',
     *     declared_on: class-string
     * }
     */
    private static function describe(object|string $target, string $baseClass, string $type): array
    {
        $reflection = new ReflectionClass($target);
        $current = $reflection;
        $resolvedMethod = null;
        $resolvedName = null;
        $resolvedMode = null;
        $declaredOn = null;

        while ($current instanceof ReflectionClass && is_subclass_of($current->getName(), $baseClass)) {
            $handle = self::declaredPublicMethod($current, 'handle');
            $execute = self::declaredPublicMethod($current, 'execute');

            if ($handle !== null && $execute !== null) {
                throw new LogicException(sprintf(
                    'V2 %s [%s] must declare only one entry method. Define handle() for new code or keep execute() for compatibility.',
                    $type,
                    $reflection->getName(),
                ));
            }

            if ($handle instanceof ReflectionMethod) {
                if ($resolvedName === 'execute') {
                    throw new LogicException(sprintf(
                        'V2 %s [%s] cannot mix handle() and execute() across its inheritance chain. Normalize the hierarchy to one entry method.',
                        $type,
                        $reflection->getName(),
                    ));
                }

                if ($resolvedMethod === null) {
                    $resolvedMethod = $handle;
                    $resolvedName = 'handle';
                    $resolvedMode = 'canonical';
                    $declaredOn = $current->getName();
                }
            }

            if ($execute instanceof ReflectionMethod) {
                if ($resolvedName === 'handle') {
                    throw new LogicException(sprintf(
                        'V2 %s [%s] cannot mix handle() and execute() across its inheritance chain. Normalize the hierarchy to one entry method.',
                        $type,
                        $reflection->getName(),
                    ));
                }

                if ($resolvedMethod === null) {
                    $resolvedMethod = $execute;
                    $resolvedName = 'execute';
                    $resolvedMode = 'compatibility';
                    $declaredOn = $current->getName();
                }
            }

            $current = $current->getParentClass();
        }

        if ($resolvedMethod instanceof ReflectionMethod && is_string($declaredOn)) {
            return [
                'method' => $resolvedMethod,
                'name' => $resolvedName,
                'mode' => $resolvedMode,
                'declared_on' => $declaredOn,
            ];
        }

        throw new LogicException(sprintf(
            'V2 %s [%s] must declare a public handle() method or, for compatibility, a public execute() method.',
            $type,
            $reflection->getName(),
        ));
    }

    private static function declaredPublicMethod(ReflectionClass $reflection, string $method): ?ReflectionMethod
    {
        if (! $reflection->hasMethod($method)) {
            return null;
        }

        $resolved = $reflection->getMethod($method);

        if (
            $resolved->getDeclaringClass()->getName() !== $reflection->getName()
            || ! $resolved->isPublic()
        ) {
            return null;
        }

        return $resolved;
    }
}
