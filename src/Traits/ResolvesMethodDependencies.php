<?php

declare(strict_types=1);

namespace Workflow\Traits;

use Illuminate\Routing\ResolvesRouteDependencies;
use ReflectionFunctionAbstract;
use stdClass;

trait ResolvesMethodDependencies
{
    use ResolvesRouteDependencies {
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
