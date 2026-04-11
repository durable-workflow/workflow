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
        return self::resolve($workflow, Workflow::class, 'workflow');
    }

    public static function forActivity(object|string $activity): ReflectionMethod
    {
        return self::resolve($activity, Activity::class, 'activity');
    }

    private static function resolve(object|string $target, string $baseClass, string $type): ReflectionMethod
    {
        $reflection = new ReflectionClass($target);
        $current = $reflection;

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
                return $handle;
            }

            if ($execute instanceof ReflectionMethod) {
                return $execute;
            }

            $current = $current->getParentClass();
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
