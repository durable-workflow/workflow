<?php

declare(strict_types=1);

namespace Workflow\Traits;

use ReflectionAttribute;
use ReflectionClass;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use stdClass;

trait ResolvesMethodDependencies
{
    public function resolveMethodDependencies(array $parameters, ReflectionFunctionAbstract $reflector)
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

            if (method_exists($this->container, 'fireAfterResolvingAttributeCallbacks')) {
                call_user_func(
                    [$this->container, 'fireAfterResolvingAttributeCallbacks'],
                    $parameter->getAttributes(),
                    $instance
                );
            }
        }

        return $parameters;
    }

    protected function resolveClassMethodDependencies(array $parameters, $instance, $method)
    {
        if (! method_exists($instance, $method)) {
            return $parameters;
        }

        return $this->resolveMethodDependencies($parameters, new ReflectionMethod($instance, $method));
    }

    protected function transformDependency(ReflectionParameter $parameter, $parameters, $skippableValue)
    {
        $attribute = $this->contextualAttributeFromDependency($parameter);

        if ($attribute !== null &&
            method_exists($this->container, 'resolveFromAttribute')) {
            return call_user_func([$this->container, 'resolveFromAttribute'], $attribute, $parameter);
        }

        $className = $this->parameterClassName($parameter);

        if ($className !== null && ! $this->alreadyInParameters($className, $parameters)) {
            $isEnum = (new ReflectionClass($className))->isEnum();

            return $parameter->isDefaultValueAvailable()
                ? ($isEnum ? $parameter->getDefaultValue() : null)
                : $this->container->make($className);
        }

        return $skippableValue;
    }

    protected function alreadyInParameters($class, array $parameters)
    {
        foreach ($parameters as $value) {
            if ($value instanceof $class) {
                return true;
            }
        }

        return false;
    }

    protected function spliceIntoParameters(array &$parameters, $offset, $value)
    {
        array_splice($parameters, $offset, 0, [$value]);
    }

    private function contextualAttributeFromDependency(ReflectionParameter $parameter): ?ReflectionAttribute
    {
        $contract = 'Illuminate\\Contracts\\Container\\ContextualAttribute';

        if (! interface_exists($contract)) {
            return null;
        }

        return $parameter->getAttributes($contract, ReflectionAttribute::IS_INSTANCEOF)[0] ?? null;
    }

    private function parameterClassName(ReflectionParameter $parameter): ?string
    {
        $type = $parameter->getType();

        if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        $name = $type->getName();
        $declaringClass = $parameter->getDeclaringClass();

        if ($declaringClass !== null && $name === 'self') {
            return $declaringClass->getName();
        }

        $parentClass = $declaringClass?->getParentClass();

        if ($declaringClass !== null && $name === 'parent' &&
            $parentClass !== false && $parentClass !== null) {
            return $parentClass->getName();
        }

        return $name;
    }
}
