<?php

declare(strict_types=1);

namespace Workflow\Traits;

use ReflectionClass;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use stdClass;

trait ResolvesMethodDependencies
{
    public function resolveMethodDependencies(array $parameters, ReflectionFunctionAbstract $reflector): array
    {
        $instanceCount = 0;

        $values = array_values($parameters);

        $skippableValue = new stdClass();

        foreach ($reflector->getParameters() as $key => $parameter) {
            $instance = $this->transformDependency($parameter, $parameters, $skippableValue);

            if ($instance !== $skippableValue) {
                $instanceCount++;

                $this->spliceIntoParameters($parameters, $key, $instance);
            } elseif (! array_key_exists($key - $instanceCount, $values) &&
                      $parameter->isDefaultValueAvailable()) {
                $this->spliceIntoParameters($parameters, $key, $parameter->getDefaultValue());
            }
        }

        return $parameters;
    }

    protected function resolveClassMethodDependencies(array $parameters, object $instance, string $method): array
    {
        if (! method_exists($instance, $method)) {
            return $parameters;
        }

        return $this->resolveMethodDependencies($parameters, new ReflectionMethod($instance, $method));
    }

    protected function transformDependency(
        ReflectionParameter $parameter,
        array $parameters,
        object $skippableValue
    ): mixed {
        $className = $this->getParameterClassName($parameter);

        if ($className !== null && ! $this->alreadyInParameters($className, $parameters)) {
            $isEnum = (new ReflectionClass($className))->isEnum();

            return $parameter->isDefaultValueAvailable()
                ? ($isEnum ? $parameter->getDefaultValue() : null)
                : $this->container->make($className);
        }

        return $skippableValue;
    }

    protected function alreadyInParameters(string $className, array $parameters): bool
    {
        foreach ($parameters as $parameter) {
            if ($parameter instanceof $className) {
                return true;
            }
        }

        return false;
    }

    protected function spliceIntoParameters(array &$parameters, int $offset, mixed $value): void
    {
        array_splice($parameters, $offset, 0, [$value]);
    }

    protected function getParameterClassName(ReflectionParameter $parameter): ?string
    {
        $type = $parameter->getType();

        if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        $name = $type->getName();
        $class = $parameter->getDeclaringClass();

        if ($class === null) {
            return $name;
        }

        if ($name === 'self') {
            return $class->getName();
        }

        if ($name === 'parent') {
            return $class->getParentClass()?->getName();
        }

        return $name;
    }
}
