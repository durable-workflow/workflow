<?php

declare(strict_types=1);

namespace Workflow\States;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class StateCaster implements CastsAttributes, SerializesCastableAttributes
{
    private string $baseStateClass;

    public function __construct(string $baseStateClass)
    {
        $this->baseStateClass = $baseStateClass;
    }

    public function get($model, string $key, $value, array $attributes)
    {
        if ($value === null) {
            return null;
        }

        $stateClassName = $this->baseStateClass::resolveStateClass($value);

        if (! is_string($stateClassName) || ! is_subclass_of($stateClassName, $this->baseStateClass)) {
            throw new InvalidArgumentException("Unknown state `{$value}` for `{$this->baseStateClass}`.");
        }

        $state = new $stateClassName($model);
        $state->setField($key);

        return $state;
    }

    public function set($model, string $key, $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof $this->baseStateClass) {
            $value->setField($key);

            return $value::getMorphClass();
        }

        if (is_string($value) && is_subclass_of($value, $this->baseStateClass)) {
            return $value::getMorphClass();
        }

        $mapping = $this->getStateMapping();

        if (! $mapping->has($value)) {
            throw new InvalidArgumentException("Unknown state `{$value}` for `{$this->baseStateClass}`.");
        }

        /** @var string $stateClass */
        $stateClass = $mapping->get($value);

        return $stateClass::getMorphClass();
    }

    public function serialize($model, string $key, $value, array $attributes)
    {
        return $value instanceof State ? $value->jsonSerialize() : $value;
    }

    private function getStateMapping(): Collection
    {
        return $this->baseStateClass::getStateMapping();
    }
}
