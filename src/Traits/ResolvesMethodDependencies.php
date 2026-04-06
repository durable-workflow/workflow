<?php

declare(strict_types=1);

namespace Workflow\Traits;

use ReflectionFunctionAbstract;
use stdClass;

if (! trait_exists(\Illuminate\Routing\ResolvesRouteDependencies::class) && trait_exists(
    \Illuminate\Routing\RouteDependencyResolverTrait::class
)) {
    class_alias(
        \Illuminate\Routing\RouteDependencyResolverTrait::class,
        \Illuminate\Routing\ResolvesRouteDependencies::class
    );
}

trait ResolvesMethodDependencies
{
    use \Illuminate\Routing\ResolvesRouteDependencies {
        resolveMethodDependencies as private resolveMethodDependenciesBase;
    }

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
        }

        return $parameters;
    }
}
