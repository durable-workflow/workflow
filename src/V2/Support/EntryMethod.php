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
     *     name: 'handle',
     *     mode: 'canonical',
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
     *     name: 'handle',
     *     mode: 'canonical',
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
     *     name: 'handle',
     *     mode: 'canonical',
     *     declared_on: class-string
     * }
     */
    private static function describe(object|string $target, string $baseClass, string $type): array
    {
        $reflection = new ReflectionClass($target);
        $current = $reflection;
        $resolvedMethod = null;
        $declaredOn = null;
        $executeDeclaredOn = null;

        while ($current instanceof ReflectionClass && is_subclass_of($current->getName(), $baseClass)) {
            $handle = self::declaredPublicMethod($current, 'handle');
            $execute = self::declaredPublicMethod($current, 'execute');

            if ($execute instanceof ReflectionMethod && $executeDeclaredOn === null) {
                $executeDeclaredOn = $current->getName();
            }

            if ($handle instanceof ReflectionMethod) {
                if ($resolvedMethod === null) {
                    $resolvedMethod = $handle;
                    $declaredOn = $current->getName();
                }
            }

            $current = $current->getParentClass();
        }

        if (is_string($executeDeclaredOn)) {
            throw new LogicException(sprintf(
                'V2 %s [%s] must declare a public handle() method; execute() is not supported as a v2 entry method.',
                $type,
                $reflection->getName(),
            ));
        }

        if ($resolvedMethod instanceof ReflectionMethod && is_string($declaredOn)) {
            return [
                'method' => $resolvedMethod,
                'name' => 'handle',
                'mode' => 'canonical',
                'declared_on' => $declaredOn,
            ];
        }

        throw new LogicException(sprintf(
            'V2 %s [%s] must declare a public handle() method.',
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
            $resolved->getDeclaringClass()
                ->getName() !== $reflection->getName()
            || ! $resolved->isPublic()
        ) {
            return null;
        }

        return $resolved;
    }
}
